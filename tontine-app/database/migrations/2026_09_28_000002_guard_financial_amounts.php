<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PRIORITÉ 4 §5 — Intégrité des montants financiers.
 *
 * Constat mesuré : sans contrainte, la base accepte
 *   - une cotisation de -5 000 (montant négatif),
 *   - une cotisation de 0,
 *   - une commission supérieure au montant encaissé.
 * Aucun contrôle applicatif HTTP ne l'empêche : la validation des formulaires
 * fait bien `numeric|min`, mais toute écriture via Tinker, une commande, un
 * import ou un futur fournisseur de paiement contourne cette couche.
 *
 * PORTABILITÉ — mesurée, pas supposée :
 *   MySQL 8 / MariaDB 10.2+ / PostgreSQL  >= `ALTER TABLE ... ADD CONSTRAINT`.
 *   SQLite                              ne supporte PAS `ADD CONSTRAINT`, et
 *                                       ajouter une CHECK à une table existante
 *                                       imposerait une reconstruction complète
 *                                       de la table. Or cette reconstruction
 *                                       fait précisément perdre les index
 *                                       partiels (bug constaté sur
 *                                       `tontine_members_single_beneficiary`
 *                                       dans la migration 2026_09_28_000001).
 *
 * Décision : on n'impose PAS de reconstruction de table sur SQLite au cours
 * d'une priorité de stabilité. La règle « montant strictement positif » est
 * garantie par le modèle (voir App\Models\Concerns\GuardsFinancialAmounts),
 * qui couvre toutes les écritures de l'application sur tous les moteurs. La
 * contrainte SQL est ajoutée là où le moteur le permet sans risque, et sert
 * de défense supplémentaire sur le futur moteur de production.
 */
return new class extends Migration
{
    /*
     * [table, nom de contrainte, expression SQL]
     *
     * ATTENTION — colonnes NON citées. Les guillemets doubles sont des
     * littéraux de chaîne en MySQL : une contrainte écrite `"amount" > 0`
     * devient `_utf8mb4'amount' > 0`, qui est FAUX, et rejette alors toutes
     * les insertions. C'est exactement ce que la suite MySQL a révélé ; SQLite
     * et PostgreSQL, eux, interprètent les guillemets comme des identifiants
     * et acceptaient le défaut. Les noms de colonnes étant des identifiants
     * simples, l'absence de citation est la seule forme portable.
     */
    private const CHECKS = [
        ['contributions', 'contributions_amount_positive', 'amount > 0'],
        ['contributions', 'contributions_commission_within_amount', 'commission_amount >= 0 AND commission_amount <= amount'],
        ['installments', 'installments_amount_positive', 'amount > 0'],
        ['installments', 'installments_commission_within_amount', 'commission_amount >= 0 AND commission_amount <= amount'],
        ['refunds', 'refunds_amount_positive', 'amount > 0'],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! $this->supportsAddConstraint($driver)) {
            return;
        }

        $this->assertNoInvalidAmounts();

        foreach (self::CHECKS as [$table, $name, $expression]) {
            $quoted = $this->quote($driver, $table);
            DB::statement("ALTER TABLE {$quoted} ADD CONSTRAINT {$this->quote($driver, $name)} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! $this->supportsAddConstraint($driver)) {
            return;
        }

        foreach (array_reverse(self::CHECKS) as [$table, $name]) {
            $this->dropConstraint($driver, $table, $name);
        }
    }

    private function supportsAddConstraint(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb', 'pgsql'], true);
    }

    private function dropConstraint(string $driver, string $table, string $name): void
    {
        $quotedTable = $this->quote($driver, $table);
        $quotedName = $this->quote($driver, $name);

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$quotedTable} DROP CONSTRAINT IF EXISTS {$quotedName}");

            return;
        }

        // MySQL : la suppression est idempotente seulement si la contrainte
        // existe, on vérifie donc avant.
        $exists = DB::getSchemaBuilder()->getConnection()
            ->selectOne(
                'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$table, $name]
            );

        if ((int) ($exists->aggregate ?? 0) > 0) {
            DB::statement("ALTER TABLE {$quotedTable} DROP CONSTRAINT {$quotedName}");
        }
    }

    private function quote(string $driver, string $identifier): string
    {
        return $driver === 'pgsql'
            ? '"'.str_replace('"', '""', $identifier).'"'
            : '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * Refuse de migrer si des montants historiques sont invalides : on ne
     * réécrit jamais un montant existant (interdit en Priorité 4).
     */
    private function assertNoInvalidAmounts(): void
    {
        $invalid = DB::table('contributions')->where('amount', '<=', 0)->count()
            + DB::table('installments')->where('amount', '<=', 0)->count()
            + DB::table('refunds')->where('amount', '<=', 0)->count()
            + DB::table('contributions')->whereColumn('commission_amount', '>', 'amount')->count()
            + DB::table('installments')->whereColumn('commission_amount', '>', 'amount')->count();

        if ($invalid > 0) {
            throw new RuntimeException(
                $invalid.' montant(s) financier(s) existant(s) violent les règles d\'intégrité. '
                .'Corrigez-les avant de migrer — aucune donnée ne sera réécrite automatiquement.'
            );
        }
    }
};
