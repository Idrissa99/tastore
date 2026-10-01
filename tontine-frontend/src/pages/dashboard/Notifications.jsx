import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  ArrowRight,
  Bell,
  CheckCheck,
  CheckCircle2,
  Clock3,
  Gift,
  Package,
  PartyPopper,
  Receipt,
  Scale,
} from 'lucide-react';
import { useNotifications } from '../../context/NotificationsContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/Badge';
import { Tabs } from '../../components/ui/Tabs';
import { EmptyState } from '../../components/ui/EmptyState';
import { SkeletonRows } from '../../components/ui/Skeleton';
import { formatRelative, formatDateTime } from '../../utils/format';
import { isAwaitingVerification } from '../../utils/notifications';

/*
 * Les notifications portent une URL ABSOLUE construite côté backend
 * (NotificationLinks, config('app.frontend_url')). On n'accepte comme lien
 * interne que le chemin correspondant à l'origine du frontend : une URL
 * pointant vers un autre domaine est affichée en texte brut plutôt que
 * suivie, ce qui évite toute navigation induite.
 */
function internalPath(url) {
  if (!url) return null;
  try {
    const frontend = new URL(import.meta.env.VITE_FRONTEND_URL || window.location.origin);
    const target = new URL(url, window.location.origin);
    if (target.origin !== frontend.origin) return null;
    return `${target.pathname}${target.search}`;
  } catch {
    return null;
  }
}

const TYPE_META = {
  BecameBeneficiaryNotification: { icon: Gift, tone: 'accent', label: 'Tour arrivé' },
  DeliveryConfirmedNotification: { icon: CheckCircle2, tone: 'success', label: 'Livraison' },
  DisputeResolvedNotification: { icon: Scale, tone: 'warning', label: 'Litige' },
  ContributionVerificationUpdatedNotification: { icon: Receipt, tone: 'info', label: 'Paiement' },
  InstallmentVerificationUpdatedNotification: { icon: Receipt, tone: 'info', label: 'Paiement' },
  InstallmentPurchaseCompletedNotification: { icon: PartyPopper, tone: 'success', label: 'Achat' },
  InstallmentDeliveryConfirmedNotification: { icon: Package, tone: 'success', label: 'Livraison' },
};

const FALLBACK = { icon: Bell, tone: 'neutral', label: 'Notification' };

export default function Notifications() {
  useDocumentTitle('Notifications');
  const navigate = useNavigate();
  const { items, unread, unreadItems, hasActionRequired, isLoading, markAsRead, markAllAsRead } = useNotifications();
  const [tab, setTab] = useState('all');

  const filtered = useMemo(() => {
    if (tab === 'unread') return items.filter((item) => !item.read_at);
    if (tab === 'action') return items.filter((item) => isAwaitingVerification(item));
    return items;
  }, [items, tab]);

  // Cliquer n'importe où sur la carte = notification lue + navigation.
  // Un seul point d'entrée évite que le lien, le bouton et la carte
  // divergent, et garantit que le badge part au clic.
  const open = (item, path) => {
    markAsRead(item.id);
    if (path) navigate(path);
  };

  return (
    <div>
      <PageHeader
        title="Notifications"
        subtitle="Paiements validés, tours arrivés, livraisons confirmées : tout ce qui change pour toi."
        breadcrumb={[{ label: 'Mon espace', to: '/tableau-de-bord' }, { label: 'Notifications' }]}
        actions={
          unreadItems > 0 ? (
            <Button variant="secondary" icon={CheckCheck} onClick={markAllAsRead}>
              Tout marquer comme lu
            </Button>
          ) : null
        }
      />

      {items.length > 0 && (
        <Tabs
          className="mb-5"
          ariaLabel="Filtrer les notifications"
          value={tab}
          onChange={setTab}
          tabs={[
            { value: 'all', label: 'Toutes', count: items.length },
            { value: 'unread', label: 'Non lues', count: unreadItems },
            ...(hasActionRequired
              ? [{ value: 'action', label: 'En attente de vérification', count: items.filter(isAwaitingVerification).length }]
              : []),
          ]}
        />
      )}

      {isLoading ? (
        <Card>
          <SkeletonRows rows={5} />
        </Card>
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={Bell}
          title={
            tab === 'unread'
              ? 'Aucune notification non lue'
              : tab === 'action'
                ? 'Rien en attente de vérification'
                : 'Aucune notification'
          }
          description={
            tab === 'unread'
              ? 'Tu es à jour : toutes tes notifications ont été lues.'
              : tab === 'action'
                ? 'Tous les paiements annoncés ont été vérifiés. Rien ne t’attend de ce côté.'
                : 'Tu seras prévenu dès qu’un paiement sera validé ou qu’une livraison sera confirmée.'
          }
          actionTo="/tableau-de-bord"
          actionLabel="Retour au tableau de bord"
        />
      ) : (
        <ul className="space-y-2.5">
          {filtered.map((item) => (
            <li key={item.id}>
              <NotificationCard notification={item} onOpen={(path) => open(item, path)} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function NotificationCard({ notification, onOpen }) {
  const meta = TYPE_META[notification.type] || FALLBACK;
  const Icon = meta.icon;
  const isRead = Boolean(notification.read_at);
  const path = internalPath(notification.data?.url);
  // Tant que la vérification n'a pas eu lieu, l'élément reste signalé : le
  //lire ne suffit pas, c'est l'administrateur qui doit agir.
  const blocked = isAwaitingVerification(notification);

  return (
    <Card
      padding="sm"
      className={`transition-all duration-200 ${
        blocked ? 'border-warning-300 bg-warning-50/50' : isRead ? 'bg-white' : 'border-primary-200 bg-primary-50/40 shadow-sm'
      }`}
    >
      <div className="flex items-start gap-3.5">
        <span
          className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${
            {
              success: 'bg-success-50 text-success-600',
              warning: 'bg-warning-50 text-warning-600',
              accent: 'bg-accent-50 text-accent-600',
              info: 'bg-info-50 text-info-600',
              brand: 'bg-primary-50 text-primary-700',
              neutral: 'bg-ink-100 text-ink-500',
              danger: 'bg-danger-50 text-danger-600',
            }[meta.tone] || 'bg-ink-100 text-ink-500'
          }`}
        >
          <Icon className="size-4.5" aria-hidden="true" />
        </span>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={meta.tone} size="xs">
              {meta.label}
            </Badge>
            {blocked && (
              <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wide text-warning-700">
                <Clock3 className="size-3" aria-hidden="true" />
                En attente de vérification
              </span>
            )}
            {!isRead && !blocked && (
              <span className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wide text-primary-700">
                <span className="size-1.5 rounded-full bg-primary-600" aria-hidden="true" />
                Non lue
              </span>
            )}
          </div>

          <p className={`mt-2 text-sm leading-relaxed ${isRead ? 'text-ink-600' : 'font-medium text-ink-900'}`}>
            {path ? (
              <Link
                to={path}
                onClick={() => onOpen(path)}
                className="transition-colors hover:text-primary-700 hover:underline"
              >
                {notification.data?.message}
              </Link>
            ) : (
              <button type="button" onClick={() => onOpen(null)} className="text-left transition-colors hover:text-primary-700 hover:underline">
                {notification.data?.message}
              </button>
            )}
          </p>

          <div className="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-2">
            <time
              dateTime={notification.created_at}
              title={formatDateTime(notification.created_at)}
              className="text-[11px] text-ink-400"
            >
              {formatRelative(notification.created_at)}
            </time>

            {path && (
              <Link
                to={path}
                onClick={() => onOpen(path)}
                className="inline-flex items-center gap-1 text-[11px] font-semibold text-primary-700 hover:underline"
              >
                Voir le détail
                <ArrowRight className="size-3" aria-hidden="true" />
              </Link>
            )}

            {!blocked && (
              <button
                type="button"
                onClick={() => onOpen(null)}
                className="inline-flex items-center gap-1 text-[11px] font-semibold text-ink-500 transition-colors hover:text-ink-800"
              >
                <CheckCheck className="size-3" aria-hidden="true" />
                Marquer comme lue
              </button>
            )}
          </div>
        </div>
      </div>
    </Card>
  );
}
