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
            'email' => 'required|email|max:191',
            'phone' => ['required', 'regex:/^(\+?91)?[6-9]\d{9}$/'],
            'topic' => 'nullable|in:'.implode(',', self::TOPICS),
            'order_number' => 'nullable|string|max:40',
            'message' => 'required|string|min:5|max:3000',
            'website' => 'prohibited',   // honeypot: people never see this field, bots fill it
        ], [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address, e.g. name@gmail.com.',
            'phone.required' => 'Please enter your mobile number so we can call or WhatsApp you back.',
            'phone.regex' => 'Please enter a 10-digit Indian mobile number.',
        ]);
        unset($data['website']);

        $msg = ContactMessage::create($data + ['ip' => $request->ip()]);

        try {
            $alert = new AdminAlert('Message from '.$msg->name.' ('.self::pretty($msg->phone).')'.($msg->topic ? ' — '.$msg->topic : ''), array_values(array_filter([
                nl2br(e($msg->message)),
                $msg->order_number ? 'Order: <b>'.e($msg->order_number).'</b>' : null,
                self::phoneLine($msg->phone),
                'Email: <b>'.e($msg->email).'</b> (just reply to this email)',
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

    /** 9876543210 → "98765 43210" */
    private static function pretty(string $phone): string
    {
        $d = substr(preg_replace('/\D/', '', $phone), -10);

        return substr($d, 0, 5).' '.substr($d, 5);
    }

    /** "Mobile: 98765 43210 · Call · WhatsApp" for the shop's email. */
    private static function phoneLine(string $phone): string
    {
        $d = substr(preg_replace('/\D/', '', $phone), -10);
        $wa = \App\Services\WhatsApp::number($d);

        return 'Mobile: <b style="font-size:17px">'.self::pretty($d).'</b> · <a href="tel:+91'.$d.'">Call</a>'
            .($wa ? ' · <a href="https://wa.me/'.$wa.'">WhatsApp</a>' : '');
    }
}
