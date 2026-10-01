<?php

namespace App\Services;

use App\Models\TontineMember;
use Illuminate\Support\Collection;

/**
 * PRIORITÉ 2 — Tirage de l'ordre de passage, au lancement d'une tontine.
 *
 * QUAND, ET POURQUOI CE TIRAGE EST PLACÉ ICI
 *
 * Une tontine ne démarre qu'une fois pleine : la transition `open` -> `active`
 * est faite par TontineService::activateIfFull(), appelée au dernier
 * insucription. C'est donc à cet instant, et à cet instant seulement, que
 * l'ordre de passage est tiré — jamais avant, jamais après.
 *
 * Cela règle du même coup la fuite d'information : tant que la tontine est
 * ouverte, `tontine_members.position` ne porte que l'ordre d'ARRIVÉE. Cet
 * ordre d'arrivée est réattribué en ordre de PASSAGE au moment du tirage.
 * Personne ne peut donc anticiper son tour, et l'ordre définitif n'est connu
 * que de ceux qui sont déjà tous entrés.
 *
 * LE TIRAGE EST PONDÉRÉ PAR L'ORDRE D'ARRIVÉE
 *
 * Un tirage uniforme traiterait le premier arrivé exactement comme le
 * dernier : c'est aléatoire, mais ça ne récompense pas ceux qui se sont
 * engagés tôt, et ça les punit doublement. Le poids est donc décroissant en
 * fonction du rang d'arrivée — le premier arrivé pèse le plus, le dernier
 * pèse le moins — SANS qu'aucun membre ne soit garanti une place. C'est la
 * différence entre « plutôt favorable au premier arrivé » et « premier
 * arrivé, premier servi » : ce dernier n'est pas aléatoire, donc écarté.
 *
 * TIRAGE SANS REMISE
 *
 * On part du lot complet, on tire un membre au hasard selon son poids, on le
 * retire, on recommence. Le résultat est donc une PERMUTATION de 1..N : pas
 * de position en double, pas de trou, pas de position orpheline. C'est un
 * choix de conception, pas un détail — l'index unique
 * `tontine_members (tontine_id, position)` refuserait toute autre sortie.
 */
class RotationDraw
{
    /**
     * @param  Collection<int, TontineMember>  $members  membres triés par ordre d'arrivée
     * @param  (callable(int): int)|null  $random  source d'aléa : reçoit un entier `n`, doit renvoyer un entier dans [0, n[
     * @return array<int, int> identifiant de membre => position (1..N, sans doublon)
     */
    public function draw(Collection $members, ?callable $random = null): array
    {
        $random ??= static fn (int $bound): int => random_int(0, $bound - 1);

        $pool = $members->values()->all();
        $order = [];
        $position = 1;

        while ($pool) {
            $index = $this->pickIndex($pool, $random);
            [$member] = array_splice($pool, $index, 1);
            $order[(int) $member->id] = $position++;
        }

        return $order;
    }

    /**
     * Tirage pondéré d'un index dans le lot restant.
     *
     * Le poids du membre de rang `i` dans le lot est `count($pool) - i` :
     * il décroît donc strictement avec l'ordre d'arrivée, et l'écart entre
     * deux arrivals successives est constant. Tirer un entier dans
     * [0, poids_total[ puis le parcourir revient exactement à un tirage
     * proportionnel au poids, sans flottants et sans biais d'arrondi.
     *
     * @param  array<int, TontineMember>  $pool
     * @param  callable(int): int  $random
     */
    private function pickIndex(array $pool, callable $random): int
    {
        $remaining = count($pool);
        $weights = [];
        $total = 0;

        foreach (array_keys($pool) as $index) {
            $weight = $remaining - $index;
            $weights[$index] = $weight;
            $total += $weight;
        }

        $ticket = $random($total);

        foreach ($weights as $index => $weight) {
            if (($ticket -= $weight) < 0) {
                return $index;
            }
        }

        // Inatteignable : la somme des poids vaut exactement `$total`, donc un
        // ticket dans [0, $total[ tombe toujours dans un segment.
        return (int) array_key_last($weights);
    }
}
