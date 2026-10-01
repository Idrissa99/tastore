@extends('layouts.app')

@section('title', 'Inscription')

@section('content')
    <h1>Créer un compte</h1>

    <form action="{{ route('register') }}" method="POST">
        @csrf

        <label>Nom complet</label>
        <input type="text" name="name" value="{{ old('name') }}" required>
        @error('name') <p style="color:red">{{ $message }}</p> @enderror

        <label>Email</label>
        <input type="email" name="email" value="{{ old('email') }}" required>
        @error('email') <p style="color:red">{{ $message }}</p> @enderror

        <label>Téléphone</label>
        <input type="text" name="phone" value="{{ old('phone') }}" required>
        @error('phone') <p style="color:red">{{ $message }}</p> @enderror

        <label>Mot de passe</label>
        <input type="password" name="password" required>
        @error('password') <p style="color:red">{{ $message }}</p> @enderror

        <label>Confirmer le mot de passe</label>
        <input type="password" name="password_confirmation" required>

        <label>Je m'inscris en tant que</label>
        <select name="role" required>
            <option value="client">Client</option>
            <option value="merchant">Commerçant</option>
        </select>

        <button type="submit">S'inscrire</button>
    </form>

    <p>Déjà un compte ? <a href="{{ route('login') }}">Se connecter</a></p>
@endsection
