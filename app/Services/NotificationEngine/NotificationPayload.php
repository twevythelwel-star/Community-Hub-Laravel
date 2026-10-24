<?php

namespace App\Services\NotificationEngine;

use App\Models\User;
use App\Models\Visitor;

/**
 * Immutable payload passed to notification channels.
 */
class NotificationPayload
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $recipient = null, // Email, phone, token
        public readonly ?User $user = null,
        public readonly ?Visitor $visitor = null,
        public readonly ?string $actionUrl = null,
        public readonly ?string $mediaUrl = null,
        public readonly ?string $templateId = null,
        public readonly array $templateVariables = [],
        public readonly ?array $targetRoles = null,
        public readonly array $metadata = []
    ) {}

    public function contentDigest(): string
    {
        return hash('sha256', $this->title.'|'.$this->body.'|'.($this->actionUrl ?? ''));
    }

    public function contentLength(): int
    {
        return mb_strlen($this->title.' '.$this->body);
    }
}
