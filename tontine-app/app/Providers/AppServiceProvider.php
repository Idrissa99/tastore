<?php

namespace App\Providers;

use App\Models\Contribution;
use App\Models\Installment;
use App\Models\InstallmentPurchase;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Policies\ContributionPolicy;
use App\Policies\InstallmentPolicy;
use App\Policies\InstallmentPurchasePolicy;
use App\Policies\ProductPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\LogRecord;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Clés de contexte jamais écrites en clair dans les logs.
     * Un processor Monolog s'exécute AVANT l'écriture : contrairement à un
     * listener sur MessageLogged, la rédaction est donc réellement effective.
     */
    private const REDACTED_KEYS = [
        'password',
        'current_password',
        'new_password',
        'password_confirmation',
        'token',
        'access_token',
        'plain_text_token',
        'plainTextToken',
        'authorization',
        'bearer',
        'transfer_code',
        'payment_reference',
        'webhook_secret',
        'mobilemoney_webhook_secret',
        'secret',
        'api_key',
        'remember_token',
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->guardCorsConfiguration();
        $this->redactSensitiveLogContext();
        $this->configurePasswordResetUrls();
        $this->warnAboutInsecureProductionDefaults();
    }

    /**
     * Les règles d'appartenance les plus répétées (cotisation, tranche, produit)
     * vivent dans des Policies plutôt que d'être recopiées dans chaque
     * contrôleur — une seule source de vérité pour l'ownership.
     */
    private function registerPolicies(): void
    {
        Gate::policy(Contribution::class, ContributionPolicy::class);
        Gate::policy(Installment::class, InstallmentPolicy::class);
        Gate::policy(InstallmentPurchase::class, InstallmentPurchasePolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductMedia::class, ProductPolicy::class);
    }

    /**
     * Un CORS en "*" exposerait l'API à n'importe quel site. On refuse de
     * démarrer plutôt que d'exposer l'API.
     */
    private function guardCorsConfiguration(): void
    {
        $cors = config('cors');

        $isWildcard = in_array('*', $cors['allowed_origins'] ?? [], true)
            || ($cors['allowed_origins_patterns'] ?? []) !== [];

        if (app()->environment('production') && $isWildcard) {
            throw new \LogicException(
                'CORS interdit en production : FRONTEND_URL / FRONTEND_URLS doivent lister '
                .'explicitement les origines autorisées (jamais "*").'
            );
        }

        if (app()->environment('production') && empty($cors['allowed_origins'])) {
            throw new \LogicException(
                'CORS obligatoire en production : définissez FRONTEND_URL dans le .env.'
            );
        }
    }

    /**
     * Empêche qu'un mot de passe, un token Bearer ou un code de transfert
     * MyNita/Amana atterrisse dans un fichier de log, y compris via une
     * future ligne Log::debug($request->all()).
     *
     * Un processor Monolog s'exécute AVANT l'écriture du record : contrairement
     * à un listener sur MessageLogged (émis après coup), la rédaction est donc
     * réellement effective. LogRecord étant immuable, on renvoie `with(...)`.
     *
     * Portée : le logger du canal par défaut (celui configuré par LOG_CHANNEL,
     * ici `stack`). Si un jour un autre canal est ajouté au premier plan, le
     * processor doit y être poussé aussi — voir config/logging.php.
     */
    private function redactSensitiveLogContext(): void
    {
        Log::getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                context: $this->redactArray((array) $record->context),
                extra: $this->redactArray((array) $record->extra),
            );
        });

        // Filet de sécurité pour les canaux secondaires : l'événement distribué
        // aux listeners est nettoyé même si le canal n'a pas le processor.
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $event->context = $this->redactArray((array) $event->context);
        });
    }

    private function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $data[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactArray($value);
            }
        }

        return $data;
    }

    /**
     * Les emails de réinitialisation de mot de passe pointent vers le frontend
     * React (pas la page Blade) — configure FRONTEND_URL dans .env.
     * Le token reste celui généré par Laravel : à usage unique et expirant.
     */
    private function configurePasswordResetUrls(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reinitialiser-mot-de-passe/'.$token
                .'?email='.urlencode($notifiable->getEmailForPasswordReset());
        });
    }

    /**
     * Signale loudly les réglages qui exposeraient l'application en production
     * plutôt que de planter : une stack trace vaut mieux qu'un site indisponible.
     *
     * Blocages durs (impossibles de continuer en sécurité) : CORS.
     * Avertissements critiques journalisés : debug, expiration des tokens,
     * secret webhook, journalisation sans rotation.
     */
    private function warnAboutInsecureProductionDefaults(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (config('app.debug')) {
            Log::critical('APP_DEBUG=true en production : les stack traces, l\'arborescence et l\'environnement sont exposes aux clients. Mettez APP_DEBUG=false.');
        }

        if (! config('sanctum.expiration')) {
            Log::critical('SANCTUM_TOKEN_EXPIRATION desactive : les tokens API n\'expirent jamais.');
        }

        if (! config('mobilemoney.webhook_secret')) {
            Log::critical('MOBILEMONEY_WEBHOOK_SECRET absent : tous les webhooks seront rejetés (503).');
        }

        // PRIORITÉ 4 §19 : le canal `single` écrit un seul fichier sans limite.
        $channels = (array) config('logging.channels.stack.channels', []);

        if (in_array('single', $channels, true) && ! in_array('daily', $channels, true)) {
            Log::critical('LOG_STACK=single : le fichier de log grossit sans limite et va remplir le disque. Passez LOG_STACK=daily.');
        }

        if (in_array('database', [(string) config('cache.default')], true)
            && in_array('database', [(string) config('session.driver')], true)
            && config('database.default') !== 'sqlite') {
            Log::warning('Cache et session partagent la base metier : chaque lecture de page ecrit une ligne. Separez-les (Redis) avant de monter en charge.');
        }
    }
}
