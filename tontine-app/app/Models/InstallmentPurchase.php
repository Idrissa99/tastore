<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentPurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'user_id',
        'installments_count',
        'installment_amount',
        'product_price',
        'status',
        'delivery_status',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'installment_amount' => 'decimal:2',
            'product_price' => 'decimal:2',
            'delivered_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
    }

    public function paidInstallmentsCount(): int
    {
        return $this->installments()->where('status', 'completed')->count();
    }

    public function markDelivered(): void
    {
        $this->update(['delivery_status' => 'delivered', 'delivered_at' => now()]);
    }
}
