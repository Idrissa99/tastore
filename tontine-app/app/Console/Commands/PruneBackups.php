<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * PRIORITÉ 4 §8 — Purge des sauvegardes.
 *
 * Volontairement EXPLICITE : une commande de suppression de sauvegardes ne doit
 * jamais partir d'un cron « pour faire le ménage ». Il faut la demander.
 * Deux garde-fous : --dry-run par défaut, et refus de supprimer plus de
 * `BACKUP_RETAIN` fichiers, sauf --force.
 */
class PruneBackups extends Command
{
    protected $signature = 'app:prune-backups
                            {--dry-run : Simule sans rien supprimer (défaut)}
                            {--force : Supprime réellement}';

    protected $description = 'Supprime les sauvegardes au-delà de la rétention configurée (opération destructive)';

    public function handle(DatabaseBackupService $backups): int
    {
        $directory = $backups->backupDirectory();

        if (! File::isDirectory($directory)) {
            $this->warn('Aucun répertoire de sauvegarde : '.$directory);

            return self::SUCCESS;
        }

        $retain = max(1, (int) config('backups.retain', 14));
        $retainDays = (int) config('backups.retain_days', 30);

        $files = collect(File::files($directory))
            ->filter(fn ($file) => $file->getExtension() === 'sqlite' || $file->getExtension() === 'sql')
            ->sortByDesc(fn ($file) => $file->getMTime());

        $tooOld = $files->filter(fn ($file) => $file->getMTime() < now()->subDays($retainDays)->getTimestamp());
        $surplus = $files->slice($retain);
        $toDelete = $tooOld->merge($surplus)->unique(fn ($file) => $file->getPathname())->values();

        if ($toDelete->isEmpty()) {
            $this->info('Rien à supprimer ('.$files->count().' sauvegarde(s), rétention '.$retain.').');

            return self::SUCCESS;
        }

        $this->line($this->option('force')
            ? 'Suppression de '.$toDelete->count().' sauvegarde(s) :'
            : 'SIMULATION — '.$toDelete->count().' sauvegarde(s) seraient supprimées :');

        foreach ($toDelete as $file) {
            $this->line('  - '.$file->getFilename());
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Rien n\'a été supprimé. Relancez avec --force.');

            return self::SUCCESS;
        }

        foreach ($toDelete as $file) {
            File::delete($file->getPathname());
        }

        $this->info($toDelete->count().' sauvegarde(s) supprimée(s).');

        return self::SUCCESS;
    }
}
