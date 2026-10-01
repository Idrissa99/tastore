<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TontineMember extends Model
{
    use HasFactory;

    /** États de participation. Indépendants de l'état de livraison. */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_BENEFICIARY = 'beneficiary';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * États de livraison.
     *
     * not_applicable  : le membre n'est pas (ou plus) bénéficiaire, ou la
     *                   tontine est de type "argent" (aucun produit physique).
     * awaiting_payment: bénéficiaire désigné, mais le round n'est pas encore
     *                   intégralement payé -> livraison interdite.
     * pending         : le round est financé, le bénéficiaire est éligible.
     * delivered       : le commerçant a confirmé la remise.
     */
    public const DELIVERY_NOT_APPLICABLE = 'not_applicable';

    public const DELIVERY_AWAITING_PAYMENT = 'awaiting_payment';

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_DELIVERED = 'delivered';

    protected $fillable = [
        'tontine_id',
        'user_id',
        'position',
        'status',
        'delivery_status',
        'beneficiary_round',
        'delivered_at',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'beneficiary_round' => 'integer',
            'joined_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function tontine(): BelongsTo
    {
        return $this->belongsTo(Tontine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function scopeAwaitingDelivery(Builder $query): Builder
    {
        return $query->where('delivery_status', self::DELIVERY_PENDING);
    }

    public function scopeWaitingForPayment(Builder $query): Builder
    {
        return $query->where('delivery_status', self::DELIVERY_AWAITING_PAYMENT);
    }

    public function totalPaid(): float
    {
        return (float) $this->contributions()->where('status', 'completed')->sum('amount');
    }

    public function isBeneficiary(): bool
    {
        return $this->status === self::STATUS_BENEFICIARY;
    }

    /**
     * Le membre a-t-il déjà effectué (ou est-il en train d'effectuer) son tour ?
     * Un tour "effectué" se poursuit même si le round suivant a déjà démarré :
     * c'est ce qui permet au commerçant de livrer après le changement de round.
     */
    public function hasBeneficiaryRound(): bool
    {
        return $this->beneficiary_round !== null;
    }

    public function isAwaitingDelivery(): bool
    {
        return $this->delivery_status === self::DELIVERY_PENDING;
    }

    public function isDelivered(): bool
    {
        return $this->delivery_status === self::DELIVERY_DELIVERED;
    }

    /**
     * Une tontine "argent" ne livre aucun produit : le bénéficiaire reçoit un
     * versement, pas un colis. Aucun payout réel n'est branché (voir
     * Tontine::payoutStatus()).
     */
    public function expectsPhysicalDelivery(): bool
    {
        return ! $this->tontine->isCashTontine();
    }

    public function markDelivered(): void
    {
        $this->update([
            'delivery_status' => self::DELIVERY_DELIVERED,
            'delivered_at' => now(),
        ]);
    }
}
