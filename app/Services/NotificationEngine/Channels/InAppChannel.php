<?php

namespace App\Services\NotificationEngine\Channels;

use App\Models\Notification;
use App\Services\NotificationEngine\ChannelDispatchResult;
use App\Services\NotificationEngine\Contracts\NotificationChannelInterface;
use App\Services\NotificationEngine\NotificationPayload;
use Throwable;

class InAppChannel implements NotificationChannelInterface
{
    public function name(): string
    {
        return 'in_app';
    }

    public function label(): string
    {
        return 'In-App Notification Feed';
    }

    public function provider(): string
    {
        return 'CommunityHub Notice Board & Ledger';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(NotificationPayload $payload): ChannelDispatchResult
    {
        try {
            $author = $payload->user;
            $authorName = $author?->display_name ?? 'Community Hub Management';

            $notification = Notification::create([
                'title' => $payload->title,
                'content' => $payload->body,
                'author_id' => $author?->id,
                'author_name' => $authorName,
                'target_roles' => $payload->targetRoles,
                'published_at' => now(),
            ]);

            return ChannelDispatchResult::sent($this->name(), (string) $notification->id, [
                'notification_id' => $notification->id,
            ]);
        } catch (Throwable $e) {
            report($e);

            return ChannelDispatchResult::failed($this->name(), 'In-App persistence failed: '.$e->getMessage());
        }
    }
}
