<?php

namespace App\Services;

use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Contact-form spam filter. Bots are told "sent" but nothing reaches the shop:
 * drop() = thrown away (certain bots), spamReason() = kept in Admin → Messages → Spam, no email.
 */
class SpamCheck
{
    /** Words that only ever come from sales pitches and bots, never from gift customers. */
    private const WORDS = ['seo', 'backlink', 'backlinks', 'guest post', 'crypto', 'bitcoin', 'casino', 'betting', 'forex', 'viagra',
        'rank your website', 'first page of google', 'web design services', 'website redesign', 'marketing services', 'lead generation',
        'loan offer', 'investment opportunity', 'unsubscribe', 'dear sir/madam', 'domain authority'];

    /** Hidden timestamp for the form, so we can tell how long the visitor took. */
    public static function stamp(): string
    {
        return Crypt::encryptString((string) time());
    }

    /** Certain bots: hidden field filled, sent within 3 seconds, or the same message again. */
    public static function drop(Request $request, array $data): bool
    {
        if (filled($request->input('website'))) {
            return true;
        }
        try {
            $took = time() - (int) Crypt::decryptString((string) $request->input('_ts'));
            if ($took < 3) {
                return true;
            }
        } catch (\Throwable) {
            // Missing or forged stamp: let spamReason() file it, a real person on an old tab is possible.
        }

        return ContactMessage::where('phone', $data['phone'])->where('message', $data['message'])
            ->where('created_at', '>=', now()->subDay())->exists();
    }

    public static function spamReason(Request $request, array $data): ?string
    {
        try {
            Crypt::decryptString((string) $request->input('_ts'));
        } catch (\Throwable) {
            return 'Sent without opening the contact page';
        }
        $text = mb_strtolower($data['name'].' '.$data['message']);
        $blocked = self::blockList();
        foreach ([mb_strtolower((string) $data['email']), (string) $data['phone']] as $who) {
            if ($who !== '' && in_array($who, $blocked, true)) {
                return 'Blocked sender';
            }
        }
        foreach ($blocked as $b) {
            if ($b !== '' && ! str_contains($b, '@') && ! ctype_digit($b) && str_contains($text, $b)) {
                return 'Blocked word: '.$b;
            }
        }
        foreach (self::WORDS as $w) {
            if (preg_match('/\b'.preg_quote($w, '/').'\b/u', $text)) {
                return 'Sales/spam word: '.$w;
            }
        }
        // One link is fine (a Google Drive folder of photos); two or more is how spam looks.
        if (preg_match_all('~https?://|www\.~i', $data['message']) >= 2 || preg_match('~https?://|www\.~i', $data['name'])) {
            return 'Several links';
        }
        // Russian, Chinese and the like — not how customers here write.
        if (preg_match('/[\p{Cyrillic}\p{Han}\p{Hangul}]/u', $text)) {
            return 'Foreign script';
        }

        return null;
    }

    /** Admin → Settings → Contact form: emails, mobile numbers or words, one per line. */
    public static function blockList(): array
    {
        return collect(preg_split('/[\r\n,]+/', (string) setting('contact.block')))
            ->map(fn ($s) => mb_strtolower(trim($s)))
            ->map(fn ($s) => preg_match('/^[\d\s+\-]{10,}$/', $s) ? substr(preg_replace('/\D/', '', $s), -10) : $s)
            ->filter()->unique()->values()->all();
    }

    public static function block(?string ...$entries): void
    {
        $list = collect(preg_split('/[\r\n]+/', (string) setting('contact.block')))->map(fn ($s) => trim($s))->filter();
        foreach (array_filter($entries) as $e) {
            if (! in_array(mb_strtolower($e), self::blockList(), true)) {
                $list->push($e);
            }
        }
        \App\Models\Setting::put(['contact.block' => $list->unique()->implode("\n")]);
    }

    /** Cloudflare Turnstile (free "I'm human" check) — only when both keys are set. */
    public static function turnstileOn(): bool
    {
        return filled(setting('turnstile.site_key')) && filled(setting('turnstile.secret'));
    }

    public static function turnstilePassed(Request $request): bool
    {
        try {
            return (bool) Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => setting('turnstile.secret'), 'response' => (string) $request->input('cf-turnstile-response'), 'remoteip' => $request->ip(),
            ])->json('success');
        } catch (\Throwable $e) {
            report($e);

            return true;   // Cloudflare unreachable: don't lose real messages
        }
    }
}
