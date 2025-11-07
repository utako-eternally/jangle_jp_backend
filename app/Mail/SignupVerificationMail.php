<?php

namespace App\Mail;

use App\Models\UserSignup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SignupVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $verificationUrl;
    public $expiresIn;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public UserSignup $signup
    ) {
        $this->verificationUrl = config('app.frontend_url') . '/signup/verify?token=' . $signup->verification_token;
        $this->expiresIn = $signup->token_expires_at->diffInHours(now());
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Jangle - メールアドレスの確認',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.signup-verification',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}