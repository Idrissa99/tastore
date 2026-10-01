@extends('layouts.app')

@section('title', 'Supervision des tontines')

@section('content')
    <h1>Toutes les tontines</h1>

    <form method="GET">
        <select name="status" onchange="this.form.submit()">
            <option value="">Tous les statuts</option>
            @foreach (['open', 'active', 'completed', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
            @endforeach
        </select>
    </form>

    @foreach ($tontines as $tontine)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <h2>{{ $tontine->name }} <small>({{ $tontine->status }})</small></h2>
            @if ($tontine->isCashTontine())
                <p>Tontine argent — {{ number_format($tontine->total_amount, 0, ',', ' ') }} F</p>
            @else
                <p>Produit : {{ $tontine->product->name }}</p>
            @endif
            <p>Membres : {{ $tontine->members->count() }} / {{ $tontine->max_members }} — Round : {{ $tontine->current_round }}</p>

            @if (! in_array($tontine->status, ['completed', 'cancelled']))
                <form action="{{ route('admin.tontines.cancel', $tontine) }}" method="POST" style="display:inline">
                    @csrf
                    <button type="submit" onclick="return confirm('Annuler cette tontine ?')">Annuler</button>
                </form>
            @endif

            {{-- Suppression DÉFINITIVE, distincte de l'annulation. Le serveur
                 refuse (409) si une somme a été encaissée ou si un litige
                 existe : le message d'erreur est renvoyé dans la session. --}}
            <form action="{{ route('admin.tontines.destroy', $tontine) }}" method="POST" style="display:inline">
                @csrf
                @method('DELETE')
                <button type="submit" onclick="return confirm('Supprimer DÉFINITIVEMENT cette tontine et tout son historique ? Action irréversible.')">
                    Supprimer
                </button>
            </form>
        </article>
    @endforeach

    {{ $tontines->links() }}
@endsection
