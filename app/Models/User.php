<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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
