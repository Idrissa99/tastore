<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\TontineService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tontineService = app(TontineService::class);

        // --- Admin ---
        User::factory()->admin()->create([
            'name' => 'Admin Tontine',
            'email' => 'admin@tontine.test',
            'phone' => '90000000',
        ]);

        // --- 3 commerçants, chacun avec quelques produits ---
        $merchants = Merchant::factory(3)->create();

        $products = collect();
        foreach ($merchants as $merchant) {
            $products = $products->merge(
                Product::factory(3)->create(['merchant_id' => $merchant->id])
            );
        }

        // --- 10 clients ---
        $clients = User::factory(10)->create();

        // --- Une tontine OUVERTE (pas encore pleine) : pour tester "rejoindre" ---
        $openTontine = Tontine::factory()->create([
            'product_id' => $products->first()->id,
            'created_by' => $clients->first()->id,
            'max_members' => 5,
        ]);
        TontineMember::create([
            'tontine_id' => $openTontine->id,
            'user_id' => $clients->first()->id,
            'position' => 1,
            'status' => 'active',
        ]);

        // --- Une tontine COMPLÈTE et ACTIVE, avec un round déjà en cours ---
        $activeTontine = Tontine::factory()->create([
            'product_id' => $products->skip(1)->first()->id,
            'created_by' => $clients[1]->id,
            'max_members' => 4,
        ]);

        foreach ($clients->slice(1, 4)->values() as $i => $client) {
            TontineMember::create([
                'tontine_id' => $activeTontine->id,
                'user_id' => $client->id,
                'position' => $i + 1,
                'status' => 'active',
            ]);
        }

        // active la tontine + désigne le 1er bénéficiaire + génère le round 1
        $tontineService->activateIfFull($activeTontine->fresh());

        // simule le paiement de la moitié des cotisations du round 1
        $firstRoundContributions = \App\Models\Contribution::whereHas(
            'tontineMember',
            fn ($q) => $q->where('tontine_id', $activeTontine->id)
        )->where('round', 1)->get();

        foreach ($firstRoundContributions->take(2) as $contribution) {
            $tontineService->recordPayment($contribution, 'DEMO-' . $contribution->id);
        }

        $this->command->info('Seed terminé : 1 tontine ouverte, 1 tontine active avec round en cours (2/4 cotisations payées).');
        $this->command->info('Connexion admin : admin@tontine.test / password');
    }
}