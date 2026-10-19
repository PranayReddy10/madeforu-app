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
