<?php

namespace App\Models\Concerns;

use App\Services\Payments\PaymentException;
use Illuminate\Database\Eloquent\Builder;

/**
 * PRIORITÉ 4 §5 — Garde-fou applicatif sur les montants financiers.
 *
 * Pourquoi ici plutôt qu'en base : SQLite ne supporte pas
 * `ALTER TABLE ... ADD CONSTRAINT`, et ajouter une CHECK à une table
 * existante y imposerait une reconstruction complète qui fait perdre les
 * index partiels (constaté sur `tontine_members_single_beneficiary`).
 * Un hook de modèle s'applique en revanche à TOUTES les écritures de
 * l'application, sur tous les moteurs : formulaires, commandes Artisan,
 * Tinker, imports, et futur fournisseur de paiement.
 *
 * Sur MySQL/PostgreSQL, la migration 2026_09_28_000002 ajoute en plus la
 * contrainte SQL : c'est une défense supplémentaire, pas un remplacement.
 *
 * Limite connue et documentée : une écriture faite par du SQL brut
 * (`DB::table(...)->update(...)`) contourne ce garde-fou. Aucun code de
 * l'application n'en fait sur une table financière.
 *
 * Les colonnes sont déclarées par des MÉTHODES et non des propriétés : PHP
 * interdit de redéclarer une propriété typée differing d'un trait.
 */
trait GuardsFinancialAmounts
{
    public static function bootGuardsFinancialAmounts(): void
    {
        static::saving(function ($model): void {
            $model->assertFinancialAmountsAreValid();
        });
    }

    /**
     * Colonnes devant être strictement positives (mouvements d'argent).
     *
     * @return array<int, string>
     */
    protected function positiveAmountColumns(): array
    {
        return [];
    }

    /**
     * Colonnes de commission, bornées par leur colonne de montant.
     *
     * @return array<string, string> [commission => montant]
     */
    protected function commissionColumns(): array
    {
        return [];
    }

    /**
     * Vérifie les invariants financiers. Lève une exception métier (donc une
     * réponse API lisible), jamais une erreur SQL opaque.
     */
    public function assertFinancialAmountsAreValid(): void
    {
        foreach ($this->positiveAmountColumns() as $column) {
            $value = $this->getAttribute($column);

            if ($value === null) {
                continue;
            }

            if ((float) $value <= 0) {
                throw new PaymentException(sprintf(
                    'Le champ %s doit être strictement positif (recu : %s).',
                    $column,
                    (string) $value
                ), 422);
            }
        }

        foreach ($this->commissionColumns() as $commissionColumn => $amountColumn) {
            $commission = $this->getAttribute($commissionColumn);
            $amount = $this->getAttribute($amountColumn);

            if ($commission === null || $amount === null) {
                continue;
            }

            if ((float) $commission < 0) {
                throw new PaymentException('La commission ne peut pas etre negative.', 422);
            }

            if ((float) $commission > (float) $amount) {
                throw new PaymentException(sprintf(
                    'La commission (%s) ne peut pas depasser le montant (%s).',
                    (string) $commission,
                    (string) $amount
                ), 422);
            }
        }
    }

    /**
     * Portee d'audit : ne voir que les lignes dont le montant est positif.
     */
    public function scopeWithPositiveAmount(Builder $query, string $column = 'amount'): Builder
    {
        return $query->where($column, '>', 0);
    }
}
