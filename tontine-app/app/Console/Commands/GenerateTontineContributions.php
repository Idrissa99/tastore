<?php

namespace App\Console\Commands;

use App\Models\Tontine;
use App\Services\TontineService;
use Illuminate\Console\Command;

class GenerateTontineContributions extends Command
{
    protected $signature = 'tontine:generate-contributions';

    protected $description = "Génère les appels de cotisation du round en cours pour toutes les tontines actives (idempotent, à planifier périodiquement)";

    public function handle(TontineService $service): int
    {
        $tontines = Tontine::where('status', 'active')->get();

        foreach ($tontines as $tontine) {
            $service->generateContributionCallForRound($tontine);
        }

        $this->info("Appels de cotisation générés pour {$tontines->count()} tontine(s) active(s).");

        return self::SUCCESS;
    }
}
