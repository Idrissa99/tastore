<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\WebhookRequest;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected PaymentService $paymentService,
    ) {}

    public function initiate(Request $request, Contribution $contribution)
    {
        $this->authorize('initiate', $contribution);

        $validated = $request->validate([
            'payment_method' => ['nullable', 'in:'.implode(',', array_keys(config('payments.channels')))],
        ]);

        $channel = (string) ($validated['payment_method'] ?? $contribution->payment_method ?? '');

        if (! array_key_exists($channel, config('payments.channels', []))) {
            $channel = 'orange_money';
        }

        $result = $this->paymentService->initiateContribution($contribution, $channel);

        return response()->json([
            'reference' => $result->externalReference,
            'payment_url' => $result->paymentUrl,
            'status' => $result->status,
        ]);
    }

    /**
     * Webhook opérateur : aucune authentification utilisateur, uniquement la
     * signature HMAC + la fenêtre de timestamp vérifiées par le gateway
     * (voir FakeMobileMoneyGateway::assertValidSignature). Le rate limiting est
     * posé sur la route. Le montant et la devise sont revérifiés côté serveur
     * par TontineService::recordPayment avant tout marquage "completed".
     */
    public function webhook(Request $request)
    {
        $webhook = new WebhookRequest(
            payload: $request->all(),
            signature: $request->header('X-Webhook-Signature'),
            rawBody: $request->getContent(),
            timestamp: $request->header('X-Webhook-Timestamp'),
            eventId: $request->header('X-Webhook-Event'),
        );

        // PRIORITÉ 4 §23 — Un webhook rejeté est INVISIBLE pour l'exploitant :
        // l'opérateur voit ses appels renvoyer 401/503 et ne sait pas pourquoi.
        // On journalise donc le motif du rejet, jamais le corps de la requête
        // (qui contient montant, référence et code de transfert : la rédaction
        // de la Priorité 3 couvre ces clés, mais inutile de les exposer ici).
        try {
            $result = $this->gateways->default()->handleWebhook($webhook);
            $record = $this->paymentService->processWebhook($result);
        } catch (PaymentException $exception) {
            Log::warning('Webhook de paiement rejeté.', [
                'reason' => $exception->getMessage(),
                'status' => $exception->statusCode,
                'event_id' => $webhook->eventId,
                'provider' => $this->gateways->default()->provider(),
            ]);

            throw $exception;
        }

        return response()->json([
            'message' => 'ok',
            'status' => $result->status,
            'type' => $record instanceof Contribution ? 'contribution' : 'installment',
        ]);
    }
}
