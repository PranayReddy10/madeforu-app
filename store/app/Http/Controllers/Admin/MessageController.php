<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;

class MessageController extends Controller
{
    public function index()
    {
        $messages = ContactMessage::latest()->paginate(30);
        // Opening the list marks what's on it as read.
        ContactMessage::whereIn('id', $messages->pluck('id'))->whereNull('read_at')->update(['read_at' => now()]);

        return view('admin.messages.index', compact('messages'));
    }

    public function destroy(ContactMessage $message)
    {
        $message->delete();

        return back()->with('status', 'Message deleted.');
    }
}
