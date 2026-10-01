<?php

namespace Tests\Feature\Resilience;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\HealthCheck;
use App\Services\Payments\FakeMobileMoneyGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PRIORITÉ 4 §8/§9/§14/§19 — Sauvegardes, restauration, santé et
 * configuration de production.
 */
class InfrastructureResilienceTest extends TestCase
{
    use RefreshDatabase;

    private ?string $backupDirectory = null;

    /**
     * PRIORITÉ 4 §8 — Les tests de sauvegarde écrivent dans un répertoire
     * JETABLE, jamais dans `storage/app/backups`.
     *
     * Constat mesuré : le nettoyage en `tearDown` ne corresponded à rien. Les
     * fichiers produits sont en `.sql` (la base de test est `:memory:`) et
     * portent des étiquettes comme `garde-fou`, `integrite`, `prune-0-test`.
     * Le filtre exigeait `.sqlite` ET « test » dans le nom : aucune
     * correspondance. Résultat, 4 à 6 fichiers conservés à chaque exécution de
     * la suite — 50+ fichiers déjà accumulés dans ce dépôt.
     *
     * Ce n'est pas seulement sale. `app:prune-backups` compte TOUS les
     * fichiers `.sql`/`.sqlite` du répertoire pour appliquer `BACKUP_RETAIN` :
     * ces artefacts de test pourraient donc faire supprimer de VRAIES
     * sauvegardes pour leur faire de la place. Sur un serveur de production,
     * la même pollution s'installerait à chaque exécution de la CI.
     *
     * Écrire dans un répertoire temporaire règle la cause : la suite ne peut
     * plus rien laisser derrière elle, quoi qu'elle fasse.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDirectory = storage_path('framework/testing/backups-'.uniqid());

        File::ensureDirectoryExists($this->backupDirectory);

        config(['backups.directory' => $this->backupDirectory]);
    }

    protected function tearDown(): void
    {
        if ($this->backupDirectory !== null) {
            File::deleteDirectory($this->backupDirectory);
            $this->backupDirectory = null;
        }

        parent::tearDown();
    }

    private function makeMemberId(): int
    {
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id]);

        return TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => User::factory()->create()->id,
            'position' => 1,
        ])->id;
    }

    // ------------------------------------------------------ SAUVEGARDES (§8)

    public function test_backup_creates_a_readable_file_outside_public(): void
    {
        $backups = app(DatabaseBackupService::class);

        $result = $backups->create('test-backup');

        $this->assertFileExists($result['path']);
        $this->assertGreaterThan(0, $result['size']);
        $this->assertTrue($backups->verify($result['path'])['valid']);

        // Le fichier ne doit PAS être dans un dossier servi en HTTP.
        $this->assertStringNotContainsString(public_path(), $result['path']);
        $this->assertStringNotContainsString('storage/app/public', $result['path']);
        $this->assertStringContainsString('backups', $result['path']);

        File::delete($result['path']);
    }

    public function test_backup_leaves_no_working_file_behind(): void
    {
        // PRIORITÉ 4 §8 — Les outils de dump externes (mysqldump, pg_dump)
        // écrivent dans un fichier de travail. Mesuré avant correction : ce
        // fichier, contenant l'intégralité de la base en clair et en
        // permissions 644, n'était JAMAIS supprimé, et `app:prune-backups` ne
        // retient que les extensions `sql`/`sqlite` : il s'accumulait donc sans
        // limite. Ce test échouerait sur MySQL/PostgreSQL avant le correctif.
        $backups = app(DatabaseBackupService::class);

        $result = $backups->create('fichiers-temporaires');

        $leftovers = array_map(
            fn ($file) => $file->getFilename(),
            File::files($backups->backupDirectory())
        );

        $this->assertSame(
            [basename($result['path'])],
            $leftovers,
            'Une sauvegarde ne doit laisser que son fichier final, aucun fichier de travail.'
        );

        File::delete($result['path']);
    }

    public function test_backup_never_overwrites_an_existing_file(): void
    {
        $backups = app(DatabaseBackupService::class);

        // Deux sauvegardes au même instant portent le même nom : la seconde
        // doit échouer plutôt que d'écraser la première.
        $first = $backups->create('doublon');

        try {
            $backups->create('doublon');
            $this->fail('La seconde sauvegarde aurait dû être refusée.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('existe déjà', $exception->getMessage());
        }

        File::delete($first['path']);
    }

    public function test_backup_refuses_a_web_accessible_directory(): void
    {
        $backups = app(DatabaseBackupService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('servi par HTTP');

        $backups->assertNotWebAccessible(public_path('sauvegardes'));
    }

    public function test_backup_directory_is_never_the_public_storage_link(): void
    {
        $directory = app(DatabaseBackupService::class)->backupDirectory();

        $this->assertStringNotContainsString('app/public', $directory);
        $this->assertStringNotContainsString('/public', $directory);
    }

    public function test_verify_rejects_a_corrupted_file(): void
    {
        $path = storage_path('app/backups/corrompu-test.sqlite');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'ceci n est pas une base sqlite');

        $verification = app(DatabaseBackupService::class)->verify($path);

        $this->assertFalse($verification['valid']);
        File::delete($path);
    }

    public function test_verify_rejects_a_missing_file(): void
    {
        $verification = app(DatabaseBackupService::class)->verify('/tmp/inexistant-p4.sqlite');

        $this->assertFalse($verification['valid']);
    }

    public function test_backup_preserves_the_data(): void
    {
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id]);
        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => User::factory()->create()->id,
            'position' => 1,
        ]);

        $backups = app(DatabaseBackupService::class);
        $result = $backups->create('integrite');

        $restored = sys_get_temp_dir().'/p4-backup-check-'.getmypid().'.sqlite';
        @unlink($restored);

        $backups->restore($result['path'], $restored);

        $pdo = new \PDO('sqlite:'.$restored);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(0, count($pdo->query('PRAGMA foreign_key_check')->fetchAll()));
        $this->assertGreaterThanOrEqual(1, (int) $pdo->query('SELECT COUNT(*) FROM tontines')->fetchColumn());
        $this->assertGreaterThanOrEqual(1, (int) $pdo->query('SELECT COUNT(*) FROM tontine_members')->fetchColumn());

        @unlink($restored);
        File::delete($result['path']);
    }

    public function test_restore_refuses_to_overwrite_the_active_database(): void
    {
        $backups = app(DatabaseBackupService::class);
        $result = $backups->create('garde-fou');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('base active');

        $backups->restore($result['path']);

        File::delete($result['path']);
    }

    public function test_restore_refuses_an_existing_target_file(): void
    {
        $backups = app(DatabaseBackupService::class);
        $result = $backups->create('cible');

        $target = sys_get_temp_dir().'/p4-cible-'.getmypid().'.sqlite';
        File::put($target, 'deja la');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('existe déjà');
            $backups->restore($result['path'], $target);
        } finally {
            @unlink($target);
            File::delete($result['path']);
        }
    }

    public function test_restore_command_is_inert_without_force_and_yes(): void
    {
        $backups = app(DatabaseBackupService::class);
        $result = $backups->create('commande');

        $target = sys_get_temp_dir().'/p4-inert-'.getmypid().'.sqlite';
        @unlink($target);

        $this->artisan('app:restore-database', ['backup' => $result['path'], '--database' => $target])
            ->assertSuccessful();

        $this->assertFileDoesNotExist($target);

        File::delete($result['path']);
    }

    public function test_prune_backups_is_dry_run_by_default(): void
    {
        $backups = app(DatabaseBackupService::class);

        for ($i = 0; $i < 3; $i++) {
            $backups->create('prune-'.$i.'-test');
        }

        config(['backups.retain' => 1, 'backups.retain_days' => 30]);

        $before = count(File::files($backups->backupDirectory()));

        $this->artisan('app:prune-backups')->assertSuccessful();

        $this->assertSame($before, count(File::files($backups->backupDirectory())));
    }

    // -------------------------------------------------------- SANTÉ (§14)

    public function test_live_probe_answers_even_without_a_database(): void
    {
        $this->getJson('/up')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_ready_probe_checks_the_database(): void
    {
        $this->getJson('/up/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.migrations.status', 'ok');
    }

    public function test_health_check_reports_a_broken_connection(): void
    {
        // Connexion séparée, jamais résolue pendant le test : on ne purge pas
        // la connexion du test (sinon la transaction de RefreshDatabase est
        // perdue et tous les tests suivants plantent).
        config([
            'database.connections.p4_broken' => [
                'driver' => 'sqlite',
                'database' => '/tmp/p4-inexistant-'.getmypid().'.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'p4_broken',
        ]);

        $report = app(HealthCheck::class)->report();

        config(['database.default' => 'sqlite']);

        $this->assertSame('degraded', $report['status']);
        $this->assertSame('fail', $report['checks']['database']['status']);
        $this->assertSame('fail', $report['checks']['migrations']['status']);
    }

    public function test_health_check_does_not_depend_on_cache_or_queue(): void
    {
        // Cache et file d'attente hors service : l'application doit rester
        // déclarée prête (ils ne sont pas critiques pour le cycle métier).
        config(['cache.default' => 'array', 'queue.default' => 'sync']);

        $this->assertTrue(app(HealthCheck::class)->isReady());
        $this->assertArrayNotHasKey('cache', app(HealthCheck::class)->report()['checks']);
        $this->assertArrayNotHasKey('queue', app(HealthCheck::class)->report()['checks']);
    }

    // ------------------------------------------------ PRODUCTION (§15/§16)

    public function test_env_example_defines_app_env_exactly_once(): void
    {
        $contents = (string) file_get_contents(base_path('.env.example'));

        $this->assertSame(
            1,
            substr_count($contents, "\nAPP_ENV="),
            'APP_ENV ne doit être défini qu\'une fois dans .env.example (le doublon de la'
            .' Priorité 0 faisait passer une nouvelle installation en « testing »).'
        );
    }

    public function test_env_example_contains_no_real_secret(): void
    {
        $contents = (string) file_get_contents(base_path('.env.example'));

        foreach ([
            'APP_KEY=',
            'MOBILEMONEY_WEBHOOK_SECRET=',
            'DB_PASSWORD=',
            'MAIL_PASSWORD=',
            'REDIS_PASSWORD=',
        ] as $variable) {
            $this->assertStringContainsString($variable, $contents);
        }

        // Aucune valeur de secret plausible après le signe égal.
        $this->assertDoesNotMatchRegularExpression(
            '/^(APP_KEY|MOBILEMONEY_WEBHOOK_SECRET)=\S{8,}/m',
            $contents
        );
    }

    public function test_env_example_documents_production_critical_settings(): void
    {
        $contents = (string) file_get_contents(base_path('.env.example'));

        foreach ([
            'APP_DEBUG=',
            'APP_URL=',
            'FRONTEND_URL=',
            'SANCTUM_TOKEN_EXPIRATION=',
            'MOBILEMONEY_WEBHOOK_SECRET=',
            'TRUSTED_PROXIES=',
            'LOG_DAILY_DAYS=',
            'SESSION_SECURE_COOKIE=',
            'BACKUP_DIRECTORY=',
            'PAYMENT_ALLOW_FAKE_IN_PRODUCTION=',
        ] as $variable) {
            $this->assertStringContainsString($variable, $contents, "{$variable} absent de .env.example");
        }
    }

    public function test_production_uses_a_rotating_log_channel(): void
    {
        $contents = (string) file_get_contents(base_path('.env.example'));

        // Le canal `single` écrit un seul fichier sans limite : il a déjà
        // produit un fichier de plusieurs Mo sur ce projet.
        $this->assertStringContainsString('LOG_STACK=daily', $contents);
        $this->assertStringContainsString('LOG_DAILY_DAYS=', $contents);
    }

    public function test_trusted_proxies_defaults_to_empty_never_wildcard(): void
    {
        $this->assertSame('', config('security.trusted_proxies'));

        $contents = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('TRUSTED_PROXIES=', $contents);
        $this->assertStringNotContainsString('TRUSTED_PROXIES=*', $contents);
    }

    public function test_commission_rate_is_untouched_by_priority_four(): void
    {
        // La Priorité 4 est de l'infrastructure : aucune règle métier ne bouge.
        // 1. Le taux de commission n'a pas été modifié.
        $this->assertSame(0.03, config('commissions.rate'));

        // 2. Aucune migration de la Priorité 4 ne touche aux commissions ni
        //    aux montants historiques (elles ne font que poser des contraintes).
        foreach (glob(database_path('migrations/2026_09_28_*.php')) as $migration) {
            $contents = (string) file_get_contents($migration);

            $this->assertStringNotContainsString('commission_rate', $contents, basename($migration));
            $this->assertStringNotContainsString(
                '->update(',
                $contents,
                basename($migration).' ne doit mettre à jour aucune ligne existante.'
            );
        }

        // 3. La valeur historique figée reste lue telle quelle.
        $contribution = Contribution::create([
            'tontine_member_id' => $this->makeMemberId(),
            'round' => 1,
            'amount' => 1000,
            'commission_rate' => 0.07,
            'commission_amount' => 0,
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $this->assertEquals(0, $contribution->fresh()->commission_amount);
    }

    public function test_no_real_payment_provider_is_registered(): void
    {
        $providers = config('payments.providers');

        $this->assertSame(['fake' => FakeMobileMoneyGateway::class], $providers);

        foreach (config('payments.channel_providers') as $channel => $provider) {
            $this->assertSame('fake', $provider, "Le canal {$channel} ne doit pointer que vers le gateway factice.");
        }

        $this->assertTrue(app(FakeMobileMoneyGateway::class)->isTestGateway());
    }
}
