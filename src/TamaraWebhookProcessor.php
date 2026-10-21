<?php

namespace Tamara;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\Exceptions\TamaraWebhookException;

/**
 * Handles incoming Tamara webhooks and dispatches events to the matching order model.
 */
class TamaraWebhookProcessor
{
    const EVENT_MAP = [
        'order_approved'   => 'onTamaraApproved',
        'order_authorised' => 'onTamaraAuthorised',
        'order_captured'   => 'onTamaraCaptured',
        'order_declined'   => 'onTamaraDeclined',
        'order_cancelled'  => 'onTamaraCancelled',
        'order_expired'    => 'onTamaraExpired',
    ];

    /** @var callable[] */
    protected $resolvers = [];

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Add an entity resolver function: fn(string $orderReferenceId): ?TamaraOrderableInterface
     *
     * @param callable $resolver
     * @return self
     */
    public function addResolver(callable $resolver): self
    {
        $this->resolvers[] = $resolver;
        return $this;
    }

    /**
     * Process raw webhook request
     *
     * @param array $data Decoded JSON
     * @param string $rawPayload
     * @return array{success: bool, error?: string}
     */
    public function process(array $data, string $rawPayload): array
    {
        $orderReferenceId = $data['order_reference_id'] ?? null;
        $eventType        = $data['event_type'] ?? null;

        if (!$orderReferenceId || !$eventType) {
            $this->logger->warning('Tamara Webhook: Missing order_reference_id or event_type', [
                'payload' => $rawPayload,
            ]);
            return ['success' => false, 'error' => 'Missing required fields'];
        }

        $entity = $this->resolveEntity($orderReferenceId);

        if (!$entity) {
            $this->logger->warning("Tamara Webhook: No order found for reference [{$orderReferenceId}]");
            return ['success' => false, 'error' => 'Order entity not found'];
        }

        // Call event method on model
        $this->dispatch($entity, $eventType, $data);

        // Store raw payload if needed
        $entity->onTamaraRawPayload($rawPayload);

        $this->logger->info("Tamara Webhook processed: [{$eventType}] for [{$orderReferenceId}]");

        return ['success' => true];
    }

    protected function resolveEntity(string $referenceId): ?TamaraOrderableInterface
    {
        foreach ($this->resolvers as $resolver) {
            $result = $resolver($referenceId);
            if ($result instanceof TamaraOrderableInterface) {
                return $result;
            }
        }
        return null;
    }

    protected function dispatch(TamaraOrderableInterface $entity, string $eventType, array $data): void
    {
        if (!isset(self::EVENT_MAP[$eventType])) {
            $this->logger->warning("Tamara Webhook: Unknown event [{$eventType}]");
            return;
        }

        $method = self::EVENT_MAP[$eventType];
        $entity->$method($data);
    }
}

