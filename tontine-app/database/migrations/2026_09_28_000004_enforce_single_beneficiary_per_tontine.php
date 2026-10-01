<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PRIORITÉ 4 §1/§4 — Garantie « un seul bénéficiaire par tontine » sur TOUS les moteurs.
 *
 * CONSTAT MESURÉ (priorité 4, MySQL 8.0.46 réel) :
 *
 *   La migration 2026_09_28_000001 recrée `tontine_members_single_beneficiary`
 *   uniquement sur SQLite. Sur MySQL et PostgreSQL elle ne fait RIEN. La
 *   garantie dépendait donc entièrement du verrou de ligne dans
 *   TontineService::designateBeneficiary(), et une écriture SQL brute — ou un
 *   futur chemin de code qui oublierait ce verrou — pouvait désigner DEUX
 *   bénéficiaires pour une même tontine. Vérifié avant correction : sur MySQL,
 *   deux membres en statut 'beneficiary' pour la même tontine étaient
 *   ACCEPTÉS par la base, alors que SQLite les refusait.
 *
 *   Même code, garanties différentes selon le moteur : c'est exactement le
 *   défaut que la priorité 4 doit supprimer.
 *
 * SOLUTION, par moteur :
 *
 *   SQLite / PostgreSQL
 *       Index unique PARTIEL : `UNIQUE (tontine_id) WHERE status = 'beneficiary'`.
 *       Nativement supporté. Sur SQLite l'index existe déjà (posé par
 *       2026_09_28_000001) : on ne fait rien, seulement on le vérifie.
 *
 *   MySQL / MariaDB
 *       MySQL n'a pas d'index partiel. On utilise le Equivalent standard :
 *       une colonne GENERATED VIRTUAL qui ne vaut `tontine_id` que pour les
 *       lignes bénéficiaires, et NULL sinon, indexée de façon unique.
 *       Or un index UNIQUE ignore les NULL : seuls les bénéficiaires
 *       participent réellement à la contrainte, et autant de non-bénéficiaires
 *       que l'on veut coexistent. La colonne est VIRTUAL (jamais stockée) :
 *       MySQL n'a donc pas à réécrire les lignes existantes.
 *
 *       Le type de la colonne générée est ALIGNÉ sur celui de `tontine_id`
 *       (lu dans information_schema) : sur une colonne BIGINT UNSIGNED,
 *       déclarer INT forcerait une conversion et tronquerait silencieusement
 *       les identifiants au-delà de 2^31.
 *
 * AUCUNE DONNÉE N'EST RÉÉCRITE. Si des bénéficiaires multiples existent déjà,
 * la migration REFUSE de s'exécuter plutôt que de choisir arbitrairement
 * lequel conserver (interdit en Priorité 4, section 28).
 *
 * CONTRAINTE DE MAINTENANCE : toute migration future qui reconstruit
 * `tontine_members` (donc un `->change()` sur une colonne de cette table, ou un
 * `dropColumn`) supprime la colonne générée. Il faut la recréer ensuite, dans
 * cet ordre. Sur SQLite, la même opération détruit l'index partiel — voir
 * 2026_09_28_000001.
 */
return new class extends Migration
{
    private const INDEX = 'tontine_members_single_beneficiary';

    private const GENERATED_COLUMN = 'beneficiary_tontine_id';

    private const BENEFICIARY = 'beneficiary';

    public function up(): void
    {
        $driver = $this->driver();

        $this->assertNoDuplicateBeneficiaries();

        match ($driver) {
            'sqlite' => $this->assertPartialIndexExistsOnSqlite(),
            'pgsql' => $this->createPartialIndex(),
            'mysql', 'mariadb' => $this->createGeneratedColumnIndex($driver),
            default => $this->warnUnsupported($driver),
        };
    }

    public function down(): void
    {
        $driver = $this->driver();

        // Sur SQLite, l'index partiel appartient à 2026_09_28_000001 :
        // le laisser en place est sans danger et évite de laisser la table
        // sans la garantie de base.
        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasColumn('tontine_members', self::GENERATED_COLUMN)) {
            Schema::table('tontine_members', function ($table) {
                $table->dropIndex(self::INDEX);
                $table->dropColumn(self::GENERATED_COLUMN);
            });

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }

    /**
     * Index unique partiel — SQLite et PostgreSQL.
     */
    private function createPartialIndex(): void
    {
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON tontine_members (tontine_id) WHERE status = \'%s\'',
            self::INDEX,
            self::BENEFICIARY
        ));
    }

    /**
     * Équivalent MySQL : colonne générée VIRTUAL + index unique.
     */
    private function createGeneratedColumnIndex(string $driver): void
    {
        $type = $this->foreignIdColumnType($driver);

        DB::statement(sprintf(
            'ALTER TABLE `tontine_members`
             ADD COLUMN `%s` %s
             GENERATED ALWAYS AS (CASE WHEN `status` = \'%s\' THEN `tontine_id` ELSE NULL END) VIRTUAL,
             ADD UNIQUE INDEX `%s` (`%s`)',
            self::GENERATED_COLUMN,
            $type,
            self::BENEFICIARY,
            self::INDEX,
            self::GENERATED_COLUMN
        ));
    }

    /**
     * Reprend le type exact de `tontine_id` pour que la colonne générée
     * n'introduise ni troncature ni conversion silencieuse.
     */
    private function foreignIdColumnType(string $driver): string
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['tontine_members', 'tontine_id']
        );

        if ($row === null) {
            throw new RuntimeException('Colonne tontine_members.tontine_id introuvable.');
        }

        // COLUMN_TYPE vaut "bigint unsigned" / "int unsigned" : c'est
        // exactement la déclaration réutilisable, signe compris.
        $type = trim((string) ($row->COLUMN_TYPE ?? $row->DATA_TYPE));

        return strtolower($type) === 'int' ? 'int' : $type;
    }

    private function assertPartialIndexExistsOnSqlite(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS aggregate FROM sqlite_master
             WHERE type = 'index' AND name = ?",
            [self::INDEX]
        );

        if ((int) ($exists->aggregate ?? 0) === 0) {
            throw new RuntimeException(
                'Index partiel '.self::INDEX.' absent sur SQLite : la garantie « un bénéficiaire '
                .'par tontine » ne serait plus assurée. Vérifiez la migration 2026_09_28_000001.'
            );
        }
    }

    /**
     * Refuse de migrer si des bénéficiaires multiples existent : choisir
     * arbitrairement lequel garder réécrirait l'historique de rotation.
     */
    private function assertNoDuplicateBeneficiaries(): void
    {
        $duplicates = DB::table('tontine_members')
            ->select('tontine_id', DB::raw('COUNT(*) as total'))
            ->where('status', self::BENEFICIARY)
            ->groupBy('tontine_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Bénéficiaires multiples sur les tontines : '
                .$duplicates->pluck('tontine_id')->implode(', ')
                .'. Résolvez ces incohérences avant de migrer — aucune donnée ne sera corrigée automatiquement.'
            );
        }
    }

    private function warnUnsupported(string $driver): void
    {
        // On ne bloque pas le déploiement sur un moteur inconnu : la garantie
        // applicative (verrou de ligne dans designateBeneficiary) reste active.
        DB::statement('SELECT 1');

        Log::warning(
            "Moteur « {$driver} » non couvert par la garantie « un bénéficiaire par tontine » : "
            .'seul le verrou de ligne applicatif s\'applique.'
        );
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }
};
