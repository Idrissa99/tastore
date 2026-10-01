<?php

namespace Tests\Feature\Resilience;

use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\InstallmentPurchaseService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\TontineService;
use App\Services\TransactionRunner;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\BuildsTontineCycles;
use Tests\TestCase;

/**
 * PRIORITÉ 4 §2/§4/§6 — Concurrence SQLite et portabilité du schéma.
 *
 * Ces tests verrouillent les TROIS décisions prises à la Priorité 4 après
 * mesures réelles sur ce projet :
 *
 *  1. la garantie « un seul bénéficiaire par tontine » est appliquée par la
 *     base sur TOUS les moteurs, et pas seulement sur SQLite ;
 *  2. SQLite est configuré pour ATTENDRE un verrou occupé (busy_timeout) au
 *     lieu de renvoyer « database is locked » à l'utilisateur ;
 *  3. les transactions financières sont rejouées en cas de contention, ce qui
 *     est le seul remède réel du « read-then-write » sur PHP 8.3.
 */
class ConcurrencyResilienceTest extends TestCase
{
    use BuildsTontineCycles;
    use RefreshDatabase;

    // ------------------------------------------- BÉNÉFICIAIRE UNIQUE (§1/§4)

    public function test_the_database_refuses_a_second_beneficiary_for_the_same_tontine(): void
    {
        $tontine = Tontine::factory()->create();
        $first = $this->member($tontine, 1);
        $second = $this->member($tontine, 2);

        $first->update(['status' => TontineMember::STATUS_BENEFICIARY]);

        // Écriture SQL BRUTE : elle contourne volontairement le verrou de ligne
        // applicatif, c'est donc la seule façon de vérifier que c'est bien la
        // BASE qui refuse, et pas seulement TontineService.
        $this->expectException(QueryException::class);

        DB::table('tontine_members')
            ->where('id', $second->id)
            ->update(['status' => TontineMember::STATUS_BENEFICIARY]);
    }

    public function test_several_non_beneficiary_members_coexist_in_the_same_tontine(): void
    {
        $tontine = Tontine::factory()->create();

        // La contrainte ne doit porter que sur les bénéficiaires : sinon elle
        // interdirait une tontine entière, ce qui serait le défaut inverse.
        foreach (['active', 'completed', 'withdrawn', 'active'] as $index => $status) {
            $this->member($tontine, $index + 1, $status);
        }

        $this->assertSame(4, TontineMember::where('tontine_id', $tontine->id)->count());
    }

    public function test_the_beneficiary_can_be_handed_over_to_another_member(): void
    {
        $tontine = Tontine::factory()->create();
        $outgoing = $this->member($tontine, 1);
        $incoming = $this->member($tontine, 2);

        $outgoing->update(['status' => TontineMember::STATUS_BENEFICIARY]);

        // Passation de tour : l'ancien devient « completed », le nouveau est
        // promu. C'est le cycle normal, la contrainte ne doit pas le bloquer.
        $outgoing->update(['status' => TontineMember::STATUS_COMPLETED]);
        $incoming->update(['status' => TontineMember::STATUS_BENEFICIARY]);

        $this->assertSame(1, TontineMember::where('tontine_id', $tontine->id)
            ->where('status', TontineMember::STATUS_BENEFICIARY)
            ->count());
    }

    public function test_the_single_beneficiary_constraint_is_engine_independent(): void
    {
        // Le point de la correction : sur MySQL et PostgreSQL, la garantie
        // existait déjà applicativement mais PAS en base. Ce test verrouille
        // l'intention pour qu'une future migration ne la retire pas en
        // « simplifiant » le schéma.
        $migration = file_get_contents(
            database_path('migrations/2026_09_28_000004_enforce_single_beneficiary_per_tontine.php')
        );

        $this->assertStringContainsString("'sqlite' =>", $migration);
        $this->assertStringContainsString("'pgsql' =>", $migration);
        $this->assertStringContainsString("'mysql', 'mariadb' =>", $migration);
        $this->assertStringContainsString('GENERATED ALWAYS AS', $migration);
    }

    // ------------------------------------------- CONTENTION SQLITE (§2/§6)

    public function test_sqlite_waits_for_a_busy_lock_instead_of_failing(): void
    {
        // Sans busy_timeout, la mesure donnait « database is locked » en 0,00 s.
        $this->assertSame(
            5000,
            (int) config('database.connections.sqlite.busy_timeout'),
            'SQLite doit attendre un verrou occupé : sans cela, deux requêtes '
            .'simultanées renvoient une erreur 500 alors que la base est saine.'
        );
    }

    public function test_sqlite_uses_a_journal_mode_that_allows_readers_during_writes(): void
    {
        // En mode `delete` (défaut), un simple affichage de page bloque les
        // écritures, et réciproquement.
        $this->assertSame('WAL', config('database.connections.sqlite.journal_mode'));
    }

    public function test_the_configured_sqlite_settings_are_actually_applied(): void
    {
        // Une valeur de configuration non appliquée ne protège personne : on
        // vérifie le PRAGMA réellement exécuté par la connexion.
        $pdo = DB::connection('sqlite')->getPdo();

        $this->assertSame(
            5000,
            (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn()
        );
    }

    public function test_sqlite_settings_can_be_overridden_from_the_environment(): void
    {
        // Un hébergeur avec un disque lent, ou une base montée en lecture seule,
        // doit pouvoir revenir au comportement par défaut.
        $this->assertArrayHasKey('busy_timeout', config('database.connections.sqlite'));
        $this->assertArrayHasKey('journal_mode', config('database.connections.sqlite'));
        $this->assertArrayHasKey('synchronous', config('database.connections.sqlite'));
    }

    // ------------------------------------- REPRISE SUR CONTENTION (§6/§7)

    public function test_financial_transactions_are_retried_on_contention(): void
    {
        $runner = app(TransactionRunner::class);

        $this->assertGreaterThan(
            1,
            $runner->attempts(),
            'Une transaction financière doit pouvoir être rejouée : sur SQLite, '
            .'« database is locked » est détecté par Laravel comme une erreur de '
            .'concurrence, et c\'est la reprise qui évite une erreur 500.'
        );
    }

    public function test_the_transaction_runner_commits_the_callback_result(): void
    {
        $tontine = Tontine::factory()->create();

        $result = app(TransactionRunner::class)->run(
            fn () => DB::table('tontines')->where('id', $tontine->id)->update(['name' => 'Renommee'])
        );

        $this->assertSame(1, $result);
        $this->assertSame('Renommee', $tontine->fresh()->name);
    }

    public function test_the_transaction_runner_rolls_back_on_failure(): void
    {
        $tontine = Tontine::factory()->create();

        try {
            app(TransactionRunner::class)->run(function () use ($tontine) {
                DB::table('tontines')->where('id', $tontine->id)->update(['name' => 'Jamais ecrite']);

                throw new \RuntimeException('echec metier');
            });

            $this->fail('L\'exception aurait du ete relancee.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('echec metier', $exception->getMessage());
        }

        $this->assertNotSame('Jamais ecrite', $tontine->fresh()->name);
    }

    public function test_the_transaction_runner_does_not_swallow_a_real_error(): void
    {
        // La reprise ne doit concerner QUE la contention : une contrainte
        // violée doit remonter immédiatement, sinon on masquerait un bug.
        $this->expectException(QueryException::class);

        app(TransactionRunner::class)->run(function () {
            DB::table('tontine_members')->insert([
                'tontine_id' => 999999,
                'user_id' => 999999,
                'position' => 1,
                'status' => 'active',
            ]);
        });
    }

    public function test_every_financial_service_goes_through_the_retrying_runner(): void
    {
        // Un `DB::transaction()` oublié dans un service laisserait une
        // opération financière sans protection contre la contention.
        $services = [
            app(TontineService::class),
            app(PaymentService::class),
            app(RefundService::class),
            app(DeliveryService::class),
            app(InstallmentPurchaseService::class),
        ];

        foreach ($services as $service) {
            $runner = (new \ReflectionProperty($service, 'transactions'))->getValue($service);

            $this->assertInstanceOf(
                TransactionRunner::class,
                $runner,
                'Le service '.$service::class.' n\'exécute pas ses écritures via le TransactionRunner.'
            );
        }
    }

    public function test_the_immediate_transaction_connection_is_not_forced_on_php_8_3(): void
    {
        // Garde-fou : `transaction_mode` n'est honoré par Laravel qu'à partir de
        // PHP 8.4. Le projet est en 8.3 ; si quelqu'un réintroduisait une
        // connexion maison sur cette base, elle serait sans effet ou casserait
        // les COMMIT/ROLLBACK de PDO (le rollback ne rollbackerait pas).
        $this->assertFalse(
            method_exists(Connection::class, 'setResolver'),
            'L\'API publique setResolver() a changé : revoir la stratégie de reprise.'
        );

        $this->assertStringNotContainsString(
            'ImmediateTransactionSQLiteConnection',
            implode("\n", array_map(
                fn (string $file) => (string) file_get_contents($file),
                glob(app_path('Services/*.php')) ?: []
            )),
            'La reprise passe par TransactionRunner, pas par une connexion SQLite sur mesure.'
        );
    }

    // ------------------------------------------------------------- OUTILS

    private function member(Tontine $tontine, int $position, string $status = 'active'): TontineMember
    {
        return TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => User::factory()->create()->id,
            'position' => $position,
            'status' => $status,
        ]);
    }
}
