<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

/** Admin → Customers: every account, their orders, and who can open the admin panel. */
class UserController extends Controller
{
    /** Orders that count towards "spent": paid online, or cash on delivery that isn't cancelled. */
    private static function counted(Builder $q): Builder
    {
        return $q->where('status', '!=', 'cancelled')->where(fn ($w) => $w->where('payment_status', 'paid')->orWhere('payment_method', 'cod'));
    }

    public function index(Request $request)
    {
        $role = $request->query('role');
        $q = trim((string) $request->query('q'));
        $users = User::query()
            ->when($role === 'admins', fn ($b) => $b->where('is_admin', true))
            ->when($role === 'customers', fn ($b) => $b->where(fn ($w) => $w->where('is_admin', false)->orWhereNull('is_admin')))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $q ?: 'x').'%')))
            ->withCount('orders')
            ->withSum(['orders as spent' => fn ($o) => self::counted($o)], 'total')
            ->withMax('orders as last_order_at', 'created_at')
            ->orderByDesc('is_admin')->latest()
            ->paginate(30)->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'counts' => ['all' => User::count(), 'admins' => User::where('is_admin', true)->count()],
        ]);
    }

    /**
     * Everyone who has ordered (imported WordPress orders too) or has an account with a phone,
     * one row per number, for WhatsApp messages: send one by one, or download for a broadcast list.
     */
    public static function contacts(): \Illuminate\Support\Collection
    {
        $norm = function (?string $p): ?string {
            $d = preg_replace('/\D/', '', (string) $p);
            $d = strlen($d) > 10 ? substr($d, -10) : $d;

            return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
        };
        $rows = [];
        foreach (\App\Models\Order::orderBy('created_at')->get(['name', 'phone', 'city', 'status', 'total', 'payment_status', 'payment_method', 'created_at']) as $o) {
            if (! $ph = $norm($o->phone)) {
                continue;
            }
            $r = $rows[$ph] ?? ['phone' => $ph, 'name' => $o->name, 'city' => $o->city, 'orders' => 0, 'spent' => 0.0, 'last' => null];
            $r['name'] = $o->name ?: $r['name'];
            $r['city'] = $o->city ?: $r['city'];
            if ($o->status !== 'cancelled') {
                $r['orders']++;
                $r['spent'] += ($o->payment_status === 'paid' || $o->payment_method === 'cod') ? (float) $o->total : 0;
                $r['last'] = $o->created_at;
            }
            $rows[$ph] = $r;
        }
        foreach (User::whereNotNull('phone')->get(['name', 'phone', 'created_at']) as $u) {
            if (($ph = $norm($u->phone)) && ! isset($rows[$ph])) {
                $rows[$ph] = ['phone' => $ph, 'name' => $u->name, 'city' => null, 'orders' => 0, 'spent' => 0.0, 'last' => null];
            }
        }

        return collect($rows)->map(fn ($r) => (object) $r)->sortByDesc(fn ($r) => [$r->orders, $r->spent])->values();
    }

    public function whatsapp(Request $request)
    {
        $filter = $request->query('show', 'buyers');
        $all = self::contacts();
        $list = match ($filter) {
            'recent' => $all->filter(fn ($r) => $r->last && $r->last->gte(now()->subDays(90))),
            'lapsed' => $all->filter(fn ($r) => $r->orders && (! $r->last || $r->last->lt(now()->subDays(90)))),
            'all' => $all,
            default => $all->filter(fn ($r) => $r->orders > 0),
        };
        if ($request->query('download')) {
            return response()->streamDownload(function () use ($list) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Name', 'Phone', 'City', 'Orders', 'Spent', 'Last order']);
                foreach ($list as $r) {
                    fputcsv($out, [$r->name, '+91'.$r->phone, $r->city, $r->orders, round($r->spent), $r->last?->format('Y-m-d')]);
                }
                fclose($out);
            }, 'madeforu-whatsapp-'.$filter.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
        }
        $C = \App\Support\Campaign::class;
        $default = $C::active()
            ? "Hi {name}! 🪔 {$C::name()} is coming — make it special with a gift from your own photos. "
              .($C::coupon() && $C::discount() > 0 ? 'Use code '.$C::coupon().' for '.rtrim(rtrim(number_format($C::discount(), 1), '0'), '.').'% off. ' : '')
              .'Order by '.$C::deadline()?->format('j M').' for delivery in time: {link}  — Team '.config('store.name')
            : 'Hi {name}! Thank you for ordering from '.config('store.name').' 💝 New personalised gifts are up — have a look: {link}';

        return view('admin.users.whatsapp', [
            'list' => $list->values(), 'filter' => $filter,
            'counts' => ['buyers' => $all->where('orders', '>', 0)->count(), 'recent' => $all->filter(fn ($r) => $r->last && $r->last->gte(now()->subDays(90)))->count(),
                'lapsed' => $all->filter(fn ($r) => $r->orders && (! $r->last || $r->last->lt(now()->subDays(90))))->count(), 'all' => $all->count()],
            'message' => (string) (setting('whatsapp.broadcast') ?: $default),
            'link' => $C::active() ? $C::url() : url('/'),
        ]);
    }

    public function saveWhatsappMessage(Request $request)
    {
        $data = $request->validate(['message' => 'required|string|max:1000']);
        \App\Models\Setting::put(['whatsapp.broadcast' => $data['message']]);

        return back()->with('status', 'Message saved.');
    }

    public function show(User $user)
    {
        return view('admin.users.show', [
            'user' => $user,
            'orders' => $user->orders()->with('items')->get(),
            'spent' => (float) self::counted($user->orders()->getQuery())->sum('total'),
        ]);
    }

    /** Add an admin (or make an existing account an admin). */
    public function store(Request $request)
    {
        $digits = preg_replace('/\D/', '', (string) $request->input('phone'));
        $request->merge(['phone' => $digits !== '' ? substr($digits, -10) : null, 'email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160',
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'password' => 'nullable|string|min:8|max:100',
        ], ['phone.regex' => 'Please enter a 10-digit mobile number.']);

        $user = User::firstOrNew(['email' => $data['email']]);
        $new = ! $user->exists;
        $user->name = $user->name ?: $data['name'];
        $user->phone = $user->phone ?: $data['phone'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        } elseif ($new) {
            $user->password = bin2hex(random_bytes(16));   // they set their own from the emailed link
        }
        $user->forceFill(['is_admin' => true])->save();

        $msg = $new ? "{$user->email} can now sign in to the admin panel." : "{$user->name} ({$user->email}) already had an account and is now an admin.";
        if (empty($data['password'])) {
            Password::sendResetLink(['email' => $user->email]);
            $msg .= ' We emailed them a link to set their password.';
        }

        return redirect()->route('admin.users.show', $user)->with('status', $msg);
    }

    public function update(Request $request, User $user)
    {
        $digits = preg_replace('/\D/', '', (string) $request->input('phone'));
        $request->merge(['phone' => $digits !== '' ? substr($digits, -10) : null, 'email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'password' => 'nullable|string|min:8|max:100',
            'is_admin' => 'nullable|boolean',
        ], ['phone.regex' => 'Please enter a 10-digit mobile number.']);

        $admin = $request->boolean('is_admin');
        if (! $admin && $user->is_admin) {
            if ($user->is($request->user())) {
                return back()->with('error', 'You can’t remove your own admin access. Ask another admin to do it.');
            }
            if (User::where('is_admin', true)->count() <= 1) {
                return back()->with('error', 'This is the only admin — add another admin first.');
            }
        }
        $user->fill(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone']]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->forceFill(['is_admin' => $admin])->save();

        return back()->with('status', 'Saved.'.(! empty($data['password']) ? ' New password set.' : ''));
    }

    public function resetLink(User $user)
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        return back()->with($status === Password::RESET_LINK_SENT ? 'status' : 'error',
            $status === Password::RESET_LINK_SENT ? "Password link emailed to {$user->email}." : 'Could not send the email — check mail settings, or set a password here instead.');
    }
}
