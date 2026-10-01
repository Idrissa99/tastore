/**
 * Roue des membres d'une tontine, dans l'ordre de passage.
 *
 * L'ordre de passage est TIRÉ au lancement, c'est-à-dire quand la tontine est
 * complète. Tant que ce n'est pas le cas, l'API renvoie `position: null` et
 * `rotation_revealed: false` : la roue affiche alors les participants SANS
 * leur numéro d'ordre, et aucun ordre de passage ne doit être suggéré — ni
 * par la position dans le tableau, ni par une numérotation.
 *
 * L'anneau ne reflète que l'état de PARTICIPATION :
 *   - le bénéficiaire du round en cours est mis en avant ;
 *   - un membre dont le tour est terminé ET qui a reçu le produit est plein ;
 *   - un membre dont le tour est terminé sans livraison a un contour pointillé
 *     (un tour terminé n'est pas nécessairement livré) ;
 *   - un membre sorti est neutre.
 * L'état de LIVRAISON est détaillé dans la liste en dessous de la roue.
 */
import { initials } from '../../utils/format';

const RING = {
  withdrawn: 'border-ink-200 bg-ink-100 text-ink-400 border-solid',
  beneficiary: 'border-accent-500 bg-accent-500 text-ink-900 shadow-[0_0_0_5px_rgb(245_158_11/0.22)]',
  completed: 'border-dashed border-primary-600 bg-white text-primary-700',
  active: 'border-solid border-primary-600 bg-white text-primary-700',
};

function ringFor(member) {
  if (member.status === 'withdrawn') return RING.withdrawn;
  if (member.status === 'beneficiary') return RING.beneficiary;
  if (member.status === 'completed') {
    return member.delivery_status === 'delivered'
      ? 'border-solid border-primary-600 bg-primary-600 text-white'
      : RING.completed;
  }
  return RING.active;
}

export default function TontineWheel({
  members = [],
  currentRound,
  revealed,
  size = 240,
  className = '',
}) {
  if (!members.length) {
    return (
      <div
        className={`flex items-center justify-center rounded-full border-2 border-dashed border-line text-xs text-ink-400 ${className}`}
        style={{ width: size, height: size }}
      >
        Aucun membre
      </div>
    );
  }

  const radius = size / 2 - 30;
  const center = size / 2;
  const node = size < 200 ? 'size-8 text-[11px]' : 'size-10 text-xs';

  const ringSize = (radius + 16) * 2;

  // Par défaut on se fie aux données : une seule position reçue signifie que
  // l'ordre de passage a été tiré. Sans cela, un appelant qui oublierait la
  // prop afficherait des cercles vides (l'API renvoie `position: null`).
  const showOrder = revealed ?? members.some((member) => member.position != null);

  return (
    <div className={`relative mx-auto ${className}`} style={{ width: size, height: size }}>
      <div
        className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full border border-dashed border-primary-200"
        style={{ width: ringSize, height: ringSize }}
        aria-hidden="true"
      />

      <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
        {showOrder ? (
          <>
            <span className="font-display text-xl font-extrabold tracking-tight text-primary-800">
              Round {currentRound}
            </span>
            <span className="mt-0.5 text-[11px] font-medium text-ink-500">
              {members.length} membre{members.length > 1 ? 's' : ''}
            </span>
          </>
        ) : (
          <span className="px-4 text-[11px] font-medium leading-snug text-ink-500">
            L&apos;ordre de passage sera tiré
            <br />
            quand la tontine sera complète
          </span>
        )}
      </div>

      {members.map((member, index) => {
        const angle = (index / members.length) * 2 * Math.PI - Math.PI / 2;
        const x = center + radius * Math.cos(angle);
        const y = center + radius * Math.sin(angle);

        return (
          <div
            key={member.id}
            title={
              showOrder
                ? `${member.user_name} — position ${member.position} — participation : ${member.status} — livraison : ${member.delivery_status}`
                : `${member.user_name} — participation : ${member.status}`
            }
            className={`absolute flex ${node} items-center justify-center rounded-full border-2 font-bold -translate-x-1/2 -translate-y-1/2 transition-transform duration-200 hover:scale-110 ${ringFor(member)}`}
            style={{ left: x, top: y }}
          >
            {showOrder ? member.position : initials(member.user_name)}
          </div>
        );
      })}
    </div>
  );
}
