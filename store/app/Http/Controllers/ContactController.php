<?php

namespace App\Http\Controllers;

use App\Mail\AdminAlert;
use App\Models\ContactMessage;
use App\Services\OrderEmails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public const TOPICS = ['Order help', 'Custom or bulk order', 'Returns & damage', 'Something else'];

    public function store(Request $request)
    {
        $phone = preg_replace('/[^\d+]/', '', (string) $request->input('phone'));
        $request->merge(['phone' => $phone ?: null]);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:191|required_without:phone',
            'phone' => ['nullable', 'regex:/^(\+?91)?[6-9]\d{9}$/', 'required_without:email'],
            'topic' => 'nullable|in:'.implode(',', self::TOPICS),
            'order_number' => 'nullable|string|max:40',
            'message' => 'required|string|min:5|max:3000',
            'website' => 'prohibited',   // honeypot: people never see this field, bots fill it
        ], [
            'email.required_without' => 'Please give an email or a phone number so we can reply.',
            'phone.required_without' => 'Please give an email or a phone number so we can reply.',
            'phone.regex' => 'Please enter a 10-digit Indian mobile number.',
        ]);
        unset($data['website']);

        $msg = ContactMessage::create($data + ['ip' => $request->ip()]);

        try {
            $alert = new AdminAlert('Message from '.$msg->name.($msg->topic ? ' — '.$msg->topic : ''), array_values(array_filter([
                nl2br(e($msg->message)),
                $msg->order_number ? 'Order: <b>'.e($msg->order_number).'</b>' : null,
                collect([$msg->email ? e($msg->email) : null, $msg->phone ? e($msg->phone) : null])->filter()->implode(' · '),
            ])), route('admin.messages.index'), 'Open messages');
            if ($msg->email) {
                $alert->replyTo($msg->email, $msg->name);
            }
            Mail::to(OrderEmails::adminAddresses())->send($alert);
        } catch (\Throwable $e) {
            report($e);   // saved in admin either way
        }

        return back()->with('contact_sent', true);
    }
}
