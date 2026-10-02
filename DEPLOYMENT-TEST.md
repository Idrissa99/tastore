# TAStore — Déploiement de l'environnement de TEST

Recette complète, de zéro, sur l'offre gratuite : **API Laravel sur Render** (Docker),
**SPA React sur Vercel**, **MySQL managé sur Aiven**.

Ce document ne contient aucun secret. Toutes les valeurs sensibles sont générées
localement et saisies à la main dans les tableaux de bord.

---

## Où en est le projet aujourd'hui

| Étape | État |
|---|---|
| `tontine-app/Dockerfile` + `docker/` (nginx, php-fpm, `start.sh`) | ✅ prêt |
| `render.yaml` (Blueprint) | ✅ prêt, **modifié non commité** (`DB_PORT` / `DB_DATABASE` passés en `sync: false`) |
| `tontine-frontend/vercel.json` | ✅ prêt |
| `.env.render.local` (APP_KEY + secret webhook) | ✅ généré, ignoré par Git |
| Secrets base MySQL (Aiven) | ❓ à créer / à noter |
| Service Render créé | ❓ |
| Projet Vercel créé | ❓ |

L'ordre des étapes ci-dessous est **important** : `render.yaml` n'est lu par Render
qu'à la **création** du service. Toute valeur fausse ou absente à ce moment-là se
retrouve dans les logs de build, noyée dans des centaines de lignes.

---

## Étape 1 — Créer la base MySQL chez Aiven

Render ne fournit **aucun MySQL**. Il faut une base managée ailleurs.

1. https://aiven.io/free-mysql-database → créer un compte (sans carte bancaire).
2. **Create service** → type **MySQL** → plan **Free** (1 Go, sans expiration).
3. Choisir la région la plus proche de l'Afrique de l'Ouest si l'offre le permet.
4. Menu **Overview** → encadré **Connection information**. Noter :

   | Variable Render | Ce que Aiven affiche |
   |---|---|
   | `DB_HOST` | Host |
   | `DB_PORT` | **Port** (aléatoire : 12xxx, 24xxx…) |
   | `DB_DATABASE` | **Database** (souvent `defaultdb`) |
   | `DB_USERNAME` | User |
   | `DB_PASSWORD` | Password |

   ⚠ Le **port** et le **nom de base** ne sont **jamais** `3306` et `tontine`.
   C'est exactement pour ça que `render.yaml` les déclare en `sync: false` : un
   `3306` codé en dur fait échouer la migration avec « connection refused ».

5. ⚠ **MySQL ≥ 8.0.16 obligatoire.** En dessous, les contraintes `CHECK` sont
   acceptées puis ignorées : la migration `2026_09_28_000002` croirait avoir
   protégé les montants alors que non. Vérifier la version affichée dans
   l'onglet **Service** d'Aiven.

---

## Étape 2 — Vérifier les secrets locaux

Le fichier `.env.render.local` existe à la racine et contient déjà :

- `APP_KEY` — générée avec `php artisan key:generate --show`
- `MOBILEMONEY_WEBHOOK_SECRET` — chaîne aléatoire inventée

Ce fichier est **ignoré par Git** (règle `.env.*` du `.gitignore`) et ne doit
jamais être commité, ni copié dans un canal de discussion.

```bash
# Pour relire la valeur à saisir dans Render (jamais dans un commit) :
grep '^APP_KEY=' .env.render.local
```

Sans `MOBILEMONEY_WEBHOOK_SECRET`, l'application journalise
`MOBILEMONEY_WEBHOOK_SECRET absent : tous les webhooks seront rejetés (503)`.

---

## Étape 3 — Commiter le `render.yaml` corrigé

`render.yaml` n'est lu qu'à la création du service : **il doit être sur GitHub
avant** de lancer l'étape 5.

```bash
git add render.yaml
git commit -m "fix(deploy): DB_PORT et DB_DATABASE saisis par l'hebergeur MySQL"
git push
```

---

## Étape 4 — Créer le projet Vercel (AVANT Render)

On fait Vercel d'abord pour connaître le domaine définitif, et le reporter dans
`FRONTEND_URL` **avant** de créer le service Render (voir étape 5).

1. https://vercel.com/new → importer le dépôt GitHub.
2. **Root Directory** : `tontine-frontend` ← indispensable, le dépôt est un
   monorepo.
3. **Framework Preset** : `Vite` (détecté via `vercel.json`).
4. **Environment Variables** → `VITE_API_BASE_URL`, pour **Production**,
   **Preview** et **Development** :

   ```
   https://tastore-api.onrender.com/api
   ```

   (L'URL n'est encore pas en ligne, ce n'est pas un problème : Vercel n'appelle
   pas l'API au moment du build.)
5. **Deploy**. Relever le domaine, par exemple `https://tastore-xxxx.vercel.app`.

   ⚠ Le nom `tastore` est **déjà pris** sur Vercel : ton projet aura donc un
   autre nom. `render.yaml` contient `https://tastore.vercel.app` comme
   **valeur d'exemple** — il faut la remplacer par le vrai domaine (étape 5).

6. Tester que le build a réussi : le site affiche la page d'accueil React.
   Tant que `VITE_API_BASE_URL` est absente, `src/config/api.js` échoue
   bruyamment au chargement plutôt que de retomber sur `localhost`.

---

## Étape 5 — Créer le service API sur Render (Blueprint)

1. Ouvrir le dépôt sur GitHub et **pousser** les commits de l'étape 3.
2. Render → **New** → **Blueprint** → sélectionner le dépôt.
3. Render affiche les variables `sync: false` et les demande une par une :

   | Variable | Valeur à saisir |
   |---|---|
   | `APP_KEY` | contenu de `APP_KEY` dans `.env.render.local` |
   | `DB_HOST` | Host Aiven |
   | `DB_PORT` | **Port** Aiven (ex. `12894`) |
   | `DB_DATABASE` | **Database** Aiven (souvent `defaultdb`) |
   | `DB_USERNAME` | User Aiven |
   | `DB_PASSWORD` | Password Aiven |
   | `MOBILEMONEY_WEBHOOK_SECRET` | valeur de `.env.render.local` |

4. ⚠ **Avant de cliquer sur « Apply », corriger `FRONTEND_URL`** dans la
   prévisualisation du Blueprint (ou dans `render.yaml`, étape 6) avec le **vrai
   domaine Vercel** de l'étape 4.

   Sans cette variable, l'application **refuse de démarrer** en production :
   `AppServiceProvider` lève une exception si la liste CORS est vide
   (`CORS obligatoire en production`).
5. **Apply** → le build Docker démarre. Durée : 5 à 10 min (extension PHP,
   `composer install`, migration, seed).

### Ce que fait le conteneur au démarrage (`docker/start.sh`)

Dans l'ordre : aligne nginx sur `$PORT` → écrit un `.env` à partir des variables
d'environnement → `package:discover` + caches → `storage:link` →
`migrate --force` → `db:seed` **si la base est vide** → lance php-fpm + nginx
au premier plan.

Le seed est conditionnel à une base vide : c'est obligatoire, car le service
gratuit se réveille après 15 min d'inactivité et le script s'exécute à **chaque**
démarrage.

### Après le premier déploiement

- Si Render a attribué une autre URL que `https://tastore-api.onrender.com` :
  **Environment** → corriger `APP_URL` → **Save & Deploy**. `APP_URL` sert à
  construire les URLs des images produits ; fausse, aucune photo ne s'affiche.
- Vérifier `FRONTEND_URL` (domaine Vercel de l'étape 4) → **Save & Deploy**.

---

## Étape 6 — Vérifications immédiates

Remplacer `tastore-api` par l'URL réelle si besoin.

```bash
API=https://tastore-api.onrender.com

# 1. Le processus PHP répond (c'est la sonde de santé de Render)
curl -i $API/up                       # attendu : HTTP 200

# 2. La base est migrée et le seed a tourné
curl -s $API/api/produits | head -c 400     # attendu : JSON paginé, 9 produits
curl -s $API/api/config                      # attendu : commission_rate, payment_channels

# 3. Connexion admin (compte créé par le seed)
curl -s -X POST $API/api/login \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"email":"admin@tontine.test","password":"password"}'
# attendu : {"user":{...,"role":"admin"},"token":"1|xxxx"}

# 4. Le jeton fonctionne
TOKEN=1|xxxx   # celui renvoyé ci-dessus
curl -s $API/api/admin/dashboard -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

Si `/up` renvoie 200 mais `/api/produits` renvoie 500 : le problème est la base
(réviser l'étape 1), pas l'image. Lire **Logs** dans Render.

Piège : la **première** requête après 15 min d'inactivité peut expirer (timeout
ou « cold start »). Réessayer une fois, c'est normal sur l'offre gratuite.

---

## Étape 7 — Scénario de test fonctionnel

### 7.1 Côté navigateur (Vercel)

1. `https://<ton-domaine-vercel>` → page d'accueil, catalogue,`tontines`.
2. `https://<domaine-vercel>/inscription` → créer un compte **client**.
3. `/connexion` → `admin@tontine.test` / `password`.
4. Parcourir : `/tableau-de-bord`, `/admin`, `/admin/commercants`, `/admin/tontines`,
   `/admin/rapports`, `/admin/commissions`, `/admin/paiements`.

### 7.2 Vérification de l'email (piège du `MAIL_MAILER=log`)

Aucun SMTP n'est configuré : les e-mails sont **écrits dans les logs** du service.
Un compte inscrit n'est pas vérifié, et le middleware `verified` bloque la
création de tontine et les paiements.

1. Render → **Logs**.
2. Chercher `verification` (ou l'email du compte inscrit) → copier le lien
   `https://tastore-api.onrender.com/email/verify/{id}/{hash}?...`.
3. L'ouvrir dans le navigateur. Le lien pointe vers l'**API** (route `web.php`),
   pas vers le SPA : c'est normal.
4. Retour sur le SPA, recharger : l'accès est débloqué.

Même mécanisme pour **« mot de passe oublié »** : le lien est dans les logs et
pointe vers `<FRONTEND_URL>/reinitialiser-mot-de-passe/{token}?email=...`.

### 7.3 Parcours complet d'une tontine

Le seed crée déjà : 1 tontine **ouverte** (5 places max, 1 membre) et 1 tontine
**active** avec un round en cours (2/4 cotisations payées).

1. **Créer** une tontine : `/tontines/nouvelle` (client vérifié).
2. **Rejoindre** depuis `/tontines` : les 4 autres membres remplissent la tontine.
3. À 4/4, la tontine passe **active**, le round 1 est généré et le bénéficiaire
   désigné.
4. **Payer une cotisation** sur `/mes-cotisations` → saisir un **code de
   transfert** (ex. `TEST-001`). C'est le seul canal de paiement réellement
   fonctionnel : voir « Limites » plus bas.
5. **Livraison** par le commerçant : `/commercant/livraisons`.
6. **Litige** : `/tontines/{id}/litige`, puis résolution côté
   `/admin/litiges/{id}`.
7. **Achat en tranches** : `/produits/{id}/tranches` puis `/mes-achats`.

### 7.4 Parcours commerçant (inscription + validation admin)

Les commerçants du seed ont des e-mails **aléatoires** (faker) : on ne peut pas
s'y connecter sans accès SQL. Il faut donc créer le compte par l'application.

1. `/inscription` avec le rôle **commerçant** → le compte est créé avec
   `status = pending`.
2. Connexion **admin** → `/admin/commercants` → **Approuver**.
3. Reconnecter avec le compte commerçant → `/commercant`,
   `/commercant/produits`, `/commercant/livraisons`.

### 7.5 Tester la SPA en local contre l'API déployée

```bash
# tontine-frontend/.env
VITE_API_BASE_URL=https://tastore-api.onrender.com/api

cd tontine-frontend && npm ci && npm run dev
```

⚠ L'origine `http://localhost:5173` n'est **pas** dans `FRONTEND_URL` : le
navigateur sera bloqué par CORS. Ajouter temporairement
`FRONTEND_URLS=http://localhost:5173` dans Render → **Save & Deploy**, et
l'enlever après le test.

---

## Limites connues de l'environnement de test

| Limite | Conséquence |
|---|---|
| Offre gratuite Render | le service **s'endort après 15 min** ; la première requête suivante prend 30 à 60 s |
| **Pas de shell** sur Render gratuit | impossible de créer un compte, lancer un tinker ou regarder la base à la main |
| `MAIL_MAILER=log` | aucun e-mail réel ; tout passe par les logs |
| `PAYMENT_PROVIDER=fake` + `PAYMENT_ALLOW_FAKE_IN_PRODUCTION=false` | le **paiement automatique** (initiation Mobile Money) est **refusé**. Seul le **code de transfert** saisi manuellement fonctionne |
| `FILESYSTEM_DISK=local` | les images uploadées sont **perdues à chaque redéploiement** (disque éphémère) |
| Région `frankfurt` | latence accrue vers l'Afrique de l'Ouest |
| Mot de passe `password` | compte admin du seed, à ne jamais conserver en production |

---

## Diagnostic des erreurs fréquentes

| Symptôme (Render → Logs) | Cause | Correction |
|---|---|---|
| `CORS obligatoire en production` | `FRONTEND_URL` vide | renseigner le domaine Vercel |
| `SQLSTATE[HY000] [2002] Connection refused` | mauvais `DB_PORT` (3306 codé en dur) | port Aiven exact |
| `SQLSTATE[HY000] [1049] Unknown database` | mauvais `DB_DATABASE` | souvent `defaultdb` chez Aiven |
| `APP_KEY est absente de l'environnement du service` | `APP_KEY` non saisie | étape 5 |
| `no such column ... CHECK` / montants non protégés | MySQL < 8.0.16 | vérifier la version Aiven |
| `Base déjà peuplée : aucun semiage` | **normal** au 2ᵉ démarrage | aucune action |
| Erreur CORS dans la console du navigateur | `FRONTEND_URL` ≠ origine réelle | corriger + Save & Deploy |
| Page blanche sur Vercel | `VITE_API_BASE_URL` absente du build | variable Vercel + redéployer |
| `MOBILEMONEY_WEBHOOK_SECRET absent` | secret non saisi | étape 2 |
| Build qui échoue sur `COPY . .` | `dockerContext` non aligné | garder `./tontine-app` dans `render.yaml` |

---

## Redéploiement

```bash
git add -A && git commit -m "..." && git push
```

- **Render** : *Manual Deploy* → *Deploy latest commit*. Les migrations sont
  rejouées (`migrate --force`), **les données sont conservées**, le seed est
  sauté (base non vide). Les fichiers uploadés, eux, sont perdus.
- **Vercel** : redéploiement automatique à chaque push sur la branche de
  production.
- **Annuler** : Render → *Deploys* → sélectionner un déploiement vert →
  *Rollback*. Vercel : *Deployments* →-menu ⋯ → *Promote to Production*.

---

## Avant de passer en production (plus tard)

1. `APP_ENV=local` / `APP_DEBUG=true` **uniquement** sur une machine, jamais en
   ligne : les stack traces exposent l'arborescence, la config et les variables
   d'environnement.
2. Remplacer le mot de passe du compte admin du seed, ou supprimer le seed.
3. Brancher un vrai fournisseur Mobile Money (`PAYMENT_PROVIDER`) et un vrai
   SMTP (`MAIL_MAILER`), avec `MOBILEMONEY_WEBHOOK_SECRET` solide.
4. `SESSION_DRIVER=redis` ou `database` avec un `SESSION_DOMAIN` en `https`.
5. Passer le plan Render payant (le service ne s'endort plus) et un disque
   persistant pour `storage/app/public`, sinon les photos produits disparaissent.
6. Retirer les `sync: false` de `render.yaml` : sur un projet réel, les secrets
   se déclarent dans l'onglet **Environment**, pas dans un fichier versionné.
