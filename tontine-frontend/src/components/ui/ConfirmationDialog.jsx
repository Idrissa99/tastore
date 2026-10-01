import { useRef, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Modal } from './Modal';
import { Button } from './Button';

/**
 * Confirmation avant une action sensible (annulation, refus, suppression).
 * Remplace les window.confirm natifs, qui bloquent le thread et ne suivent
 * pas l'identité visuelle de l'application.
 */
export function ConfirmationDialog({
  open,
  onClose,
  onConfirm,
  title = 'Confirmer l’action',
  message,
  confirmLabel = 'Confirmer',
  cancelLabel = 'Annuler',
  tone = 'danger',
  loading = false,
}) {
  const [confirmed, setConfirmed] = useState(false);
  const busyRef = useRef(loading);

  const handleConfirm = async () => {
    if (busyRef.current) return;
    setConfirmed(true);
    try {
      await onConfirm?.();
    } finally {
      setConfirmed(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={confirmed ? undefined : onClose}
      size="sm"
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={confirmed}>
            {cancelLabel}
          </Button>
          <Button
            variant={tone === 'danger' ? 'danger' : 'primary'}
            loading={loading || confirmed}
            onClick={handleConfirm}
            data-autofocus
          >
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex gap-4">
        <span
          className={`flex size-11 shrink-0 items-center justify-center rounded-xl ${
            tone === 'danger' ? 'bg-danger-50 text-danger-600' : 'bg-warning-50 text-warning-600'
          }`}
        >
          <AlertTriangle className="size-5" aria-hidden="true" />
        </span>
        <div className="min-w-0 pt-0.5">
          <h3 className="text-base font-bold text-ink-900">{title}</h3>
          {message && <div className="mt-1.5 text-sm leading-relaxed text-ink-600">{message}</div>}
        </div>
      </div>
    </Modal>
  );
}

export default ConfirmationDialog;
