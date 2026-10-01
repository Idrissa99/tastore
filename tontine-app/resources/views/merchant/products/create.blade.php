@extends('layouts.app')

@section('title', 'Ajouter un produit')

@section('content')
    <h1>Ajouter un produit</h1>

    <form action="{{ route('merchant.products.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        @include('merchant.products._form')
        <button type="submit">Créer</button>
    </form>
@endsection
