@extends('layouts.app')

@section('title', 'Produits')

@section('content')
    <h1>Catalogue produits</h1>

    <form method="GET">
        <input type="text" name="q" placeholder="Rechercher un produit..." value="{{ request('q') }}">

        <select name="category" onchange="this.form.submit()">
            <option value="">Toutes les catégories</option>
            @foreach ($categories as $category)
                <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
            @endforeach
        </select>

        <button type="submit">Rechercher</button>
    </form>

    @forelse ($products as $product)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            @if ($product->primaryImageUrl())
                <img src="{{ $product->primaryImageUrl() }}" alt="{{ $product->name }}" style="width:150px; height:150px; object-fit:cover;">
            @endif
            <h2><a href="{{ route('products.show', $product) }}">{{ $product->name }}</a></h2>
            <p>{{ $product->category }} — {{ number_format($product->price, 0, ',', ' ') }} F</p>
            <p>Vendu par {{ $product->merchant->business_name }}</p>
        </article>
    @empty
        <p>Aucun produit trouvé.</p>
    @endforelse

    {{ $products->links() }}
@endsection
