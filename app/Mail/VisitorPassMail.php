<?php

namespace App\Mail;

use App\Models\Visitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VisitorPassMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Visitor $visitor,
        public string $qrCodePath = ''
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Community Hub: Your Visitor Pass',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.visitor-pass',
            with: [
                'visitor' => $this->visitor,
                'guestPassUrl' => $this->visitor->guestPassUrl(),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $qrCodeFullPath = public_path("storage/{$this->qrCodePath}");

        if ($this->qrCodePath !== '' && is_file($qrCodeFullPath)) {
            return [
                Attachment::fromPath($qrCodeFullPath)
                    ->as('visitor_pass_qr.png')
                    ->withMime('image/png'),
            ];
        }

        return [];
    }
}
