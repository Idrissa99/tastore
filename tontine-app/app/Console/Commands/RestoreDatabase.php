<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * PRIORITÉ 4 §9 — Restauration d'une sauvegarde.
 *
 * Mesures de sécurité, dans l'ordre :
 *  1. sans --force ET --yes, RIEN n'est écrit (confirmation obligatoire) ;
 *  2. le fichier doit passer la vérification d'intégrité avant toute écriture ;
 *  3. sur SQLite, la base active du serveur ne peut pas être ciblée par
 *     défaut : il faut indiquer explicitement un fichier cible ;
 *  4. la cible ne doit pas déjà exister (pas d'écrasement accidentel).
 */
class RestoreDatabase extends Command
{
    protected $signature = 'app:restore-database
                            {backup : Chemin du fichier de sauvegarde}
                            {--database= : Fichier SQLite cible (obligatoire sur SQLite)}
                            {--force : Confirme l\'opération destructrice}
                            {--yes : Deuxième confirmation, exigée avec --force}';

    protected $description = 'Restaure une sauvegarde vers un environnement cible (opération destructive)';

    public function handle(DatabaseBackupService $backups): int
    {
        $path = (string) $this->argument('backup');

        $this->line('Fichier    : '.$path);
        $this->line('Driver     : '.$backups->driver());

        if (! $this->option('force') || ! $this->option('yes')) {
            $this->newLine();
            $this->warn('AUCUNE MODIFICATION EFFECTUÉE.');
            $this->line('Cette commande est destructive. Relancez avec :');
            $this->line('  --force --yes');

            return self::SUCCESS;
        }

        $verification = $backups->verify($path);
        if (! $verification['valid']) {
            $this->error('Sauvegarde inutilisable : '.$verification['reason']);

            return self::FAILURE;
        }

        $this->line('Vérifié    : '.$verification['reason']);

        if (! $this->confirm('Confirmer la restauration ? Cette opération remplace les données de la cible.', false)) {
            $this->warn('Restauration annulée.');

            return self::SUCCESS;
        }

        try {
            $result = $backups->restore($path, $this->option('database') ?: null);
        } catch (\Throwable $exception) {
            $this->error('Restauration échouée : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Restauration terminée');
        $this->line('  Cible : '.$result['path']);
        $this->newLine();
        $this->line('Contrôler la cible avant de l\'utiliser :');

        // Sur SQLite on a restauré un FICHIER : `--database` a du sens.
        // Sur MySQL/PostgreSQL le dump SQL a été rejoué dans la base configurée
        // par le .env : indiquer le chemin du dump serait trompeur, il n'est
        // pas une base.
        if (str_ends_with($result['path'], '.sqlite')) {
            $this->line('  php artisan migrate:status --database='.$result['path']);
            $this->line('  sqlite3 '.$result['path'].' "PRAGMA integrity_check;"');
        } else {
            $this->line('  php artisan migrate:status   (base configurée dans le .env)');
            $this->line('  Comparer le nombre de tables et de lignes avec la source.');
        }

        return self::SUCCESS;
    }
}
