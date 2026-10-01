@extends('layouts.app')

@section('title', 'Livraisons')

@section('content')
    <h1>Livraisons</h1>

    <p><small>
        Une livraison ne peut être confirmée que si le round du bénéficiaire est
        intégralement payé et que le produit est encore disponible.
    </small></p>

    @forelse ($deliveries as $delivery)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <p><strong>{{ $delivery['user_name'] }}</strong>
                — {{ $delivery['tontine']['product']['name'] ?? 'Produit indisponible' }}</p>
            <p>Tontine : {{ $delivery['tontine']['name'] }}
                ({{ $delivery['tontine']['status'] }},
                round {{ $delivery['tontine']['current_round'] }})</p>
            <p>Tour de ce membre : round {{ $delivery['beneficiary_round'] ?? '—' }}</p>

            <p>Statut :
                @if ($delivery['delivery_status'] === 'delivered')
                    ✅ Livré
                @elseif ($delivery['delivery_status'] === 'awaiting_payment')
                    ⏳ Bénéficiaire désigné — round pas encore intégralement payé
                @elseif ($delivery['delivery_status'] === 'pending')
                    📦 Prêt à être livré
                @else
                    —
                @endif
            </p>

            @if ($delivery['is_eligible'])
                <form action="{{ route('merchant.orders.deliver', $delivery['id']) }}" method="POST">
                    @csrf
                    <button type="submit" onclick="return confirm('Confirmer la remise du produit ?')">
                        Confirmer la livraison
                    </button>
                </form>
            @elseif ($delivery['delivery_status'] === 'awaiting_payment')
                <p><em>Livraison impossible pour l'instant : le round doit être intégralement payé.</em></p>
            @endif
        </article>
    @empty
        <p>Aucune livraison pour le moment.</p>
    @endforelse
@endsection
