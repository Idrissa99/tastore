@extends('layouts.app')

@section('title', 'Connexion')

@section('content')
    <h1>Connexion</h1>

    <form action="{{ route('login') }}" method="POST">
        @csrf

        <label>Email</label>
        <input type="email" name="email" value="{{ old('email') }}" required>
        @error('email') <p style="color:red">{{ $message }}</p> @enderror

        <label>Mot de passe</label>
        <input type="password" name="password" required>
        @error('password') <p style="color:red">{{ $message }}</p> @enderror

        <label>
            <input type="checkbox" name="remember"> Se souvenir de moi
        </label>

        <button type="submit">Se connecter</button>
    </form>

    <p><a href="{{ route('password.request') }}">Mot de passe oublié ?</a></p>

    <p>Pas encore de compte ? <a href="{{ route('register') }}">S'inscrire</a></p>
@endsection
