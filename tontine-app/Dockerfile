# syntax=docker/dockerfile:1
#
# TAStore — API Laravel 13 / PHP 8.3
#
# POURQUOI UN Dockerfile ? Render ne propose pas de runtime PHP natif
# (doc officielle : « Render doesn't provide a native runtime for your
# application's language (e.g., PHP) »). Le seul moyen de déployer Laravel
# sur Render est donc une image Docker.
#
# Choix techniques :
#  - php:8.3-fpm-alpine + nginx dans le même conteneur : deux processus
#    légers qui tiennent dans les 512 Mo de l'offre gratuite, sans
#    superviseur externe.
#  - Composer est exécuté À LA CONSTRUCTION, pas au démarrage : le démarrage
#    ne dépend donc pas du réseau pour monter l'application, et chaque
#    déploiement est reproductible (composer.lock est respecté).
#  - Aucun asset Vite n'est construit ici : le SPA est compilé par Vercel,
#    l'API ne sert que du JSON et la page Blade d'accueil n'est pas routée.
#    `welcome.blade.php` (seul fichier utilisant @vite) n'est atteignable
#    par aucune route.

FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts : `package:discover` a besoin d'un .env, qui n'existe pas
# encore au moment du build. Il est relancé au démarrage par docker/start.sh.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

# --no-autoloader ci-dessus NE PRODUIT PAS vendor/autoload.php : l'étape finale
# ne faisait que le copier, et le conteneur échouait sur
# « Failed opening required '/var/www/html/vendor/autoload.php' ». Le
# générateur est ici, entre le dépot des paquets et leur copie dans l'image.
# --no-scripts est indispensable comme sur l'install ci-dessus : le script
# `post-autoload-dump` lance `artisan package:discover`, et cette étape ne
# contient ni artisan ni le code applicatif (« Could not open input file:
# artisan »). La découverte des paquets est refaite par docker/start.sh.
RUN composer dump-autoload --no-dev --optimize --no-interaction --no-scripts

FROM php:8.3-fpm-alpine

# pdo_mysql   : base MySQL de production (cf. render.yaml)
# opcache     : cache d'opcodes, indispensable sur un service qui redémarre
# bcmath      : arithmétique des montants
#
# Trois extensions ont été retirées du conteneur, aucune n'est utilisée :
#  - `intl` et `pdo_sqlite` : l'appli n'appelle aucune API dépendant d'intl
#    (zéro `isoFormat`, `NumberFormatter`, `IntlDateFormatter`, `Number::`,
#    `->locale()`), et le service ne fait QUE du MySQL (`.dockerignore` exclut
#    *.sqlite). Elles sont en outre fragiles à compiler quand Alpine glisse :
#    `intl` casse avec les nouvelles versions d'ICU, `pdo_sqlite` exige
#    `sqlite-dev`, absent de la liste d'apk (c'est ce qui a fait échouer le
#    tout premier build : « Package 'sqlite3' not found »).
#  - `zip` : aucun `ZipArchive` dans app/, routes/ ou config/ — les seules
#    occurrences de « archive » sont le statut `archived` d'un produit. La
#    garder coûtait `libzip-dev` au build ET `libzip` à l'exécution :
#    `apk del .build-deps` emportait la lib runtime (soname libzip.so.5, paquet
#    `libzip` du dépôt community) et PHP journalisait « Unable to load dynamic
#    library 'zip' » à chaque appel, y compris sur `artisan`.
#
# Chaque étape a son propre RUN : avec un seul `RUN set -eux`, un échec de
# `docker-php-ext-install` remonte `exit code: 1` sans dire quelle extension
# a cassé — le log ne montrait que la dernière ligne.
RUN apk add --no-cache nginx curl \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS mysql-dev

RUN docker-php-ext-install -j"$(nproc)" \
        opcache pdo_mysql bcmath

RUN apk del --no-network .build-deps

WORKDIR /var/www/html

# Code applicatif.
COPY . .

# Autoloader optimisé : `composer dump-autoload` a tourné dans l'étape
# `vendor`, ce que `--no-autoloader` avait laissé de côté.
COPY --from=vendor /app/vendor/ ./vendor/

# Configuration applicative.
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-tastore.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-tastore.ini
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Permissions d'écriture pour les répertoires que Laravel doit pouvoir
# remplir. Le conteneur tourne en root : les fichiers restent lisibles par
# le worker nginx/php-fpm, qui tourne en www-data.
RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

# Sonde de vivacité : /up ne teste que le processus PHP, ce qui évite à
# l'orchestrateur de tuer le conteneur pendant un redémarrage de la base.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT:-8000}/up" || exit 1

CMD ["/usr/local/bin/start.sh"]
