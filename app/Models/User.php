<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'email', 'password', 'avatar', 'status', 'notification_preferences',
        'first_name', 'last_name', 'phone', 'city', 'bio', 'job_title',
    ];

    /**
     * Colonnes modifiables en masse depuis l'API.
     *
     * `two_factor_enabled` en est volontairement absente : elle exige une
     * confirmation du mot de passe, donc elle passe par forceFill dans
     * OtpController.
     */

    /**
     * `has_password` est toujours présent dans les réponses JSON.
     *
     * Le mot de passe est masqué, mais l'interface doit savoir si l'utilisateur
     * en a un : c'est un compte créé via Google, et lui demander son mot de
     * passe pour activer la double authentification n'aurait pas de sens.
     */
    protected $appends = ['has_password'];

    public function getHasPasswordAttribute(): bool
    {
        return $this->password !== null;
    }

    /** Préférences de notifications par défaut (toutes activées). */
    public const DEFAULT_NOTIFICATION_PREFERENCES = [
        'task_assigned' => true,
        'task_completed' => true,
        'task_removed' => true,
        'comment' => true,
        'mention' => true,
        'deadline_reminder' => true,
    ];

    /** Correspondance type de notification → clé de préférence. */
    public const NOTIFICATION_TYPE_MAP = [
        'tache_assignee' => 'task_assigned',
        'tache_terminee' => 'task_completed',
        'tache_retiree' => 'task_removed',
        'nouveau_commentaire' => 'comment',
        'mention' => 'mention',
        'rappel_echeance' => 'deadline_reminder',
        // Les alertes a 3 jours et les rappels de retard relevent du meme
        // reglage que le rappel de la veille : sans cette correspondance,
        // wantsNotification() les laisserait toujours actives.
        'echeance_proche' => 'deadline_reminder',
        'tache_en_retard' => 'deadline_reminder',
        // Les notifications de rôles et d'accès (nomme_admin, role_modifie,
        // compte_active, compte_desactive, membre_retire) sont volontairement
        // absentes de cette carte : wantsNotification() les livre donc
        // toujours. Une nomination ou une désactivation n'a pas de réglage à
        // côté : la personne doit être prévenue, même si elle a coupé le reste.
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Les adresses sont stockées en minuscules.
     *
     * Sans cela, un compte créé avec "Claire@Example.com" resterait
     * introuvable par une recherche normalisée, et deux comptes ne
     * différant que par la casse pourraient coexister.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : Str::lower(trim($value)),
        );
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    public function ownedAgencies()
    {
        return $this->hasMany(Agency::class, 'owner_id');
    }

    public function agencyMemberships()
    {
        return $this->hasMany(AgencyMember::class);
    }

    public function ownedProjects()
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    public function projectMemberships()
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function tasksAssigned()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function tasksCreated()
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordLink($token));
    }

    /** Codes OTP en attente pour cet utilisateur et cet usage. */
    public function pendingOtpCodes(string $purpose)
    {
        return $this->hasMany(EmailOtpCode::class)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }

    public function roleInAgency(int $agencyId): ?string
    {
        return $this->agencyMemberships()
            ->where('agency_id', $agencyId)
            ->where('status', 'actif')
            ->value('role');
    }

    public function isActiveMemberOfAgency(int $agencyId): bool
    {
        return $this->agencyMemberships()
            ->where('agency_id', $agencyId)
            ->where('status', 'actif')
            ->exists();
    }

    /**
     * Administrateur d'une agence (hors propriétaire).
     *
     * Utilisé par AgencyPolicy et CommentPolicy ; le propriétaire est traité
     * à part via `agency->owner_id`.
     */
    public function isAdminOfAgency(int $agencyId): bool
    {
        return $this->roleInAgency($agencyId) === 'admin';
    }

    public function isMemberOfProject(int $projectId): bool
    {
        return $this->projectMemberships()
            ->where('project_id', $projectId)
            ->exists();
    }

    /** Préférences complètes (valeurs par défaut fusionnées). */
    public function notificationPreferences(): array
    {
        return array_merge(
            self::DEFAULT_NOTIFICATION_PREFERENCES,
            $this->notification_preferences ?? []
        );
    }

    /** L'utilisateur accepte-t-il ce type de notification ? */
    public function wantsNotification(string $type): bool
    {
        $key = self::NOTIFICATION_TYPE_MAP[$type] ?? null;

        if (! $key) {
            return true;
        }

        return (bool) ($this->notificationPreferences()[$key] ?? true);
    }
}
