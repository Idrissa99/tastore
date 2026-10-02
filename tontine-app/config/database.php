<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),

            /*
             * PRIORITÉ 4 §2/§6 — Concurrence SQLite.
             *
             * Ces trois réglages sont le SEUL vrai levier de concurrence de
             * SQLite, et ils étaient tous à `null` (donc désactivés). Mesuré
             * sur ce projet, deux écritures concurrentes (deux requêtes HTTP
             * qui tombent au même moment, cas prévu au §6) donnaient :
             *
             *     busy_timeout = 0  ->  « database is locked »  en 0.00 s
             *     busy_timeout = 5s ->  écriture réussie 1.58 s plus tard
             *
             * Autrement dit, sans ce réglage l'utilisateur recevait une erreur
             * 500 alors que la base était parfaitement saine et qu'un simple
             * attente de 2 secondes aurait suffi.
             *
             *  - busy_timeout : combien de temps SQLite ATTEND un verrou
             *    occupé au lieu d'échouer immédiatement. 5000 ms absorbe les
             *    transactions financières, qui sont courtes (aucun appel HTTP
             *    externe n'est fait transaction, cf. §22).
             *  - journal_mode = WAL : autorise les lecteurs pendant qu'un
             *    écrivain travaille. Sur le mode `delete` (défaut), un simple
             *    affichage de page bloque l'écriture et inversement ; c'est la
             *    cause n°1 de « database is locked » en lecture.
             *  - synchronous = NORMAL : le compromis recommandé par SQLite
             *    lui-même en mode WAL (durabilité des transactions maintenue,
             *    seule la perte de la DERNIÈRE transaction en cas de coupure
             *    courant est possible). `FULL` reste le choix sûr si l'hébergeur
             *    coupe le courant sans prévenir.
             *
             * Tous sont surchargeables par .env : sur un disque réseau lent, ou
             * si la base est montée en lecture seule, remettez-les à `null`.
             */
            'busy_timeout' => env('DB_SQLITE_BUSY_TIMEOUT', 5000),
            'journal_mode' => env('DB_SQLITE_JOURNAL_MODE', 'WAL'),
            'synchronous' => env('DB_SQLITE_SYNCHRONOUS', 'NORMAL'),

            /*
             * Laisser `transaction_mode` sur le défaut du framework.
             *
             * IMMEDIATE serait théoriquement préférable sur SQLite (le verrou
             * d'écriture est pris dès BEGIN), mais Laravel n'honore ce réglage
             * qu'à partir de PHP 8.4 — le projet tourne en 8.3, où le POSER
             * serait silencieusement sans effet. Le vrai remède au conflit
             * d'écriture est ailleurs : `DB::transaction(..., attempts: 3)`,
             * qui rejoue la transaction entière quand SQLite répond
             * « database is locked ». Voir App\Services\TransactionRunner.
             */
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            // TLS : mysql_ssl_set() n'active le chiffrement que si ATTR_SSL_CA
            // est renseigné, y compris quand la vérification est désactivée.
            // CA fournie -> ssl-mode=VERIFY_CA. CA absente -> ssl-mode=REQUIRED
            // (chiffrement sans vérification) : `/dev/null` n'est qu'un drapeau
            // non vide, jamais lu. `array_filter` est écarté car il supprimerait
            // le `false` et la connexion retomberait en clair.
            'options' => extension_loaded('pdo_mysql') ? (($ca = env('MYSQL_ATTR_SSL_CA')) ? [Mysql::ATTR_SSL_CA => $ca] : [Mysql::ATTR_SSL_CA => '/dev/null', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
