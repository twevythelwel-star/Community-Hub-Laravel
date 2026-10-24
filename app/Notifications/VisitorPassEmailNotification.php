<?php

namespace App\Notifications;

use App\Models\Visitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitorPassEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $qrCodePath,
        public Visitor $visitor
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $guestPassUrl = $this->visitor->guestPassUrl();
        $qrCodeFullPath = public_path("storage/{$this->qrCodePath}");

        // Check if QR code file exists before attaching
        // is_file, not file_exists: with no QR image the path is the storage
        // directory itself, which file_exists() accepts and attach() cannot read.
        $hasQrCode = $this->qrCodePath !== '' && is_file($qrCodeFullPath);

        $mailMessage = (new MailMessage)
            ->subject('Your Visitor Pass')
            ->greeting("Hello {$this->visitor->name},")
            ->line('You have been registered as a visitor to the community.')
            ->line('Please use the QR code below or the link to access the property.')
            ->action('View Guest Pass', $guestPassUrl)
            ->line('Expected arrival: '.$this->visitor->expected_at->format('l, F j, Y \a\t g:i A'))
            ->line('Host: '.$this->visitor->homeowner_name);

        if ($hasQrCode) {
            $mailMessage->attach($qrCodeFullPath, [
                'as' => 'visitor_pass_qr.png',
                'mime' => 'image/png',
            ]);
        }

        return $mailMessage
            ->line('Thank you for your cooperation.')
            ->salutation('Community Management');
    }
}
