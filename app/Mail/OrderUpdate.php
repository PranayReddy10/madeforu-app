<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Customer email for an order step: in_production, delivered, cancelled, refunded, returned. */
class OrderUpdate extends Mailable
{
    use Queueable;

    public const SUBJECTS = [
        'in_production' => 'We\'re making your gift — order :number',
        'delivered' => 'Delivered! Order :number 💝',
        'cancelled' => 'Order :number has been cancelled',
        'refunded' => 'Refund for order :number',
        'returned' => 'Order :number came back to us',
    ];

    public function __construct(public Order $order, public string $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: str_replace(':number', $this->order->number, self::SUBJECTS[$this->event] ?? 'Update on order :number'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order-update');
    }
}
