<?php

namespace App\Services\Payments;

use Illuminate\Contracts\Container\Container;

class PaymentGatewayManager
{
    public function __construct(private readonly Container $container) {}

    public function forChannel(string $channel): PaymentGatewayInterface
    {
        $provider = config("payments.channel_providers.{$channel}", config('payments.default_provider'));

        return $this->forProvider($provider);
    }

    public function default(): PaymentGatewayInterface
    {
        return $this->forProvider(config('payments.default_provider'));
    }

    public function forProvider(?string $provider): PaymentGatewayInterface
    {
        if (! $provider) {
            throw new PaymentException('Aucun fournisseur de paiement configuré.', 503);
        }

        $gatewayClass = config("payments.providers.{$provider}");

        if (! $gatewayClass || ! is_string($gatewayClass)) {
            throw new PaymentException("Fournisseur de paiement inconnu : {$provider}.", 503);
        }

        $gateway = $this->container->make($gatewayClass);

        if (! $gateway instanceof PaymentGatewayInterface) {
            throw new PaymentException("La classe {$gatewayClass} n'implémente pas le contrat de paiement.", 503);
        }

        return $gateway;
    }
}
