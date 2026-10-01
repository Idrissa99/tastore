<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * PRIORITÉ 4 §8 — Sauvegarde de la base de données.
 *
 * Sur SQLite la copie est cohérente (VACUUM INTO) : le fichier n'est pas
 * simplement recopié pendant une écriture, ce qui pourrait capturer un
 * instantané à moitié écrit.
 */
class BackupDatabase extends Command
{
    protected $signature = 'app:backup-database
                            {--label=auto : Étiquette lisible ajoutée au nom du fichier}
                            {--verify : Vérifier la sauvegarde immédiatement après écriture}';

    protected $description = 'Produit une sauvegarde cohérente de la base de données (hors dossier servi par HTTP)';

    public function handle(DatabaseBackupService $backups): int
    {
        $this->line("Driver : <info>{$backups->driver()}</info>");
        $this->line("Destination : <info>{$backups->backupDirectory()}</info>");

        try {
            $result = $backups->create($this->option('label'));
        } catch (\Throwable $exception) {
            $this->error('Sauvegarde échouée : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Sauvegarde créée');
        $this->line('  Fichier : '.$result['path']);
        $this->line('  Taille   : '.$this->humanSize($result['size']));

        if ($this->option('verify')) {
            $verification = $backups->verify($result['path']);

            if (! $verification['valid']) {
                $this->error('Vérification KO : '.$verification['reason']);

                return self::FAILURE;
            }

            $this->line('  Vérifié  : '.$verification['reason']);
        }

        $this->newLine();
        $this->line('Restaurer sur un environnement temporaire :');
        $this->line('  php artisan app:restore-database '.$result['path'].' --database=/tmp/verif.sqlite --force --yes');

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, 2).' '.$units[$unit];
    }
}
