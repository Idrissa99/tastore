@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    <h1>Notifications</h1>

    <form action="{{ route('notifications.mark-all-read') }}" method="POST">
        @csrf
        <button type="submit">Tout marquer comme lu</button>
    </form>

    @forelse ($notifications as $notification)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0; {{ $notification->read_at ? 'opacity:0.6' : '' }}">
            <p>{{ $notification->data['message'] ?? 'Notification' }}</p>
            <p><small>{{ $notification->created_at->diffForHumans() }}</small></p>

            @if (! $notification->read_at)
                <form action="{{ route('notifications.mark-read', $notification->id) }}" method="POST">
                    @csrf
                    <button type="submit">Marquer comme lu</button>
                </form>
            @endif
        </article>
    @empty
        <p>Aucune notification.</p>
    @endforelse

    {{ $notifications->links() }}
@endsection
