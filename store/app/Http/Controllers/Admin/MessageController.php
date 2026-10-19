<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Services\SpamCheck;

class MessageController extends Controller
{
    public function index()
    {
        $spamView = request('box') === 'spam';
        $messages = ContactMessage::when($spamView, fn ($q) => $q->whereNotNull('spam_reason'), fn ($q) => $q->whereNull('spam_reason'))
            ->latest()->paginate(30)->withQueryString();
        // Opening the list marks what's on it as read.
        ContactMessage::whereIn('id', $messages->pluck('id'))->whereNull('read_at')->update(['read_at' => now()]);
        $spamCount = ContactMessage::whereNotNull('spam_reason')->count();

        return view('admin.messages.index', compact('messages', 'spamView', 'spamCount'));
    }

    /** Move to Spam and block the sender's email and mobile from now on. */
    public function spam(ContactMessage $message)
    {
        $message->update(['spam_reason' => 'Marked as spam']);
        SpamCheck::block($message->email, $message->phone);

        return back()->with('status', 'Moved to Spam — messages from '.collect([$message->email, $message->phone])->filter()->implode(' / ').' will go straight to Spam.');
    }

    public function notSpam(ContactMessage $message)
    {
        $message->update(['spam_reason' => null]);

        return back()->with('status', 'Moved back to the inbox.');
    }

    public function emptySpam()
    {
        $n = ContactMessage::whereNotNull('spam_reason')->delete();

        return back()->with('status', $n.' spam '.($n === 1 ? 'message' : 'messages').' deleted.');
    }

    public function destroy(ContactMessage $message)
    {
        $message->delete();

        return back()->with('status', 'Message deleted.');
    }
}
