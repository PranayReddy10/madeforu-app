@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <form method="post" action="{{ route('password.email') }}" class="panel auth">
        @csrf
        <h1>Forgot password</h1>
        <p class="muted">Enter your email or mobile number and we'll email you a link to set a new password. Shopped with us before the new website? Use this to set your password — your past orders are already in your account.</p>
        <label>Email or mobile number<input name="login" value="{{ old('login') }}" required autofocus autocomplete="username"></label>
        @error('login')<p class="err">{{ $message }}</p>@enderror
        <button class="btn primary block">Send reset link</button>
        <p class="center small"><a href="{{ route('login') }}">Back to sign in</a></p>
    </form>
</div>
@endsection
