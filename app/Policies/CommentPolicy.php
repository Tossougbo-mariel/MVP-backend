<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;

class CommentPolicy
{
    // Voir les commentaires d'une tâche — admin, ou membre du projet concerné
    public function view(User $user, Comment $comment): bool
    {
        $agencyId = $comment->task->project->agency_id;

        return $user->isAdminOfAgency($agencyId)
            || $comment->task->project->hasMember($user->id);
    }

    // Ajouter un commentaire — admin, ou membre du projet concerné
    public function create(User $user, int $taskId): bool
    {
        $task = Task::findOrFail($taskId);

        return $user->isAdminOfAgency($task->project->agency_id)
            || $task->project->hasMember($user->id);
    }

    // Supprimer un commentaire — uniquement son propre commentaire (section 9 du cahier des charges)
    // même un admin ne peut pas supprimer le commentaire de quelqu'un d'autre
    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }
}
