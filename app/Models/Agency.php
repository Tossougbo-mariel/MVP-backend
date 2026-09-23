<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agency extends Model
{
    protected $fillable = ['name', 'description', 'owner_id', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];

    public const DEFAULT_SETTINGS = [
        'whoCanInvite' => 'owner',
        'whoCanCreateProjects' => 'admin',
        'defaultTaskView' => 'grid',
        'defaultMemberRole' => 'membre',
        'emailNotifications' => true,
    ];

    public function getSettingsAttribute($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return array_merge(self::DEFAULT_SETTINGS, is_array($value) ? $value : []);
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

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
