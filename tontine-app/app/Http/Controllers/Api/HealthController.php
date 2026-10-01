<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthCheck;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRIORITÉ 4 §14 — Sonde de disponibilité.
 *
 * `/up`       : le processus répond. 200 tant que PHP fonctionne, même si la
 *               base est down — c'est ce qui évite à un orchestrateur de tuer
 *               un conteneur pendant un redémarrage de base.
 * `/up/ready` : l'application est UTILISABLE (base joignable + migrations
 *               appliquées). 503 sinon : c'est celle-ci qu'un load balancer
 *               doit surveiller.
 */
class HealthController extends Controller
{
    public function __construct(protected HealthCheck $health) {}

    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'check' => 'live',
        ]);
    }

    public function ready(): JsonResponse
    {
        $report = $this->health->report();

        return response()->json(
            ['check' => 'ready'] + $report,
            $report['status'] === 'ok' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE
        );
    }
}
