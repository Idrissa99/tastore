@extends('layouts.app')

@section('title', $merchant->business_name)

@section('content')
    <h1>{{ $merchant->business_name }}</h1>

    <p>{{ $merchant->description }}</p>
    <p>{{ $merchant->city }}</p>
    <p>Note moyenne : {{ number_format($merchant->rating, 1) }} / 5 ({{ $merchant->reviews->count() }} avis)</p>

    <h2>Produits</h2>
    @foreach ($products as $product)
        <p><a href="{{ route('products.show', $product) }}">{{ $product->name }}</a> — {{ number_format($product->price, 0, ',', ' ') }} F</p>
    @endforeach

    <h2>Avis</h2>
    @forelse ($merchant->reviews as $review)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <p>{{ $review->rating }}/5 — {{ $review->user->name }}</p>
            @if ($review->comment)
                <p>{{ $review->comment }}</p>
            @endif
        </article>
    @empty
        <p>Aucun avis pour le moment.</p>
    @endforelse
@endsection
