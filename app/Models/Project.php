<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $appends = ['progress'];

    protected $fillable = [
        'agency_id', 'name', 'description', 'start_date',
        'due_date', 'status', 'owner_id', 'wallpaper',
    ];

    public function getProgressAttribute(): int
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        $total = $tasks->count();

        if ($total === 0) {
            return 0;
        }

        $credits = [
            'a_faire' => 0,
            'en_cours' => 25,
            'en_revision' => 70,
            'terminee' => 100,
        ];

        $sum = $tasks->sum(fn ($task) => $credits[$task->status] ?? 0);

        return (int) round($sum / $total);
    }

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function hasMember(int $userId): bool
    {
        return $this->members()->where('user_id', $userId)->exists();
    }
}
