<?php

namespace App\Jobs;

use App\Models\Visitor;
use App\Notifications\VisitorPassEmailNotification;
use App\Services\Messaging\MessageNotSent;
use App\Services\Messaging\PhoneNumber;
use App\Services\QrCodePng;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends a visitor their guest pass by email, SMS and/or WhatsApp.
 *
 * Each channel is attempted on its own and a failure in one is logged, not
 * rethrown. Rethrowing made the queue retry the whole job, so a WhatsApp
 * refusal re-sent the email and the SMS each time. Logs identify the visitor
 * by id and the number only in masked form; message text is never logged.
 */
class SendVisitorPassNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Visitor $visitor,
        public array $channels = ['email']
    ) {}

    public function handle(SmsService $sms, WhatsAppService $whatsApp, QrCodePng $qrCode): void
    {
        $wants = fn (string $channel, bool $preference) => $preference && in_array($channel, $this->channels, true);

        $sendEmail = $wants('email', (bool) $this->visitor->notify_email);
        $sendSms = $wants('sms', (bool) $this->visitor->notify_sms);
        $sendWhatsApp = $wants('whatsapp', (bool) $this->visitor->notify_whatsapp);

        // SMS only carries the link, so the image is made only for the
        // channels that use it, and a failure to make it does not stop the
        // link going out.
        $qrCodePath = ($sendEmail || $sendWhatsApp) ? $this->generateQrCode($qrCode) : null;

        if ($sendEmail) {
            $this->attempt('email', fn () => $this->sendEmailNotification($qrCodePath));
        }

        if ($sendSms) {
            $this->attempt('sms', fn () => $sms->sendVisitorPass(
                (string) $this->visitor->contact,
                $this->visitor->guestPassUrl(),
                $this->visitor->name,
                (string) $this->visitor->homeowner_name,
                $this->expectedAt(),
            ));
        }

        if ($sendWhatsApp) {
            $this->attempt('whatsapp', fn () => $whatsApp->sendVisitorPass(
                (string) $this->visitor->contact,
                $this->visitor->guestPassUrl(),
                $qrCodePath ? Storage::disk('public')->url($qrCodePath) : '',
                $this->visitor->name,
                (string) $this->visitor->homeowner_name,
                $this->expectedAt(),
            ));
        }
    }

    private function attempt(string $channel, callable $send): void
    {
        try {
            $send();
        } catch (MessageNotSent $e) {
            Log::warning("Visitor pass not sent by {$channel}", [
                'visitor_id' => $this->visitor->id,
                'contact' => PhoneNumber::mask($this->visitor->contact),
                'reason' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            Log::error("Visitor pass {$channel} failed unexpectedly", [
                'visitor_id' => $this->visitor->id,
                'exception' => $e::class,
            ]);

            report($e);
        }
    }

    private function generateQrCode(QrCodePng $qrCode): ?string
    {
        $path = "qr_codes/visitor_qr_{$this->visitor->id}_{$this->visitor->share_token}.png";

        try {
            Storage::disk('public')->put($path, $qrCode->render($this->visitor->guestPassUrl(), 300, 2));
            $this->visitor->update(['qr_code_path' => $path]);

            return $path;
        } catch (Throwable $e) {
            Log::warning('Visitor pass QR image could not be generated; sending the link only', [
                'visitor_id' => $this->visitor->id,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    private function sendEmailNotification(?string $qrCodePath): void
    {
        $email = $this->visitor->contact;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        // An on-demand notifiable. The previous anonymous class had no
        // Notifiable trait, so calling notify() on it was a fatal error.
        Notification::route('mail', $email)
            ->notify(new VisitorPassEmailNotification((string) $qrCodePath, $this->visitor));
    }

    private function expectedAt(): string
    {
        return $this->visitor->expected_at->format('l, F j, Y \a\t g:i A');
    }
}
