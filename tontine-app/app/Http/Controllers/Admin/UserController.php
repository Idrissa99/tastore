<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\EmailVerifiedByAdminNotification;
use App\Services\SessionRevocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function __construct(protected SessionRevocationService $sessions) {}

    public function index(Request $request)
    {
        $users = User::with('merchant')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
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

        return view('admin.users.index', compact('users'));
    }

    public function block(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 403, 'Tu ne peux pas te bloquer toi-même.');
        abort_if($user->isAdmin(), 403, 'Impossible de bloquer un autre administrateur.');

        // `is_blocked` n'est pas fillable : affectation directe obligatoire.
        $user->is_blocked = true;
        $user->save();

        // Les tokens déjà délivrés deviennent inutilisables immédiatement.
        $revoked = $this->sessions->afterAccountBlocked($user);

        return back()->with('success', $revoked > 0
            ? "Compte de {$user->name} suspendu. {$revoked} session(s) déconnectée(s)."
            : "Compte de {$user->name} suspendu.");
    }

    public function unblock(User $user)
    {
        abort_if($user->isAdmin(), 403, 'Un administrateur ne peut pas être modifié.');

        $user->is_blocked = false;
        $user->save();

        return back()->with('success', "Compte de {$user->name} réactivé. Il devra se reconnecter.");
    }

    public function verifyEmail(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403, 'Un compte administrateur est toujours vérifié.');
        abort_if($user->hasVerifiedEmail(), 409, 'Cette adresse est déjà vérifiée.');

        $user->markEmailAsVerified();
        $user->notify(new EmailVerifiedByAdminNotification);

        Log::info('Adresse e-mail vérifiée manuellement par un administrateur.', [
            'user_id' => $user->id,
            'actor_id' => $request->user()->id,
        ]);

        return back()->with('success', "Adresse de {$user->name} vérifiée. Le membre a été prévenu.");
    }

    public function unverifyEmail(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403, 'Un administrateur ne peut pas être rétrogradé.');
        abort_if(! $user->hasVerifiedEmail(), 409, 'Cette adresse n’est pas vérifiée.');

        $user->email_verified_at = null;
        $user->save();

        $revoked = $this->sessions->revokeAll($user);

        Log::warning('Vérification d’adresse e-mail retirée par un administrateur.', [
            'user_id' => $user->id,
            'actor_id' => $request->user()->id,
            'revoked_sessions' => $revoked,
        ]);

        return back()->with('success', $revoked > 0
            ? "Vérification retirée. {$revoked} session(s) révoquée(s)."
            : 'Vérification retirée.');
    }

    public function resendVerification(User $user)
    {
        abort_if($user->isAdmin(), 403, 'Un compte administrateur est toujours vérifié.');
        abort_if($user->hasVerifiedEmail(), 409, 'Cette adresse est déjà vérifiée.');

        $user->sendEmailVerificationNotification();

        return back()->with('success', 'Lien de vérification renvoyé.');
    }
}
