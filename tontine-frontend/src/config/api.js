/**
 * Point d'entrée UNIQUE de l'URL de l'API.
 *
 * Pourquoi ce fichier existe : l'URL était lue en deux endroits
 * (`src/api/client.js` et `src/components/ui/ProductImage.jsx`), chacun avec
 * son propre repli sur `http://127.0.0.1:8000/api`. Un build de production
 * sans `VITE_API_BASE_URL`defined produisait donc un site web qui n'appelait
 * QUE la machine du développeur, sans le moindre avertissement : l'erreur
 * n'apparaissait qu'à l'écran, une fois le site déployé.
 *
 * Ici, un build de production SANS la variable échoue immédiatement et
 * bruyamment, au chargement du module, plutôt que d'envoyer silencieusement
 * les utilisateurs vers localhost.
 */

const RAW_BASE_URL = import.meta.env.VITE_API_BASE_URL;

const DEV_FALLBACK = 'http://127.0.0.1:8000/api';

function resolveBaseUrl() {
  if (typeof RAW_BASE_URL === 'string' && RAW_BASE_URL.trim() !== '') {
    return RAW_BASE_URL.trim().replace(/\/+$/, '');
  }

  if (import.meta.env.DEV) {
    return DEV_FALLBACK;
  }

  throw new Error(
    'VITE_API_BASE_URL est absente du build. Définis-la dans les variables ' +
      "d'environnement de l'hébergeur (ex. Vercel → Settings → Environment " +
      `Variables), par exemple : ${DEV_FALLBACK.replace('127.0.0.1', 'tastore-api.onrender.com')}`,
  );
}

/** URL de base de l'API Laravel, sans barre oblique finale. Ex. `https://api.exemple.com/api` */
export const API_BASE_URL = resolveBaseUrl();

/** Origine du backend, déduite de l'URL de l'API. Ex. `https://api.exemple.com` */
export const API_ORIGIN = (() => {
  try {
    return new URL(API_BASE_URL).origin;
  } catch {
    return '';
  }
})();
