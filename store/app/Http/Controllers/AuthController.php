<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin(Seo $seo)
    {
        $seo->title('Login')->noindex();

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['login' => 'required|string', 'password' => 'required|string']);
        $field = str_contains($data['login'], '@') ? 'email' : 'phone';
        $value = $field === 'phone' ? substr(preg_replace('/\D/', '', $data['login']), -10) : $data['login'];
        // Keep the guest cart across the session id change on login.
        $cart = session('cart.lines');
        if (Auth::attempt([$field => $value, 'password' => $data['password']], true)) {
            $request->session()->regenerate();
            if ($cart) {
                session(['cart.lines' => $cart]);
            }

            return redirect()->intended(route('account'));
        }

        return back()->withInput($request->only('login'))->withErrors(['login' => 'Those details do not match an account.']);
    }

    public function showRegister(Seo $seo)
    {
        $seo->title('Create account')->noindex();

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
        $user = User::create($data);
        Auth::login($user, true);
        $request->session()->regenerate();
        if ($cart) {
            session(['cart.lines' => $cart]);
        }

        return redirect()->intended(route('account'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
