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
        'name', 'email', 'password', 'avatar', 'status',
        'first_name', 'last_name', 'phone', 'city', 'bio', 'job_title',
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

    public function isAdminOfAgency(int $agencyId): bool
    {
        $agency = \App\Models\Agency::find($agencyId);
        if ($agency && $agency->owner_id === $this->id) {
            return true; // le owner a toujours les droits admin,
                          // même si sa ligne agency_members est absente
                          // ou désynchronisée
        }
        return $this->roleInAgency($agencyId) === 'admin';
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
}
