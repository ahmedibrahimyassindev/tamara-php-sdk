<?php

namespace TamaraPhpSdk\Security;

/**
 * State machine defining valid Tamara payment order lifecycle transitions.
 * Protects against out-of-order or delayed webhook events overwriting newer or terminal states.
 */
class TamaraOrderStateMachine
{
    const STATUS_NEW                = 'new';
    const STATUS_APPROVED           = 'approved';
    const STATUS_AUTHORISED         = 'authorised';
    const STATUS_PARTIALLY_CAPTURED = 'partially_captured';
    const STATUS_FULLY_CAPTURED     = 'fully_captured';
    const STATUS_DECLINED           = 'declined';
    const STATUS_CANCELLED          = 'cancelled';
    const STATUS_EXPIRED            = 'expired';
    const STATUS_REFUNDED           = 'refunded';
    const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    /** @var array<string, string[]> Mapping of status => allowed next statuses */
    const ALLOWED_TRANSITIONS = [
        self::STATUS_NEW => [
            self::STATUS_APPROVED,
            self::STATUS_AUTHORISED,
            self::STATUS_PARTIALLY_CAPTURED,
            self::STATUS_FULLY_CAPTURED,
            self::STATUS_DECLINED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ],
        self::STATUS_APPROVED => [
            self::STATUS_AUTHORISED,
            self::STATUS_PARTIALLY_CAPTURED,
            self::STATUS_FULLY_CAPTURED,
            self::STATUS_DECLINED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ],
        self::STATUS_AUTHORISED => [
            self::STATUS_PARTIALLY_CAPTURED,
            self::STATUS_FULLY_CAPTURED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_REFUNDED,
        ],
        self::STATUS_PARTIALLY_CAPTURED => [
            self::STATUS_FULLY_CAPTURED,
            self::STATUS_REFUNDED,
            self::STATUS_PARTIALLY_REFUNDED,
        ],
        self::STATUS_FULLY_CAPTURED => [
            self::STATUS_REFUNDED,
            self::STATUS_PARTIALLY_REFUNDED,
        ],
        // Terminal failure / finished states
        self::STATUS_DECLINED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_EXPIRED => [],
        self::STATUS_REFUNDED => [],
        self::STATUS_PARTIALLY_REFUNDED => [
            self::STATUS_REFUNDED,
        ],
    ];

    /**
     * Check if transitioning from $current to $target is permissible.
     */
    public static function canTransition(?string $current, string $target): bool
    {
        $targetNorm = self::normalizeStatus($target);

        // If entity has no status yet, allow initial transitions
        if ($current === null || trim($current) === '') {
            return in_array($targetNorm, [
                self::STATUS_NEW,
                self::STATUS_APPROVED,
                self::STATUS_AUTHORISED,
                self::STATUS_PARTIALLY_CAPTURED,
                self::STATUS_FULLY_CAPTURED,
                self::STATUS_DECLINED,
                self::STATUS_CANCELLED,
                self::STATUS_EXPIRED,
            ], true);
        }

        $currentNorm = self::normalizeStatus($current);

        // Idempotent transitions (same status) are always safe
        if ($currentNorm === $targetNorm) {
            return true;
        }

        // Terminal states cannot transition to anything
        if (self::isTerminal($currentNorm)) {
            return false;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$currentNorm] ?? [];
        return in_array($targetNorm, $allowed, true);
    }

    /**
     * Return true when an invalid transition is stale and retrying it cannot help.
     * A later lifecycle target may become valid after missing earlier events arrive.
     */
    public static function isStaleTransition(?string $current, string $target): bool
    {
        $currentNorm = $current === null || trim($current) === ''
            ? null
            : self::normalizeStatus($current);
        $targetNorm = self::normalizeStatus($target);
        if ($currentNorm !== null && self::isTerminal($currentNorm)) {
            return true;
        }

        $rank = [
            self::STATUS_NEW => 0,
            self::STATUS_APPROVED => 1,
            self::STATUS_AUTHORISED => 2,
            self::STATUS_PARTIALLY_CAPTURED => 3,
            self::STATUS_FULLY_CAPTURED => 4,
            self::STATUS_PARTIALLY_REFUNDED => 5,
            self::STATUS_REFUNDED => 6,
        ];

        if (!isset($rank[$targetNorm])) {
            // Failure outcomes and unknown states do not have a future lifecycle rank.
            return true;
        }

        $currentRank = $currentNorm === null ? -1 : ($rank[$currentNorm] ?? null);
        if ($currentRank === null) {
            return false;
        }

        return $rank[$targetNorm] <= $currentRank;
    }

    public static function isKnownStatus(?string $status): bool
    {
        if ($status === null || trim($status) === '') {
            return true;
        }

        return array_key_exists(self::normalizeStatus($status), self::ALLOWED_TRANSITIONS);
    }

    /**
     * Check whether a status represents a terminal state.
     */
    public static function isTerminal(string $status): bool
    {
        $norm = self::normalizeStatus($status);
        return in_array($norm, [
            self::STATUS_DECLINED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_REFUNDED,
        ], true);
    }

    /**
     * Normalize spelling variants (e.g. authorized -> authorised, captured -> fully_captured).
     */
    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'authorized' => self::STATUS_AUTHORISED,
            'captured', 'paid' => self::STATUS_FULLY_CAPTURED,
            'fully_refunded' => self::STATUS_REFUNDED,
            'canceled' => self::STATUS_CANCELLED,
            default => $status,
        };
    }
}

