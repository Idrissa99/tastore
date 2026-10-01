@extends('layouts.app')

@section('title', $product->name)

@section('content')
    <h1>{{ $product->name }}</h1>

    @if ($product->images->isNotEmpty())
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            @foreach ($product->images as $image)
                <img src="{{ $image->url }}" alt="{{ $product->name }}" style="width:200px; height:200px; object-fit:cover;">
            @endforeach
        </div>
    @endif

    @if ($product->videos->isNotEmpty())
        <video src="{{ $product->videos->first()->url }}" controls style="max-width:400px; display:block; margin-top:1rem;"></video>
    @endif

    <p>{{ $product->category }}</p>
    <p><strong>{{ number_format($product->price, 0, ',', ' ') }} F</strong></p>
    <p>{{ $product->description }}</p>
    <p>Vendu par <a href="{{ route('merchants.show', $product->merchant) }}">{{ $product->merchant->business_name }}</a> — {{ $product->merchant->city }}</p>
    <p>Stock disponible : {{ $product->stock }}</p>

    @auth
        @php
            $canCreateThisTontine = auth()->user()->isAdmin()
                || (auth()->user()->isMerchant() && auth()->user()->merchant?->id === $product->merchant_id);
        @endphp

        @if ($canCreateThisTontine)
            <a href="{{ route('tontines.create', ['product_id' => $product->id]) }}">
                Créer une tontine pour ce produit
            </a>
            <br>
        @endif

        <a href="{{ route('installment-purchases.create', $product) }}">
            Acheter ce produit en plusieurs fois (sans groupe)
        </a>
    @else
        <p><a href="{{ route('login') }}">Connecte-toi</a> pour acheter ce produit.</p>
    @endauth
@endsection
