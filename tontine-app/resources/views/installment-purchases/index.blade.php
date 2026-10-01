@extends('layouts.app')

@section('title', 'Mes achats par tranches')

@section('content')
    <h1>Mes achats par tranches</h1>

    @forelse ($purchases as $purchase)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <h2><a href="{{ route('installment-purchases.show', $purchase) }}">{{ $purchase->product->name }}</a></h2>
            <p>Statut : {{ $purchase->status }} — livraison : {{ $purchase->delivery_status }}</p>
            <p>{{ $purchase->paidInstallmentsCount() }} / {{ $purchase->installments_count }} tranches payées</p>
        </article>
    @empty
        <p>Aucun achat par tranches pour le moment.</p>
    @endforelse
@endsection
