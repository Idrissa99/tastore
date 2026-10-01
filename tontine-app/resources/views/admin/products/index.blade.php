@extends('layouts.app')

@section('title', 'Produits (admin)')

@section('content')
    <h1>Tous les produits</h1>

    <form method="GET">
        <input type="text" name="q" placeholder="Rechercher..." value="{{ request('q') }}">
        <select name="status" onchange="this.form.submit()">
            <option value="">Tous les statuts</option>
            @foreach (['draft', 'published', 'archived'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
            @endforeach
        </select>
        <button type="submit">Filtrer</button>
    </form>

    @foreach ($products as $product)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <h2>{{ $product->name }} <small>({{ $product->status }})</small></h2>
            <p>{{ number_format($product->price, 0, ',', ' ') }} F — vendu par {{ $product->merchant->business_name }}</p>

            @if ($product->status !== 'archived')
                <form action="{{ route('admin.products.archive', $product) }}" method="POST" style="display:inline">
                    @csrf
                    <button type="submit" onclick="return confirm('Archiver ce produit ?')">Archiver</button>
                </form>
            @endif

            <form action="{{ route('admin.products.destroy', $product) }}" method="POST" style="display:inline">
                @csrf @method('DELETE')
                <button type="submit" onclick="return confirm('Supprimer définitivement ce produit ?')">Supprimer</button>
            </form>
        </article>
    @endforeach

    {{ $products->links() }}
@endsection
