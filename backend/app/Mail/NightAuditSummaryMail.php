<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\NightAuditRun;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NightAuditSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public NightAuditRun $run, public Hotel $hotel)
    {
    }

    public function envelope(): Envelope
    {
        $date = $this->run->audit_date->toDateString();
        $suffix = $this->run->exceptions_count > 0
            ? "{$this->run->exceptions_count} exception(s)"
            : 'all clear';

        return new Envelope(
            subject: "Night audit — {$this->hotel->name_en} — {$date} ({$suffix})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.night-audit-summary',
            with: [
                'run' => $this->run,
                'hotel' => $this->hotel,
            ],
        );
    }
}
