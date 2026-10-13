<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationStatusChanged extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $jobTitle,
        public string $companyName,
        public string $status,
        public string $applicationUrl,
        public bool $isPreview = false,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'InternBridge — Cập nhật hồ sơ ứng tuyển của bạn',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.application-status',
            with: ['statusLabel' => match ($this->status) {
                'REVIEWING' => 'Đang xem xét',
                'SELECTED' => 'Được lựa chọn',
                'REJECTED' => 'Chưa phù hợp',
                default => 'Đã cập nhật',
            }],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
