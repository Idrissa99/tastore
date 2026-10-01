<?php

namespace Tests\Unit;

use App\Models\TontineMember;
use App\Services\RotationDraw;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * PRIORITÉ 2 — Le tirage de l'ordre de passage.
 *
 * Ces tests sont volontairement sans base de données : ils vérifient la
 * PROPRIÉTÉ du tirage, pas son intégration (voir RotationOrderTest pour la
 * chaîne complète jusqu'à l'API).
 */
class RotationDrawTest extends TestCase
{
    /**
     * Membres factices : RotationDraw ne lit que `id`, et l'ordre du tableau
     * porte l'ordre d'arrivée.
     *
     * `id` n'est pas dans `$fillable` (c'est la clé primaire) : il faut passer
     * par setAttribute, sinon les 12 membres se retrouveraient tous à l'id 0.
     *
     * @return Collection<int, TontineMember>
     */
    private function members(int $count)
    {
        return collect(range(1, $count))->map(function (int $id) {
            $member = new TontineMember;
            $member->setAttribute('id', $id);

            return $member;
        });
    }

    public function test_it_always_returns_a_permutation_without_gap(): void
    {
        // RNG arbitraire : le point à vérifier est la STRUCTURE de la sortie,
        // pas le hasard.
        $random = fn (int $bound) => 0;

        foreach ([1, 2, 3, 5, 8, 12] as $size) {
            $order = (new RotationDraw)->draw($this->members($size), $random);

            $this->assertCount($size, $order, "Un tirage de {$size} membres doit affecter {$size} positions.");
            $this->assertSame(
                range(1, $size),
                array_values(array_unique(array_values($order))),
                'Les positions doivent être 1..N, sans doublon et sans trou.',
            );
            $this->assertEqualsCanonicalizing(
                range(1, $size),
                array_keys($order),
                'Chaque membre doit recevoir exactement une position.',
            );
        }
    }

    public function test_the_first_joiner_never_loses_every_draw(): void
    {
        $draw = new RotationDraw;

        // Le premier arrivé est le plus lourd : il ne peut pas être écarté.
        $lastTicketEveryTime = fn (int $bound) => $bound - 1;
        $firstTicketEveryTime = fn (int $bound) => 0;

        // Ticket minimal : le poids le plus élevé gagne -> le premier arrivé.
        $this->assertSame(
            [1 => 1, 2 => 2, 3 => 3],
            $draw->draw($this->members(3), $firstTicketEveryTime),
        );

        // Ticket maximal : le poids le plus faible gagne -> le dernier arrivé.
        $this->assertSame(
            [3 => 1, 2 => 2, 1 => 3],
            $draw->draw($this->members(3), $lastTicketEveryTime),
        );
    }

    /**
     * Le cœur du contrat : « aléatoire MAIS prioritaire aux premiers ».
     * Un tirage uniforme donnerait 1 chance sur 3 au premier arrivé ; ici il
     * doit être nettement plus souvent servi, tout en restant loin d'être
     * garanti.
     */
    public function test_earliest_joiners_are_favoured_without_being_guaranteed(): void
    {
        $draw = new RotationDraw;
        $runs = 20000;
        $firstWins = 0;
        $lastWins = 0;

        for ($run = 0; $run < $runs; $run++) {
            $order = $draw->draw($this->members(3));

            if (array_search(1, $order, true) === 1) {
                $firstWins++;
            }

            if (array_search(3, $order, true) === 1) {
                $lastWins++;
            }
        }

        // Uniforme = 1/3 chacun, soit ~6667 sur 20000.
        $this->assertGreaterThan(
            $runs * 0.4,
            $firstWins,
            'Le premier arrivé doit être nettement avantagé par rapport à un tirage uniforme.',
        );
        $this->assertLessThan(
            $runs * 0.95,
            $firstWins,
            'Mais il ne doit JAMAIS être garanti au round 1 : le tirage doit rester aléatoire.',
        );

        $this->assertLessThan(
            $firstWins,
            $lastWins,
            'Le dernier arrivé doit être moins souvent servi en premier que le premier arrivé.',
        );
    }
}
