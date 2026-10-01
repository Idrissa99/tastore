<?php

namespace App\Models;

use App\Models\Concerns\GuardsFinancialAmounts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use GuardsFinancialAmounts, HasFactory;


    /**
     * Un remboursement nul ou negatif n'a aucun sens.
     *
     * @return array<int, string>
     */
    protected function positiveAmountColumns(): array
    {
        return ['amount'];
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    /**
     * Aucun fournisseur Mobile Money réel n'est connecté : un remboursement ne
     * peut être marqué « traité » que manuellement par un administrateur, après
     * restitution réelle de l'argent. On ne simule jamais un remboursement
     * opérateur.
     */
    public const METHOD_MANUAL = 'manual';

    protected $fillable = [
        'contribution_id',
        'tontine_id',
        'user_id',
        'amount',
        'method',
        'payment_reference',
        'status',
        'processed_by',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    public function tontine(): BelongsTo
    {
        return $this->belongsTo(Tontine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }
}
