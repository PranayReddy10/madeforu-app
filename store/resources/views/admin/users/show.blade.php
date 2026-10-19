@extends('admin.layout')
@section('title', $user->name)
@section('actions')<a class="btn ghost" href="{{ route('admin.users.index') }}">← All customers</a>@endsection
@section('content')
<div class="cards">
    <div class="card stat"><span>Orders</span><strong>{{ $orders->count() }}</strong><small>{{ $orders->where('status', 'cancelled')->count() }} cancelled</small></div>
    <div class="card stat"><span>Spent</span><strong>{{ money($spent) }}</strong><small>paid or cash on delivery</small></div>
    <div class="card stat"><span>Customer since</span><strong>{{ $user->created_at?->format('M Y') }}</strong><small>{{ $user->created_at?->diffForHumans() }}</small></div>
    <div class="card stat"><span>Role</span><strong>{{ $user->is_admin ? 'Admin' : 'Customer' }}</strong><small>{{ $user->is_admin ? 'can open this admin panel' : 'shop account only' }}</small></div>
</div>

<div class="grid-main">
    <div class="card">
        <h2>Orders</h2>
        <table class="tbl">
            <thead><tr><th>Order</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($orders as $o)
                <tr>
                    <td><a href="{{ route('admin.orders.show', $o) }}">{{ $o->number }}</a></td>
                    <td>{{ $o->created_at->format('d M Y') }}</td>
                    <td>{{ $o->items->sum('qty') }}</td>
                    <td>{{ money($o->total) }}<br><small class="muted">{{ $o->payment_method === 'cod' ? 'COD' : ucfirst($o->payment_method) }} · {{ $o->payment_status }}</small></td>
                    <td><span class="badge s-{{ $o->status }}">{{ $o->statusLabel() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No orders on this account yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div>
        <form method="post" action="{{ route('admin.users.update', $user) }}" class="card">
            @csrf @method('PUT')
            <h2>Account</h2>
            <label>Name <input name="name" value="{{ old('name', $user->name) }}" required maxlength="120"></label>
            <label>Email <input type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="160"></label>
            <label>Mobile <input name="phone" value="{{ old('phone', $user->phone) }}" inputmode="numeric" maxlength="14"></label>
            <label>New password <small class="muted">leave blank to keep</small> <input type="password" name="password" autocomplete="new-password" minlength="8"></label>
            <input type="hidden" name="is_admin" value="0">
            <label class="check"><input type="checkbox" name="is_admin" value="1" @checked(old('is_admin', $user->is_admin)) @disabled($user->is(auth()->user()))> Admin — can open this admin panel</label>
            @if ($user->is(auth()->user()))<input type="hidden" name="is_admin" value="1"><p class="muted small">This is you — another admin has to remove your access.</p>@endif
            <button class="btn primary">Save</button>
        </form>
        <form method="post" action="{{ route('admin.users.reset-link', $user) }}" class="card">
            @csrf
            <h2>Password</h2>
            <p class="muted small">Email {{ $user->email }} a link to choose a new password.</p>
            <button class="btn ghost">Send password link</button>
        </form>
    </div>
</div>
@endsection
