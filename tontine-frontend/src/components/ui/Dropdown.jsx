import { cloneElement, isValidElement, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronDown } from 'lucide-react';

/**
 * Menu déroulant accessible (button + aria-expanded), fermeture au clic
 * extérieur et à la touche Échap.
 *
 * `trigger` est un élément React unique ; ses gestionnaires et attributs
 * ARIA sont enrichis automatiquement, sans wrapper intermédiaire cliquable
 * (qui casserait la navigation au clavier).
 */
export function Dropdown({ trigger, children, align = 'right', className = '', panelClassName = '', width = 'w-56' }) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onPointerDown = (event) => {
      if (!rootRef.current?.contains(event.target)) setOpen(false);
    };
    const onKeyDown = (event) => {
      if (event.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  const triggerElement = isValidElement(trigger)
    ? cloneElement(trigger, {
        onClick: (event) => {
          trigger.props.onClick?.(event);
          setOpen((value) => !value);
        },
        'aria-haspopup': 'menu',
        'aria-expanded': open,
      })
    : trigger;

  return (
    <div ref={rootRef} className={`relative ${className}`}>
      {triggerElement}
      {open && (
        <div
          role="menu"
          className={`absolute z-50 mt-2 ${width} animate-[scale-in_0.16s_cubic-bezier(0.22,1,0.36,1)] overflow-hidden rounded-xl border border-line-strong bg-white p-1 shadow-lg ${
            align === 'right' ? 'right-0' : 'left-0'
          } ${panelClassName}`}
        >
          {typeof children === 'function' ? children({ close: () => setOpen(false) }) : children}
        </div>
      )}
    </div>
  );
}

/** Élément de menu (bouton). */
export function DropdownItem({ icon: Icon, danger = false, className = '', children, ...props }) {
  const tone = danger ? 'text-danger-600 hover:bg-danger-50' : 'text-ink-700 hover:bg-ink-50';
  return (
    <button
      type="button"
      role="menuitem"
      className={`flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors ${tone} ${className}`}
      {...props}
    >
      {Icon && <Icon className="size-4 shrink-0" aria-hidden="true" />}
      <span className="min-w-0 flex-1 truncate">{children}</span>
    </button>
  );
}

/** Élément de menu (lien). */
export function DropdownLink({ to, icon: Icon, danger = false, className = '', children, ...props }) {
  const tone = danger ? 'text-danger-600 hover:bg-danger-50' : 'text-ink-700 hover:bg-ink-50';
  return (
    <Link
      to={to}
      role="menuitem"
      className={`flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors ${tone} ${className}`}
      {...props}
    >
      {Icon && <Icon className="size-4 shrink-0" aria-hidden="true" />}
      <span className="min-w-0 flex-1 truncate">{children}</span>
    </Link>
  );
}

export function DropdownLabel({ children }) {
  return <p className="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wider text-ink-400">{children}</p>;
}

export function DropdownSeparator() {
  return <div className="my-1 h-px bg-line-soft" role="separator" />;
}

/** Bouton servant de déclencheur de menu. */
export function DropdownTrigger({ children, className = '', ...props }) {
  return (
    <button
      type="button"
      aria-haspopup="menu"
      className={`inline-flex items-center gap-1.5 ${className}`}
      {...props}
    >
      {children}
      <ChevronDown className="size-3.5 opacity-60" aria-hidden="true" />
    </button>
  );
}

export default Dropdown;
