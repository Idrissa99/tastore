import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { IconButton } from './Button';

const SIZES = {
  sm: 'max-w-md',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
};

/**
 * Modale accessible : fermeture par Échap, clic sur le fond, focus piégé
 * sur la modale et restitution du focus au déclencheur.
 */
export function Modal({ open, onClose, title, description, size = 'md', footer, children, className = '', initialFocusRef }) {
  const panelRef = useRef(null);
  const restoreRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    restoreRef.current = document.activeElement;

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onClose?.();
        return;
      }
      if (event.key !== 'Tab') return;

      const focusables = panelRef.current?.querySelectorAll(
        'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
      );
      if (!focusables?.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', onKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const timer = setTimeout(() => {
      if (initialFocusRef?.current) initialFocusRef.current.focus();
      else panelRef.current?.querySelector('[data-autofocus]')?.focus();
    }, 40);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
      clearTimeout(timer);
      restoreRef.current?.focus?.();
    };
  }, [open, onClose, initialFocusRef]);

  if (!open) return null;

  return createPortal(
    <div className="fixed inset-0 z-[100] flex items-end justify-center sm:items-center sm:p-4">
      <div
        className="absolute inset-0 animate-[fade-in_0.2s_ease-out] bg-ink-950/50 backdrop-blur-[3px]"
        onClick={onClose}
        aria-hidden="true"
      />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={typeof title === 'string' ? title : undefined}
        className={`relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl border border-line-strong bg-white shadow-2xl animate-[scale-in_0.22s_cubic-bezier(0.22,1,0.36,1)] sm:rounded-2xl ${SIZES[size] || SIZES.md} ${className}`}
      >
        {(title || onClose) && (
          <header className="flex items-start justify-between gap-4 border-b border-line-soft px-5 py-4 sm:px-6">
            <div className="min-w-0">
              {title && <h2 className="text-lg font-bold text-ink-900">{title}</h2>}
              {description && <p className="mt-0.5 text-sm leading-relaxed text-ink-500">{description}</p>}
            </div>
            {onClose && <IconButton icon={X} label="Fermer" size="sm" onClick={onClose} />}
          </header>
        )}

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">{children}</div>

        {footer && (
          <footer className="flex flex-wrap items-center justify-end gap-2.5 border-t border-line-soft bg-ink-50/60 px-5 py-3.5 sm:px-6">
            {footer}
          </footer>
        )}
      </div>
    </div>,
    document.body,
  );
}

export default Modal;
