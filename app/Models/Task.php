<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Task extends Model
{
    protected $appends = ['deadline_status'];

    protected $fillable = [
        'project_id', 'title', 'description', 'status', 'priority',
        'assigned_to', 'created_by', 'start_date', 'due_date', 'completed_at',
        'archived_at',
    ];

    protected $casts = [
        'reminder_sent_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected function deadlineStatus(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            if ($this->status === 'terminee') {
                return null;
            }

            if (! $this->due_date) {
                return 'a_venir';
            }

            $today = Carbon::today();
            $due = Carbon::parse($this->due_date)->startOfDay();

            if ($due->lt($today)) {
                return 'en_retard';
            }

            if ($today->diffInDays($due) <= 3) {
                return 'a_echeance';
            }

            return 'a_venir';
        });
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function subtasks()
    {
        return $this->hasMany(Subtask::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    // Tâches dont celle-ci dépend (prérequis)
    public function dependencies()
    {
        return $this->belongsToMany(
            Task::class,
            'task_dependencies',
            'task_id',
            'depends_on_task_id'
        );
    }

    // Tâches qui dépendent de celle-ci
    public function dependents()
    {
        return $this->belongsToMany(
            Task::class,
            'task_dependencies',
            'depends_on_task_id',
            'task_id'
        );
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
