<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyAccountEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $channel,
        public readonly string $code,
        public readonly string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Подтверждение электронной почты');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-account',
            with: [
                'channel' => $this->channel,
                'code' => $this->code,
                'verificationUrl' => $this->verificationUrl,
            ],
        );
    }
}
