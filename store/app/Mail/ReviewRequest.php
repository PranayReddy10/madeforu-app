<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReviewRequest extends Mailable
{
    use Queueable;

    public function __construct(public Order $order, public string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'How was your '.config('store.name').' gift? 💝');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.review-request');
    }
}
