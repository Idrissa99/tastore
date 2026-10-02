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

FROM php:8.3-fpm-alpine

# pdo_mysql   : base MySQL de production (cf. render.yaml)
# zip         : archives de sauvegarde / exports
# opcache     : cache d'opcodes, indispensable sur un service qui redémarre
# bcmath      : arithmétique des montants
#
# `intl` et `pdo_sqlite` ont été retirés du conteneur. Le service ne fait QUE
# du MySQL (`.dockerignore` exclut *.sqlite, DB_CONNECTION=mysql) et l'appli
# n'appelle aucune API dépendant d'intl : zéro `isoFormat`, `NumberFormatter`,
# `IntlDateFormatter`, `Number::` ou `->locale()` dans app/ et resources/.
# Or ces deux extensions sont les plus fragiles à compiler sur une image
# Alpine qui glisse : `intl` casse régulièrement avec les nouvelles versions
# d'ICU, et `pdo_sqlite` exige `sqlite-dev`, qui n'était pas dans la liste
# d'apk ci-dessous. Les réintroduire = réinstaller `icu-dev` / `sqlite-dev`
# et accepter de rebloquer à chaque bump d'Alpine.
#
# Chaque étape a son propre RUN : avec un seul `RUN set -eux`, un échec de
# `docker-php-ext-install` remonte `exit code: 1` sans dire quelle extension
# a cassé — le log ne montre que la dernière ligne.
RUN apk add --no-cache nginx curl \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS mysql-dev libzip-dev

RUN docker-php-ext-install -j"$(nproc)" \
        opcache pdo_mysql zip bcmath

RUN apk del --no-network .build-deps

WORKDIR /var/www/html

# Code applicatif.
COPY . .

# Autoloader optimisé : les dépendances vendor/ ont été installées dans
# l'étape précédente, seul l'autoloader manquait.
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
