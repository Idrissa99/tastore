@extends('layouts.app')

@section('title', 'Litiges')

@section('content')
    <h1>Litiges</h1>

    <form method="GET">
        <select name="status" onchange="this.form.submit()">
            <option value="">Tous les statuts</option>
            @foreach (['open', 'resolved', 'rejected'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
            @endforeach
        </select>
    </form>

    @foreach ($disputes as $dispute)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <h2><a href="{{ route('admin.disputes.show', $dispute) }}">{{ $dispute->subject }}</a> <small>({{ $dispute->status }})</small></h2>
            <p>Tontine : {{ $dispute->tontine->name }} — signalé par {{ $dispute->raisedBy->name }}</p>
        </article>
    @endforeach

    {{ $disputes->links() }}
@endsection
