<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sauvegardes
    |--------------------------------------------------------------------------
    |
    | Répertoire des sauvegardes, relatif à la racine du projet. Volontairement
    | HORS de `public/` et de `storage/app/public` : ce dernier est servi en
    | HTTP via le lien `public/storage`, une sauvegarde y serait téléchargeable
    | par n'importe quel visiteur.
    |
    | `storage/app/backups` est le défaut. Ne jamais pointer ce réglage sur un
    | dossier exposé par le serveur web : DatabaseBackupService refuse de le
    | faire au moment de l'écriture.
    |
    */

    'directory' => env('BACKUP_DIRECTORY', 'storage/app/backups'),

    /*
    |--------------------------------------------------------------------------
    | Conservation
    |--------------------------------------------------------------------------
    |
    | Nombre de sauvegardes conservées et âge maximal, en jours. 0 = illimité.
    | La purge est une commande EXPLICITE (`app:prune-backups`), jamais
    | automatique : une suppression implicite de sauvegardes est un risque
    | opérationnel inacceptable.
    |
    */

    'retain' => (int) env('BACKUP_RETAIN', 14),

    'retain_days' => (int) env('BACKUP_RETAIN_DAYS', 30),

];
