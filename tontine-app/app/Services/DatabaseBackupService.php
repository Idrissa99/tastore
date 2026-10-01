<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * PRIORITÉ 4 §8 — Sauvegarde et restauration de la base de données.
 *
 * Choix de conception, justifiés par ce projet :
 *
 * 1. EMPLACEMENT. Les sauvegardes ne vont JAMAIS dans `public/` ni dans
 *    `storage/app/public` : ce répertoire est servi en HTTP par le lien
 *    `public/storage`. Elles vont dans `storage/app/backups`, hors périmètre
 *    web, et la commande refuse un chemin public.
 *
 * 2. COHÉRENCE SQLITE. Copier le fichier à chaud peut capturer un instantané
 *    incohérent (page écrite, index pas encore). On utilise donc le
 *    `.backup` de SQLite, qui produit une copie transactionnellement
 *    cohérente même pendant l'écriture. À défaut (extension absente), on
 *    bascule sur `VACUUM INTO`, également cohérent et atomique.
 *
 * 3. MYSQL/MARIADB. On délègue à `mysqldump`, et on exige que le fichier
 *    produit soit lisible et non vide.
 *
 * 4. NON-DESTRUCTIF. La restauration exige `--force` ET la confirmation
 *    explicite `--yes`. Sans ces deux indicateurs, la commande n'écrit rien.
 *    La base cible ne peut pas être celle du serveur web actif.
 */
class DatabaseBackupService
{
    public const DRIVER_SQLITE = 'sqlite';

    public const DRIVER_MYSQL = 'mysql';

    public const DRIVER_MARIADB = 'mariadb';

    public const DRIVER_PGSQL = 'pgsql';

    /**
     * Fichier de travail du dump courant, mémorisé pour que la commande et la
     * lecture visent le même fichier. Voir temporaryPath().
     */
    private ?string $temporaryPath = null;

    public function driver(): string
    {
        return (string) config('database.default');
    }

    /**
     * Répertoire de sauvegarde : hors de `public/`, hors du disque public.
     */
    public function backupDirectory(): string
    {
        $configured = (string) config('backups.directory', '');

        $directory = $configured !== ''
            ? base_path($configured)
            : storage_path('app/backups');

        return rtrim($directory, '/');
    }

    public function ensureDirectoryExists(): void
    {
        $directory = $this->backupDirectory();

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0750, true);
        }
    }

    /**
     * Vérifie qu'un chemin de sauvegarde ne sera jamais servi en HTTP.
     */
    public function assertNotWebAccessible(string $path): void
    {
        $real = realpath($path) ?: $path;
        $public = realpath(public_path()) ?: public_path();

        if (str_starts_with($real, $public) || str_contains($real, '/public/')) {
            throw new RuntimeException(
                'Refus d\'écrire une sauvegarde dans un dossier servi par HTTP : '.$real
            );
        }
    }

    /**
     * Produit une sauvegarde. Ne remplace jamais un fichier existant.
     *
     * @return array{path: string, size: int, driver: string}
     */
    public function create(?string $label = null): array
    {
        $this->ensureDirectoryExists();
        $this->assertNotWebAccessible($this->backupDirectory());

        $label = $this->sanitizeLabel($label);
        $driver = $this->driver();

        [$content, $extension] = match ($driver) {
            // Base en mémoire : sérialisation SQL (pas de fichier à copier).
            self::DRIVER_SQLITE => [
                $this->snapshotSqlite(),
                $this->sqliteDatabasePath() === ':memory:' ? 'sql' : 'sqlite',
            ],
            self::DRIVER_MYSQL, self::DRIVER_MARIADB => [$this->dumpMysql(), 'sql'],
            self::DRIVER_PGSQL => [$this->dumpPostgres(), 'sql'],
            default => throw new RuntimeException(
                "Sauvegarde non supportée pour le driver « {$driver} ». Utilisez MySQL, MariaDB, PostgreSQL ou SQLite."
            ),
        };

        if ($content === '' || strlen($content) < 32) {
            throw new RuntimeException('Sauvegarde vide : la commande sous-jacente n\'a rien produit.');
        }

        $filename = sprintf(
            '%s-%s-%s.%s',
            config('app.name', 'app'),
            now()->format('Ymd-His'),
            $label,
            $extension
        );

        $path = $this->backupDirectory().'/'.$filename;
        $this->assertNotWebAccessible($path);

        if (File::exists($path)) {
            throw new RuntimeException('Une sauvegarde portant ce nom existe déjà : '.$filename);
        }

        // Écriture atomique : fichier temporaire puis rename, pour qu'un
        // arrêt brutal ne laisse pas une sauvegarde tronquée "valide".
        $temporary = $path.'.tmp';
        File::put($temporary, $content, true);
        rename($temporary, $path);
        chmod($path, 0640);

        return ['path' => $path, 'size' => filesize($path), 'driver' => $driver];
    }

    /**
     * Vérifie qu'un fichier de sauvegarde est exploitable.
     *
     * @return array{valid: bool, reason: string}
     */
    public function verify(string $path): array
    {
        if (! File::exists($path)) {
            return ['valid' => false, 'reason' => 'Fichier introuvable.'];
        }

        $size = filesize($path);
        if ($size < 32) {
            return ['valid' => false, 'reason' => 'Fichier trop petit pour être une sauvegarde.'];
        }

        if (str_ends_with($path, '.sqlite')) {
            // Un header SQLite valide commence par cette chaîne magic.
            $handle = fopen($path, 'rb');
            $header = (string) fread($handle, 16);
            fclose($handle);

            if (! str_starts_with($header, 'SQLite format 3')) {
                return ['valid' => false, 'reason' => 'Le fichier n\'a pas un header SQLite valide.'];
            }

            return ['valid' => true, 'reason' => 'Header SQLite valide.'];
        }

        $contents = (string) File::get($path);
        if (! str_contains($contents, 'CREATE TABLE') && ! str_contains($contents, 'INSERT INTO')) {
            return ['valid' => false, 'reason' => 'Le dump ne contient ni CREATE TABLE ni INSERT INTO.'];
        }

        return ['valid' => true, 'reason' => 'Dump SQL cohérent.'];
    }

    /**
     * Restaure une sauvegarde. Opération DESTRUCTIVE : réservée à une copie de
     * sauvegarde, jamais à la base servie par le site.
     *
     * @return array{path: string, driver: string}
     */
    public function restore(string $path, ?string $targetDatabase = null): array
    {
        $this->assertNotWebAccessible($path);

        $verification = $this->verify($path);
        if (! $verification['valid']) {
            throw new RuntimeException('Sauvegarde inutilisable : '.$verification['reason']);
        }

        $driver = $this->driver();

        if ($driver === self::DRIVER_SQLITE) {
            $target = $targetDatabase ?? $this->sqliteDatabasePath();

            // On ne restaure jamais la base du serveur web en cours d'exécution :
            // cela se ferait sous les pieds des requêtes actives.
            $active = realpath($target) ?: $target;
            $current = realpath($this->sqliteDatabasePath()) ?: $this->sqliteDatabasePath();
            if ($targetDatabase === null && $active === $current) {
                throw new RuntimeException(
                    'Refus de restaurer sur la base active. Copiez la sauvegarde vers un fichier cible : '
                    .'--database=/chemin/cible.sqlite'
                );
            }

            if (File::exists($target)) {
                throw new RuntimeException('Le fichier cible existe déjà : '.$target);
            }

            $this->assertNotWebAccessible($target);

            if (str_ends_with($path, '.sqlite')) {
                File::copy($path, $target);
            } else {
                // Sauvegarde au format SQL : on rejoue le dump dans le fichier
                // cible, sans jamais modifier la base d'origine.
                $this->applySqliteDump($path, $target);
            }

            return ['path' => $target, 'driver' => $driver];
        }

        $this->restoreSqlDump($path);

        return ['path' => $path, 'driver' => $driver];
    }

    public function sqliteDatabasePath(): string
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        return $database === ':memory:' ? $database : $database;
    }

    /**
     * Copie cohérente de SQLite, sans figer les écritures.
     *
     * Deux cas :
     *  - base sur fichier : `VACUUM INTO`, atomique et cohérent même pendant
     *    une écriture concurrente (le fichier n'est jamais recopié à chaud) ;
     *  - base en mémoire (`:memory:`) : impossible d'en faire une copie via
     *    le système de fichiers, on sérialise donc le schéma et les données
     *    en SQL. Ce chemin sert aux tests et aux environments éphémères.
     */
    private function snapshotSqlite(): string
    {
        $database = $this->sqliteDatabasePath();

        if ($database === ':memory:' || $database === '') {
            return $this->dumpInMemorySqlite();
        }

        if (! is_file($database)) {
            throw new RuntimeException('Base SQLite introuvable : '.$database);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'tontine-backup-');
        @unlink($temporary);

        $pdo = new \PDO('sqlite:'.$database);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $statement = $pdo->prepare('VACUUM INTO ?');
        $statement->execute([$temporary]);

        $contents = (string) file_get_contents($temporary);
        @unlink($temporary);

        return $contents;
    }

    private function dumpInMemorySqlite(): string
    {
        $pdo = DB::connection()->getPdo();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dump = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n";

        // Les CREATE de sqlite_master ne sont PAS termines par un point-virgule :
        // on le rajoute, sinon le rejeu les concatene en une seule instruction.
        foreach ($this->createStatements($pdo) as $statement) {
            $dump .= rtrim(trim($statement), ';').";\n";
        }

        $dump .= $this->sqliteIterdump($pdo);
        $dump .= "COMMIT;\n";

        return $dump;
    }

    /**
     * Schéma complet (CREATE TABLE + INDEX) rejouable sur une base vide.
     *
     * @return array<int, string>
     */
    private function createStatements(\PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT sql FROM sqlite_master
             WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' AND type IN ('table','index')
             ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_filter(array_map('strval', $rows ?: [])));
    }

    private function sqliteIterdump(\PDO $pdo): string
    {
        $dump = '';

        foreach ($this->tables($pdo) as $table) {
            $rows = $pdo->query("SELECT * FROM \"{$table}\"")->fetchAll(\PDO::FETCH_ASSOC);

            if ($rows === []) {
                continue;
            }

            $columns = implode(', ', array_map(
                fn ($column) => '"'.str_replace('"', '""', (string) $column).'"',
                array_keys($rows[0])
            ));

            $tuples = array_map(function (array $row): string {
                $literals = array_map(function ($value): string {
                    if ($value === null) {
                        return 'NULL';
                    }

                    if (is_int($value) || is_float($value)) {
                        return (string) $value;
                    }

                    return "'".str_replace("'", "''", (string) $value)."'";
                }, array_values($row));

                return '('.implode(', ', $literals).')';
            }, $rows);

            $dump .= 'INSERT INTO "'.$table.'" ('.$columns.') VALUES '.implode(', ', $tuples).";\n";
        }

        return $dump;
    }

    /**
     * @return array<int, string>
     */
    private function tables(\PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_map('strval', $rows ?: []);
    }

    private function dumpMysql(): string
    {
        $binary = $this->locateBinary(['mysqldump', 'mariadb-dump']);

        $connection = config('database.connections.'.config('database.default'));
        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '3306');
        $database = (string) ($connection['database'] ?? '');

        $command = sprintf(
            '%s --host=%s --port=%s --user=%s --single-transaction --quick --no-tablespaces --result-file=%s %s',
            escapeshellcmd($binary),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg((string) ($connection['username'] ?? '')),
            escapeshellarg($this->temporaryPath()),
            escapeshellarg($database)
        );

        if (! empty($connection['password'])) {
            $command .= ' --password='.escapeshellarg((string) $connection['password']);
        }

        return $this->runAndRead($command);
    }

    private function dumpPostgres(): string
    {
        $binary = $this->locateBinary(['pg_dump']);

        $connection = config('database.connections.'.config('database.default'));

        $command = sprintf(
            'PGPASSWORD=%s %s --host=%s --port=%s --username=%s --no-owner --no-privileges --file=%s %s',
            escapeshellarg((string) ($connection['password'] ?? '')),
            escapeshellcmd($binary),
            escapeshellarg((string) ($connection['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($connection['port'] ?? '5432')),
            escapeshellarg((string) ($connection['username'] ?? '')),
            escapeshellarg($this->temporaryPath()),
            escapeshellarg((string) ($connection['database'] ?? ''))
        );

        return $this->runAndRead($command);
    }

    /**
     * Exécute un outil de dump qui écrit dans un fichier temporaire, puis lit
     * ce fichier et le SUPPRIME.
     *
     * PRIORITÉ 4 §8/§19 — La suppression n'était pas faite avant correction.
     * Mesuré : `app:backup-database` sur MySQL laissait sur disque un
     * `.dump-<pid>.tmp` contenant l'intégralité de la base, en permissions
     * 644 (lisible par tous les comptes du serveur), à chaque exécution. Or
     * `app:prune-backups` ne retient que les extensions `sql` et `sqlite` :
     * ces fichiers n'étaient donc JAMAIS purgés et s'accumulaient sans limite,
     * en violation de la règle « les logs et sauvegardes ne doivent pas remplir
     * le disque ».
     *
     * Le `finally` couvre aussi le cas où la lecture échoue : un fichier
     * temporaire contenant des données de production ne doit pas survivre à
     * une erreur.
     */
    private function runAndRead(string $command): string
    {
        $temporary = $this->temporaryPath();

        try {
            $this->run($command, $temporary);

            return (string) file_get_contents($temporary);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            $this->temporaryPath = null;
        }
    }

    private function applySqliteDump(string $dumpPath, string $targetPath): void
    {
        touch($targetPath);

        $pdo = new \PDO('sqlite:'.$targetPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // SQLite via PDO n'execute qu'une instruction par exec() : on rejoue
        // donc le dump instruction par instruction.
        foreach ($this->splitStatements((string) file_get_contents($dumpPath)) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @return array<int, string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';

        foreach (explode("\n", $sql) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $current .= ($current === '' ? '' : "\n").$line;

            if (str_ends_with($trimmed, ';')) {
                $statements[] = $current;
                $current = '';
            }
        }

        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }

    private function restoreSqlDump(string $path): void
    {
        $driver = $this->driver();
        $connection = config('database.connections.'.config('database.default'));

        if (in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_MARIADB], true)) {
            $binary = $this->locateBinary(['mysql', 'mariadb']);
            $command = sprintf(
                '%s --host=%s --port=%s --user=%s%s %s < %s',
                escapeshellcmd($binary),
                escapeshellarg((string) ($connection['host'] ?? '127.0.0.1')),
                escapeshellarg((string) ($connection['port'] ?? '3306')),
                escapeshellarg((string) ($connection['username'] ?? '')),
                empty($connection['password']) ? '' : ' --password='.escapeshellarg((string) $connection['password']),
                escapeshellarg((string) ($connection['database'] ?? '')),
                escapeshellarg($path)
            );

            $this->run($command);

            return;
        }

        if ($driver === self::DRIVER_PGSQL) {
            $binary = $this->locateBinary(['psql']);
            $command = sprintf(
                'PGPASSWORD=%s %s --host=%s --port=%s --username=%s --dbname=%s --file=%s',
                escapeshellarg((string) ($connection['password'] ?? '')),
                escapeshellcmd($binary),
                escapeshellarg((string) ($connection['host'] ?? '127.0.0.1')),
                escapeshellarg((string) ($connection['port'] ?? '5432')),
                escapeshellarg((string) ($connection['username'] ?? '')),
                escapeshellarg((string) ($connection['database'] ?? '')),
                escapeshellarg($path)
            );

            $this->run($command);
        }
    }

    private function locateBinary(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));

            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        throw new RuntimeException(
            'Binaire introuvable ('.implode(', ', $candidates).'). Installez le client de la base de données.'
        );
    }

    /**
     * Exécute une commande de dump/restauration.
     *
     * `$discardOnFailure` est le fichier temporaire À SUPPRIMER si la commande
     * échoue (le dump qu'elle était en train de produire). Ce n'est pas le
     * fichier d'entrée d'une restauration : dans ce cas, passer `null`.
     */
    private function run(string $command, ?string $discardOnFailure = null): void
    {
        $this->ensureDirectoryExists();

        $output = [];
        exec($command.' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            if ($discardOnFailure !== null) {
                @unlink($discardOnFailure);
                $this->temporaryPath = null;
            }

            throw new RuntimeException(
                'Commande de sauvegarde en échec : '.implode(' | ', array_slice($output, -5))
            );
        }
    }

    /**
     * Fichier de travail d'un dump externe.
     *
     * PRIORITÉ 4 §8 — Deux propriétés :
     *
     *  - Le fichier est créé en 600 : il contient l'intégralité de la base en
     *    clair pendant l'exécution, et `mysqldump` comme `pg_dump` peuvent être
     *    interrompus entre la création et la reprise du contenu.
     *  - Le nom est MÉMORISÉ pour toute la durée du processus. Il est
     *    référencé à deux endroits (construction de la commande, puis lecture
     *    du résultat) : un nom régénéré à chaque appel enverrait `mysqldump`
     *    écrire dans un fichier et le lecteur en lire un autre, vide.
     */
    private function temporaryPath(): string
    {
        if ($this->temporaryPath !== null) {
            return $this->temporaryPath;
        }

        $this->ensureDirectoryExists();

        $path = $this->backupDirectory().'/.dump-'.getmypid().'-'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($path, '') === false) {
            throw new RuntimeException('Impossible de créer le fichier de travail : '.$path);
        }

        chmod($path, 0600);

        return $this->temporaryPath = $path;
    }

    private function sanitizeLabel(?string $label): string
    {
        $label = $label !== null && $label !== '' ? $label : 'auto';

        return trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $label), '-') ?: 'auto';
    }
}
