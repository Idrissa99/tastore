<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PRIORITÉ 4 §2/§6 — Exécution des transactions financières avec reprise.
 *
 * LE PROBLÈME MESURÉ
 *
 * Toutes les opérations financières de cette application suivent le même
 * schéma : `DB::transaction()` → SELECT pour verrouiller et relire l'état →
 * UPDATE. Sur SQLite c'est un piège :
 *
 *   - `BEGIN DEFERRED` prend d'abord un verrou de LECTURE ;
 *   - le verrou d'ÉCRITURE n'est demandé qu'au premier UPDATE ;
 *   - si une autre transaction a écrit entre-temps, SQLite ne peut plus faire
 *     évoluer son snapshot et refuse l'écriture.
 *
 * Ce refus est SQLITE_BUSY, et ce N'EST PAS une attente : `busy_timeout` n'y
 * change rien. Mesuré sur ce projet, deux requêtes concurrentes typiques :
 *
 *     DEFERRED, busy_timeout = 5 s  ->  « database is locked »  en 0,11 s
 *
 * Autrement dit, deux utilisateurs qui payent au même moment (cas prévu au
 * §6) : l'un est accepté, l'autre reçoit une erreur 500 alors que la base est
 * parfaitement saine.
 *
 * `BEGIN IMMEDIATE` réglerait le fond de cause, mais Laravel 11.28+ n'honore
 * `transaction_mode` qu'à partir de PHP 8.4, et le projet est en PHP 8.3 :
 * le réglage y serait silencieusement sans effet. On ne contourne donc pas le
 * framework, on utilise son mécanisme prévu : Laravel classe « database is
 * locked » parmi les erreurs de CONCURRENCE et rejoue alors la transaction
 * entière quand on lui donne un nombre de tentatives.
 *
 * POURQUOI REJOUER EST SÛR ICI
 *
 * La reprise re-exécute le CLOSURE depuis le début, sur une transaction
 * neuve et après ROLLBACK : il n'existe donc aucun état partiel à nettoyer.
 * Elle ne peut pas doubler une opération financière parce que, par
 * construction de la Priorité 4 §7, chaque closure est idempotente
 * (`lockForUpdate`, relecture d'état, `firstOrCreate`) : au second essai, la
 * ligne est déjà dans l'état visé et la closure le détecte.
 *
 * Limite assumée : la reprise ne sert QUE sur contention de verrou. Sur MySQL
 * et PostgreSQL, `lockForUpdate` fait son travail et ces tentatives
 * supplémentaires ne sont jamais consommées — le surcoût est nul.
 */
class TransactionRunner
{
    /**
     * Nombre de tentatives au total. 1 essai + 2 reprises.
     *
     * Suffisant car la cause est transitoire et que `busy_timeout` (5 s par
     * défaut) fait déjà attendre chaque instruction : au second essai, la
     * transaction concurrente est presque toujours terminée.
     */
    private const ATTEMPTS = 3;

    /**
     * @template TReturn
     *
     * @param  Closure(ConnectionInterface): TReturn  $callback
     * @return TReturn
     */
    public function run(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback, self::ATTEMPTS);
        } catch (Throwable $exception) {
            // PRIORITÉ 4 §23 — On ne journalise QUE l'échec définitif, après
            // épuisement des tentatives. C'est le seul événement actionnable :
            // il signifie qu'une transaction concurrente tient le verrou plus
            // longtemps que la fenêtre d'attente, et l'utilisateur reçoit une
            // erreur. Les reprises qui RÉUSSISSENT ne sont pas journalisées,
            // sinon le journal serait noyé sous du bruit normal.
            if ($this->looksLikeContention($exception)) {
                Log::error('Transaction financière abandonnée après contention de verrou.', [
                    'attempts' => self::ATTEMPTS,
                    'driver' => DB::connection()->getDriverName(),
                    'busy_timeout_ms' => config('database.connections.'.DB::connection()->getDriverName().'.busy_timeout'),
                    'message' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Reprend la détection de Laravel (Illuminate\Database\ConcurrencyErrorDetector)
     * sans la dupliquer : une seule source de vérité sur ce qui est « conflit ».
     */
    private function looksLikeContention(Throwable $exception): bool
    {
        return app(ConcurrencyErrorDetectorContract::class)->causedByConcurrencyError($exception);
    }

    /**
     * Expose le nombre de tentatives : permet aux tests de vérifier le
     * contrat sans dupliquer la constante.
     */
    public function attempts(): int
    {
        return self::ATTEMPTS;
    }
}
