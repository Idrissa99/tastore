<?php

namespace App\Models;

use App\Models\Concerns\GuardsFinancialAmounts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installment extends Model
{
    use GuardsFinancialAmounts, HasFactory;



    /**
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
        'installment_purchase_id',
        'installment_number',
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
            'installment_number' => 'integer',
            'amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'commission_rate' => 'decimal:6',
            'submitted_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(InstallmentPurchase::class, 'installment_purchase_id');
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
}
