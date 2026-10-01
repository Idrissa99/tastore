@extends('layouts.app')

@section('title', 'Tableau de bord admin')

@section('content')
    <h1>Tableau de bord administrateur</h1>

    <nav>
        <a href="{{ route('admin.merchants.index') }}">Commerçants</a> |
        <a href="{{ route('admin.tontines.index') }}">Tontines</a> |
        <a href="{{ route('admin.disputes.index') }}">Litiges</a> |
        <a href="{{ route('admin.refunds.index') }}">Remboursements</a> |
        <a href="{{ route('admin.products.index') }}">Produits</a> |
        <a href="{{ route('admin.users.index') }}">Utilisateurs</a> |
        <a href="{{ route('admin.reports.index') }}">Rapports</a> |
        <a href="{{ route('admin.commissions.index') }}">Commissions</a>
    </nav>

    <ul>
        <li>Utilisateurs : {{ $stats['total_users'] }}</li>
        <li>Commerçants : {{ $stats['total_merchants'] }} (dont {{ $stats['pending_merchants'] }} en attente de validation)</li>
        <li>Tontines ouvertes : {{ $stats['open_tontines'] }}</li>
        <li>Tontines actives : {{ $stats['active_tontines'] }}</li>
        <li>Tontines terminées : {{ $stats['completed_tontines'] }}</li>
        <li>Tontines annulées : {{ $stats['cancelled_tontines'] }}</li>
        <li>Cotisations encaissées : {{ $stats['contributions']['count'] }} —
            {{ number_format($stats['contributions']['collected'], 0, ',', ' ') }} F —
            {{ number_format($stats['contributions']['commission'], 0, ',', ' ') }} F de commission</li>
        <li>Tranches encaissées : {{ $stats['installments']['count'] }} —
            {{ number_format($stats['installments']['collected'], 0, ',', ' ') }} F —
            {{ number_format($stats['installments']['commission'], 0, ',', ' ') }} F de commission</li>
        <li><strong>Total collecté : {{ number_format($stats['total_collected'], 0, ',', ' ') }} F</strong></li>
        <li><strong>Total commissions perçues : {{ number_format($stats['total_commissions'], 0, ',', ' ') }} F</strong></li>
        <li>Remboursements en attente : {{ $stats['pending_refunds'] }} ({{ number_format($stats['pending_refunds_amount'], 0, ',', ' ') }} F)</li>
        <li>Litiges ouverts : {{ $stats['open_disputes'] }}</li>
    </ul>

    <h2>Outils d'administration</h2>

    <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
        <h3><a href="{{ route('admin.products.index') }}">Produits</a></h3>
        <p>Filtrer par statut/recherche, archiver (masque du catalogue sans casser les tontines existantes) ou supprimer (bloqué si des tontines y sont liées).</p>
    </article>

    <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
        <h3><a href="{{ route('admin.users.index') }}">Utilisateurs</a></h3>
        <p>Suspendre un compte (impossible sur soi-même ou un autre administrateur). Un compte suspendu est rejeté à la connexion, et déconnecté de force à sa prochaine requête s'il était déjà en session — donc pas besoin d'attendre qu'il se reconnecte.</p>
    </article>

    <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
        <h3><a href="{{ route('admin.reports.index') }}">Rapports</a></h3>
        <p>Filtre de dates, montants séparés par source (cotisations / tranches) puis total explicite, export CSV identifiant chaque ligne.</p>
    </article>
@endsection
