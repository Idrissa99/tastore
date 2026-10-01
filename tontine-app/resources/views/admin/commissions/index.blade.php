@extends('layouts.app')

@section('title', 'Commissions')

@section('content')
    <h1>Commissions</h1>

    <h2>Taux actuel : {{ number_format($currentRate * 100, 2) }}%</h2>

    <form action="{{ route('admin.commissions.update-rate') }}" method="POST">
        @csrf
        <label>Nouveau taux (%)</label>
        <input type="number" step="0.1" name="rate_percent" value="{{ number_format($currentRate * 100, 2) }}" min="0" max="100">
        @error('rate_percent') <p style="color:red">{{ $message }}</p> @enderror
        <button type="submit">Mettre à jour</button>
    </form>
    <p><small>
        S'applique uniquement aux prochains paiements — pas de recalcul rétroactif.
        Les montants ci-dessous utilisent le taux gelé sur chaque transaction,
        jamais le taux global actuel.
    </small></p>

    <h2>Total plateforme</h2>
    <ul>
        <li>Cotisations de tontines : {{ $totals['contributions']['count'] }} —
            {{ number_format($totals['contributions']['collected'], 0, ',', ' ') }} F encaissés —
            {{ number_format($totals['contributions']['commission'], 0, ',', ' ') }} F de commission</li>
        <li>Tranches d'achats : {{ $totals['installments']['count'] }} —
            {{ number_format($totals['installments']['collected'], 0, ',', ' ') }} F encaissés —
            {{ number_format($totals['installments']['commission'], 0, ',', ' ') }} F de commission</li>
        <li><strong>Total : {{ number_format($totals['totals']['collected'], 0, ',', ' ') }} F collectés —
            {{ number_format($totals['totals']['commission'], 0, ',', ' ') }} F de commissions perçues</strong></li>
    </ul>

    <h2>Détail par commerçant</h2>
    <table border="1" cellpadding="8">
        <thead>
            <tr>
                <th>Commerçant</th>
                <th>Cotisations payées</th>
                <th>Tranches payées</th>
                <th>Montant collecté</th>
                <th>Commission générée</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($merchants as $row)
                <tr>
                    <td><a href="{{ route('merchants.show', $row['merchant']) }}">{{ $row['merchant']->business_name }}</a></td>
                    <td>{{ $row['contributions_count'] }}</td>
                    <td>{{ $row['installments_count'] }}</td>
                    <td>{{ number_format($row['total_collected'], 0, ',', ' ') }} F</td>
                    <td>{{ number_format($row['total_commission'], 0, ',', ' ') }} F</td>
                </tr>
            @empty
                <tr><td colspan="5">Aucun encaissement sur des produits pour le moment.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Tontines argent (sans commerçant)</h2>
    <p>{{ $cashStats->contributions_count }} cotisation(s) — {{ number_format($cashStats->total_collected, 0, ',', ' ') }} F collectés — {{ number_format($cashStats->total_commission, 0, ',', ' ') }} F de commission</p>
@endsection
