@extends('layouts.app')

@section('title', 'Signaler un litige')

@section('content')
    <h1>Signaler un litige — {{ $tontine->name }}</h1>

    <form action="{{ route('disputes.store', $tontine) }}" method="POST">
        @csrf

        <label>Sujet</label>
        <input type="text" name="subject" value="{{ old('subject') }}" required>
        @error('subject') <p style="color:red">{{ $message }}</p> @enderror

        <label>Description</label>
        <textarea name="description" required>{{ old('description') }}</textarea>
        @error('description') <p style="color:red">{{ $message }}</p> @enderror

        <button type="submit">Envoyer le signalement</button>
    </form>
@endsection
