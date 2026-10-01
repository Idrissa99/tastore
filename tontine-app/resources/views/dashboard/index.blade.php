@extends('layouts.app')

@section('title', 'Mon tableau de bord')

@section('content')
    <h1>Mon tableau de bord</h1>

    <p>
        <a href="{{ route('contributions.index') }}">{{ $pendingContributionsCount }} cotisation(s) en attente</a>
        —
        <a href="{{ route('notifications.index') }}">{{ $unreadNotificationsCount }} notification(s) non lue(s)</a>
    </p>

    @if ($pendingRefunds->isNotEmpty())
        <h2>Remboursements en attente</h2>
        @foreach ($pendingRefunds as $refund)
            <p>{{ number_format($refund->amount, 0, ',', ' ') }} F — tontine « {{ $refund->tontine->name }} » (annulée)</p>
        @endforeach
    @endif

    <h2>Mes tontines</h2>

    @forelse ($memberships as $membership)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <h3><a href="{{ route('tontines.show', $membership->tontine) }}">{{ $membership->tontine->name }}</a></h3>
            <p>Statut de la tontine : {{ $membership->tontine->status }}</p>
            <p>Mon statut : {{ $membership->status }}</p>
        </article>
    @empty
        <p>Tu ne participes à aucune tontine pour le moment. <a href="{{ route('tontines.index') }}">Explore les tontines ouvertes</a>.</p>
    @endforelse
@endsection
