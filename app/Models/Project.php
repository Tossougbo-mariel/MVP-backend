<?php

namespace App\Models;

use App\Support\TaskStatusResolver;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $appends = ['progress'];

    protected $fillable = [
        'agency_id', 'name', 'description', 'start_date',
        'due_date', 'status', 'owner_id', 'wallpaper',
    ];

    /**
     * Avancement du projet, en pourcentage.
     *
     * Le poids de chaque statut est derive de sa position dans la liste de
     * l'agence : la derniere colonne vaut 100, la premiere 0. Les statuts
     * terminaux valent toujours 100. Sans statuts configures, on retombe sur
     * les quatre statuts historiques et le resultat est identique a
     * l'ancien calcul a credits fixes.
     */
    public function getProgressAttribute(): int
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        $total = $tasks->count();

        if ($total === 0) {
            return 0;
        }

        $statuses = TaskStatusResolver::forAgency((int) $this->agency_id)->values();
        $lastIndex = max(0, $statuses->count() - 1);

        $credits = [];
        foreach ($statuses as $i => $status) {
            $credits[$status['key']] = $status['is_terminal'] || $lastIndex === 0
                ? 100
                : (int) round(($i / $lastIndex) * 100);
        }

        // Statut inconnu pour cette agence : on ne lui attribue aucun credit
        // plutot que de le deviner.
        $sum = $tasks->sum(fn (Task $task) => $credits[$task->status] ?? 0);

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
