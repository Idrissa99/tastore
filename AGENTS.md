# Repository instructions

## Layout

- This is an umbrella with two independent apps: `tontine-app/` is the Laravel 13/PHP 8.3 backend; `tontine-frontend/` is the React 19/Vite 8 SPA. There is no root manifest, CI workflow, or command; run tools in the relevant app directory.
- The nested `tontine-app/tontine-app` and `tontine-frontend/tontine-frontend` symlinks point outside this umbrella. Do not follow or edit through them.
- The SPA consumes Laravel’s `routes/api.php`; `routes/web.php` is a separate Blade interface. Check both surfaces when changing behavior that spans the two clients.
- Do not edit `vendor/`, `node_modules/`, `public/build`, `public/hot`, `storage/framework`, `bootstrap/cache`, `.phpunit.result.cache`, or `database/*.sqlite*`.

## Backend (`tontine-app/`)

- Requires PHP `^8.3` and Composer. SQLite is the default database; cache, sessions, and queue use database drivers. PHPUnit uses in-memory SQLite plus array/sync drivers.
- `composer run setup` is declared, but it always runs `php artisan key:generate` and does not create `database/database.sqlite` before migrating. Do not run it against an existing environment. For a fresh checkout, run: `composer install`; copy `.env.example` to `.env` only if absent; `php artisan key:generate`; `php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"`; `php artisan migrate --force`; `npm install --ignore-scripts`; `npm run build`.
- `.env.example` defines `APP_ENV` twice (`local` and `testing`); inspect the generated `.env` before relying on the environment. There is no backend npm lockfile, so use `npm install`, not `npm ci`.
- Run the backend development processes with `composer run dev` (Laravel server, queue listener, Pail, and backend Vite).
- Full tests: `composer test`. A focused file is `php artisan test tests/Feature/Auth/RegistrationTest.php`; add `--filter=<method>` for one test. Use `./vendor/bin/pint --test` for a non-mutating PHP style check; no PHP lint script, static analysis, or typecheck is configured.
- Lint is not clean at baseline: Oxlint emits existing warnings (exit 0), while Pint reports existing violations. Inspect changed files instead of mass-formatting unrelated code.
- Mobile-money is intentionally backed by `FakeMobileMoneyGateway`; do not assume a real provider is configured. The webhook uses `MOBILEMONEY_WEBHOOK_SECRET`.
- If public product media is missing, inspect `public/storage`; this checkout’s link targets another directory, and `php artisan storage:link --force` repairs it.

## Frontend (`tontine-frontend/`)

- Use Node `^20.19.0` or `>=22.12.0` and `npm ci` because `package-lock.json` is committed. Copy `.env.example` to `.env` only if needed; the API defaults to `http://127.0.0.1:8000/api`.
- Run `npm run dev`, `npm run build`, and `npm run lint -- src` for the normal frontend workflows. Plain `npm run lint` also traverses the nested symlink and can report duplicate paths. There is no configured frontend test or typecheck command.
- The frontend `.env` is not ignored. Never put secrets in `VITE_*` variables; Vite embeds them in the browser bundle. Treat all `.env` files as local secrets and do not print or commit their contents.

## Integration gotchas

- The backend and frontend Vite servers both default to port `5173`. Reserve `5173` for the React SPA and run the backend Vite process separately or on an explicit alternate port.
- Browser CORS and password-reset links default to `http://localhost:5173`, not `127.0.0.1:5173`; if using another frontend origin, set `FRONTEND_URL` in the backend environment and run `php artisan config:clear`.
