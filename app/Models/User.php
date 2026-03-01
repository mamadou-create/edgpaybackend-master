<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Enums\RoleEnum;
use App\Traits\TwoFactorAuthenticatable;
use App\Traits\TraitUuid;

use Illuminate\Support\Str;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TraitUuid, TwoFactorAuthenticatable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'email',
        'phone',
        'display_name',
        'profile_photo_path',
        'role_id',
        'is_pro',
        'solde_portefeuille',
        'commission_portefeuille',
        'status',
        'password',
        'profile_photo_path',
        'otp',
        'two_factor_enabled',
        'two_factor_token',
        'two_factor_expires_at',
        'activation_token',
        'email_verified_at',
        'activation_account_expires_at',
        'password_reset_token',
        'password_reset_expires_at',
        'assigned_user'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_token',
        'activation_token',
        'password_reset_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activation_account_expires_at' => 'datetime',
            'password' => 'hashed',
            'is_pro' => 'boolean',
            'status' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'solde_portefeuille' => 'integer',
            'commission_portefeuille' => 'integer',
            'two_factor_expires_at' => 'datetime',
            'password_reset_expires_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Vérifie si le compte utilisateur est activé
     */
    public function isActivated()
    {
        return $this->status && $this->email_verified_at !== null;
    }

    /**
     * Génère un token d'activation (SMS)
     */
    public function generateActivationToken()
    {
        $this->otp = rand(100000, 999999); // Code à 6 chiffres pour SMS
        $this->activation_token = Str::random(60); // Gardé pour compatibilité
        $this->activation_account_expires_at = now()->addMinutes(60); // ⚠️ Correction: addMinutes au lieu de addMinute
        $this->save();

        return $this->otp; // ✅ Retourne le code SMS
    }

    /**
     * Réinitialise le token d'activation
     */
    public function resetActivationToken()
    {
        $this->otp = null;
        $this->activation_token = null;
        $this->activation_account_expires_at = null;
        $this->save();
    }

    /**
     * Vérifie si le token d'activation est valide
     */
    public function isActivationAccountTokenValid($token)
    {
        return $this->otp === $token &&
            $this->activation_account_expires_at &&
            $this->activation_account_expires_at->isFuture();
    }

    /**
     * Génère un token de réinitialisation de mot de passe (SMS)
     */
    public function generatePasswordResetToken()
    {
        $this->otp = rand(100000, 999999); // Code à 6 chiffres pour SMS
        $this->password_reset_token = Str::random(60); // Gardé pour compatibilité
        $this->password_reset_expires_at = now()->addMinutes(60); // ⚠️ Correction: addMinutes
        $this->save();

        return $this->otp; // ✅ Retourne le code SMS
    }

    /**
     * Vérifie si le token de réinitialisation est valide
     */
    public function isPasswordResetTokenValid($token)
    {
        return $this->otp === $token &&
            $this->password_reset_expires_at &&
            $this->password_reset_expires_at->isFuture();
    }

    /**
     * Réinitialise le token de réinitialisation de mot de passe
     */
    public function resetPasswordResetToken()
    {
        $this->otp = null;
        $this->password_reset_token = null;
        $this->password_reset_expires_at = null;
        $this->save();
    }

    /**
     * Vérifie si le numéro de téléphone est valide (format Guinée)
     */
    public function hasValidPhoneFormat(): bool
    {
        if (!$this->phone) {
            return false;
        }

        // Format: 623XXXXXX, 65XXXXXXX, 66XXXXXXX
        $cleaned = preg_replace('/\s+/', '', $this->phone);
        return preg_match('/^(62|65|66)[0-9]{7}$/', $cleaned) === 1;
    }

    /**
     * Nettoie et formate le numéro de téléphone
     */
    public function getFormattedPhoneAttribute(): string
    {
        if (!$this->phone) {
            return '';
        }
        return preg_replace('/\s+/', '', $this->phone);
    }

    /**
     * Marque le téléphone comme vérifié
     */
    public function markPhoneAsVerified()
    {
        $this->phone_verified_at = now();
        $this->save();
    }

    /**
     * Vérifie si le téléphone est vérifié
     */
    public function hasVerifiedPhone(): bool
    {
        return !is_null($this->phone_verified_at);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey(); // Returns user ID
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     */
    public function getJWTCustomClaims()
    {
        return [
            'phone' => $this->phone,
            'display_name' => $this->display_name,
            'type' => 'user_credentials'
        ];
    }

    /**
     * Find user by phone for authentication
     */
    public static function findByPhone($phone)
    {
        // Nettoyer le numéro avant recherche
        $cleanedPhone = preg_replace('/\s+/', '', $phone);
        return static::where('phone', $cleanedPhone)->first();
    }

    /**
     * Scope pour les utilisateurs avec téléphone vérifié
     */
    public function scopePhoneVerified($query)
    {
        return $query->whereNotNull('phone_verified_at');
    }

    /**
     * Scope pour les utilisateurs actifs
     */
    public function scopeActive($query)
    {
        return $query->where('status', true)->whereNotNull('email_verified_at');
    }

    public function user_assigned()
    {
        return $this->hasOne(User::class, 'assigned_user');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    // Synchroniser le solde avec le wallet
    public function syncWalletBalance(): void
    {
        if ($this->wallet && $this->solde_portefeuille != $this->wallet->cash_available) {
            $this->solde_portefeuille = $this->wallet->cash_available;
            $this->save();
        }
    }

    // Synchroniser la commission avec le wallet
    public function syncCommissionBalance(): void
    {
        if ($this->wallet && $this->commission_portefeuille != $this->wallet->commission_balance) {
            $this->commission_portefeuille = $this->wallet->commission_balance;
            $this->save();
        }
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }


    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function lastMessage()
    {
        return $this->hasOne(Message::class, 'receiver_id')
            ->orWhere('sender_id', $this->id)
            ->latest();
    }

    public function readAnnouncements(): BelongsToMany
    {
        return $this->belongsToMany(Announcement::class, 'announcement_user')
            ->withPivot('read_at')
            ->withTimestamps();
    }



    // Si vous voulez aussi pouvoir accéder au wallet même s'il est supprimé (soft delete)
    public function walletWithTrashed()
    {
        return $this->hasOne(Wallet::class)->withTrashed();
    }

    public function hasPermission($permissionSlug)
    {
        if (!$this->role) return false;

        return $this->role->hasPermission($permissionSlug);
    }

    public function hasLimitedPermission($permissionSlug)
    {
        if (!$this->role) return false;

        return $this->role->hasLimitedPermission($permissionSlug);
    }

    public function isSuperAdmin()
    {
        return $this->role && $this->role->is_super_admin;
    }

    public function isSubAdmin()
    {
        return $this->role && !$this->role->is_super_admin && $this->role->slug !== RoleEnum::CLIENT && $this->role->slug !== RoleEnum::PRO && $this->role->slug !== RoleEnum::API_CLIENT;
    }

    public function isPro()
    {
        return $this->role && $this->role->slug === RoleEnum::PRO;
    }

    public function commissionReceiver(): User
    {
        // Cas 1 : l'utilisateur est un PRO
        if ($this->isPro()) {
            // S'il a été créé par un sous-admin
            if ($this->assigned_user) {
                $creator = User::find($this->assigned_user);

                if ($creator && $creator->isSubAdmin()) {
                    return $creator;
                }
            }
        }

        // Cas 2 : l'utilisateur est un sous-admin
        if ($this->isSubAdmin()) {
            return User::whereHas('role', fn($q) => $q->where('is_super_admin', true))->first();
        }

        // Fallback : Super Admin
        return User::whereHas('role', fn($q) => $q->where('is_super_admin', true))->first();
    }


    public function getIsSuperAdminAttribute(): bool
    {
        return $this->isSuperAdmin();
    }

    public function getIsSubAdminAttribute(): bool
    {
        return $this->isSubAdmin();
    }

    public function getIsClientAttribute(): bool
    {
        return $this->role && $this->role->slug === RoleEnum::CLIENT;
    }

    /**
     * Vérifie si l'utilisateur a un rôle spécifique.
     *
     * @param string $roleSlug
     * @return bool
     */
    public function hasRole($roleSlug)
    {
        return $this->role && $this->role->slug === $roleSlug;
    }

    public static function generateApiClientCredentials()
    {
        return [
            'phone' => "mding-" . Str::uuid()->toString(),
            'password' => Str::random(64),
        ];
    }

    // ─── Relations Module Crédit ──────────────────────────────────────────────

    public function creditProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CreditProfile::class, 'user_id');
    }

    public function creances(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Creance::class, 'user_id');
    }

    public function creanceTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CreanceTransaction::class, 'user_id');
    }

    public function ledgerEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'user_id');
    }

    public function anomalies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AnomalyFlag::class, 'user_id');
    }

    public function auditLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }
}
