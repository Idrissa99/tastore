<?php

namespace App\Services;

/**
 * PRIORITÉ 4 §14 — État de santé de l'application.
 *
 * Le contrôle `/up` par défaut de Laravel renvoie une page vide : il dit
 * seulement « PHP a démarré ». Il ne détecte pas une base de données
 * injoignable, qui est LA panne bloquante de cette application (toute la
 * logique de tontine, paiement et remboursement est en base).
 *
 * On sépare donc deux notions :
 *  - `live`  : le processus répond (nécessaire pour ne pas tuer un pod
 *              pendant un redémarrage) ;
 *  - `ready` : la base répond ET les migrations sont à jour.
 *
 * Le cache et la file d'attente ne sont PAS vérifiés : ce sont des
 * services secondaires, et un cache froid ne doit pas faire déclarer
 * l'application hors service.
 */
class HealthCheck
{
    /**
     * @return array{status: string, checks: array<string, array{status: string, detail: string}>}
     */
    public function report(): array
    {
        $checks = [];

        $checks['database'] = $this->checkDatabase();
        $checks['migrations'] = $this->checkMigrations();

        $ready = collect($checks)->every(fn (array $check) => $check['status'] === 'ok');

        return [
            'status' => $ready ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    public function isReady(): bool
    {
        return $this->report()['status'] === 'ok';
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function checkDatabase(): array
    {
        try {
            $connection = \Illuminate\Support\Facades\DB::connection();
            $connection->getPdo();
            // Une requête réelle, pas seulement un ping : détecte une base
            // accessible mais dont les tables ont disparu.
            $connection->select('select 1');

            return ['status' => 'ok', 'detail' => $connection->getDriverName()];
        } catch (\Throwable $exception) {
            return ['status' => 'fail', 'detail' => 'Base injoignable'];
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function checkMigrations(): array
    {
        try {
            $connection = \Illuminate\Support\Facades\DB::connection();

            if (! $connection->getSchemaBuilder()->hasTable('migrations')) {
                return ['status' => 'fail', 'detail' => 'Table migrations absente'];
            }

            $files = glob(database_path('migrations/*.php')) ?: [];
            $applied = $connection->table('migrations')->pluck('migration')->all();
            $pending = array_values(array_filter(
                array_map(fn ($file) => basename($file, '.php'), $files),
                fn (string $migration) => ! in_array($migration, $applied, true)
            ));

            if ($pending !== []) {
                return [
                    'status' => 'fail',
                    'detail' => count($pending).' migration(s) en attente',
                ];
            }

            return ['status' => 'ok', 'detail' => count($applied).' migration(s) appliquées'];
        } catch (\Throwable $exception) {
            return ['status' => 'fail', 'detail' => 'État des migrations illisible'];
        }
    }
}
