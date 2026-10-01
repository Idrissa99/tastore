@extends('layouts.app')

@section('title', 'Rapports financiers')

@section('content')
    <h1>Rapports financiers</h1>

    <form method="GET">
        <label>Du</label>
        <input type="date" name="from" value="{{ $stats['from'] }}">
        <label>Au</label>
        <input type="date" name="to" value="{{ $stats['to'] }}">
        <button type="submit">Filtrer</button>
    </form>

    <h2>Cotisations de tontines</h2>
    <ul>
        <li>Nombre : {{ $stats['contributions']['count'] }}</li>
        <li>Montant encaissé : {{ number_format($stats['contributions']['collected'], 0, ',', ' ') }} F</li>
        <li>Commissions (taux gelé par transaction) : {{ number_format($stats['contributions']['commission'], 0, ',', ' ') }} F</li>
    </ul>

    <h2>Tranches d'achats</h2>
    <ul>
        <li>Nombre : {{ $stats['installments']['count'] }}</li>
        <li>Montant encaissé : {{ number_format($stats['installments']['collected'], 0, ',', ' ') }} F</li>
        <li>Commissions (taux gelé par transaction) : {{ number_format($stats['installments']['commission'], 0, ',', ' ') }} F</li>
    </ul>

    <h2>Total plateforme (somme explicite des deux sources)</h2>
    <ul>
        <li>Encaissements : {{ $stats['totals']['count'] }}</li>
        <li>Montant encaissé : {{ number_format($stats['totals']['collected'], 0, ',', ' ') }} F</li>
        <li>Commissions : {{ number_format($stats['totals']['commission'], 0, ',', ' ') }} F</li>
    </ul>

    <h2>Remboursements créés sur la période</h2>
    <ul>
        <li>En attente : {{ $stats['refunds']['pending_count'] }} — {{ number_format($stats['refunds']['pending_amount'], 0, ',', ' ') }} F</li>
        <li>Traités : {{ $stats['refunds']['processed_count'] }} — {{ number_format($stats['refunds']['processed_amount'], 0, ',', ' ') }} F</li>
    </ul>

    <a href="{{ route('admin.reports.export', ['from' => $stats['from'], 'to' => $stats['to']]) }}">
        Exporter en CSV
    </a>
@endsection
