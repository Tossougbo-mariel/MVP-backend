<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agency extends Model
{
    protected $fillable = ['name', 'description', 'owner_id', 'settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** Valeur de réglage (drapeau) avec repli sur la valeur par défaut. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** L'agence veut-elle recevoir des e-mails de notification ? */
    public function wantsEmails(): bool
    {
        return (bool) $this->setting('emailNotifications', true);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->hasMany(AgencyMember::class);
    }

    public function invitations()
    {
        return $this->hasMany(Invitation::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
