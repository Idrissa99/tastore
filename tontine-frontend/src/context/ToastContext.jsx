import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import ToastViewport from '../components/ui/ToastViewport';

const ToastContext = createContext(null);

let nextId = 0;

/**
 * File de notifications éphémères (success / error / info).
 * Un seul viewport est monté à la racine de l'application.
 */
export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const timers = useRef(new Map());

  const dismiss = useCallback((id) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));
    const timer = timers.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timers.current.delete(id);
    }
  }, []);

  const push = useCallback(
    (message, { type = 'info', title, duration = 4500, action } = {}) => {
      if (!message) return null;
      const id = ++nextId;
      setToasts((current) => [...current.slice(-3), { id, message, type, title, action }]);
      if (duration > 0) {
        timers.current.set(
          id,
          setTimeout(() => dismiss(id), duration),
        );
      }
      return id;
    },
    [dismiss],
  );

  const api = useMemo(
    () => ({
      toast: push,
      success: (message, options) => push(message, { ...options, type: 'success' }),
      error: (message, options) => push(message, { ...options, type: 'error', duration: 6000 }),
      info: (message, options) => push(message, { ...options, type: 'info' }),
      warning: (message, options) => push(message, { ...options, type: 'warning' }),
      dismiss,
    }),
    [push, dismiss],
  );

  return (
    <ToastContext.Provider value={api}>
      {children}
      <ToastViewport toasts={toasts} onDismiss={dismiss} />
    </ToastContext.Provider>
  );
}

export function useToast() {
  const context = useContext(ToastContext);
  if (!context) throw new Error("useToast doit être utilisé à l'intérieur de <ToastProvider>.");
  return context;
}
