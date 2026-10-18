@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <form method="post" action="{{ route('password.update') }}" class="panel auth">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <h1>Set a new password</h1>
        <label>Email<input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email"></label>
        @error('email')<p class="err">{{ $message }}</p>@enderror
        <label>New password<input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus></label>
        @error('password')<p class="err">{{ $message }}</p>@enderror
        <label>Confirm new password<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <button class="btn primary block">Save password and sign in</button>
    </form>
</div>
@endsection
