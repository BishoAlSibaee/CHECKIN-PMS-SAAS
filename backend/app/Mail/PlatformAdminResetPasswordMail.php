<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformAdminResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public function __construct(string $token, string $email)
    {
        $base = rtrim((string) config('hotel.platform_admin_frontend_url'), '/');
        $this->resetUrl = $base.'/reset-password?token='.urlencode($token).'&email='.urlencode($email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your password — Platform Admin',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.platform-admin-reset-password',
            with: ['resetUrl' => $this->resetUrl],
        );
    }
}
