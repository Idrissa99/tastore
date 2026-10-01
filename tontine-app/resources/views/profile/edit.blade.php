@extends('layouts.app')

@section('title', 'Mon profil')

@section('content')
    <h1>Mon profil</h1>

    <form action="{{ route('profile.update') }}" method="POST">
        @csrf
        @method('PUT')

        <label>Nom complet</label>
        <input type="text" name="name" value="{{ old('name', $user->name) }}" required>
        @error('name') <p style="color:red">{{ $message }}</p> @enderror

        <label>Téléphone</label>
        <input type="text" name="phone" value="{{ old('phone', $user->phone) }}" required>
        @error('phone') <p style="color:red">{{ $message }}</p> @enderror

        <p>Email : {{ $user->email }} ({{ $user->hasVerifiedEmail() ? 'vérifié' : 'non vérifié' }})</p>

        <h2>Changer de mot de passe (optionnel)</h2>

        <label>Mot de passe actuel</label>
        <input type="password" name="current_password">
        @error('current_password') <p style="color:red">{{ $message }}</p> @enderror

        <label>Nouveau mot de passe</label>
        <input type="password" name="new_password">
        @error('new_password') <p style="color:red">{{ $message }}</p> @enderror

        <label>Confirmer le nouveau mot de passe</label>
        <input type="password" name="new_password_confirmation">

        <button type="submit">Enregistrer</button>
    </form>
@endsection
