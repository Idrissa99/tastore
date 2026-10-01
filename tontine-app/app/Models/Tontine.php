<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tontine extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Tontines de type "argent" : le bénéficiaire perçoit un versement.
     * Aucun payout Mobile Money / virement n'est branché dans cette version —
     * l'application ne simule donc AUCUN transfert. Voir payoutStatus().
     */
    public const PAYOUT_NOT_IMPLEMENTED = 'not_implemented';

    protected $fillable = [
        'product_id',
        'created_by',
        'name',
        'type',
        'total_amount',
        'contribution_amount',
        'commission_rate',
        'frequency',
        'max_members',
        'status',
        'current_round',
        'start_date',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'contribution_amount' => 'decimal:2',
            'commission_rate' => 'decimal:6',
            'current_round' => 'integer',
            'start_date' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TontineMember::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasManyThrough(Contribution::class, TontineMember::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Relation absente jusqu'ici alors que `merchant_reviews.tontine_id` existe
     * ET cascade en supprimant la tontine : sans elle, le périmètre des
     * suppressions était invisible depuis le modèle. C'est notamment ce qui
     * permet de refuser de supprimer une tontine qui porte un avis, sans
     * désynchroniser `merchants.rating`.
     */
    public function merchantReviews(): HasMany
    {
        return $this->hasMany(MerchantReview::class);
    }

    public function isFull(): bool
    {
        return $this->members()->count() >= $this->max_members;
    }

    public function currentBeneficiary(): ?TontineMember
    {
        return $this->members()->where('status', TontineMember::STATUS_BENEFICIARY)->first();
    }

    /**
     * L'ordre de passage a-t-il été tiré ?
     *
     * Le tirage n'existe qu'après le passage `open` -> `active`, c'est-à-dire
     * une fois la tontine complète (voir TontineService::activateIfFull et
     * RotationDraw). Tant que la tontine est ouverte, `position` ne porte que
     * l'ordre d'ARRIVÉE — ce n'est pas l'ordre de passage, et le présenter
     * comme tel afficherait un ordre qui n'a pas encore été décidé.
     */
    public function hasRotationOrder(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_COMPLETED], true);
    }

    public function isCashTontine(): bool
    {
        return $this->type === 'cash';
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Round suivant encore attendu des membres (aucun membre retiré).
     * C'est exactement le périmètre utilisé pour générer les appels de cotisation.
     */
    public function contributingMembers()
    {
        return $this->members()->where('status', '!=', TontineMember::STATUS_WITHDRAWN);
    }

    public function roundContributions(int $round)
    {
        return Contribution::where('round', $round)
            ->whereHas('tontineMember', fn ($query) => $query->where('tontine_id', $this->id))
            ->whereHas('tontineMember', fn ($query) => $query->where('status', '!=', TontineMember::STATUS_WITHDRAWN));
    }

    /**
     * Un round est financé quand chaque membre contributeur du round a une
     * cotisation payée. C'est la SEULE condition financière qui rend le
     * bénéficiaire éligible à la livraison.
     */
    public function roundIsFunded(int $round): bool
    {
        $expected = (int) $this->contributingMembers()->count();

        if ($expected === 0) {
            return false;
        }

        $paid = (int) $this->roundContributions($round)
            ->where('status', Contribution::STATUS_COMPLETED)
            ->count();

        return $paid >= $expected;
    }

    /**
     * Détail financier d'un round, utilisé par les écrans et les tests.
     */
    public function roundSummary(int $round): array
    {
        $expectedMembers = (int) $this->contributingMembers()->count();
        $paid = (int) $this->roundContributions($round)
            ->where('status', Contribution::STATUS_COMPLETED)
            ->count();

        return [
            'round' => $round,
            'expected_members' => $expectedMembers,
            'paid_members' => $paid,
            'is_funded' => $expectedMembers > 0 && $paid >= $expectedMembers,
        ];
    }

    /**
     * Aucun système de versement n'est branché : cette méthode documente
     * explicitement l'absence de payout plutôt que de laisser croire qu'un
     * transfert a été effectué. Point d'intégration pour une future API
     * opérateur (Mobile Money / banque).
     */
    public function payoutStatus(): string
    {
        return self::PAYOUT_NOT_IMPLEMENTED;
    }

    public function isPayoutSupported(): bool
    {
        return $this->payoutStatus() !== self::PAYOUT_NOT_IMPLEMENTED;
    }
}
