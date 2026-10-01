@extends('layouts.app')

@section('title', 'Remboursements')

@section('content')
    <h1>Remboursements</h1>

    <p><small>
        Aucun fournisseur Mobile Money réel n'est connecté : marquer un
        remboursement « traité » déclare un règlement effectué manuellement
        hors plateforme. L'application ne déclenche aucun transfert.
    </small></p>

    <form method="GET">
        <select name="status" onchange="this.form.submit()">
            <option value="">Tous les statuts</option>
            <option value="pending" @selected(request('status') === 'pending')>En attente</option>
            <option value="processed" @selected(request('status') === 'processed')>Traités</option>
        </select>
    </form>

    @forelse ($refunds as $refund)
        <article style="border:1px solid #ccc; padding:1rem; margin:1rem 0;">
            <p>{{ $refund->user->name }} — {{ number_format($refund->amount, 0, ',', ' ') }} F — tontine « {{ $refund->tontine->name }} »</p>
            <p>Statut : {{ $refund->status === 'processed' ? 'Traité' : 'En attente' }}
                — mode : {{ $refund->method === 'manual' ? 'règlement manuel' : $refund->method }}
                @if ($refund->payment_reference) — réf. : {{ $refund->payment_reference }} @endif
            </p>

            @if ($refund->isPending())
                <form action="{{ route('admin.refunds.process', $refund) }}" method="POST">
                    @csrf
                    <label>Référence du règlement (facultatif)</label>
                    <input type="text" name="payment_reference" maxlength="100">
                    <button type="submit" onclick="return confirm('Confirmer que ce remboursement a été effectué manuellement ?')">
                        Marquer comme traité
                    </button>
                </form>
            @endif
        </article>
    @empty
        <p>Aucun remboursement.</p>
    @endforelse

    {{ $refunds->links() }}
@endsection
