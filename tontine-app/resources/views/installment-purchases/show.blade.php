@extends('layouts.app')

@section('title', $purchase->product->name)

@section('content')
    <h1>{{ $purchase->product->name }}</h1>

    <p>Vendu par {{ $purchase->product->merchant->business_name }}</p>
    <p>Statut : {{ $purchase->status }}</p>
    <p>Livraison : {{ $purchase->delivery_status === 'delivered' ? '✅ Livré' : ($purchase->delivery_status === 'pending' ? '⏳ En attente' : '—') }}</p>
    <p>{{ $purchase->paidInstallmentsCount() }} / {{ $purchase->installments_count }} tranches payées</p>

    <h2>Tranches</h2>
    @foreach ($purchase->installments as $installment)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <p>Tranche {{ $installment->installment_number }} — {{ number_format($installment->amount, 0, ',', ' ') }} F</p>
            <p>Statut : {{ $installment->status === 'completed' ? '✅ Payée' : '⏳ En attente' }}</p>

            @if ($installment->status !== 'completed')
                <form action="{{ route('installments.pay', $installment) }}" method="POST">
                    @csrf
                    <label>Moyen de paiement</label>
                    <select name="payment_method" required>
                        @foreach ($channels as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <label>Référence / code de dépôt (si applicable)</label>
                    <input type="text" name="reference">
                    <button type="submit">Payer cette tranche</button>
                </form>
            @endif
        </article>
    @endforeach
@endsection
