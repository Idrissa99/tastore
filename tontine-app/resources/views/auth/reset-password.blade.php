@extends('layouts.app')

@section('title', 'Nouveau mot de passe')

@section('content')
    <h1>Choisir un nouveau mot de passe</h1>

    <form action="{{ route('password.update') }}" method="POST">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <label>Email</label>
        <input type="email" name="email" value="{{ old('email', request('email')) }}" required>
        @error('email') <p style="color:red">{{ $message }}</p> @enderror

        <label>Nouveau mot de passe</label>
        <input type="password" name="password" required>
        @error('password') <p style="color:red">{{ $message }}</p> @enderror

        <label>Confirmer le mot de passe</label>
        <input type="password" name="password_confirmation" required>

        <button type="submit">Réinitialiser</button>
    </form>
@endsection
