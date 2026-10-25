<?php

namespace Tamara;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\Exceptions\TamaraApiException;
use Tamara\Exceptions\TamaraSecurityException;
use Tamara\Exceptions\TamaraWebhookException;
use Tamara\Security\TamaraDeduplicatorInterface;
use Tamara\Security\TamaraAtomicDeduplicatorInterface;
use Tamara\Security\TamaraInMemoryDeduplicator;
use Tamara\Security\TamaraOrderStateMachine;
use Tamara\Security\TamaraSanitizer;
use Tamara\Security\TamaraSignatureVerifier;
use Throwable;

/**
 * Handles incoming Tamara webhooks securely:
 *  - Enforces Tamara HS256 JWT signature verification
 *  - Rejects malformed or unexpected payloads
 *  - Validates and enforces state machine transitions against out-of-order events
 *  - Deduplicates events to prevent duplicate processing
 *  - Acknowledges unsupported event types without retrying them
 *  - Disables unredacted raw payload storage by default
 *  - Prevents sensitive data leakage in logs
 */
class TamaraWebhookProcessor
{
    /** @var array<string, string> Standard Tamara event mapping */
    const EVENT_MAP = [
        'order_approved'       => 'onTamaraApproved',
        'order_authorised'     => 'onTamaraAuthorised',
        'order_authorized'     => 'onTamaraAuthorised',
        'order_captured'       => 'onTamaraCaptured',
        'order_fully_captured' => 'onTamaraCaptured',
        'order_declined'       => 'onTamaraDeclined',
        'order_cancelled'      => 'onTamaraCancelled',
        'order_canceled'       => 'onTamaraCancelled',
        'order_expired'        => 'onTamaraExpired',
        'order_refunded'       => 'onTamaraRefunded',
    ];

    /** @var array<string, string> Map event type to target order status */
    const EVENT_TO_STATUS = [
        'order_approved'       => TamaraOrderStateMachine::STATUS_APPROVED,
        'order_authorised'     => TamaraOrderStateMachine::STATUS_AUTHORISED,
        'order_authorized'     => TamaraOrderStateMachine::STATUS_AUTHORISED,
        'order_captured'       => TamaraOrderStateMachine::STATUS_FULLY_CAPTURED,
        'order_fully_captured' => TamaraOrderStateMachine::STATUS_FULLY_CAPTURED,
        'order_declined'       => TamaraOrderStateMachine::STATUS_DECLINED,
        'order_cancelled'      => TamaraOrderStateMachine::STATUS_CANCELLED,
        'order_canceled'       => TamaraOrderStateMachine::STATUS_CANCELLED,
        'order_expired'        => TamaraOrderStateMachine::STATUS_EXPIRED,
        'order_refunded'       => TamaraOrderStateMachine::STATUS_REFUNDED,
    ];

    /** @var callable[] Entity resolvers */
    protected $resolvers = [];

    /** @var array<string, callable> Custom event handlers */
    protected $customHandlers = [];

    /** @var TamaraSignatureVerifier|null */
    protected $verifier;

    /** @var TamaraDeduplicatorInterface|null */
    protected $deduplicator;

    /** @var LoggerInterface */
    protected $logger;

    /** @var bool Whether to require signature verification */
    protected $enforceSignature = true;

    /** @var bool Whether to persist raw payloads (disabled by default for compliance) */
    protected $storeRawPayload = false;

    /** @var bool Redact raw payload if storage is enabled */
    protected $redactRawPayload = true;
    protected $autoAuthorise = false;
    protected $authoriser = null;
    protected $orderStatusResolver = null;

    public function __construct(
        ?TamaraSignatureVerifier $verifier = null,
        ?TamaraDeduplicatorInterface $deduplicator = null,
        ?LoggerInterface $logger = null
    ) {
        $this->verifier     = $verifier;
        $this->deduplicator = $deduplicator ?? new TamaraInMemoryDeduplicator();
        $this->logger       = $logger ?? new NullLogger();
    }

    /**
     * Set or configure the Tamara JWT signature verifier.
     */
    public function setVerifier(TamaraSignatureVerifier $verifier): self
    {
        $this->verifier = $verifier;
        return $this;
    }

    /**
     * Create and set verifier from notification token.
     */
    public function setNotificationToken(string $notificationToken): self
    {
        $this->verifier = new TamaraSignatureVerifier($notificationToken);
        return $this;
    }

    /**
     * Enable or disable signature verification enforcement.
     */
    public function enforceSignature(bool $enforce = true): self
    {
        $this->enforceSignature = $enforce;
        return $this;
    }

    /**
     * Set a custom event deduplicator (e.g. Redis, Cache, DB).
     */
    public function setDeduplicator(?TamaraDeduplicatorInterface $deduplicator): self
    {
        $this->deduplicator = $deduplicator;
        return $this;
    }

    public function setAuthoriser(callable $authoriser): self
    {
        $this->authoriser = $authoriser;
        return $this;
    }

    public function setOrderStatusResolver(callable $resolver): self
    {
        $this->orderStatusResolver = $resolver;
        return $this;
    }

    public function autoAuthorise(bool $enabled = true): self
    {
        if ($enabled && $this->authoriser === null) {
            throw new \LogicException('Auto-authorisation requires an authoriser configured by TamaraService.');
        }
        $this->autoAuthorise = $enabled;
        return $this;
    }

    /**
     * Configure raw payload storage (disabled by default for privacy/PCI compliance).
     */
    public function enableRawPayloadStorage(bool $enable = true, bool $redact = true): self
    {
        $this->storeRawPayload = $enable;
        $this->redactRawPayload = $redact;
        return $this;
    }

    /**
     * Add an entity resolver: fn(string $orderReferenceId, ?string $orderId = null): ?TamaraOrderableInterface
     */
    public function addResolver(callable $resolver): self
    {
        $this->resolvers[] = $resolver;
        return $this;
    }

    /**
     * Register a custom handler for specific event types.
     *
     * @param string $eventType e.g. 'order_refunded' or custom event
     * @param callable $handler fn(TamaraOrderableInterface $entity, array $data): void
     */
    public function on(string $eventType, callable $handler): self
    {
        $this->customHandlers[strtolower(trim($eventType))] = $handler;
        return $this;
    }

    /**
     * Process an incoming Tamara webhook request.
     *
     * @param array $data Decoded JSON body
     * @param string $rawPayload Raw request body
     * @param string|null $token Explicit tamaraToken (JWT)
     * @param string|null $authHeader HTTP Authorization header
     * @param array|string|null $query Query parameters
     * @return array{success: bool, error?: string, duplicate?: bool, ignored?: bool, message?: string}
     */
    public function process(
        array $data,
        string $rawPayload,
        ?string $token = null,
        ?string $authHeader = null,
        mixed $query = null
    ): array {
        // 1. Enforce Webhook Signature Verification (Fail closed)
        if ($this->enforceSignature) {
            if ($this->verifier === null) {
                $this->logger->error('Tamara Webhook: Signature verification is enabled but verifier/notification token is not configured.');
                return ['success' => false, 'http_status' => 500, 'error' => 'Webhook verification misconfigured'];
            }

            $extractedToken = TamaraSignatureVerifier::extractToken($token, $authHeader, $query);

            if (empty($extractedToken)) {
                $this->logger->warning('Tamara Webhook: Missing required authentication token.');
                return ['success' => false, 'http_status' => 401, 'error' => 'Missing Tamara authentication token'];
            }

            try {
                $this->verifier->verify($extractedToken);
            } catch (TamaraSecurityException $e) {
                $this->logger->warning("Tamara Webhook: Authentication failed - {$e->getMessage()}");
                return ['success' => false, 'http_status' => 401, 'error' => 'Invalid webhook authentication'];
            }
        }

        // 2. Reject Malformed Webhook Payloads
        $validationError = $this->validatePayload($data, $rawPayload);
        if ($validationError !== null) {
            $this->logger->warning("Tamara Webhook: Malformed payload rejected - {$validationError}");
            return ['success' => false, 'http_status' => 400, 'error' => "Malformed payload: {$validationError}"];
        }

        $orderReferenceId = (string) $data['order_reference_id'];
        $orderId          = (string) $data['order_id'];
        $eventType        = strtolower(trim((string) $data['event_type']));

        // 3. Acknowledge unknown event types so Tamara does not retry events we cannot handle
        $hasMethodHandler = isset(self::EVENT_MAP[$eventType]);
        $hasCustomHandler = isset($this->customHandlers[$eventType]);

        if (!$hasMethodHandler && !$hasCustomHandler) {
            $this->logger->warning("Tamara Webhook: Unsupported or unhandled event type [{$eventType}]");
            return [
                'success' => true, 'http_status' => 200, 'ignored' => true,
                'message' => 'Unsupported webhook event ignored',
            ];
        }

        // 4. Prevent Duplicate Webhook Processing
        $eventKey = $this->resolveEventKey($data, $eventType, $orderId);
        if ($this->deduplicator && $this->deduplicator->isProcessed($eventKey)) {
            $this->logger->info("Tamara Webhook: Duplicate event [{$eventKey}] ignored.");
            return [
                'success'   => true,
                'http_status' => 200,
                'duplicate' => true,
                'message'   => 'Duplicate webhook event already processed',
            ];
        }

        // 5. Resolve Order Entity
        $entity = $this->resolveEntity($orderReferenceId, $orderId);
        if (!$entity) {
            $this->logger->warning("Tamara Webhook: Order entity not found for reference [{$orderReferenceId}]");
            return ['success' => false, 'http_status' => 404, 'error' => 'Order entity not found'];
        }

        // 6. Protect Against Out-of-Order Events (State Machine Validation)
        try {
            $targetStatus = $this->resolveTargetStatus($eventType, $data, $entity, $orderId);
        } catch (Throwable $e) {
            $this->logger->error('Unable to resolve Tamara remote order status for webhook.', [
                'order_reference_id' => $orderReferenceId,
            ]);
            return ['success' => false, 'http_status' => 502, 'error' => 'Unable to verify Tamara order status'];
        }
        $currentStatus = $entity->getTamaraStatus();
        $autoAuthorisedMarker = $this->autoAuthorisedMarkerKey($orderId);
        $autoAuthorisedAlreadyDispatched = $this->deduplicator !== null
            && $this->deduplicator->isProcessed($autoAuthorisedMarker);

        if ($targetStatus !== null && !TamaraOrderStateMachine::canTransition($currentStatus, $targetStatus)) {
            $stale = TamaraOrderStateMachine::isStaleTransition($currentStatus, $targetStatus);
            $this->logger->warning(sprintf(
                'Tamara Webhook: Out-of-order transition %s. Order [%s] in status [%s] cannot transition to [%s] via [%s].',
                $stale ? 'acknowledged as stale' : 'deferred for retry',
                $orderReferenceId,
                $currentStatus ?? 'none',
                $targetStatus,
                $eventType
            ));
            return [
                'success' => $stale,
                'http_status' => $stale ? 200 : 409,
                'ignored' => true,
                'retry' => !$stale,
                'message' => $stale ? 'Stale webhook event ignored' : 'Webhook event is ahead of the current order status',
            ];
        }

        $claimed = false;
        if ($this->deduplicator instanceof TamaraAtomicDeduplicatorInterface) {
            if (!$this->deduplicator->claim($eventKey, 300)) {
                $alreadyProcessed = $this->deduplicator->isProcessed($eventKey);
                return [
                    'success' => $alreadyProcessed,
                    'http_status' => $alreadyProcessed ? 200 : 503,
                    'duplicate' => $alreadyProcessed,
                    'retry' => !$alreadyProcessed,
                    'message' => $alreadyProcessed ? 'Duplicate webhook event already processed' : 'Webhook event is already being processed',
                ];
            }
            $claimed = true;
        }

        if ($targetStatus !== null) {
            $data['_tamara_order_status'] = $targetStatus;
        }

        // 7. Dispatch to Entity or Custom Handler
        $skipAuthorisedDispatch = in_array($eventType, ['order_authorised', 'order_authorized'], true)
            && (TamaraOrderStateMachine::normalizeStatus((string) $currentStatus) === TamaraOrderStateMachine::STATUS_AUTHORISED
                || $autoAuthorisedAlreadyDispatched);
        try {
            if (!$skipAuthorisedDispatch) {
                $this->dispatch($entity, $eventType, $data);
            }
        } catch (Throwable $e) {
            $this->releaseClaim($eventKey, $claimed);
            $this->logger->error('Tamara webhook handler failed.', ['order_reference_id' => $orderReferenceId]);
            return ['success' => false, 'http_status' => 500, 'error' => 'Webhook handler failed'];
        }
        if ($eventType === 'order_approved' && $this->autoAuthorise) {
            try {
                ($this->authoriser)($orderId);
            } catch (TamaraApiException $e) {
                $this->releaseClaim($eventKey, $claimed);
                $this->logger->error('Tamara auto-authorisation failed.', [
                    'order_reference_id' => $orderReferenceId,
                    'http_status' => $e->getHttpStatusCode(),
                ]);
                return ['success' => false, 'http_status' => 502, 'error' => 'Tamara auto-authorisation failed'];
            } catch (\Throwable $e) {
                $this->releaseClaim($eventKey, $claimed);
                $this->logger->error('Tamara auto-authorisation failed unexpectedly.', [
                    'order_reference_id' => $orderReferenceId,
                ]);
                return ['success' => false, 'http_status' => 502, 'error' => 'Tamara auto-authorisation failed'];
            }

            try {
                $this->dispatch($entity, 'order_authorised', $data);
                if ($this->deduplicator !== null) {
                    $this->deduplicator->markProcessed($autoAuthorisedMarker, 86400);
                }
            } catch (Throwable $e) {
                $this->releaseClaim($eventKey, $claimed);
                $this->logger->error('Tamara authorisation succeeded remotely but local handling failed.', [
                    'order_reference_id' => $orderReferenceId,
                ]);
                return ['success' => false, 'http_status' => 500, 'error' => 'Local authorisation handling failed'];
            }
        }

        // 8. Deduplication: Mark Event as Processed
        if ($this->deduplicator) {
            $this->deduplicator->markProcessed($eventKey);
        }

        // 9. Avoid Storing Raw Webhook Payloads by Default
        if ($this->storeRawPayload) {
            $payloadToStore = $this->redactRawPayload
                ? TamaraSanitizer::sanitizeJson($rawPayload)
                : $rawPayload;
            $entity->onTamaraRawPayload($payloadToStore);
        }

        // 10. Remove Sensitive Data from Logs
        $this->logger->info("Tamara Webhook successfully processed [{$eventType}] for order reference [{$orderReferenceId}]", [
            'event_type'         => $eventType,
            'order_reference_id' => $orderReferenceId,
            'order_id'           => $orderId,
        ]);

        return ['success' => true, 'http_status' => 200];
    }

    /**
     * Validate structure, required fields, and field types of webhook payload.
     */
    protected function validatePayload(array $data, string $rawPayload): ?string
    {
        if (trim($rawPayload) === '') {
            return 'Payload body is empty';
        }

        // Verify JSON parse sanity
        $parsed = json_decode($rawPayload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            return 'Invalid JSON payload structure';
        }

        if (empty($data['event_type']) || !is_string($data['event_type'])) {
            return "Field 'event_type' is missing or not a string";
        }

        if (empty($data['order_id']) || !is_string($data['order_id'])) {
            return "Field 'order_id' is missing or not a string";
        }

        if (!isset($data['order_reference_id']) || (!is_string($data['order_reference_id']) && !is_numeric($data['order_reference_id']))) {
            return "Field 'order_reference_id' is missing or not a valid identifier";
        }

        if (isset($data['data']) && !is_array($data['data'])) {
            return "Field 'data' must be an array when present";
        }

        return null;
    }

    /**
     * Resolve a durable event key for deduplication.
     */
    protected function resolveEventKey(array $data, string $eventType, string $orderId): string
    {
        if (!empty($data['event_id']) && is_string($data['event_id'])) {
            return 'tamara:event:' . $data['event_id'];
        }

        $eventData = is_array($data['data'] ?? null) ? $data['data'] : [];
        foreach (['capture_id', 'refund_id', 'cancel_id', 'transaction_id'] as $idField) {
            if (!empty($eventData[$idField]) && (is_string($eventData[$idField]) || is_numeric($eventData[$idField]))) {
                return 'tamara:event:' . $eventType . ':' . $idField . ':' . $eventData[$idField];
            }
        }

        return 'tamara:event:' . $eventType . ':' . hash('sha256', (string) json_encode($this->canonicalize($data)));
    }

    protected function resolveTargetStatus(
        string $eventType,
        array $data,
        TamaraOrderableInterface $entity,
        string $orderId
    ): ?string {
        if (!in_array($eventType, ['order_captured', 'order_fully_captured', 'order_refunded'], true)) {
            return self::EVENT_TO_STATUS[$eventType] ?? null;
        }

        $remoteStatus = $this->orderStatusResolver !== null
            ? ($this->orderStatusResolver)($orderId)
            : null;
        $remoteStatus = $remoteStatus ?? ($data['status'] ?? ($data['data']['status'] ?? null));
        if (is_string($remoteStatus)) {
            $normalized = TamaraOrderStateMachine::normalizeStatus($remoteStatus);
            if (in_array($normalized, [
                TamaraOrderStateMachine::STATUS_PARTIALLY_CAPTURED,
                TamaraOrderStateMachine::STATUS_FULLY_CAPTURED,
                TamaraOrderStateMachine::STATUS_PARTIALLY_REFUNDED,
                TamaraOrderStateMachine::STATUS_REFUNDED,
            ], true)) {
                return $normalized;
            }
        }

        $amountData = in_array($eventType, ['order_captured', 'order_fully_captured'], true)
            ? ($data['data']['captured_amount']['amount'] ?? null)
            : ($data['data']['refunded_amount']['amount'] ?? null);
        if (is_numeric($amountData) && (float) $amountData < $entity->getTamaraAmount()) {
            return in_array($eventType, ['order_captured', 'order_fully_captured'], true)
                ? TamaraOrderStateMachine::STATUS_PARTIALLY_CAPTURED
                : TamaraOrderStateMachine::STATUS_PARTIALLY_REFUNDED;
        }

        return self::EVENT_TO_STATUS[$eventType] ?? null;
    }

    protected function canonicalize(array $value): array
    {
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->canonicalize($nested);
            }
        }
        ksort($value);
        return $value;
    }

    protected function releaseClaim(string $eventKey, bool $claimed): void
    {
        if ($claimed && $this->deduplicator instanceof TamaraAtomicDeduplicatorInterface) {
            $this->deduplicator->release($eventKey);
        }
    }

    protected function autoAuthorisedMarkerKey(string $orderId): string
    {
        return 'tamara:auto-authorised-handler:' . hash('sha256', $orderId);
    }

    /**
     * Resolve entity by querying registered resolver callbacks.
     */
    protected function resolveEntity(string $referenceId, ?string $orderId = null): ?TamaraOrderableInterface
    {
        foreach ($this->resolvers as $resolver) {
            $result = $resolver($referenceId, $orderId);
            if ($result instanceof TamaraOrderableInterface) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Dispatch event to custom handler or standard entity callback.
     */
    protected function dispatch(TamaraOrderableInterface $entity, string $eventType, array $data): void
    {
        if (isset($this->customHandlers[$eventType])) {
            ($this->customHandlers[$eventType])($entity, $data);
            return;
        }

        if (isset(self::EVENT_MAP[$eventType])) {
            $method = self::EVENT_MAP[$eventType];
            if (in_array($eventType, ['order_captured', 'order_fully_captured'], true)
                && ($data['_tamara_order_status'] ?? null) === TamaraOrderStateMachine::STATUS_PARTIALLY_CAPTURED
                && method_exists($entity, 'onTamaraPartiallyCaptured')) {
                $method = 'onTamaraPartiallyCaptured';
            } elseif ($eventType === 'order_refunded'
                && ($data['_tamara_order_status'] ?? null) === TamaraOrderStateMachine::STATUS_PARTIALLY_REFUNDED
                && method_exists($entity, 'onTamaraPartiallyRefunded')) {
                $method = 'onTamaraPartiallyRefunded';
            }
            if (method_exists($entity, $method)) {
                $entity->$method($data);
            }
        }
    }
}
