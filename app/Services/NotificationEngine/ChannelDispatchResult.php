<?php

namespace App\Services\NotificationEngine;

/**
 * Result of attempting delivery through a notification channel.
 *
 * Guarantees that unconfigured or failing providers NEVER report false "sent" states.
 */
class ChannelDispatchResult
{
    public function __construct(
        public readonly string $channel,
        public readonly string $status, // 'sent', 'unavailable', 'failed'
        public readonly ?string $reference = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = []
    ) {}

    public static function sent(string $channel, ?string $reference = null, array $metadata = []): self
    {
        return new self($channel, 'sent', $reference, null, $metadata);
    }

    public static function delivered(string $channel, ?string $reference = null, array $metadata = []): self
    {
        return new self($channel, 'delivered', $reference, null, $metadata);
    }

    public static function unavailable(string $channel, string $reason): self
    {
        return new self($channel, 'unavailable', null, $reason);
    }

    public static function failed(string $channel, string $reason, array $metadata = []): self
    {
        return new self($channel, 'failed', null, $reason, $metadata);
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isUnavailable(): bool
    {
        return $this->status === 'unavailable';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'status' => $this->status,
            'reference' => $this->reference,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
        ];
    }
}
