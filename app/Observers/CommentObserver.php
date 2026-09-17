<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Notification;

class CommentObserver
{
    public function created(Comment $comment): void
    {
        $task = $comment->task;

        ActivityLog::create([
            'user_id' => $comment->user_id,
            'agency_id' => $task->project?->agency_id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'action' => 'commentaire',
            'description' => "a commenté « {$task->title} »",
        ]);

        // On notifie le responsable ET le créateur de la tâche,
        // sauf celui qui vient justement de commenter
        $toNotify = collect([$task->assigned_to, $task->created_by])
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $comment->user_id);

        foreach ($toNotify as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type' => 'nouveau_commentaire',
                'title' => 'Nouveau commentaire',
                'message' => "Nouveau commentaire sur « {$task->title} »",
            ]);
        }
    }
}
