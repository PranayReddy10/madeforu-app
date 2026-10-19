@extends('admin.layout')
@section('title', 'Customers & admins')
@section('content')
<nav class="ol-tabs">
    <a href="{{ route('admin.users.index', array_filter(['q' => request('q')])) }}" class="{{ request('role') ? '' : 'on' }}">Everyone <b>{{ $counts['all'] }}</b></a>
    <a href="{{ route('admin.users.index', array_filter(['role' => 'customers', 'q' => request('q')])) }}" class="{{ request('role') === 'customers' ? 'on' : '' }}">Customers <b>{{ $counts['all'] - $counts['admins'] }}</b></a>
    <a href="{{ route('admin.users.index', array_filter(['role' => 'admins', 'q' => request('q')])) }}" class="{{ request('role') === 'admins' ? 'on' : '' }}">Admins <b>{{ $counts['admins'] }}</b></a>
</nav>

<form class="ol-search card" method="get">
    @if (request('role'))<input type="hidden" name="role" value="{{ request('role') }}">@endif
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, email or phone">
    <button class="btn">Search</button>
</form>

<div class="card">
    <table class="tbl users-tbl">
        <thead><tr><th>Name</th><th>Phone</th><th>Orders</th><th>Spent</th><th>Last order</th><th>Joined</th><th>Role</th></tr></thead>
        <tbody>
        @forelse ($users as $u)
            <tr>
                <td><a href="{{ route('admin.users.show', $u) }}"><b>{{ $u->name }}</b></a><br><small class="muted">{{ $u->email }}</small></td>
                <td>{{ $u->phone ?: '—' }}</td>
                <td>{{ $u->orders_count }}</td>
                <td>{{ money($u->spent ?? 0) }}</td>
                <td>{{ $u->last_order_at ? \Illuminate\Support\Carbon::parse($u->last_order_at)->format('d M Y') : '—' }}</td>
                <td>{{ $u->created_at?->format('d M Y') }}</td>
                <td>@if ($u->is_admin)<span class="badge s-shipped">Admin</span>@else<span class="badge">Customer</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No accounts found.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $users->links('admin.pagination') }}
</div>

<form method="post" action="{{ route('admin.users.store') }}" class="card narrow" id="add-admin">
    @csrf
    <h2>Add an admin</h2>
    <p class="muted small">They sign in at <b>{{ url('/login') }}</b> with this email, then open the admin panel. If the email already has a customer account, that account becomes an admin (their orders stay).</p>
    <div class="row2">
        <label>Name <input name="name" value="{{ old('name') }}" required maxlength="120"></label>
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required maxlength="160"></label>
    </div>
    <div class="row2">
        <label>Mobile <small class="muted">optional</small> <input name="phone" value="{{ old('phone') }}" inputmode="numeric" maxlength="14"></label>
        <label>Password <small class="muted">min 8 — or leave blank to email them a link to set their own</small> <input type="password" name="password" autocomplete="new-password" minlength="8"></label>
    </div>
    <button class="btn primary">Add admin</button>
</form>
@endsection
