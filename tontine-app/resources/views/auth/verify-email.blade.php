@extends('layouts.app')

@section('title', 'Vérifie ton email')

@section('content')
    <h1>Vérifie ton adresse email</h1>

    <p>Nous t'avons envoyé un lien de vérification à ton adresse email lors de l'inscription. Clique dessus pour activer ton compte.</p>

    <p>Tu ne l'as pas reçu ?</p>

    <form action="{{ route('verification.send') }}" method="POST">
        @csrf
        <button type="submit">Renvoyer l'email de vérification</button>
    </form>
@endsection
