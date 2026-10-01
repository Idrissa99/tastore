@extends('layouts.app')

@section('title', 'Tableau de bord commerçant')

@section('content')
    <h1>Tableau de bord — {{ $merchant->business_name }}</h1>

    <h2>Chiffre d'affaires encaissé</h2>
    <ul>
        <li>Cotisations de tontines : <strong>{{ number_format($revenue['contributions'], 0, ',', ' ') }} F</strong></li>
        <li>Tranches d'achats : <strong>{{ number_format($revenue['installments'], 0, ',', ' ') }} F</strong></li>
        <li>Total : <strong>{{ number_format($revenue['total'], 0, ',', ' ') }} F</strong></li>
    </ul>

    <nav>
        <a href="{{ route('merchant.products.index') }}">Mes produits</a> |
        <a href="{{ route('merchant.orders.index') }}">Livraisons (tontines)</a> |
        <a href="{{ route('merchant.installment-orders.index') }}">Livraisons (achats par tranches)</a>
    </nav>

    <h2>Livraisons prêtes ({{ $pendingDeliveries->count() }})</h2>
    @forelse ($pendingDeliveries as $delivery)
        <p>
            {{ $delivery['user_name'] }} — {{ $delivery['tontine']['product']['name'] ?? 'Produit indisponible' }}
            (tontine : {{ $delivery['tontine']['name'] }})
        </p>
    @empty
        <p>Aucune livraison prête. Une livraison devient possible une fois le round intégralement payé.</p>
    @endforelse

    <h2>Bénéficiaires en attente de paiement du round ({{ $waitingForPayment->count() }})</h2>
    @forelse ($waitingForPayment as $delivery)
        <p>
            {{ $delivery['user_name'] }} — {{ $delivery['tontine']['product']['name'] ?? 'Produit indisponible' }}
            (round {{ $delivery['beneficiary_round'] }})
        </p>
    @empty
        <p>Aucun bénéficiaire en attente.</p>
    @endforelse

    <h2>Mes produits</h2>
    <ul>
        @foreach ($products as $product)
            <li>{{ $product->name }} — stock : {{ $product->stock }} — {{ $product->tontines_count }} tontine(s)</li>
        @endforeach
    </ul>
@endsection
