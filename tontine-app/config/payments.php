<?php

use App\Services\Payments\FakeMobileMoneyGateway;

return [
    'currency' => env('PAYMENT_CURRENCY', 'XOF'),

    'default_provider' => env('PAYMENT_PROVIDER', 'fake'),

    'providers' => [
        'fake' => FakeMobileMoneyGateway::class,
    ],

    'channel_providers' => [
        'orange_money' => 'fake',
        'airtel_money' => 'fake',
        'moov_money' => 'fake',
        'mynita' => 'fake',
        'amana' => 'fake',
        'card' => 'fake',
        'deposit_code' => 'fake',
        'bank' => 'fake',
    ],

    'webhook_tolerance' => (int) env('PAYMENT_WEBHOOK_TOLERANCE', 300),

    'allow_fake_in_production' => (bool) env('PAYMENT_ALLOW_FAKE_IN_PRODUCTION', false),

    'channels' => [
        'orange_money' => 'Orange Money',
        'airtel_money' => 'Airtel Money',
        'moov_money' => 'Moov Money',
        'mynita' => 'MyNita',
        'amana' => 'Amana',
        'card' => 'Carte bancaire (Visa / Mastercard)',
        'deposit_code' => 'Code de dépôt en agence',
        'bank' => 'Virement bancaire',
    ],

    'manual_verification_channels' => ['mynita', 'amana'],
];
