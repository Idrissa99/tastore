<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, \Illuminate\Auth\MustVerifyEmail;

    /**
     * `role` restefillable car AuthController l'assigne explicitement à
     * l'inscription — RegisterRequest le borne à client|merchant, il n'existe
     * aucun endpoint d'update de profil.
     *
     * Volontairement ABSENTS du fillable (mass-assignment) car ils ne doivent
     * jamais pouvoir être posés par une requête HTTP :
     *   - `is_verified`  : dérivé de `email_verified_at` (voir booted()).
     *   - `is_blocked`   : réservé aux administrateurs, via affectation directe.
     *   - `avatar_path`   : réservé au contrôleur de profil.
     *   - `email_verified_at` / `password` : gérés par Laravel uniquement.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
    ];

    protected $appends = ['avatar_url'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_verified' => 'boolean',
            'is_blocked' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            $user->is_verified = ! is_null($user->email_verified_at);
        });
    }

    /**
     * Un compte admin est toujours considéré comme vérifié — pas besoin de
     * cliquer sur un lien d'email pour débloquer les actions protégées par
     * le middleware "verified". Ça ne change rien pour client/commerçant.
     */
    public function hasVerifiedEmail(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function merchant(): HasOne
    {
        return $this->hasOne(Merchant::class);
    }

    public function tontinesCreated(): HasMany
    {
        return $this->hasMany(Tontine::class, 'created_by');
    }

    public function tontineMemberships(): HasMany
    {
        return $this->hasMany(TontineMember::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isMerchant(): bool
    {
        return $this->role === 'merchant';
    }

    public function isClient(): bool
    {
        return $this->role === 'client';
    }
}
