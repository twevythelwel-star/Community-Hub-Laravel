<?php

namespace App\Services\NotificationEngine\Contracts;

use App\Services\NotificationEngine\ChannelDispatchResult;
use App\Services\NotificationEngine\NotificationPayload;

interface NotificationChannelInterface
{
    /** Unique lowercase channel key: email, sms, whatsapp, push, in_app */
    public function name(): string;

    /** Display name of the channel */
    public function label(): string;

    /** External service provider name or technology */
    public function provider(): string;

    /** Whether the provider is configured and available to deliver */
    public function isConfigured(): bool;

    /** Dispatch the notification payload through this channel */
    public function send(NotificationPayload $payload): ChannelDispatchResult;
}
