<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderPlaced extends Mailable
{
    use Queueable;

    public function __construct(public Order $order, public bool $forAdmin = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->forAdmin
            ? 'New order '.$this->order->number.' - '.money($this->order->total)
            : 'Your '.config('store.name').' order '.$this->order->number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order');
    }
}
