<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LowStock extends Mailable
{
    use Queueable;

    /** @param array<int, array{name: string, qty: int}> $items */
    public function __construct(public array $items) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Low stock: '.collect($this->items)->pluck('name')->implode(', '));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>These are running low after the latest order:</p><ul>'
            .collect($this->items)->map(fn ($i) => '<li>'.e($i['name']).' — <b>'.$i['qty'].' left</b></li>')->implode('')
            .'</ul><p><a href="'.e(route('admin.products.index', ['stock' => 'low'])).'">Open low-stock products</a></p>');
    }
}
