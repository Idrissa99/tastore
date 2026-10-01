@extends('layouts.app')

@section('title', $tontine->name)

@section('content')
    <h1>{{ $tontine->name }}</h1>

    @if ($tontine->isCashTontine())
        <p>Tontine argent — {{ number_format($tontine->total_amount, 0, ',', ' ') }} F à réunir au total</p>
    @else
        <p>Produit : {{ $tontine->product->name }} ({{ number_format($tontine->product->price, 0, ',', ' ') }} F)</p>
    @endif
    <p>Cotisation : {{ number_format($tontine->contribution_amount, 0, ',', ' ') }} F / {{ $tontine->frequency }}</p>
    <p>Statut : {{ $tontine->status }}</p>
    <p>Membres : {{ $tontine->members->count() }} / {{ $tontine->max_members }}</p>

    <h2>Membres</h2>
    {{-- Tant que la tontine n'est pas complète, `position` ne porte que
         l'ordre d'arrivée : le tirage de l'ordre de passage n'a pas eu lieu.
         Une liste numérotée laisserait croire à un ordre de passage qui
         n'existe pas encore, donc on affiche une liste non ordonnée. --}}
    @if ($tontine->hasRotationOrder())
        <ol>
            @foreach ($tontine->members->sortBy('position') as $member)
                <li>
                    {{ $member->user->name }}
                    — {{ $member->status }}
                    @if ($member->status === 'beneficiary') 🎁 @endif
                </li>
            @endforeach
        </ol>
    @else
        <p><em>L'ordre de passage sera tiré quand la tontine sera complète.</em></p>
        <ul>
            @foreach ($tontine->members->sortBy('position') as $member)
                <li>
                    {{ $member->user->name }}
                    — {{ $member->status }}
                </li>
            @endforeach
        </ul>
    @endif

    @auth
        @if (! $tontine->members->contains('user_id', auth()->id()) && $tontine->status === 'open')
            <form action="{{ route('tontines.join', $tontine) }}" method="POST">
                @csrf
                <button type="submit">Rejoindre cette tontine</button>
            </form>
        @endif

        @if ($tontine->members->contains('user_id', auth()->id()))
            <p><a href="{{ route('disputes.create', $tontine) }}">Signaler un problème sur cette tontine</a></p>
        @endif

        @if ($tontine->status === 'completed' && ! $tontine->isCashTontine() && $tontine->members->contains('user_id', auth()->id()))
            <h2>Évaluer le commerçant</h2>
            <form action="{{ route('merchants.review', $tontine) }}" method="POST">
                @csrf
                <label>Note</label>
                <select name="rating" required>
                    <option value="5">5 — Excellent</option>
                    <option value="4">4 — Bien</option>
                    <option value="3">3 — Correct</option>
                    <option value="2">2 — Moyen</option>
                    <option value="1">1 — Mauvais</option>
                </select>
                <label>Commentaire (optionnel)</label>
                <textarea name="comment"></textarea>
                <button type="submit">Envoyer l'avis</button>
            </form>
        @endif
    @endauth
@endsection
