@extends('layouts.app')

@section('title', 'Mot de passe oublié')

@section('content')
    <h1>Mot de passe oublié</h1>

    <p>Indique ton email, on t'envoie un lien pour en choisir un nouveau.</p>

    <form action="{{ route('password.email') }}" method="POST">
        @csrf

        <label>Email</label>
        <input type="email" name="email" value="{{ old('email') }}" required>
        @error('email') <p style="color:red">{{ $message }}</p> @enderror

        <button type="submit">Envoyer le lien</button>
    </form>
@endsection
