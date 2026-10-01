# Déploiement en production

Document de référence pour déployer et exploiter l'application **Tontine d'achat**
(Laravel 13 / PHP 8.3 / React + Vite / Sanctum).

Toutes les commandes ci-dessous sont à exécuter **sur le serveur cible**. Rien
dans ce document n'a été exécuté contre un serveur réel : les procedures ont
été validées en local (SQLite et MySQL 8).

---

## 1. Prérequis serveur

| Composant | Version testée | Remarque |
|---|---|---|
| PHP | 8.3.x | `>= 8.3` (exigé par le `composer.json`) |
| Extensions PHP | `pdo_mysql` **ou** `pdo_sqlite`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `bcmath`, `curl` | `pdo_sqlite` suffit en développement |
| Composer | 2.x | |
| Node.js | `^20.19` ou `>=22.12` | **uniquement au build**, pas à l'exécution |
| Serveur web | Nginx ou Apache | voir §12 (HTTPS) |
| Base de données | SQLite, MySQL 8 / MariaDB 10.6+, ou PostgreSQL 14+ | voir §2 |
| Gestionnaire de processus | Supervisor / systemd / Docker | pour le worker de file (§11) |

> Le `composer.json` du projet exige PHP `^8.3`. Ne déployez pas sur 8.2.

---

## 2. Choix de la base de données

### Ce que l'audit a mesuré

- Les **36 migrations historiques + les 4 de la Priorité 4** s'exécutent
  **sans erreur sur MySQL 8.0.46 réel** (vérifié : `php artisan migrate` sur une
  base vide, puis `migrate:status` sans ligne « Pending »).
- Une **sonde d'intégrité de 21 cas** (montant négatif, montant nul, commission
  supérieure au montant, doublons, clés étrangères, statuts hors enum) a été
  exécutée sur les deux moteurs :
  - **MySQL 8 : 21/21** conformes après correction.
  - SQLite : **17/21**. Les 4 écarts sont exactement les écritures en SQL brut
    qui contournent le modèle : SQLite ne supporte pas `ADD CONSTRAINT`, donc
    les `CHECK` financiers y sont apposés au niveau modèle uniquement
    (`GuardsFinancialAmounts`), qui couvre toutes les écritures de
    l'application sur tous les moteurs.
- `migrate:rollback --step=1` puis re-application de la migration de la
  Priorité 4 : vérifié sans erreur sur MySQL.
- SQLite ne supporte ni `ALTER TABLE … ADD CONSTRAINT`, ni les index partiels
  reproductibles après reconstruction de table. Les contrôles d'intégrité
  financière les plus forts (montant strictement positif, commission bornée)
  sont donc posés **au niveau modèle** (`GuardsFinancialAmounts`), et **en plus**
  au niveau SQL sur MySQL/PostgreSQL.

### Option A — SQLite (développement, démonstration, petit environnement)

Possible si **toutes** ces conditions sont réunies :

- une seule instance backend (pas de load balancing) ;
- peu d'écritures concurrentes (SQLite sérialise les écritures) ;
- sauvegardes quotidiennes vérifiées (§14) ;
- pas drequirement de disponibilité 99,9 %.

Limites réelles : `database is locked` sous charge (atténué par §2.4, pas
supprimé), un seul écrivain, `lockForUpdate()` qui ne verrouille pas la ligne
mais la base entière.

### Option B — MySQL 8 / MariaDB 10.6+ (**recommandé avant production commerciale**)

Pourquoi MySQL plutôt que PostgreSQL, pour **cette** application :

- le schéma n'utilise aucune fonctionnalité PostgreSQL-specific, et le
  `ENUM` natif de MySQL correspond exactement à la façon dont les migrations
  déclarent les statuts ;
- l'hébergement mutualisé/VPS géré propose quasi systématiquement MySQL ;
- `mysqldump --single-transaction` donne une sauvegarde cohérente simple ;
- les contraintes `CHECK` sont supportées depuis MySQL 8.0.16 (à vérifier sur
  votre version).

Recommandation : **MySQL 8.0.16+**.

**Je ne lance pas la migration de données.** La procédure est en §16.

### 2.3 Garantie « un bénéficiaire par tontine » — désormais identique sur les 3 moteurs

L'invariant « une seule tontine, un seul bénéficiaire actif » (Priorité 2) est
garanti par **deux couches**, identiques sur MySQL, PostgreSQL et SQLite :

| Couche | Rôle | Moteur |
|---|---|---|
| Verrou de ligne `lockForUpdate()` sur `tontines` + garde applicative | Sérialise les désignations concurrentes | tous |
| Contrainte de base de données | Empêche deux bénéficiaires, même en SQL brut | **tous** |

La contrainte de base est posée ainsi :

| Moteur | Mécanisme |
|---|---|
| SQLite, PostgreSQL | index unique **partiel** `UNIQUE (tontine_id) WHERE status = 'beneficiary'` |
| MySQL / MariaDB | colonne **générée VIRTUAL** + index unique (MySQL n'a pas d'index partiel) |

Sur MySQL, la colonne générée ne vaut `tontine_id` que pour les lignes
bénéficiaires, et `NULL` sinon ; or un index `UNIQUE` ignore les `NULL`. Les
deux effets sont donc réunis : un bénéficiaire maximum, et autant de membres
non bénéficiaires que nécessaire.

> **Pourquoi ce n'était pas déjà le cas.** La migration `2026_09_28_000001` ne
> recréait l'index que sur SQLite. Vérifié sur MySQL 8.0.46 avant correction :
> deux membres en statut `beneficiary` pour la même tontine étaient **acceptés
> par la base**. Le code applicatif les empêchait (verrou de ligne), mais la
> garantie n'existait pas au niveau SQL. Corrigé par la migration
> `2026_09_28_000004`, puis re-vérifié : la base refuse désormais le doublon.

> ⚠️ Contrainte de maintenance : toute migration future qui **reconstruit**
> `tontine_members` — donc un `->change()` sur une colonne de cette table, ou un
> `dropColumn` — supprime la contrainte du moteur (index partiel sur SQLite,
> colonne générée sur MySQL). Il faut la recréer ensuite.
> Sur SQLite, `2026_09_28_000001` supprime puis recrée l'index dans le bon
> ordre, sinon la reconstruction le transforme en index simple `UNIQUE(tontine_id)`
> et la migration échoue. **Respectez cet ordre.**

### 2.4 Concurrence SQLite : ce qui est mesuré, pas supposé

Trois réglages de `config/database.php` étaient à `null` (désactivés). Ils
sont désormais activés par défaut, chacun justifié par une mesure :

| Réglage | Avant | Après | Mesure |
|---|---|---|---|
| `busy_timeout` | `null` (échec immédiat) | `5000` ms | 2 écritures concurrentes : échec en **0,00 s** → succès après **1,58 s** d'attente |
| `journal_mode` | `null` (mode `delete`) | `WAL` | en `delete`, une simple lecture bloque l'écriture |
| `synchronous` | `null` | `NORMAL` | compromis recommandé par SQLite en WAL |

`DB_SQLITE_BUSY_TIMEOUT`, `DB_SQLITE_JOURNAL_MODE` et
`DB_SQLITE_SYNCHRONOUS` permettent de les surcharger. Sans `busy_timeout`, le
symptôme utilisateur est une **erreur 500 alors que la base est parfaitement
saine** : c'est le piège le plus fréquent de SQLite en production.

#### La limite que `busy_timeout` ne peut PAS corriger

Une transaction SQLite ouverte en `DEFERRED` prend d'abord un verrou de
**lecture**, et ne réclame le verrou d'**écriture** qu'au premier `UPDATE`. Or
toutes les opérations financières de ce projet sont exactement dans ce cas :

```php
DB::transaction(function () {
    $locked = Contribution::query()->lockForUpdate()->findOrFail($id); // SELECT
    $locked->update([...]);                                            // écriture différée
});
```

Si une autre transaction a écrit entre le `SELECT` et l'`UPDATE`, SQLite ne peut
plus faire évoluer son snapshot et refuse l'écriture. Ce refus est `SQLITE_BUSY`
et **ce n'est pas une attente** : mesuré, `busy_timeout = 5 s` ne change
strictement rien (échec en 0,11 s dans les deux cas).

`BEGIN IMMEDIATE` réglerait la cause, mais Laravel n'honore `transaction_mode`
qu'à partir de **PHP 8.4** ; le projet tourne en **PHP 8.3**, où le réglage
serait silencieusement sans effet. On n'a donc pas contourné le framework : on
utilise son mécanisme prévu. Laravel classe « database is locked » parmi les
**erreurs de concurrence** et rejoue la transaction entière quand on lui donne un
nombre de tentatives. C'est `App\Services\TransactionRunner`, utilisé par tous
les services financiers (paiement, contribution, remboursement, livraison,
tranches, cycle de tontine).

Mesure avant / après, deux processus concurrents, 30 ms de traitement :

| | Résultat |
|---|---|
| `DB::transaction(...)` | **3 essais sur 3** : une requête en erreur 500 |
| `TransactionRunner` (3 tentatives) | **3 essais sur 3** : les deux réussissent, 2 lignes en base (aucune écriture perdue ni dupliquée) |

Rejouer la closure est sûr : elle repart d'une transaction neuve après
`ROLLBACK`, et chaque closure est idempotente par construction (verrou de ligne,
relecture d'état, `firstOrCreate`).

> Sur MySQL et PostgreSQL, `lockForUpdate` fait son travail : ces tentatives
> supplémentaires ne sont jamais consommées, le surcoût est nul.

---

## 3. Mise en place initiale

```bash
# 1. Code
git clone <repo> /var/www/tontine-app && cd /var/www/tontine-app

# 2. Dépendances PHP
composer install --no-dev --optimize-autoloader

# 3. Environnement
cp .env.example .env
php artisan key:generate

# 4. Base de données
#    SQLite : touch database/database.sqlite
#    MySQL  : CREATE DATABASE tontine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#             puis renseigner DB_* dans .env
php artisan migrate --force

# 5. Stockage
php artisan storage:link
mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/backups
chown -R www-data:www-data storage bootstrap/cache

# 6. Build du frontend (le SPA est compilé côté client, pas servi par Laravel)
cd ../tontine-frontend
npm ci
npm run build          # produit dist/
cd ../tontine-app
```

> `public/storage` est un lien symbolique. **Vérifiez qu'il pointe vers le bon
> répertoire sur le serveur** : un lien vers un chemin absolu d'un autre
> serveur cassera tous les fichiers téléversés (avatars, médias produits).

---

## 4. Variables `.env` obligatoires en production

```dotenv
APP_ENV=production
APP_DEBUG=false              # OBLIGATOIRE
APP_KEY=                      # php artisan key:generate
APP_URL=https://api.exemple.com

FRONTEND_URL=https://app.exemple.com     # origine du SPA (CORS + notifications)
FRONTEND_URLS=                             # origines supplémentaires, sinon vide

SANCTUM_TOKEN_EXPIRATION=43200            # 30 jours, en MINUTES
SESSION_REVOKE_OTHERS_ON_PASSWORD_CHANGE=true

MOBILEMONEY_WEBHOOK_SECRET=                # openssl rand -hex 32

TRUSTED_PROXIES=127.0.0.1                  # voir §14
LOG_CHANNEL=stack
LOG_STACK=daily                           # rotation OBLIGATOIRE
LOG_DAILY_DAYS=14
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tontine
DB_USERNAME=tontine
DB_PASSWORD=<secret>
DB_FOREIGN_KEYS=true

BACKUP_DIRECTORY=storage/app/backups
```

**Garde de démarrage** : l'application **refuse de démarrer** en production si
`FRONTEND_URL` est absent ou si le CORS vaut `*`. Elle journalise en `critical`
si `APP_DEBUG=true`, si l'expiration des tokens est désactivée, si le secret
webhook est absent, ou si les logs ne tournent pas. **Surveillez ces lignes.**

### Secrets

- `APP_KEY` : `php artisan key:generate`. Ne le committez jamais.
- `MOBILEMONEY_WEBHOOK_SECRET` : `openssl rand -hex 32`. Sans lui, **tous** les
  webhooks sont rejetés (503).
- `DB_PASSWORD` :Mot de passe dédié, jamais `root`.
- `FRONTEND_URL` : URL **publique**, sans secret. Vite expose les variables
  `VITE_*` dans le bundle : n'y mettez jamais de secret.

---

## 5. Migrations

```bash
php artisan migrate --force
php artisan migrate:status        # aucune ligne « Pending »

# Vérifier que l'infrastructure est prête
curl -fsS https://api.exemple.com/up/ready
# {"check":"ready","status":"ok","checks":{...}}
```

**Sauvegardez AVANT** (§25). Une migration ne doit jamais être appliquée sans
point de restauration.

---

## 6. Cache de configuration

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

**Après toute modification du `.env` :**

```bash
php artisan config:clear && php artisan config:cache
```

Si `route:cache` échoue à cause d'une closure dans une route, retirez-la de la
configuration : une route en closure n'est pas cachable.

---

## 7. Permissions

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
chmod 640 storage/app/backups/*          # sauvegardes lisibles par l'admin seulement
chmod 750 storage/app/backups
```

`storage/logs` et `storage/framework` doivent être accessibles en **écriture**
par l'utilisateur du serveur web.

---

## 8. Points d'entrée web

N'exposez que `public/`. **Ne servedez jamais `storage/`** : il contient les
sauvegardes et les journaux.

Nginx (extrait) :

```nginx
server {
    listen 443 ssl http2;
    server_name api.exemple.com;
    root /var/www/tontine-app/public;
    index index.php;

    # Les webhooks de paiement DOIVENT être exposés en HTTPS.
    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

`public/.htaccess` gère déjà l'en-tête `Authorization` pour Apache.

---

## 9. HTTPS — obligatoire

- **Toute l'application** (API et SPA) doit être en HTTPS.
- Les **webhooks de paiement** exigent HTTPS : les opérateurs mobiles et les
  passerelles refusent le clair, et le HMAC ne protège pas le canal.
- Cookies : `SESSION_SECURE_COOKIE=true` quand `APP_URL` est en HTTPS.
- Activez HSTS après avoir validé que tout le trafic passe bien en HTTPS.
- `FRONTEND_URL` et `APP_URL` doivent être en `https://` : les liens des
  notifications et la génération d'URL en dépendent.

---

## 10. File d'attente

**Aucune opération financière n'est asynchrone.** Vérifié : le projet ne
contient **aucun** job, listener ni événement, et les sept notifications
(`BecameBeneficiaryNotification`, `DeliveryConfirmedNotification`,
`ContributionVerificationUpdatedNotification`, etc.) sont envoyées de façon
synchrone. Paiement, clôture de round, remboursement et livraison sont donc
transactionnels et verrouillés, et la file n'a aucune influence sur l'argent.

```dotenv
QUEUE_CONNECTION=database     # par défaut ; `redis` en multi-instance
DB_QUEUE_RETRY_AFTER=90       # doit rester > au --timeout du worker
```

Configurez tout de même un worker, sinon la table `jobs` grossit sans être
consommée. Tant qu'aucun job n'est créé, l'effet est nul — mais dès la première
notification asynchrone, il le faudra.

```bash
php artisan queue:work --queue=default --tries=3 --backoff=30 --max-time=3600
```

> **`DB_QUEUE_RETRY_AFTER` > `--timeout` du worker.** Si le job est réservé
> plus longtemps que `retry_after`, Laravel le rend à un **second** worker qui
> l'exécute en parallèle. C'est le moyen classique de transformer une
> notification en notification en double.

**Si une notification de paiement devenait asynchrone**, sa logique
d'idempotence doit rester dans le service et la base, jamais dans le job : un
job rejoué ne doit jamais doubler une opération financière. Les services sont
déjà idempotents (verrou de ligne + relecture d'état + `firstOrCreate`), donc
un job rejoué ne doublera rien.

---

## 11. Supervision du worker

Choisissez **un** mécanisme selon votre environment.

### Supervisor (le plus courant sur VPS)

```ini
[program:tontine-worker]
command=php /var/www/tontine-app/artisan queue:work --sleep=3 --tries=3 --backoff=30 --max-time=3600
directory=/var/www/tontine-app
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/tontine-app/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status tontine-worker
```

### systemd

```ini
[Unit]
Description=Tontine queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/var/www/tontine-app
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --backoff=30 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Docker / Kubernetes

- `Dockerfile` / `docker-compose.yml` avec un service `worker` distinct.
- Kubernetes : un `Deployment` avec `livenessProbe: GET /up` et
  `readinessProbe: GET /up/ready`.

**Après chaque déploiement**, redémarrez le worker pour qu'il charge le code
nouveau :

```bash
sudo supervisorctl restart tontine-worker     # ou systemctl restart tontine-queue
```

---

## 12. Jobs échoués

```bash
php artisan queue:failed                    # lister
php artisan queue:failed --details         # avec la trace
php artisan queue:retry <id|all>            # relancer
php artisan queue:forget <id>               # abandonner
```

**Protocole d'intervention**

1. `queue:failed` → identifier le job et la cause.
2. Vérifier que la cause est **corrigée** (code déployé, base accessible).
3. Relancer **un seul job** d'abord, observer le résultat.
4. Ne lancez `queue:flush` que pour purger des jobs **irrattrapables** (après
   avoirarchivé la liste). `flush` est définitif : aucun job n'est rejoué.

`tries=3` et `backoff=30` (30 s, 60 s, 120 s) évitent de marteler un service
dégradé.

---

## 13. Journalisation

```dotenv
LOG_CHANNEL=stack
LOG_STACK=daily            # rotation + purge après LOG_DAILY_DAYS
LOG_DAILY_DAYS=14
LOG_LEVEL=info
```

- **Ne jamais `LOG_STACK=single` en production** : un seul fichier sans limite
  remplit le disque. Sur ce projet, le fichier avait atteint plusieurs Mo.
- Surveillance : alerter sur `storage/logs` > 80 % du disque, et sur toute
  ligne `local.CRITICAL` (le garde de démarrage en émet si la configuration est
  dangereuse).
- Les secrets sont **redactés** avant écriture (processor Monolog) :
  `password`, `token`, `authorization`, `transfer_code`, `webhook_secret`,
  `api_key`, etc. Ne contournez pas ce mécanisme avec un `Log::debug($request->all())`.

### 13.1 Ce qui est journalisé, et pourquoi

La Priorité 4 n'a ajouté que trois sources, chacune actionable. Le reste
(paiement refusé, contribution refusée) est **volontairement non journalisé** :
ces rejets sont fonctionnels, attendus, et les journaliser noierait le signal
utile sous du bruit.DEBUG n'est activé qu'en développement.

| Événement | Niveau | Où le lire |
|---|---|---|
| Configuration de production dangereuse (debug actif, secret webhook absent, expiration des tokens désactivée, logs sans rotation) | `critical` | une fois, au démarrage |
| **Webhook de paiement rejeté** | `warning` | `Webhook de paiement rejeté.` + `reason` (motif exact), `status`, `event_id`, `provider` |
| **Transaction financière abandonnée après contention de verrou** | `error` | `Transaction financière abandonnée après contention de verrou.` + `attempts`, `driver`, `busy_timeout_ms` |

Pourquoi ces deux-là :

- **Webhook rejeté** : l'opérateur ne voit qu'un `401` ou `503` de son côté.
  Sans le motif, impossible de distinguer un secret divergent, une horloge
  désynchronisée ou un format de payload inattendu — les trois causes les plus
  fréquents. Le corps de la requête n'est **pas** journalisé : il contient le
  montant et la référence.
- **Contention épuisée** : c'est la seule situation où un utilisateur reçoit
  une erreur à cause de la base (§2.4). Elle signale que `busy_timeout` est trop
  court ou qu'un moteur serveur est nécessaire. Les reprises **réussies** ne sont
  pas journalisées : ce serait du bruit normal.

Ce qui est couvert autrement : base injoignable → `/up/ready` renvoie `503`
(§14) ; jobs échoués → `php artisan queue:failed` (§12) ; sauvegardes →
sortie de `app:backup-database` (§15).

---

## 14. Stockage et fichiers

| Répertoire | Servi en HTTP | Contenu | Protection |
|---|---|---|---|
| `public/` | oui | point d'entrée | seul dossier exposé |
| `storage/app/public` | **oui** (`public/storage`) | avatars, médias produits | uploads validés (type MIME + taille) |
| `storage/app/backups` | **non** | sauvegardes `.sqlite` / `.sql` | `750` / `640`, hors web |
| `storage/logs` | non | journaux | rotation quotidienne |
| `storage/framework` | non | cache, sessions, vues | `775` |

Les exports CSV sont générés **en flux** (`streamDownload`) : aucun fichier
n'est écrit sur le disque, donc rien à nettoyer ni à protéger. Ils exigent le
rôle administrateur et ne contiennent aucun token dans l'URL.

> **Charge mémoire connue.** `FinancialReportService::exportRows()` charge les
> cotisations **et** les tranches de la période en mémoire avant d'envoyer le
> premier octet. La consommation est proportionnelle au volume de la période.
> Ce n'est pas un défaut de sécurité, mais c'est la seule requête de
> l'application sans borne de volume. En pratique : demander une période
> d'un mois, pas sur toute l'histoire. Si les volumes croissent, la correction
> consiste à trier chaque source par `paid_at DESC` dans SQL, puis à fusionner
> les deux séquences à la volée, ce qui rend la mémoire constante — refonte à
> faire à froid, car elle touche un rapport financier.

---

## 15. Sauvegardes

### Créer

```bash
php artisan app:backup-database --label=avant-deploiement --verify
```

- Copie **cohérente** : SQLite utilise `VACUUM INTO` (pas une recopie à chaud,
  qui pourrait capturer un instantané à moitié écrit) ; MySQL utilise
  `mysqldump --single-transaction --quick`.
- Écriture **atomique** (fichier temporaire puis `rename`) : un arrêt brutal ne
  laisse pas de sauvegarde tronquée présentée comme valide.
- Permissions **640** sur la sauvegarde finale ; le fichier de travail des
  outils de dump est créé en **600** puis **supprimé** dans un `finally`.
  Mesuré avant correction : sur MySQL, chaque exécution laissait sur disque un
  `.dump-<pid>.tmp` contenant l'intégralité de la base en clair, en 644, que
  `app:prune-backups` ne purgeait jamais (il ne retient que `sql` et `sqlite`)
  — donc accumulation illimitée, contraire à la règle « rien ne doit remplir
  le disque ». Le test `test_backup_leaves_no_working_file_behind` verrouille ce
  point : après une sauvegarde, le répertoire ne contient que le fichier final.
- **Jamais** d'écrasement : deux sauvegardes au même instant échouent plutôt que
  d'écraser.
- Emplacement hors `public/` : la commande **refuse** un chemin servi en HTTP.

### Conserver

```bash
php artisan app:prune-backups              # simulation, ne supprime rien
php artisan app:prune-backups --force      # supprime réellement
```

Jamais automatique : une suppression implicite de sauvegardes est un risque
inacceptable. Planifiez-le (cron hebdomadaire) si vous en avez besoin.

### Automatiser (exemple cron)

```cron
30 2 * * * cd /var/www/tontine-app && php artisan app:backup-database --label=nightly --verify >> storage/logs/backup.log 2>&1
0 4 * * 0 cd /var/www/tontine-app && php artisan app:prune-backups --force >> storage/logs/backup.log 2>&1
```

---

## 16. Test de restauration (à faire avant la mise en production)

Une sauvegarde jamais restaurée n'est pas fiable.

> **Sur SQLite et MySQL, la cible est différente.** Sur SQLite, la commande
> exige `--database=<fichier>` et **refuse** de viser la base active : la
> restauration ne peut donc pas détruire la base du serveur. Sur MySQL et
> PostgreSQL, le dump SQL est rejoué dans la base **définie par le `.env`** :
> c'est la base active, et il n'existe pas d'option pour viser une autre
> cible. Les trois garde-fous restent obligatoires (`--force`, `--yes`, et la
> confirmation interactive), mais **avant un essai MySQL, pointez le `.env` vers
> une base jetable**, jamais vers la production.

### Sur SQLite

```bash
BK=$(php artisan app:backup-database --label=drill --verify | grep 'Fichier' | awk '{print $2}')

# 1. Restaurer sur une cible temporaire (la base active est refusée)
php artisan app:restore-database "$BK" --database=/tmp/verif.sqlite --force --yes

# 2. Contrôler l'intégrité
sqlite3 /tmp/verif.sqlite "PRAGMA integrity_check;"     # doit renvoyer « ok »
sqlite3 /tmp/verif.sqlite "PRAGMA foreign_key_check;"   # doit renvoyer 0 ligne

# 3. Compter les tables métier
sqlite3 /tmp/verif.sqlite "SELECT 'users', COUNT(*) FROM users
                            UNION SELECT 'tontines', COUNT(*) FROM tontines
                            UNION SELECT 'contributions', COUNT(*) FROM contributions;"

# 4. Vérifier les relations ET les montants (identiques à la source)
sqlite3 /tmp/verif.sqlite "SELECT COUNT(*) FROM contributions c
                            JOIN tontine_members m ON m.id = c.tontine_member_id
                            JOIN tontines t ON t.id = m.tontine_id;"
sqlite3 /tmp/verif.sqlite "SELECT ROUND(SUM(amount),2), ROUND(SUM(commission_amount),2) FROM contributions;"

# 5. Contrôler l'état des migrations sur la restauration
php artisan migrate:status --database=/tmp/verif.sqlite   # 0 pending

# 6. Nettoyer
rm -f /tmp/verif.sqlite
```

### Sur MySQL / PostgreSQL

```bash
# 0. BAS JETABLE : changez DB_DATABASE dans le .env avant de continuer
BK=$(php artisan app:backup-database --label=drill --verify | grep 'Fichier' | awk '{print $2}')

php artisan app:restore-database "$BK" --force --yes

php artisan migrate:status            # 0 pending
mysql -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='tontine_jeteable';"
```

Drill exécuté et vérifié sur MySQL 8.0.46 : 37 migrations appliquées, 0 pending,
23 tables restaurées, colonne générée `beneficiary_tontine_id` reproduite.

**Refus volontaire** : restaurer sans `--database` sur SQLite est refusé
(« base active »), pour ne jamais écraser la base du serveur web en cours
d'exécution.

---

## 17. Migration de SQLite vers MySQL

**Non exécutée automatiquement.** Voici la procédure vérifiée.

```bash
# 1. Sauvegarder la base actuelle
php artisan app:backup-database --label=avant-mysql --verify

# 2. Créer la base cible MySQL
mysql -u root -p -e "CREATE DATABASE tontine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                     CREATE USER 'tontine'@'localhost' IDENTIFIED BY '<secret>';
                     GRANT ALL ON tontine.* TO 'tontine'@'localhost';"

# 3. Basculer l'environnement
#    .env : DB_CONNECTION=mysql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
php artisan config:clear

# 4. Créer le schéma (vide)
php artisan migrate --force

# 5. Transférer les données, table par table
sqlite3 database/database.sqlite .dump | grep '^INSERT INTO' | \
  sed 's/^INSERT INTO "\([^"]*\)"/INSERT INTO `\1`/' | \
  mysql -u tontine -p tontine

# 6. Contrôler
php artisan migrate:status                  # aucune pending
php artisan tinker --execute="echo App\Models\User::count().' '.App\Models\Contribution::count();"
php artisan test                             # la suite doit passer sur MySQL

# 7. Sauvegarder la base MySQL et ne PLUS utiliser SQLite
php artisan app:backup-database --label=post-mysql --verify
```

**Attention** : la ligne 5 ne transfère pas les tables système, et l'ordre
d'insertion peut dépasser `max_allowed_packet`. Pour un petit volume, augmentez
`max_allowed_packet` (par exemple 64 Mo) ou importez table par table.

---

## 18. Rollback

### Principe

> **Un rollback de code sans rollback de base n'est pas toujours sûr.**
> L'inverse non plus : la base et le code doivent être cohérents.

### Avant la migration

```bash
php artisan app:backup-database --label=avant-migration --verify
# Conserver le SHA du code déployé
git rev-parse HEAD > /tmp/deployed-sha
```

### Procédure

```bash
# 1. Maintenance
sudo systemctl stop php8.3-fpm      # ou nginx en mode maintenance

# 2. Sauvegarder l'état courant (avant d'y toucher)
php artisan app:backup-database --label=rollback-source --verify

# 3. Revenir au code précédent
git checkout <sha-précédent>
composer install --no-dev --optimize-autoloader

# 4. Restaurer la base si la migration a été appliquée
php artisan app:restore-database <sauvegarde-avant-migration> --force --yes --database=<cible>

# 5. Si la migration n'avait PAS été appliquée : ne touchez pas à la base
php artisan migrate:status

# 6. Reconstruire les caches et redémarrer
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo supervisorctl restart tontine-worker
sudo systemctl start php8.3-fpm
```

### Migrations non réversibles

Signalées explicitement dans leur `down()` :

| Migration | Réversible ? |
|---|---|
| `2026_09_28_000004_enforce_single_beneficiary_per_tontine` | Oui (colonne générée + index supprimés sur MySQL ; index supprimé sur PostgreSQL ; sur SQLite l'index appartient à `2026_09_28_000001` et est laissé en place) |
| `2026_09_28_000002_guard_financial_amounts` | **Non sur SQLite** (`DROP CONSTRAINT` impossible). Sur MySQL/PostgreSQL : oui. Sur SQLite, laisser la contrainte en place est sans danger. |
| `2026_09_28_000001_restore_lost_enum_constraints` | Oui |
| `2026_09_28_000003_add_integrity_indexes` | Oui |
| `2024_01_05_000001_make_product_optional_on_tontines_table` | Fragile sur SQLite (`dropForeign` + `change()`) — ne rollbackez pas isolément. |

Si un `down()` échoue, **ne forcez pas** : restaurez la sauvegarde.

---

## 19. Checklist préproduction

### Configuration

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` défini (`php artisan key:generate`)
- [ ] `APP_URL` en `https://`
- [ ] `FRONTEND_URL` défini, en `https://`
- [ ] `SANCTUM_TOKEN_EXPIRATION` défini (> 0)
- [ ] `MOBILEMONEY_WEBHOOK_SECRET` défini (non prévisible)
- [ ] `LOG_STACK=daily`, `LOG_DAILY_DAYS` défini
- [ ] `TRUSTED_PROXIES` renseigné si reverse proxy (jamais `*` en public)
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] Aucune ligne `local.CRITICAL` dans les journaux au démarrage

### Base de données

- [ ] Moteur de production choisi et documenté
- [ ] `DB_FOREIGN_KEYS=true`
- [ ] `php artisan migrate --force` testé sur le moteur cible (base **vide**)
- [ ] `php artisan migrate:status` : aucune pending
- [ ] Sur SQLite : `DB_SQLITE_BUSY_TIMEOUT` ≥ 5000 et `DB_SQLITE_JOURNAL_MODE=WAL`
- [ ] Une écriture concurrente ne renvoie pas `database is locked` (§2.4)
- [ ] Sauvegarde créée **et vérifiée**
- [ ] **Restauration testée** sur un fichier temporaire (§16)

### Sécurité

- [ ] HTTPS actif, HSTS activé
- [ ] `public/` est le seul dossier servi
- [ ] `storage/app/backups` en `750`, non servi
- [ ] CORS : aucune origine `*`
- [ ] Le reverse proxy est déclaré dans `TRUSTED_PROXIES`
- [ ] `curl https://api.exemple.com/up/ready` renvoie `status: ok`
- [ ] Les journaux ne contiennent ni mot de passe, ni token, ni `transfer_code`
- [ ] Une tentative de webhook mal signée produit bien une ligne
      `Webhook de paiement rejeté.` avec son motif (§13.1)
- [ ] Aucun accès HTTP à `storage/app/backups` (`curl` doit renvoyer 404)

### Application

- [ ] `php artisan test` : tout vert
- [ ] Frontend : `npm ci && npm run build`, `dist/` déployé
- [ ] `php artisan storage:link` vérifié (chemin correct)
- [ ] Permissions `storage` / `bootstrap/cache`
- [ ] Caches de configuration/routes/vues reconstruits
- [ ] Worker de file démarré et supervisé
- [ ] `supervisorctl status` / `systemctl status` : actif

### Paiements

- [ ] **Aucun fournisseur Mobile Money réel n'est intégré**
- [ ] `FakeMobileMoneyGateway` refuse de s'exécuter en production
      (`PAYMENT_ALLOW_FAKE_IN_PRODUCTION=false`)
- [ ] MyNita / Amana : le mode manuel est identifié comme tel dans l'interface
- [ ] Secret webhook configuré et le webhook de test rejeté sans signature
- [ ] Le taux de commission n'a pas changé (`Setting commission_rate`)

---

## 20. Contacts d'exploitation — pense-bête

| Besoin | Commande |
|---|---|
| État de santé | `curl -fsS https://api.exemple.com/up/ready` |
| État des migrations | `php artisan migrate:status` |
| Réponses aux migrations | `php artisan migrate:status --pending` |
| Sauvegarder | `php artisan app:backup-database --verify` |
| Restaurer (test) | `php artisan app:restore-database <f> --database=/tmp/x.sqlite --force --yes` |
| Vérifier une sauvegarde | `php artisan app:restore-database <f> --database=/tmp/x.sqlite --force --yes` puis `sqlite3 /tmp/x.sqlite "PRAGMA integrity_check;"` |
| Verrou SQLite bloquant | `grep 'contention de verrou' storage/logs/*.log` (§2.4) |
| Webhook rejetés | `grep 'Webhook de paiement rejeté' storage/logs/*.log` (§13.1) |
| Jobs échoués | `php artisan queue:failed --details` |
| Relancer un job | `php artisan queue:retry <id>` |
| Vider la file | `php artisan queue:flush` (**définitif**) |
| Purger les sauvegardes | `php artisan app:prune-backups` puis `--force` |
| Générer les appels de cotisation | `php artisan tontine:generate-contributions` (idempotent) |
| Vider les caches | `php artisan optimize:clear` |

