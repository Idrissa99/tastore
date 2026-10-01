@extends('layouts.app')

@section('title', 'Acheter en plusieurs fois')

@section('content')
    <h1>Acheter {{ $product->name }} en plusieurs fois</h1>

    <p>Prix du produit : {{ number_format($product->price, 0, ',', ' ') }} F</p>

    <div style="display:flex; gap:2rem; flex-wrap:wrap;">
        <form action="{{ route('installment-purchases.store', $product) }}" method="POST" style="flex:1; min-width:260px;">
            @csrf

            <label>Nombre de tranches</label>
            <input type="number" name="installments_count" id="calc_count" value="{{ old('installments_count', 3) }}" min="2" max="24" oninput="recalculate()">
            @error('installments_count') <p style="color:red">{{ $message }}</p> @enderror

            <button type="submit">Confirmer l'achat</button>
        </form>

        <aside style="flex:1; min-width:260px; border:1px solid #ccc; padding:1rem; height:fit-content;">
            <h2>Aperçu</h2>
            <p>Commission plateforme actuelle : <strong>{{ number_format($commissionRate * 100, 2) }}%</strong> par tranche</p>
            <ul>
                <li>Montant par tranche : <strong id="preview_amount">—</strong> F</li>
                <li>&nbsp;&nbsp;dont commission incluse : <strong id="preview_commission">—</strong> F</li>
                <li>Total à payer sur l'ensemble des tranches : <strong id="preview_total">—</strong> F</li>
            </ul>
        </aside>
    </div>

    <script>
        const price = {{ $product->price }};
        const rate = {{ $commissionRate }};

        function formatFCFA(n) {
            return Math.round(n).toLocaleString('fr-FR');
        }

        function recalculate() {
            const count = parseInt(document.getElementById('calc_count').value) || 0;

            if (count < 2) {
                document.getElementById('preview_amount').textContent = '—';
                document.getElementById('preview_commission').textContent = '—';
                document.getElementById('preview_total').textContent = '—';
                return;
            }

            const base = price / count;
            const amount = base * (1 + rate);
            const commission = base * rate;
            const total = amount * count;

            document.getElementById('preview_amount').textContent = formatFCFA(amount);
            document.getElementById('preview_commission').textContent = formatFCFA(commission);
            document.getElementById('preview_total').textContent = formatFCFA(total);
        }

        document.addEventListener('DOMContentLoaded', recalculate);
    </script>
@endsection
