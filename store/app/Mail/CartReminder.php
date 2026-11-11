<?php

namespace App\Mail;

use App\Models\AbandonedCart;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CartReminder extends Mailable
{
    use Queueable;

    public function __construct(public AbandonedCart $cart, public array $items, public int $number, public ?string $coupon = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->number === 1
            ? 'You left something special in your cart 🎁'
            : ($this->coupon ? 'Your cart is waiting — here\'s a little something to finish it' : 'Still thinking it over? Your cart is saved'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cart-reminder');
    }
}
