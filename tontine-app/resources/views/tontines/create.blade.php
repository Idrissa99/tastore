@extends('layouts.app')

@section('title', 'Créer une tontine')

@section('content')
    <h1>Créer une nouvelle tontine</h1>

    <div style="display:flex; gap:2rem; flex-wrap:wrap;">
        <form action="{{ route('tontines.store') }}" method="POST" style="flex:1; min-width:300px;">
            @csrf

            <fieldset>
                <legend>Type de tontine</legend>
                <label>
                    <input type="radio" name="type" value="product" onchange="toggleTontineType(); recalculatePreview();"
                           {{ old('type', 'product') === 'product' ? 'checked' : '' }}>
                    Tontine produit (le bénéficiaire reçoit un produit précis)
                </label>
                @if ($canCreateCash)
                    <label>
                        <input type="radio" name="type" value="cash" onchange="toggleTontineType(); recalculatePreview();"
                               {{ old('type') === 'cash' ? 'checked' : '' }}>
                        Tontine argent (le bénéficiaire reçoit directement l'argent collecté)
                    </label>
                @endif
            </fieldset>
            @error('type') <p style="color:red">{{ $message }}</p> @enderror

            @if ($products->isEmpty())
                <p style="color:red">Tu n'as aucun produit publié. Ajoute d'abord un produit avant de créer une tontine.</p>
            @endif

            <div id="product-field">
                <label>Produit</label>
                <select name="product_id" id="calc_product" onchange="recalculatePreview()">
                    <option value="">— Choisir un produit —</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" data-price="{{ $product->price }}"
                                @selected(old('product_id', $selectedProductId ?? null) == $product->id)>
                            {{ $product->name }} — {{ number_format($product->price, 0, ',', ' ') }} F
                        </option>
                    @endforeach
                </select>
                @error('product_id') <p style="color:red">{{ $message }}</p> @enderror
            </div>

            <div id="cash-field" style="display:none">
                <label>Montant total à réunir</label>
                <input type="number" name="total_amount" id="calc_total_amount" value="{{ old('total_amount') }}" oninput="recalculatePreview()">
                @error('total_amount') <p style="color:red">{{ $message }}</p> @enderror
            </div>

            <label>Nom de la tontine</label>
            <input type="text" name="name" value="{{ old('name') }}" required>
            @error('name') <p style="color:red">{{ $message }}</p> @enderror

            <label>Fréquence</label>
            <select name="frequency" required>
                <option value="daily">Journalière</option>
                <option value="weekly">Hebdomadaire</option>
                <option value="monthly">Mensuelle</option>
            </select>

            <label>Nombre maximum de membres</label>
            <input type="number" name="max_members" id="calc_max_members" value="{{ old('max_members') }}" min="2" required oninput="recalculatePreview()">
            @error('max_members') <p style="color:red">{{ $message }}</p> @enderror

            <label>Date de démarrage (optionnel)</label>
            <input type="date" name="start_date" value="{{ old('start_date') }}">

            <button type="submit">Créer la tontine</button>
        </form>

        <aside style="flex:1; min-width:260px; border:1px solid #ccc; padding:1rem; height:fit-content;">
            <h2>Aperçu de la tontine</h2>
            <p><small>Le montant du versement n'est pas saisi à la main : il est calculé automatiquement pour que le montant visé soit couvert malgré la commission plateforme.</small></p>

            <p>Commission plateforme actuelle : <strong>{{ number_format($commissionRate * 100, 2) }}%</strong> par versement, par membre</p>

            <ul>
                <li>Montant visé (prix du produit ou montant argent) : <strong id="preview_target">—</strong> F</li>
                <li>Montant du versement (par membre, par round) : <strong id="preview_contribution">—</strong> F</li>
                <li>&nbsp;&nbsp;dont commission plateforme incluse : <strong id="preview_commission">—</strong> F</li>
                <li>Total collecté par round (tous membres) : <strong id="preview_round_total">—</strong> F</li>
            </ul>
        </aside>
    </div>

    <script>
        const commissionRate = {{ $commissionRate }};

        function toggleTontineType() {
            var selected = document.querySelector('input[name="type"]:checked').value;
            document.getElementById('product-field').style.display = selected === 'product' ? 'block' : 'none';
            document.getElementById('cash-field').style.display = selected === 'cash' ? 'block' : 'none';
        }

        function formatFCFA(n) {
            return Math.round(n).toLocaleString('fr-FR');
        }

        function recalculatePreview() {
            var type = document.querySelector('input[name="type"]:checked').value;
            var maxMembers = parseInt(document.getElementById('calc_max_members').value) || 0;
            var target = 0;

            if (type === 'product') {
                var select = document.getElementById('calc_product');
                var selectedOption = select.options[select.selectedIndex];
                target = selectedOption ? parseFloat(selectedOption.getAttribute('data-price')) || 0 : 0;
            } else {
                target = parseFloat(document.getElementById('calc_total_amount').value) || 0;
            }

            if (target <= 0 || maxMembers <= 0) {
                document.getElementById('preview_target').textContent = '—';
                document.getElementById('preview_contribution').textContent = '—';
                document.getElementById('preview_commission').textContent = '—';
                document.getElementById('preview_round_total').textContent = '—';
                return;
            }

            var baseShare = target / maxMembers;
            var contribution = baseShare * (1 + commissionRate);
            var commission = baseShare * commissionRate;
            var roundTotal = contribution * maxMembers;

            document.getElementById('preview_target').textContent = formatFCFA(target);
            document.getElementById('preview_contribution').textContent = formatFCFA(contribution);
            document.getElementById('preview_commission').textContent = formatFCFA(commission);
            document.getElementById('preview_round_total').textContent = formatFCFA(roundTotal);
        }

        document.addEventListener('DOMContentLoaded', function () {
            toggleTontineType();
            recalculatePreview();
        });
    </script>
@endsection
