<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRIORITÉ 4 §1/§6/§31 — Index d'intégrité et de performance.
 *
 * Chaque index est justifié par une requête réellement émise par le code :
 *
 * 1. `tontine_members (tontine_id, position)` UNIQUE
 *    `TontineService::generateContributionCallForRound()` et
 *    `designateBeneficiary()` ordonnent les membres par `position`. Deux
 *    membres à la même position (course entre deux inscriptions simultanées)
 *    rendraient l'ordre de passage des bénéficiaires non déterministe, ce qui
 *    casse la règle métier de la Priorité 2.
 *
 * 2. `contributions (round, status)`
 *    `Tontine::roundContributions()` / `roundIsFunded()` filtrent sur le round
 *    ET le statut : c'est la requête la plus fréquente de l'application
 *    (financement d'un round, clôture, éligibilité à la livraison).
 *
 * 3. `contributions (status, paid_at)`
 *    `FinancialReportService` somme les encaissements par statut et période.
 *
 * 4. `refunds (status)`, `tontines (status)`, `tontine_members (delivery_status)`
 *    Listes admin (remboursements en attente, tontines actives) et file de
 *    livraison du commerçant.
 *
 * Aucun index n'est ajouté « au cas où » : chacun correspond à un filtre
 * présent dans le code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoDuplicatePositions();

        Schema::table('tontine_members', function (Blueprint $table) {
            $table->unique(['tontine_id', 'position'], 'tontine_members_tontine_position_unique');
            $table->index('delivery_status', 'tontine_members_delivery_status_index');
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->index(['round', 'status'], 'contributions_round_status_index');
            $table->index(['status', 'paid_at'], 'contributions_status_paid_at_index');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->index('status', 'refunds_status_index');
        });

        Schema::table('tontines', function (Blueprint $table) {
            $table->index('status', 'tontines_status_index');
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->index(['status', 'paid_at'], 'installments_status_paid_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropIndex('installments_status_paid_at_index');
        });

        Schema::table('tontines', function (Blueprint $table) {
            $table->dropIndex('tontines_status_index');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex('refunds_status_index');
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->dropIndex('contributions_status_paid_at_index');
            $table->dropIndex('contributions_round_status_index');
        });

        Schema::table('tontine_members', function (Blueprint $table) {
            $table->dropIndex('tontine_members_delivery_status_index');
            $table->dropUnique('tontine_members_tontine_position_unique');
        });
    }

    /**
     * Refuse de migrer si des positions sont en doublon ou NULL : les corriger
     * automatiquement réécrirait l'historique de rotation (interdit en
     * Priorité 4). On échoue franchement pour que l'opérateur tranche.
     */
    private function assertNoDuplicatePositions(): void
    {
        $duplicates = DB::table('tontine_members')
            ->select('tontine_id', 'position', DB::raw('COUNT(*) as total'))
            ->whereNotNull('position')
            ->groupBy('tontine_id', 'position')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Positions de membre en doublon : %s. Résolvez avant de migrer.',
                $duplicates->map(fn ($row) => "tontine {$row->tontine_id} position {$row->position}")->implode(', ')
            ));
        }

        $nulls = DB::table('tontine_members')->whereNull('position')->count();

        if ($nulls > 0) {
            throw new RuntimeException(
                $nulls.' membre(s) sans position : la rotation des bénéficiaires n\'est pas définissable. '
                .'Affectez-leur une position avant de migrer.'
            );
        }
    }
};
