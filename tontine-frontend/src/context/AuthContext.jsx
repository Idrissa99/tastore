import { createContext, useCallback, useEffect, useRef, useState } from 'react';
import { api, clearToken, getToken, onUnauthorized, setToken } from '../api/client';
import { useNavigate } from 'react-router-dom';

export const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const navigate = useNavigate();

  const loadUser = useCallback(async () => {
    if (!getToken()) {
      setUser(null);
      setIsLoading(false);
      return null;
    }

    try {
      const data = await api.get('/me');
      setUser(data);
      return data;
    } catch {
      // 401 : token expiré/révoqué -> le client l'a déjà supprimé.
      // 403 : compte bloqué -> on déconnecte aussi localement.
      clearToken();
      setUser(null);
      return null;
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    loadUser();
  }, [loadUser]);

  // Un 401 reçu en cours de session (token expiré en cours d'usage) ferme la
  // session et ramène vers la page de connexion, sans boucle : le client API
  // ne rejoue jamais la requête et n'émet qu'une seule notification par salve.
  const onSessionExpired = useRef(() => {});
  useEffect(() => {
    onSessionExpired.current = () => {
      setUser((current) => {
        if (current) {
          navigate('/connexion', { replace: true, state: { reason: 'session_expiree' } });
        }
        return null;
      });
    };
    return onUnauthorized(() => onSessionExpired.current());
  }, [navigate]);

  const login = async (email, password) => {
    const data = await api.post('/login', { email, password });
    setToken(data.token);
    setUser(data.user);
    return data.user;
  };

  const register = async (payload) => {
    const data = await api.post('/register', payload);
    setToken(data.token);
    setUser(data.user);
    return data.user;
  };

  const logout = async () => {
    try {
      await api.post('/logout');
    } catch {
      // même si l'appel échoue, on déconnecte localement
    }
    clearToken();
    setUser(null);
    navigate('/', { replace: true });
  };

  const refreshUser = () => loadUser();

  const value = {
    user,
    isAuthenticated: !!user,
    isLoading,
    isAdmin: user?.role === 'admin',
    isMerchant: user?.role === 'merchant',
    login,
    register,
    logout,
    refreshUser,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
