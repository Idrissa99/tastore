<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRIORITÉ 4 §1/§4 — Restaure les contraintes d'énumération perdues sur SQLite,
 * et répare l'index « un seul bénéficiaire par tontine ».
 *
 * Deux constats mesurés :
 *
 * 1) MOTEUR INCOHÉRENT. Sur MySQL, `tontines.status`, `tontines.frequency`,
 *    `tontines.type` et `tontine_members.status` sont de vrais ENUM appliqués
 *    par le moteur. Sur SQLite, la réécriture de table implicite de Laravel
 *    lors des `change()` des migrations 2024_01_02/03/05 a perdu ces CHECK :
 *    la colonne devient un varchar sans contrainte et accepte n'importe
 *    quelle valeur. Même code, garanties différentes selon le moteur.
 *    `enum()->change()` régénère la colonne avec sa contrainte partout.
 *
 * 2) INDEX PARTIEL FRAGILE. L'index unique partiel
 *    `tontine_members_single_beneficiary` (Priorité 2) est réécrit en index
 *    unique SIMPLE par Laravel lors d'une reconstruction de table SQLite,
 *    ce qui produit `UNIQUE(tontine_id)` et fait échouer la migration sur
 *    toute tontine ayant plusieurs membres. SQLite ne sait pas ajouter de
 *    colonne générée STORED sans reconstruction, et les index partiels ne sont
 *    pas reproductibles par le schéma introspecté.
 *
 *    Conclusion : la garantie « un bénéficiaire par tontine » est portee par
 *    le verrou de ligne sur `tontines` dans TontineService::designateBeneficiary()
 *    (identique sur les trois moteurs). L'index DB n'est qu'une defense
 *    supplementaire : il est recreee ici dans le bon ordre, c'est-a-dire
 *    APRES le changement d'enumeration, sinon la reconstruction de table le
 *    ferait disparaitre.
 */
return new class extends Migration
{
    private const TONTINE_STATUS = ['open', 'active', 'completed', 'cancelled'];

    private const TONTINE_FREQUENCY = ['daily', 'weekly', 'monthly'];

    private const TONTINE_TYPE = ['product', 'cash'];

    private const MEMBER_STATUS = ['active', 'beneficiary', 'completed', 'withdrawn'];

    private const BENEFICIARY_INDEX = 'tontine_members_single_beneficiary';

    public function up(): void
    {
        $this->assertNoInvalidValues('tontines', 'status', self::TONTINE_STATUS);
        $this->assertNoInvalidValues('tontines', 'frequency', self::TONTINE_FREQUENCY);
        $this->assertNoInvalidValues('tontines', 'type', self::TONTINE_TYPE);
        $this->assertNoInvalidValues('tontine_members', 'status', self::MEMBER_STATUS);
        $this->assertNoDuplicateBeneficiaries();

        // L'index partiel est d'abord supprimé : sans cela, `change()` tente de
        // le recréer en index simple et échoue.
        $this->dropBeneficiaryIndex();

        Schema::table('tontines', function (Blueprint $table) {
            $table->enum('status', self::TONTINE_STATUS)->default('open')->change();
            $table->enum('frequency', self::TONTINE_FREQUENCY)->default('monthly')->change();
            $table->enum('type', self::TONTINE_TYPE)->default('product')->change();
        });

        Schema::table('tontine_members', function (Blueprint $table) {
            $table->enum('status', self::MEMBER_STATUS)->default('active')->change();
        });

        $this->createBeneficiaryIndex();
    }

    public function down(): void
    {
        $this->dropBeneficiaryIndex();

        Schema::table('tontine_members', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });

        Schema::table('tontines', function (Blueprint $table) {
            $table->string('status')->default('open')->change();
            $table->string('frequency')->default('monthly')->change();
            $table->string('type')->default('product')->change();
        });

        $this->createBeneficiaryIndex();
    }

    private function createBeneficiaryIndex(): void
    {
        // Index partiel : supporté par SQLite et PostgreSQL, pas par MySQL/MariaDB.
        // Sur ces derniers, la garantie est assurée par le verrou de ligne
        // (voir en-tête de la migration) — rien à faire au niveau SQL.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement(sprintf(
            "CREATE UNIQUE INDEX %s ON tontine_members (tontine_id) WHERE status = 'beneficiary'",
            self::BENEFICIARY_INDEX
        ));
    }

    private function dropBeneficiaryIndex(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::BENEFICIARY_INDEX);
    }

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
                'Bénéficiaires multiples sur les tontines : '.$duplicates->pluck('tontine_id')->implode(', ')
            );
        }
    }

    /**
     * Refuse de migrer si des données hors enum existent : les convertir
     * silencieusement modifierait l'historique métier (interdit en Priorité 4).
     */
    private function assertNoInvalidValues(string $table, string $column, array $allowed): void
    {
        $invalid = DB::table($table)
            ->whereNotIn($column, $allowed)
            ->pluck($column)
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();

        if ($invalid->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Valeurs hors enum dans %s.%s : %s. Corregez ces lignes avant de migrer.',
                $table,
                $column,
                $invalid->implode(', ')
            ));
        }
    }
};
