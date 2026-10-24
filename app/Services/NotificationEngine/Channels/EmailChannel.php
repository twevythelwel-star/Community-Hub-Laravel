<?php

namespace App\Services\NotificationEngine\Channels;

use App\Notifications\VisitorPassEmailNotification;
use App\Services\NotificationEngine\ChannelDispatchResult;
use App\Services\NotificationEngine\Contracts\NotificationChannelInterface;
use App\Services\NotificationEngine\NotificationPayload;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

class EmailChannel implements NotificationChannelInterface
{
    public function name(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email Notification';
    }

    public function provider(): string
    {
        return 'SMTP / Transactional Mailer ('.config('mail.default', 'none').')';
    }

    public function isConfigured(): bool
    {
        $defaultMailer = config('mail.default');

        if (! filled($defaultMailer) || $defaultMailer === 'fail') {
            return false;
        }

        // If SMTP, verify host is configured
        if ($defaultMailer === 'smtp') {
            return filled(config('mail.mailers.smtp.host'));
        }

        return true;
    }

    public function send(NotificationPayload $payload): ChannelDispatchResult
    {
        if (! $this->isConfigured()) {
            return ChannelDispatchResult::unavailable(
                $this->name(),
                'Email provider is not configured. Set mail credentials in environment.'
            );
        }

        $email = $payload->recipient ?? $payload->user?->email ?? $payload->visitor?->contact;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ChannelDispatchResult::failed(
                $this->name(),
                'Email channel requires a valid email address; invalid recipient provided.'
            );
        }

        try {
            if ($payload->visitor) {
                Notification::route('mail', $email)
                    ->notify(new VisitorPassEmailNotification(
                        (string) ($payload->mediaUrl ?? $payload->visitor->qr_code_path ?? ''),
                        $payload->visitor
                    ));
            } else {
                Mail::raw($payload->body, function ($msg) use ($email, $payload) {
                    $msg->to($email)->subject($payload->title);
                });
            }

            return ChannelDispatchResult::sent($this->name(), 'email_'.bin2hex(random_bytes(8)));
        } catch (Throwable $e) {
            report($e);

            return ChannelDispatchResult::failed($this->name(), 'Email dispatch failed: '.$e->getMessage());
        }
    }
}
