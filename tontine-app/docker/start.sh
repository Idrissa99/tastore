#!/bin/sh
#
# TAStore — démarrage du conteneur sur Render.
#
# Ordre des opérations, et pourquoi :
#  1. port nginx-aligné sur $PORT  : Render fournit ce port, il ne vaut PAS
#     8000 par défaut. L'application doit écouter exactement là où le proxy
#     envoie le trafic, sinon 502.
#  2. .env                       : Render injecte les variables dans
#     l'environnement du processus, pas dans un fichier. On le crée donc à
#     partir d'elles. Le fichier est un conteneur, PAS une source de vérité.
#  3. package:discover + caches  : `composer install --no-scripts` au build
#     n'a pas pu découvrir les paquets (pas de .env). Le faire ici, une fois
#     l'environnement disponible.
#  4. storage:link               : le lien `public/storage` est créé au
#     démarrage, car il ne peut pas l'être pendant le build.
#  5. migrate --force            : MIGRATIONS NORMALES, jamais
#     `migrate:fresh`. Le schéma est créé s'il est vide ; les données
#     existantes sont conservées.
#  6. seed si base vide          : sans cela, une base neuve n'a aucun
#     compte et les espaces admin / commerçant sont inutilisables. Le seed
#     est idempotent : il ne s'exécute que sur une base sans utilisateur.
#  7. nginx + php-fpm            : les deux restent au premier plan du
#     conteneur, sinon Render croit que le service est mort.

set -eu

APP_DIR=/var/www/html
PORT="${PORT:-8000}"

cd "$APP_DIR"

echo "==> Port d'écoute : ${PORT}"

# --- 1. nginx sur le port de Render ---------------------------------------
# La configuration est statique ; on y injecte le port réel.
sed -i "s/^\( *\)listen 8000;/\1listen ${PORT};/" /etc/nginx/http.d/default.conf
sed -i "s/^\( *\)listen \[::\]:8000;/\1listen [::]:${PORT};/" /etc/nginx/http.d/default.conf
grep -q "listen ${PORT};" /etc/nginx/http.d/default.conf || {
    echo "ERREUR : le port ${PORT} n'a pas pu être injecté dans nginx." >&2
    exit 1
}

# --- 2. .env à partir de l'environnement du processus ----------------------
# Écriture minimale : on ne recopie que ce qui est défini, et on n'affiche
# AUCUNE valeur (les logs de Render sont publics pour un service public).
write_env() {
    name="$1"
    value="$(printenv "$name" || true)"
    [ -n "$value" ] || return 0
    if grep -q "^${name}=" .env 2>/dev/null; then
        # sed avec un séparateur rare, et échappement des caractères / & |
        escaped=$(printf '%s' "$value" | sed -e 's/[&|\\]/\\&/g')
        sed -i "s|^${name}=.*|${name}=${escaped}|" .env
    else
        printf '%s=%s\n' "$name" "$value" >> .env
    fi
}

if [ ! -f .env ]; then
    touch .env
fi

# APP_KEY est prioritaire : sans elle, le chiffrement des sessions et des
# jetons Sanctum est impossible et l'application refuse de démarrer.
if [ -n "${APP_KEY:-}" ]; then
    write_env APP_KEY
elif ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "ERREUR : APP_KEY est absente de l'environnement du service." >&2
    echo "        Génère-la en local avec : php artisan key:generate --show" >&2
    exit 1
fi

for name in \
    APP_ENV APP_DEBUG APP_NAME APP_URL \
    LOG_CHANNEL LOG_STACK LOG_LEVEL LOG_DAILY_DAYS \
    DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
    CACHE_STORE SESSION_DRIVER QUEUE_CONNECTION \
    SESSION_LIFETIME SESSION_REVOKE_OTHERS_ON_PASSWORD_CHANGE \
    FILESYSTEM_DISK TRUSTED_PROXIES \
    FRONTEND_URL FRONTEND_URLS CORS_MAX_AGE \
    SANCTUM_TOKEN_EXPIRATION SANCTUM_TOKEN_NAME SANCTUM_STATEFUL_DOMAINS \
    MAIL_MAILER MAIL_FROM_ADDRESS MAIL_FROM_NAME \
    MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION MAIL_SCHEME \
    MOBILEMONEY_WEBHOOK_SECRET \
    BACKUP_DIRECTORY BACKUP_RETAIN BACKUP_RETAIN_DAYS \
    COMMISSION_RATE \
    PAYMENT_PROVIDER PAYMENT_ALLOW_FAKE_IN_PRODUCTION PAYMENT_CURRENCY
do
    write_env "$name"
done

# --- 2 bis. Variables sans lesquelles le BOOT échoue ------------------------
# `write_env` ignore les valeurs vides : une variable absente de Render
# disparaît donc du .env sans bruit. Or AppServiceProvider lève une
# LogicException si la liste d'origines CORS est vide en production, et ce
# throw part du boot : CHAQUE requête répond 500, y compris /up, donc Render
# tue le deploy au bout de son délai et ne dit jamais pourquoi. On vérifie
# avant de lancer quoi que ce soit, pour obtenir un message lisible.
if grep -q '^APP_ENV=production' .env && ! grep -qE '^FRONTEND_URL=.+' .env; then
    echo "ERREUR : FRONTEND_URL est absente ou vide dans l'environnement." >&2
    echo "        Renseigne-la dans Render > Environment (c'est l'origine du SPA)," >&2
    echo "        puis Save & Deploy. Sans elle, l'application refuse de démarrer :" >&2
    echo "        « CORS obligatoire en production »." >&2
    exit 1
fi

# --- 3. Paquets et caches -------------------------------------------------
echo "==> Découverte des paquets"
# --force est INTERDIT ici : `package:discover` ne déclare aucune option
# (`protected $signature = 'package:discover'`), et l'option `--force`
# n'existe que sur storage:link, migrate et db:seed. Le fichier s'arrêtait
# sur « The "--force" option does not exist. » avant même les migrations.
php artisan package:discover --ansi

echo "==> Cache de la configuration"
php artisan config:cache --ansi

echo "==> Cache des routes"
php artisan route:cache --ansi

# --- 4. Lien de stockage public -------------------------------------------
# Nécessaire pour que /storage/... soit servi. --force car le lien peut
# avoir survécu à un redéploiement sur un disque persistant.
php artisan storage:link --force >/dev/null 2>&1 || true

# --- 5. Migrations --------------------------------------------------------
# `migrate:fresh` est INTERDIT ici : il supprimerait toutes les données.
# `--force` est requis car APP_ENV=production refuse l'invite de confirmation.
echo "==> Migrations"
php artisan migrate --force --ansi

# --- 6. Jeu de démonstration, une seule fois -------------------------------
# Sur une base NEUVE, l'application n'aurait AUCUN compte : ni
# administrateur, ni commerçant. Les espaces correspondants seraient donc
# impossibles à tester — et Render en offre gratuit n'a AUCUN shell : on ne
# peut pas créer ces comptes à la main après coup.
#
# Le remplissage est conditionné à une base VIDE. C'est indispensable ici :
# ce script s'exécute à CHAQUE démarrage, et un service gratuit se réveille
# après 15 minutes d'inactivité. Sans ce garde-fou, le second démarrage
# rechargerait le jeu par-dessus les données existantes et échouerait sur la
# clé unique de l'admin.
#
# En cas d'échec, `tinker` ne renvoie pas de nombre : on saute alors le seed
# au lieu de faire planter le conteneur (la mise en veille du service vaut
# mieux qu'une boucle de redémarrages).
echo "==> Vérification du jeu de données"
USER_COUNT="$(php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null \
    | grep -oE '[0-9]+' | tail -n 1)"

if [ "${USER_COUNT:-}" = "0" ]; then
    echo "==> Base vide : insertion du jeu de démonstration"
    php artisan db:seed --force --ansi
else
    echo "==> Base déjà peuplée : aucun semiage."
fi

# --- 7. Les deux processus, au premier plan -------------------------------
echo "==> Démarrage de PHP-FPM et nginx"
php-fpm --daemonize
nginx -g 'daemon off;' &
NGINX_PID=$!

# --- 8. Auto-diagnostic de la sonde ---------------------------------------
# Render n'affiche que les logs DU conteneur. Or une exception Laravel part
# dans storage/logs/laravel-*.log : sur le disque éphémère de l'offre
# gratuite, sans shell, elle est INACCESSIBLE. Résultat observé : une boucle
# de « 500 » sur /up puis « Timed Out », sans jamais la cause — et l'edge ne
# transmet plus rien, donc impossible de lire la réponse HTTP de l'extérieur.
# On interroge donc la sonde nous-mêmes, puis on recopie le log applicatif.
echo "==> Auto-diagnostic de /up"
PROBE=000
for _ in 1 2 3 4 5; do
    PROBE="$(curl -s -o /tmp/up-probe.body -w '%{http_code}' --max-time 20 \
        "http://127.0.0.1:${PORT}/up" 2>/dev/null || true)"
    [ "$PROBE" = "200" ] && break
    sleep 2
done
echo "    /up -> HTTP ${PROBE:-000}"

if [ "${PROBE:-000}" != "200" ]; then
    echo "    Sonde non verte. Log applicatif :"
    LAST_LOG="$(ls -t storage/logs/laravel-*.log 2>/dev/null | head -n 1 || true)"
    if [ -n "${LAST_LOG:-}" ] && [ -s "$LAST_LOG" ]; then
        tail -n 20 "$LAST_LOG"
    else
        echo "    (pas de storage/logs/laravel-*.log : l'erreur vient de PHP, pas de Laravel)"
    fi
fi

# Si l'un des deux s'arrête, le conteneur doit s'arrêter aussi : Render doit
# le redémarrer proprement plutôt que de laisser un service qui répond à
# moitié.
term_handler() {
    echo "==> Arrêt"
    nginx -s quit 2>/dev/null || true
    # Le chemin du fichier PID suit la valeur `pid` par défaut de PHP-FPM
    # (/usr/local/var/run/php-fpm.pid). Il pointait sur l'ancien socket Unix
    # `php-fpm.pids`, supprimé quand php-fpm est passé en TCP : le signal
    # partait dans le vide et le maître FPM survivait à l'arrêt.
    kill -QUIT "$(cat /usr/local/var/run/php-fpm.pid 2>/dev/null)" 2>/dev/null || true
    wait "$NGINX_PID" 2>/dev/null || true
    exit 0
}
trap term_handler TERM INT

wait -n "$NGINX_PID"
echo "ERREUR : nginx s'est arrêté de façon inattendue." >&2
term_handler
