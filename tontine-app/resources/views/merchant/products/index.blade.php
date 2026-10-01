@extends('layouts.app')

@section('title', 'Mes produits')

@section('content')
    <h1>Mes produits</h1>

    <a href="{{ route('merchant.products.create') }}">+ Ajouter un produit</a>

    @foreach ($products as $product)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <h2>{{ $product->name }} <small>({{ $product->status }})</small></h2>
            <p>{{ number_format($product->price, 0, ',', ' ') }} F — Stock : {{ $product->stock }}</p>

            <form action="{{ route('merchant.products.stock', $product) }}" method="POST" style="display:inline">
                @csrf @method('PATCH')
                <input type="number" name="stock" value="{{ $product->stock }}" min="0" style="width:80px">
                <button type="submit">Mettre à jour le stock</button>
            </form>

            <a href="{{ route('merchant.products.edit', $product) }}">Modifier</a>

            <form action="{{ route('merchant.products.destroy', $product) }}" method="POST" style="display:inline">
                @csrf @method('DELETE')
                <button type="submit" onclick="return confirm('Supprimer ce produit ?')">Supprimer</button>
            </form>
        </article>
    @endforeach
@endsection
