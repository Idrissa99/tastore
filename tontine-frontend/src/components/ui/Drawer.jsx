import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { IconButton } from './Button';

/** Tiroir latéral — filtres sur mobile, navigation secondaire. */
export function Drawer({ open, onClose, title, description, side = 'right', width = 'max-w-sm', children, footer, bodyClassName = '' }) {
  const panelRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onKeyDown = (event) => {
      if (event.key === 'Escape') onClose?.();
    };
    document.addEventListener('keydown', onKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const timer = setTimeout(() => panelRef.current?.focus(), 40);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
      clearTimeout(timer);
    };
  }, [open, onClose]);

  if (!open) return null;

  return createPortal(
    <div className="fixed inset-0 z-[100]">
      <div className="absolute inset-0 animate-[fade-in_0.2s_ease-out] bg-ink-950/45 backdrop-blur-[2px]" onClick={onClose} aria-hidden="true" />
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`absolute inset-y-0 flex w-full ${width} flex-col border-y border-r border-line-strong bg-white shadow-2xl outline-none ${
          side === 'right' ? 'right-0 animate-[slide-left_0.28s_cubic-bezier(0.22,1,0.36,1)]' : 'left-0'
        }`}
      >
        <header className="flex items-start justify-between gap-4 border-b border-line-soft px-5 py-4">
          <div className="min-w-0">
            <h2 className="text-base font-bold text-ink-900">{title}</h2>
            {description && <p className="mt-0.5 text-xs text-ink-500">{description}</p>}
          </div>
          {onClose && <IconButton icon={X} label="Fermer" size="sm" onClick={onClose} />}
        </header>
        <div className={`min-h-0 flex-1 overflow-y-auto ${bodyClassName || 'px-5 py-4'}`}>{children}</div>
        {footer && <footer className="border-t border-line-soft bg-ink-50/60 px-5 py-3.5">{footer}</footer>}
      </div>
    </div>,
    document.body,
  );
}

export default Drawer;
