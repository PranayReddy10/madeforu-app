<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

class DailySummary extends Mailable
{
    use Queueable;

    public function __construct(public array $s) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('store.name').' today: '.$this->s['orders_today'].' '.Str::plural('order', $this->s['orders_today']).', '.money($this->s['revenue_today']));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.daily-summary');
    }
}
