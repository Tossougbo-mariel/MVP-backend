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
        $author = $comment->user?->name ?? 'Quelqu\'un';
        $content = trim((string) $comment->content);

        // Lien direct vers la tâche commentée (pour les notifications)
        $taskLink = $task->project?->agency_id
            ? "/agences/{$task->project->agency_id}/projets/{$task->project_id}/taches/{$task->id}"
            : null;

        ActivityLog::create([
            'user_id' => $comment->user_id,
            'agency_id' => $task->project?->agency_id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'action' => 'commentaire',
            'description' => "a commenté la tâche « {$task->title} »",
        ]);

        // On notifie le responsable ET le créateur de la tâche,
        // sauf celui qui vient justement de commenter
        $toNotify = collect([$task->assigned_to, $task->created_by])
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $comment->user_id);

        foreach ($toNotify as $userId) {
            Notification::notifyUser(
                (int) $userId,
                'nouveau_commentaire',
                'Nouveau commentaire',
                "{$author} a commenté « {$task->title} »"
                    . ($content !== '' ? " : « {$content} »" : ''),
                $taskLink
            );
        }

        // Mentions explicites (@) : on notifie chaque personne mentionnée
        $mentioned = collect($comment->mention_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $id === $comment->user_id || $toNotify->contains($id));

        foreach ($mentioned as $userId) {
            Notification::notifyUser(
                $userId,
                'mention',
                'Vous avez été mentionné',
                "{$author} vous a mentionné sur « {$task->title} »"
                    . ($content !== '' ? " : « {$content} »" : ''),
                $taskLink
            );
        }
    }
}
