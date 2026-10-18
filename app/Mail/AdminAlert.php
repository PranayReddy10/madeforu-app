<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Short email to the shop: a title, a few lines (HTML allowed, already escaped by the caller) and a link. */
class AdminAlert extends Mailable
{
    use Queueable;

    /** @param array<int, string> $lines */
    public function __construct(public string $title, public array $lines = [], public ?string $url = null, public ?string $button = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-alert');
    }
}
