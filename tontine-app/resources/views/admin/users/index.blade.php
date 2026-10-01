@extends('layouts.app')

@section('title', 'Utilisateurs (admin)')

@section('content')
    <h1>Utilisateurs</h1>

    <form method="GET">
        <input type="text" name="q" placeholder="Nom ou email..." value="{{ request('q') }}">
        <select name="role" onchange="this.form.submit()">
            <option value="">Tous les rôles</option>
            @foreach (['client', 'merchant', 'admin'] as $role)
                <option value="{{ $role }}" @selected(request('role') === $role)>{{ $role }}</option>
            @endforeach
        </select>
        <button type="submit">Filtrer</button>
    </form>

    @foreach ($users as $user)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <p>
                <strong>{{ $user->name }}</strong> ({{ $user->role }})
                @if ($user->is_blocked) — 🚫 suspendu @endif
            </p>
            <p>{{ $user->email }} — {{ $user->phone }}</p>

            @if ($user->is_blocked)
                <form action="{{ route('admin.users.unblock', $user) }}" method="POST">
                    @csrf
                    <button type="submit">Réactiver</button>
                </form>
            @elseif ($user->role !== 'admin')
                <form action="{{ route('admin.users.block', $user) }}" method="POST">
                    @csrf
                    <button type="submit" onclick="return confirm('Suspendre ce compte ?')">Suspendre</button>
                </form>
            @endif
        </article>
    @endforeach

    {{ $users->links() }}
@endsection
