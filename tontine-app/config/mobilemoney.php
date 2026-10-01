<?php

return [
    // secret partagé utilisé pour vérifier l'authenticité des webhooks entrants
    'webhook_secret' => env('MOBILEMONEY_WEBHOOK_SECRET'),
];
