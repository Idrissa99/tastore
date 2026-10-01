@extends('layouts.app')

@section('title', 'Tontines disponibles')

@section('content')
    <h1>Tontines ouvertes</h1>

    @forelse ($tontines as $tontine)
        <article style="border:1px solid #ccc; padding:1rem; margin-bottom:1rem;">
            <h2><a href="{{ route('tontines.show', $tontine) }}">{{ $tontine->name }}</a></h2>
            @if ($tontine->isCashTontine())
                <p>Tontine argent — {{ number_format($tontine->total_amount, 0, ',', ' ') }} F à réunir</p>
            @else
                <p>Produit : {{ $tontine->product->name }} ({{ number_format($tontine->product->price, 0, ',', ' ') }} F)</p>
            @endif
            <p>Cotisation : {{ number_format($tontine->contribution_amount, 0, ',', ' ') }} F / {{ $tontine->frequency }}</p>
            <p>Membres : {{ $tontine->members->count() }} / {{ $tontine->max_members }}</p>
        </article>
    @empty
        <p>Aucune tontine disponible pour le moment.</p>
    @endforelse

    {{ $tontines->links() }}
@endsection
