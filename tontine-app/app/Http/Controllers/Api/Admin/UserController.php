<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Notifications\EmailVerifiedByAdminNotification;
use App\Services\SessionRevocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gestion des comptes utilisateurs par un administrateur.
 *
 * Règles communes à toutes les actions de gestion :
 *  - un compte `admin` n'est jamais modifiable (il est toujours considéré
 *    comme vérifié — voir User::hasVerifiedEmail) ;
 *  - `is_blocked` et `email_verified_at` ne sont PAS fillable : toute
 *    écriture passe par une affectation directe, sinon une requête HTTP
 *    pourrait élever un statut via mass-assignment ;
 *  - les actions sensibles (blocage, rétrogradation de la vérification)
 *    révoquent les tokens Sanctum déjà délivrés ;
 *  - chaque action de gestion est journalisée : vérifier un e-mail débloque
 *    le middleware `verified`, donc l'accès aux versements.
 */
class UserController extends Controller
{
    public function __construct(protected SessionRevocationService $sessions) {}

    public function index(Request $request)
    {
        $users = User::with('merchant')
            ->withCount('tontineMemberships')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('status'), function ($query) use ($request) {
                match ($request->input('status')) {
                    'blocked' => $query->where('is_blocked', true),
                    'active' => $query->where('is_blocked', false),
                    default => null,
                };
            })
            // Les comptes bloqués ou non vérifiés sont les deux files de
            // travail réelles de l'administrateur : on doit pouvoir les
            // isoler sans croiser les filtres.
            ->when($request->filled('verified'), function ($query) use ($request) {
                match ($request->input('verified')) {
                    'yes' => $query->whereNotNull('email_verified_at'),
                    'no' => $query->whereNull('email_verified_at'),
                    default => null,
                };
            })
            ->when($request->filled('q'), fn ($q) => $q->where(function ($query) use ($request) {
                $query->where('name', 'like', '%'.$request->input('q').'%')
                    ->orWhere('email', 'like', '%'.$request->input('q').'%');
            }))
            ->latest()
            ->paginate(20);

        return AdminUserResource::collection($users);
    }

    public function block(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 403, 'Tu ne peux pas te bloquer toi-même.');
        abort_if($user->isAdmin(), 403, 'Impossible de bloquer un autre administrateur.');

        // Affectation directe : `is_blocked` n'est pas fillable, ce qui empêche
        // toute élévation/abaissement de statut par mass-assignment.
        $user->is_blocked = true;
        $user->save();

        // Les tokens existants deviennent immédiatement inutilisables.
        $revoked = $this->sessions->afterAccountBlocked($user);

        return response()->json([
            'message' => "Utilisateur bloqué. {$revoked} session(s) révoquée(s).",
            'revoked_sessions' => $revoked,
            'user' => new AdminUserResource($user->load('merchant')),
        ]);
    }

    public function unblock(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403, 'Un administrateur ne peut pas être modifié.');

        $user->is_blocked = false;
        $user->save();

        return response()->json([
            'message' => 'Utilisateur débloqué. Il devra se reconnecter.',
            'user' => new AdminUserResource($user->load('merchant')),
        ]);
    }

    /**
     * Valide manuellement l'adresse e-mail d'un membre.
     *
     * Contexte : un membre ne reçoit jamais le lien (SMS Punch, e-mail
     * erroné, adresse saisie de travers). Sans vérification, le middleware
     * `verified` lui interdit de rejoindre une tontine et de payer.
     * L'administrateur peut confirmer l'adresse après contrôle — et le
     * membre en est prévenu.
     */
    public function verifyEmail(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403, 'Un compte administrateur est toujours vérifié.');
        abort_if($user->hasVerifiedEmail(), 409, 'Cette adresse est déjà vérifiée.');

        // `email_verified_at` n'est pas fillable : `markEmailAsVerified()`
        // écrit en direct et `User::booted()` resynchronise `is_verified`.
        $user->markEmailAsVerified();

        // Sans cette notification, le membre reste bloqué sur l'écran
        // « vérifie ton email » en croyant à un problème technique.
        $user->notify(new EmailVerifiedByAdminNotification);

        Log::info('Adresse e-mail vérifiée manuellement par un administrateur.', [
            'user_id' => $user->id,
            'actor_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => "Adresse de {$user->name} vérifiée. Le membre a été prévenu.",
            'user' => new AdminUserResource($user->fresh()->load('merchant')),
        ]);
    }

    /**
     * Retire la vérification d'un compte.
     *
     * Action corrective (erreur de l'administrateur, adresse usurpée).
     * Elle RETIRE un droit : les sessions déjà délivrées sont révoquées,
     * sinon le membre resterait connecté avec un compte que le middleware
     * `verified` ne devrait plus laisser agir.
     */
    public function unverifyEmail(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403, 'Un administrateur ne peut pas être rétrogradé.');
        abort_if(! $user->hasVerifiedEmail(), 409, 'Cette adresse n’est pas vérifiée.');

        // Affectation directe + `save()` : `booted()` repasse `is_verified`
        // à false automatiquement.
        $user->email_verified_at = null;
        $user->save();

        $revoked = $this->sessions->revokeAll($user);

        Log::warning('Vérification d’adresse e-mail retirée par un administrateur.', [
            'user_id' => $user->id,
            'actor_id' => $request->user()->id,
            'revoked_sessions' => $revoked,
        ]);

        return response()->json([
            'message' => $revoked > 0
                ? "Vérification retirée. {$revoked} session(s) révoquée(s)."
                : 'Vérification retirée.',
            'revoked_sessions' => $revoked,
            'user' => new AdminUserResource($user->fresh()->load('merchant')),
        ]);
    }

    /**
     * Renvoie le lien de vérification au lieu de valider l'adresse.
     *
     * Préférable quand le membre peut encore utiliser sa boîte mail : la
     * preuve reste dans le domaine du titulaire de l'adresse.
     */
    public function resendVerification(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403, 'Un compte administrateur est toujours vérifié.');
        abort_if($user->hasVerifiedEmail(), 409, 'Cette adresse est déjà vérifiée.');

        $user->sendEmailVerificationNotification();

        Log::info('Lien de vérification renvoyé par un administrateur.', [
            'user_id' => $user->id,
            'actor_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Lien de vérification renvoyé.',
        ]);
    }
}
