@extends('layouts.app')

@section('title', $dispute->subject)

@section('content')
    <h1>{{ $dispute->subject }}</h1>

    <p>Statut : {{ $dispute->status }}</p>
    <p>Tontine : {{ $dispute->tontine->name }}</p>
    <p>Signalé par : {{ $dispute->raisedBy->name }} le {{ $dispute->created_at->format('d/m/Y H:i') }}</p>
    <p>{{ $dispute->description }}</p>

    <h2>Membres de la tontine concernée</h2>
    <ul>
        @foreach ($dispute->tontine->members as $member)
            <li>{{ $member->user->name }} — {{ $member->status }} — livraison : {{ $member->delivery_status }}</li>
        @endforeach
    </ul>

    @if ($dispute->isOpen())
        <h2>Traiter ce litige</h2>
        <form action="{{ route('admin.disputes.resolve', $dispute) }}" method="POST">
            @csrf
            <label>Décision</label>
            <select name="status" required>
                <option value="resolved">Résolu</option>
                <option value="rejected">Rejeté</option>
            </select>

            <label>Note de résolution</label>
            <textarea name="resolution_note" required></textarea>

            <button type="submit">Valider</button>
        </form>
    @else
        <p><strong>Résolution :</strong> {{ $dispute->resolution_note }} (par {{ $dispute->resolvedBy->name }})</p>
    @endif
@endsection
