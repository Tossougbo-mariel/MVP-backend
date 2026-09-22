<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
        'project_id', 'title', 'description', 'status', 'priority',
        'assigned_to', 'created_by', 'start_date', 'due_date', 'completed_at',
        'archived_at',
    ];

    protected $casts = [
        'reminder_sent_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

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
