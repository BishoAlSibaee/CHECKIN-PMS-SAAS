<?php

namespace App\Mail;

use App\Models\ReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ReportReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ReportExport $export)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Hotel report ready: ' . str_replace('-', ' ', $this->export->slug),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-ready',
            with: [
                'export' => $this->export,
                'downloadUrl' => $this->export->downloadUrl(),
                'periodLabel' => $this->periodLabel(),
                'formatLabel' => $this->export->formatLabel(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (!$this->export->file_path || !Storage::disk('local')->exists($this->export->file_path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $this->export->file_path)
                ->as($this->export->slug . '.' . $this->export->fileExtension())
                ->withMime($this->export->mimeType()),
        ];
    }

    private function periodLabel(): string
    {
        if ($this->export->slug === 'room-board') {
            return $this->export->end_date?->toDateString() ?? '—';
        }

        $start = $this->export->start_date?->toDateString() ?? '—';
        $end = $this->export->end_date?->toDateString() ?? '—';

        return "{$start} → {$end}";
    }
}
