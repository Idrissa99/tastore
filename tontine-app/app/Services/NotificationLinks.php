<?php

namespace App\Services;

/**
 * PRIORITÉ 3 §17 — Liens de notification.
 *
 * Les notifications sont consommées par le SPA React, mais les URLs générées
 * pointaient vers les routes Blade (`/tontines/{id}`, `/mes-cotisations`...).
 * Elles doivent viser l'ORIGINE publique du frontend, et toujours vers une
 * route qui existe réellement côté SPA.
 *
 * Aucun secret, aucun token, et jamais d'identifiant permettant un accès non
 * autorisé : seuls des identifiants de ressource publique et le nom de la
 * ressource sont exposés.
 */
class NotificationLinks
{
    /**
     * @var array<string, string> préfixe de route SPA par type de notification
     */
    private const ROUTES = [
        'tontine' => '/tontines/',
        'contributions' => '/mes-cotisations',
        'installment-purchases' => '/mes-achats',
        'notifications' => '/notifications',
        'profile' => '/profil',
    ];

    public static function tontine(int $tontineId): string
    {
        return self::url(self::ROUTES['tontine'].$tontineId);
    }

    public static function contributions(): string
    {
        return self::url(self::ROUTES['contributions']);
    }

    public static function installmentPurchases(): string
    {
        return self::url(self::ROUTES['installment-purchases']);
    }

    public static function installmentPurchase(int $purchaseId): string
    {
        return self::url(self::ROUTES['installment-purchases'].'/'.$purchaseId);
    }

    public static function profile(): string
    {
        return self::url(self::ROUTES['profile']);
    }

    public static function notifications(): string
    {
        return self::url(self::ROUTES['notifications']);
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
