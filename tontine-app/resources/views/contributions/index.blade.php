@extends('layouts.app')

@section('title', 'Mes cotisations')

@section('content')
    <h1>Mes cotisations</h1>

    @forelse ($contributions as $contribution)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <p>
                <strong>{{ $contribution->tontineMember->tontine->name }}</strong>
                — round {{ $contribution->round }}
                — {{ number_format($contribution->amount, 0, ',', ' ') }} F
            </p>
            <p>Statut : {{ $contribution->status === 'completed' ? '✅ Payée' : '⏳ En attente' }}</p>

            @if ($contribution->status !== 'completed')
                <form action="{{ route('contributions.pay', $contribution) }}" method="POST">
                    @csrf

                    <label>Moyen de paiement</label>
                    <select name="payment_method" required>
                        @foreach ($channels as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <label>Référence / code de dépôt (si applicable)</label>
                    <input type="text" name="reference" placeholder="ex : code reçu à l'agence">

                    <button type="submit">Confirmer le paiement</button>
                </form>
            @endif
        </article>
    @empty
        <p>Aucune cotisation pour le moment.</p>
    @endforelse
@endsection
