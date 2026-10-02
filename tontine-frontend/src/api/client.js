export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api';
const TOKEN_KEY = 'tontine_token';

/*
 * RISQUE ASSUMÉ : le token est stocké dans localStorage, ce qui le rend lisible
 * par tout script injecté dans la page (XSS). C'est un compromis connu :
 *   - le token n'est jamais mis dans une URL, un paramètre de requête ou un
 *     message d'erreur, et n'est envoyé qu'en en-tête Authorization vers
 *     l'API (jamais à un domaine tiers) ;
 *   - il est effacé à la déconnexion, sur 401 et à la fermeture de l'onglet.
 * Migration progressive vers un cookie HttpOnly + protection CSRF : à faire,
 * pas dans cette priorité. Voir README/rapport d'audit.
 */

export function getToken() {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

export function setToken(token) {
  try {
    if (token) {
      localStorage.setItem(TOKEN_KEY, token);
    } else {
      localStorage.removeItem(TOKEN_KEY);
    }
  } catch {
    // localStorage indisponible (navigation privée) : on continue sans persistance.
  }
}

export function clearToken() {
  setToken(null);
}

class ApiError extends Error {
  constructor(message, status, errors = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors; // erreurs de validation Laravel (422), par champ
  }

  get isUnauthorized() {
    return this.status === 401;
  }

  get isForbidden() {
    return this.status === 403;
  }
}

/**
 * 401 = authentification absente, invalide ou expirée -> la session doit
 *       être fermée et l'utilisateur renvoyé vers la connexion.
 * 403 = utilisateur authentifié mais action interdite -> on ne déconnecte PAS,
 *       on affiche simplement le refus.
 * Les deux sont traités distinctement pour ne jamais déconnecter quelqu'un
 * sur un simple manque de droit.
 */
const unauthorizedListeners = new Set();

export function onUnauthorized(listener) {
  unauthorizedListeners.add(listener);
  return () => unauthorizedListeners.delete(listener);
}

let unauthorizedNotified = false;

function notifyUnauthorized() {
  if (unauthorizedNotified) return; // évite les boucles sur rafales de 401
  unauthorizedNotified = true;
  unauthorizedListeners.forEach((listener) => {
    try {
      listener();
    } catch {
      // un listener défaillant ne doit pas casser le client API
    }
  });
  setTimeout(() => {
    unauthorizedNotified = false;
  }, 0);
}

const AUTH_PATHS = ['/login', '/register', '/forgot-password', '/reset-password'];

/**
 * Requête générique vers l'API Laravel.
 * - Ajoute automatiquement le token Bearer si présent.
 * - Gère le multipart/form-data (upload) sans forcer Content-Type JSON.
 * - Normalise les erreurs (422 validation, 401/403 auth, autres).
 * - Ne réessaie JAMAIS automatiquement une requête : pas de boucle infinie.
 */
async function request(path, { method = 'GET', body, isFormData = false, params, signal } = {}) {
  const token = getToken();

  const headers = { Accept: 'application/json' };
  if (!isFormData) {
    headers['Content-Type'] = 'application/json';
  }
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  let url = `${API_BASE_URL}${path}`;
  if (params) {
    // Ne garder que les valeurs réellement définies : sans ça, un champ vide
    // (ex: recherche non remplie) finit en "?q=undefined" dans l'URL, ce qui
    // le fait compter comme un vrai critère de recherche côté backend.
    const cleanParams = Object.fromEntries(
      Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '')
    );
    const query = new URLSearchParams(cleanParams).toString();
    if (query) url += `?${query}`;
  }

  let response;
  try {
    response = await fetch(url, {
      method,
      headers,
      signal,
      body: body ? (isFormData ? body : JSON.stringify(body)) : undefined,
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new ApiError('Impossible de joindre le serveur. Vérifie ta connexion.', 0);
  }

  if (response.status === 401) {
    // Le token est expiré, révoqué ou absent : on le supprime pour ne plus
    // renvoyer un token mort, puis on prévient l'application.
    if (!AUTH_PATHS.includes(path)) {
      clearToken();
      notifyUnauthorized();
    }
    throw new ApiError('Session expirée ou invalide. Reconnecte-toi.', 401);
  }

  if (response.status === 204) {
    return null;
  }

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const message =
      response.status === 403
        ? data?.message || "Tu n'as pas les droits nécessaires pour cette action."
        : data?.message || 'Une erreur est survenue.';
    throw new ApiError(message, response.status, data?.errors || null);
  }

  return data;
}

export const api = {
  get: (path, params, options = {}) => request(path, { method: 'GET', params, ...options }),
  post: (path, body, options = {}) => request(path, { method: 'POST', body, ...options }),
  put: (path, body, options = {}) => request(path, { method: 'PUT', body, ...options }),
  patch: (path, body, options = {}) => request(path, { method: 'PATCH', body, ...options }),
  delete: (path, options = {}) => request(path, { method: 'DELETE', ...options }),
};

export { ApiError };
