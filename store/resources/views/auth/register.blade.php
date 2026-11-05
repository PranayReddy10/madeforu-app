@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <form method="post" action="{{ route('register') }}" class="panel auth">
        @csrf
        <h1>Create account</h1>
        <label>Full name<input name="name" value="{{ old('name') }}" required autocomplete="name"></label>
        @error('name')<p class="err">{{ $message }}</p>@enderror
        <label>Email<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
        @error('email')<p class="err">{{ $message }}</p>@enderror
        <label>Mobile number<input name="phone" value="{{ old('phone') }}" required inputmode="numeric" maxlength="10" autocomplete="tel-national"></label>
        @error('phone')<p class="err">{{ $message }}</p>@enderror
        <label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        @error('password')<p class="err">{{ $message }}</p>@enderror
        <label>Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <button class="btn primary block">Create account</button>
        <p class="center small">Already have one? <a href="{{ route('login') }}">Sign in</a></p>
    </form>
</div>
@endsection
