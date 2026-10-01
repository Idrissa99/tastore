@extends('layouts.app')

@section('title', 'Livraisons — achats par tranches')

@section('content')
    <h1>Livraisons — achats par tranches</h1>

    @forelse ($purchases as $purchase)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <p><strong>{{ $purchase->user->name }}</strong> — {{ $purchase->product->name }}</p>
            <p>Statut : {{ $purchase->delivery_status === 'delivered' ? '✅ Livré' : '⏳ En attente' }}</p>

            @if ($purchase->delivery_status === 'pending')
                <form action="{{ route('merchant.installment-orders.deliver', $purchase) }}" method="POST">
                    @csrf
                    <button type="submit" onclick="return confirm('Confirmer la remise du produit ?')">
                        Confirmer la livraison
                    </button>
                </form>
            @endif
        </article>
    @empty
        <p>Aucune livraison pour le moment.</p>
    @endforelse
@endsection
