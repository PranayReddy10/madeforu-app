<?php

namespace App\Http\Controllers;

use App\Models\CustomerUpload;
use App\Models\User;
use App\Services\Seo;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin(Seo $seo)
    {
        $seo->title('Login')->noindex();
        $this->rememberNext();

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['login' => 'required|string', 'password' => 'required|string']);
        $field = str_contains($data['login'], '@') ? 'email' : 'phone';
        $value = $field === 'phone' ? substr(preg_replace('/\D/', '', $data['login']), -10) : $data['login'];
        // Keep the guest cart across the session id change on login.
        $cart = session('cart.lines');
        $oldSession = $request->session()->getId();
        if (Auth::attempt([$field => $value, 'password' => $data['password']], true)) {
            $request->session()->regenerate();
            if ($cart) {
                session(['cart.lines' => $cart]);
            }
            $this->keepPhotos($oldSession);

            return redirect()->intended(route('account'));
        }

        return back()->withInput($request->only('login'))->withErrors(['login' => 'Those details do not match an account.']);
    }

    public function showRegister(Seo $seo)
    {
        $seo->title('Create account')->noindex();
        $this->rememberNext();

        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160|unique:users,email',
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/', 'unique:users,phone'],
            'password' => 'required|string|min:8|confirmed',
        ]);
        $cart = session('cart.lines');
        $oldSession = $request->session()->getId();
        $user = User::create($data);
        Auth::login($user, true);
        $request->session()->regenerate();
        if ($cart) {
            session(['cart.lines' => $cart]);
        }
        $this->keepPhotos($oldSession);

        return redirect()->intended(route('account'));
    }

    public function showForgot(Seo $seo)
    {
        $seo->title('Forgot password')->noindex();

        return view('auth.forgot');
    }

    /** Email a reset link. Accepts the mobile number too, since many customers remember that instead. */
    public function sendReset(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:160']);
        $login = trim($data['login']);
        $email = str_contains($login, '@') ? $login
            : User::where('phone', substr(preg_replace('/\D/', '', $login), -10))->value('email');
        if ($email) {
            Password::sendResetLink(['email' => $email]);
        }

        // Same answer either way, so the form can't be used to find out who has an account.
        return back()->with('status', 'If we have an account for that, we have emailed a link to set a new password. Please check your inbox (and spam).');
    }

    public function showReset(Request $request, string $token, Seo $seo)
    {
        $seo->title('Set a new password')->noindex();

        return view('auth.reset', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
            Auth::login($user, true);
        });
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
        }
        $request->session()->regenerate();

        return redirect()->route('account')->with('status', 'Your password is set. You are signed in.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /** ?next=cart or ?next=checkout: come back there after signing in or up (the cart is kept). */
    private function rememberNext(): void
    {
        $next = ['cart' => route('cart'), 'checkout' => route('checkout')][request()->query('next')] ?? null;
        if ($next) {
            session()->put('url.intended', $next);
        }
    }

    /** Photos uploaded before signing in belong to the old session id; move them so they can still be added or removed. */
    private function keepPhotos(string $oldSession): void
    {
        CustomerUpload::where('session_id', $oldSession)->whereNull('order_item_id')->update(['session_id' => session()->getId()]);
    }
}
