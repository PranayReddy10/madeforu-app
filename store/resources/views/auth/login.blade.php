@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <form method="post" action="{{ route('login') }}" class="panel auth">
        @csrf
        <h1>Sign in</h1>
        <label>Email or mobile number<input name="login" value="{{ old('login') }}" required autofocus autocomplete="username"></label>
        @error('login')<p class="err">{{ $message }}</p>@enderror
        <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn primary block">Sign in</button>
        <p class="center small">New here? <a href="{{ route('register') }}">Create an account</a></p>
    </form>
</div>
@endsection
