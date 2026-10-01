import { useMemo, useState } from 'react';
import { Ban, BadgeCheck, CircleCheck, MailWarning, MoreHorizontal, Send, UserCog, Users, XCircle } from 'lucide-react';
import { api } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { StatCard } from '../../components/ui/StatCard';
import { Button, IconButton } from '../../components/ui/Button';
import { Dropdown, DropdownItem, DropdownSeparator } from '../../components/ui/Dropdown';
import { SearchInput } from '../../components/ui/Input';
import { Select } from '../../components/ui/Input';
import { StatusBadge } from '../../components/ui/Badge';
import { Avatar } from '../../components/ui/Avatar';
import { DataTable } from '../../components/ui/Table';
import { ConfirmationDialog } from '../../components/ui/ConfirmationDialog';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { normalizePagination } from '../../components/ui/Pagination';
import { Pagination } from '../../components/ui/Pagination';
import { formatDate, pluralize } from '../../utils/format';
import { resolveStatus } from '../../utils/status';

/**
 * Administration — utilisateurs.
 *
 * GET /admin/users accepte role, status, verified (yes|no) et q, et pagine
 * (20 par page).
 *
 * La vérification d'e-mail est une action d'administration à part entière :
 * sans elle, le middleware `verified` du backend empêche le membre de
 * rejoindre une tontine, d'en créer une et de régler ses versements. Trois
 * actions complémentaires sont proposées :
 *   - vérifier   : valide l'adresse manuellement (le membre est prévenu) ;
 *   - renvoyer   : lui renvoie le lien officiel, preuve d'adresse conservée ;
 *   - dévérifier : retire la validation et révoque ses sessions.
 */
function AdminUsers() {
  const { user: currentUser } = useAuth();
  const toast = useToast();
  useDocumentTitle('Utilisateurs');

  const [query, setQuery] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');
  const [verified, setVerified] = useState('');
  const [page, setPage] = useState(1);
  const [busyId, setBusyId] = useState(null);
  const [confirm, setConfirm] = useState(null);

  const { data, isLoading, error, reload } = useApi(
    () =>
      api.get('/admin/users', {
        page,
        role: role || undefined,
        status: status || undefined,
        verified: verified || undefined,
        q: query || undefined,
      }),
    [page, role, status, verified, query],
  );

  const users = data?.data || [];
  const pagination = normalizePagination(data);

  const stats = useMemo(() => {
    const list = data?.data || [];
    return {
      shown: list.length,
      blocked: list.filter((item) => item.is_blocked).length,
      merchants: list.filter((item) => item.role === 'merchant').length,
      unverified: list.filter((item) => !item.is_verified).length,
    };
  }, [data]);

  const toggleBlock = async (target) => {
    const blocking = !target.is_blocked;
    setBusyId(target.id);
    try {
      const response = blocking
        ? await api.post(`/admin/users/${target.id}/block`)
        : await api.post(`/admin/users/${target.id}/unblock`);
      toast.success(response?.message || (blocking ? 'Utilisateur suspendu.' : 'Utilisateur réactivé.'));
      await reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusyId(null);
      setConfirm(null);
    }
  };

  const verifyEmail = async (target) => {
    setBusyId(target.id);
    try {
      const response = await api.post(`/admin/users/${target.id}/verify-email`);
      toast.success(response?.message || 'Adresse e-mail vérifiée.');
      await reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusyId(null);
    }
  };

  const resendVerification = async (target) => {
    setBusyId(target.id);
    try {
      const response = await api.post(`/admin/users/${target.id}/resend-verification`);
      toast.success(response?.message || 'Lien de vérification renvoyé.');
      await reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusyId(null);
    }
  };

  const unverifyEmail = async (target) => {
    setBusyId(target.id);
    try {
      const response = await api.post(`/admin/users/${target.id}/unverify-email`);
      const revoked = response?.revoked_sessions ?? 0;
      toast.success(
        revoked > 0
          ? `Vérification retirée. ${revoked} session(s) révoquée(s).`
          : 'Vérification retirée.',
      );
      await reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusyId(null);
      setConfirm(null);
    }
  };

  const columns = [
    {
      key: 'user',
      header: 'Utilisateur',
      render: (row) => (
        <div className="flex min-w-0 items-center gap-3">
          <Avatar src={row.avatar_url} name={row.name} size="sm" />
          <div className="min-w-0">
            <p className="truncate font-semibold text-ink-900">{row.name}</p>
            <p className="truncate text-xs text-ink-500">{row.email}</p>
          </div>
        </div>
      ),
      mobileClassName: 'text-left',
    },
    {
      key: 'phone',
      header: 'Téléphone',
      hideOnMobile: true,
      render: (row) => <span className="text-ink-600">{row.phone || '—'}</span>,
    },
    {
      key: 'role',
      header: 'Rôle',
      render: (row) => <StatusBadge status={resolveStatus(row.role)} size="sm" />,
    },
    {
      key: 'joined',
      header: 'Inscription',
      hideOnMobile: true,
      render: (row) => <span className="text-ink-600">{formatDate(row.created_at)}</span>,
    },
    {
      key: 'state',
      header: 'État',
      render: (row) => (
        <div className="flex flex-wrap justify-end gap-1.5">
          {row.is_blocked && <StatusBadge status="blocked" size="sm" dot />}
          {row.is_verified ? (
            <StatusBadge
              status="approved"
              label="E-mail vérifié"
              size="sm"
              dot
              className="cursor-help"
              title={row.email_verified_at ? `Vérifié le ${formatDate(row.email_verified_at)}` : undefined}
            />
          ) : (
            <StatusBadge status="pending" label="E-mail non vérifié" size="sm" dot />
          )}
        </div>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      mobileLabel: false,
      render: (row) => {
        if (row.id === currentUser?.id) {
          return <span className="text-xs text-ink-400">Vous</span>;
        }
        // Un compte administrateur est TOUJOURS considéré comme vérifié
        // (User::hasVerifiedEmail) : aucune action n'aurait de sens.
        if (row.role === 'admin') {
          return <span className="text-xs text-ink-400">—</span>;
        }

        const busy = busyId === row.id;

        return (
          <div className="flex items-center justify-end gap-1.5">
            {/* Action principale : débloquer le membre sans attendre son clic. */}
            {!row.is_verified && (
              <Button
                size="xs"
                icon={BadgeCheck}
                loading={busy}
                onClick={() => verifyEmail(row)}
                title="Valider manuellement cette adresse e-mail"
              >
                Vérifier
              </Button>
            )}

            {row.is_blocked ? (
              <Button
                variant="secondary"
                size="xs"
                icon={CircleCheck}
                disabled={busy}
                onClick={() => setConfirm({ target: row, blocking: false })}
              >
                Réactiver
              </Button>
            ) : (
              <Button
                variant="danger-outline"
                size="xs"
                icon={Ban}
                disabled={busy}
                onClick={() => setConfirm({ target: row, blocking: true })}
              >
                Suspendre
              </Button>
            )}

            <Dropdown
              width="w-64"
              trigger={
                <IconButton
                  icon={MoreHorizontal}
                  label={`Autres actions pour ${row.name}`}
                  size="sm"
                  variant="secondary"
                  disabled={busy}
                />
              }
            >
              {!row.is_verified && (
                <DropdownItem icon={Send} onClick={() => resendVerification(row)}>
                  Renvoyer le lien de vérification
                </DropdownItem>
              )}
              {row.is_verified && (
                <DropdownItem
                  icon={XCircle}
                  danger
                  onClick={() => setConfirm({ target: row, unverify: true })}
                >
                  Retirer la vérification
                </DropdownItem>
              )}
              <DropdownSeparator />
              <p className="px-3 py-2 text-[11px] leading-relaxed text-ink-400">
                {row.is_verified
                  ? `Vérifié le ${formatDate(row.email_verified_at)}.`
                  : 'Sans e-mail vérifié, le membre ne peut ni rejoindre une tontine, ni en créer, ni payer.'}
              </p>
            </Dropdown>
          </div>
        );
      },
    },
  ];

  return (
    <div>
      <PageHeader
        title="Utilisateurs"
        subtitle="Comptes clients, commerçants et administrateurs de la plateforme."
        breadcrumb={[{ label: 'Administration', to: '/admin' }, { label: 'Utilisateurs' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            <Select
              value={verified}
              onChange={(event) => { setVerified(event.target.value); setPage(1); }}
              className="w-52"
              aria-label="Filtrer par vérification d'e-mail"
            >
              <option value="">Toute vérification</option>
              <option value="no">E-mail non vérifiés</option>
              <option value="yes">E-mail vérifiés</option>
            </Select>

            <Select
              value={status}
              onChange={(event) => { setStatus(event.target.value); setPage(1); }}
              className="w-44"
              aria-label="Filtrer par état du compte"
            >
              <option value="">Tous les états</option>
              <option value="active">Actifs</option>
              <option value="blocked">Bloqués</option>
            </Select>
          </div>
        }
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Sur cette page" value={stats.shown} icon={Users} />
        <StatCard label="Commerçants" value={stats.merchants} icon={UserCog} tone="brand" />
        <StatCard label="Comptes bloqués" value={stats.blocked} icon={Ban} tone={stats.blocked ? 'danger' : 'neutral'} />
        <StatCard
          label="E-mail non vérifiés"
          value={stats.unverified}
          sublabel={
            verified === 'no'
              ? 'Filtre actif sur cette page'
              : 'Bloquent l’adhésion et le paiement'
          }
          icon={MailWarning}
          tone={stats.unverified ? 'warning' : 'success'}
        />
      </div>

      <div className="mb-5 flex flex-col gap-3 sm:flex-row">
        <SearchInput
          value={query}
          onChange={(value) => { setQuery(value); setPage(1); }}
          onClear={() => { setQuery(''); setPage(1); }}
          placeholder="Rechercher un nom ou un e-mail…"
          className="sm:max-w-sm"
        />
        <Select value={role} onChange={(event) => { setRole(event.target.value); setPage(1); }} className="sm:max-w-[200px]" aria-label="Filtrer par rôle">
          <option value="">Tous les rôles</option>
          <option value="client">Client</option>
          <option value="merchant">Commerçant</option>
          <option value="admin">Administrateur</option>
        </Select>
      </div>

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <DataTable
          columns={columns}
          rows={users}
          loading={isLoading}
          empty={
            <EmptyState
              icon={Users}
              title="Aucun utilisateur trouvé"
              description="Ajuste ta recherche ou les filtres pour élargir les résultats."
              compact
            />
          }
        />
      )}

      <Pagination meta={pagination} onPageChange={setPage} className="mt-6" itemLabel={pluralize(users.length, 'utilisateur').split(' ')[1]} />

      <ConfirmationDialog
        open={Boolean(confirm)}
        onClose={() => setConfirm(null)}
        onConfirm={() => (confirm?.unverify ? unverifyEmail(confirm.target) : toggleBlock(confirm.target))}
        title={
          confirm?.unverify
            ? 'Retirer la vérification ?'
            : confirm?.blocking
              ? 'Suspendre ce compte ?'
              : 'Réactiver ce compte ?'
        }
        message={
          confirm?.unverify ? (
            <>
              <strong>{confirm?.target?.name}</strong> ne pourra plus rejoindre ni créer une tontine, ni régler
              ses versements. Ses sessions ouvertes seront <strong>révoquées</strong> et il recevra un nouveau
              lien de vérification.
            </>
          ) : confirm?.blocking ? (
            <>
              <strong>{confirm?.target?.name}</strong> sera déconnecté et toutes ses sessions seront révoquées.
              Il ne pourra plus se connecter ni effectuer de versement.
            </>
          ) : (
            <>
              <strong>{confirm?.target?.name}</strong> pourra de nouveau se connecter. L’activation est
              immédiate, sans validation supplémentaire.
            </>
          )
        }
        confirmLabel={
          confirm?.unverify
            ? 'Retirer la vérification'
            : confirm?.blocking
              ? 'Suspendre le compte'
              : 'Réactiver le compte'
        }
        tone={confirm?.blocking || confirm?.unverify ? 'danger' : 'primary'}
        loading={busyId !== null}
      />
    </div>
  );
}

export default AdminUsers;
