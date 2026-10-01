<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRIORITÉ 2 — Finance et cycle métier des tontines.
 *
 * Objectifs :
 *  - séparer l'état de participation du membre de l'état de livraison ;
 *  - pouvoir livrer même après le changement de round (le bénéficiaire garde
 *    le round pour lequel il devait être livré) ;
 *  - garantir un seul bénéficiaire actif par tontine (donc par round) ;
 *  - rendre explicite le mode de remboursement (manuel : aucun opérateur
 *    Mobile Money réel n'est connecté).
 *
 * Aucune donnée n'est supprimée. Les seules écritures sont des transitions
 * d'état cohérentes avec les règles métier (tontines argent sans produit
 * physique, et pré-remplissage du round de bénéficiaire).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoDuplicateBeneficiaries();

        Schema::table('tontine_members', function (Blueprint $table) {
            $table->unsignedInteger('beneficiary_round')->nullable()->after('delivery_status');

            $table->enum('delivery_status', [
                'not_applicable',
                'awaiting_payment',
                'pending',
                'delivered',
            ])->default('not_applicable')->change();
        });

        Schema::table('refunds', function (Blueprint $table) {
            // Aucun fournisseur Mobile Money réel n'est connecté : le remboursement
            // est nécessairement une opération manuelle tracée par un admin.
            $table->enum('method', ['manual'])->default('manual')->after('amount');
            $table->string('payment_reference')->nullable()->after('method');
        });

        $this->backfillBeneficiaryRound();
        $this->resetCashTontineDeliveryStatus();
        $this->addSingleBeneficiaryIndex();
    }

    public function down(): void
    {
        $this->dropSingleBeneficiaryIndex();

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn(['method', 'payment_reference']);
        });

        Schema::table('tontine_members', function (Blueprint $table) {
            $table->dropColumn('beneficiary_round');

            $table->enum('delivery_status', ['not_applicable', 'pending', 'delivered'])
                ->default('not_applicable')->change();
        });
    }

    /**
     * Un seul membre peut porter le statut "beneficiary" pour une tontine donnée.
     * La règle métier est "un bénéficiaire par round" ; comme une tontine n'a
     * qu'un round en cours, la garantie au niveau de la tontine la couvre.
     */
    private function assertNoDuplicateBeneficiaries(): void
    {
        $duplicates = DB::table('tontine_members')
            ->select('tontine_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'beneficiary')
            ->groupBy('tontine_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Bénéficiaires multiples détectés sur les tontines : '
                .$duplicates->pluck('tontine_id')->implode(', ')
            );
        }
    }

    private function addSingleBeneficiaryIndex(): void
    {
        if (! $this->supportsPartialIndex()) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX tontine_members_single_beneficiary
             ON tontine_members (tontine_id)
             WHERE status = \'beneficiary\''
        );
    }

    private function dropSingleBeneficiaryIndex(): void
    {
        if (! $this->supportsPartialIndex()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS tontine_members_single_beneficiary');
    }

    private function supportsPartialIndex(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * Le round pour lequel un membre est bénéficiaire est le round courant de la
     * tontine. Cette donnée n'existait pas avant : on la déduit de l'état actuel
     * plutôt que de la deviner sur des tours déjà terminés.
     */
    private function backfillBeneficiaryRound(): void
    {
        $tontineRounds = DB::table('tontines')->pluck('current_round', 'id');

        DB::table('tontine_members')
            ->where('status', 'beneficiary')
            ->orderBy('id')
            ->each(function ($member) use ($tontineRounds) {
                $member->beneficiary_round = $tontineRounds[$member->tontine_id] ?? null;
                DB::table('tontine_members')->where('id', $member->id)->update([
                    'beneficiary_round' => $member->beneficiary_round,
                ]);
            });
    }

    /**
     * Une tontine "argent" (cash) n'a aucun produit physique : rien n'est livré.
     * Ces membres ne doivent donc jamais être exposés au commerçant.
     */
    private function resetCashTontineDeliveryStatus(): void
    {
        DB::table('tontine_members')
            ->whereIn('tontine_id', DB::table('tontines')->where('type', 'cash')->pluck('id'))
            ->where('delivery_status', '!=', 'delivered')
            ->update(['delivery_status' => 'not_applicable']);
    }
};
