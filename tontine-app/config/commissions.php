<?php

return [
    // Taux prélevé sur chaque cotisation payée. 0.03 = 3%. Modifie juste ce chiffre.
    'rate' => (float) env('COMMISSION_RATE', 0.03),
];
