<?php

namespace App\Models;

use App\Models\Concerns\GuardsFinancialAmounts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contribution extends Model
{
    use GuardsFinancialAmounts, HasFactory;



    /**
     * Montants financiers : une contribution nulle ou negative fausserait les
     * rapports et les remboursements.
     *
     * @return array<int, string>
     */
    protected function positiveAmountColumns(): array
    {
        return ['amount'];
    }

    /**
     * @return array<string, string>
     */
    protected function commissionColumns(): array
    {
        return ['commission_amount' => 'amount'];
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tontine_member_id',
        'round',
        'amount',
        'commission_amount',
        'commission_rate',
        'currency',
        'payment_method',
        'status',
        'transaction_reference',
        'transfer_code',
        'payment_failure_reason',
        'verification_status',
        'submitted_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'commission_rate' => 'decimal:6',
            'submitted_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function tontineMember(): BelongsTo
    {
        return $this->belongsTo(TontineMember::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isPendingVerification(): bool
    {
        return $this->verification_status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Une cotisation annulée ne peut plus être payée, quel que soit le canal.
     */
    public function isPayable(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)
            && ! $this->isPendingVerification();
    }

    /**
     * Seul un paiement encaissé ("completed") est remboursable, et une seule fois.
     */
    public function isRefundable(): bool
    {
        return $this->isPaid() && ! $this->refunds()->exists();
    }
}
