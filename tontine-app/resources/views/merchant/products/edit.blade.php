@extends('layouts.app')

@section('title', 'Modifier le produit')

@section('content')
    <h1>Modifier {{ $product->name }}</h1>

    @if ($product->media->isNotEmpty())
        <h2>Médias actuels</h2>
        <div style="display:flex; gap:1rem; flex-wrap:wrap;">
            @foreach ($product->media as $media)
                <div style="border:1px solid #ccc; padding:0.5rem;">
                    @if ($media->type === 'image')
                        <img src="{{ $media->url }}" alt="" style="width:120px; height:120px; object-fit:cover; display:block;">
                    @else
                        <video src="{{ $media->url }}" style="width:120px; height:120px;" controls></video>
                    @endif
                    <form action="{{ route('merchant.products.media.destroy', $media) }}" method="POST">
                        @csrf @method('DELETE')
                        <button type="submit" onclick="return confirm('Supprimer ce média ?')">Supprimer</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <form action="{{ route('merchant.products.update', $product) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('merchant.products._form')
        <button type="submit">Enregistrer</button>
    </form>
@endsection
