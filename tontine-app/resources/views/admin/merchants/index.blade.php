@extends('layouts.app')

@section('title', 'Commerçants')

@section('content')
    <h1>Commerçants</h1>

    @foreach ($merchants as $merchant)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <h2>{{ $merchant->business_name }} <small>({{ $merchant->status }})</small></h2>
            <p>Responsable : {{ $merchant->user->name }} — {{ $merchant->user->email }}</p>

            @if ($merchant->status === 'pending')
                <form action="{{ route('admin.merchants.approve', $merchant) }}" method="POST" style="display:inline">
                    @csrf
                    <button type="submit">Approuver</button>
                </form>
                <form action="{{ route('admin.merchants.reject', $merchant) }}" method="POST" style="display:inline">
                    @csrf
                    <button type="submit" onclick="return confirm('Rejeter ce commerçant ?')">Rejeter</button>
                </form>
            @endif
        </article>
    @endforeach

    {{ $merchants->links() }}
@endsection
