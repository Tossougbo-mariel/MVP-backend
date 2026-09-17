<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    // Voir une tâche — admin de l'agence, ou membre du projet auquel elle appartient
    // (le Kanban doit montrer toutes les tâches du projet, pas juste les siennes)
    public function view(User $user, Task $task): bool
    {
        $agencyId = $task->project->agency_id;

        return $user->roleInAgency($agencyId) === 'admin'
            || $task->project->hasMember($user->id);
    }

    // Créer une tâche — admin uniquement
    public function create(User $user, int $projectId): bool
    {
        $agencyId = Project::findOrFail($projectId)->agency_id;

        return $user->roleInAgency($agencyId) === 'admin';
    }

    // Modifier une tâche en entier (titre, description, priorité, responsable, dates)
    // — admin uniquement
    public function update(User $user, Task $task): bool
    {
        return $user->roleInAgency($task->project->agency_id) === 'admin';
    }

    // Changer UNIQUEMENT le statut — admin, OU membre à qui la tâche est assignée
    public function updateStatus(User $user, Task $task): bool
    {
        $agencyId = $task->project->agency_id;

        return $user->roleInAgency($agencyId) === 'admin'
            || $task->assigned_to === $user->id;
    }

    // Supprimer une tâche — admin uniquement
    public function delete(User $user, Task $task): bool
    {
        return $user->roleInAgency($task->project->agency_id) === 'admin';
    }

    // Commenter — admin, ou membre du projet
    public function comment(User $user, Task $task): bool
    {
        return $user->roleInAgency($task->project->agency_id) === 'admin'
            || $task->project->hasMember($user->id);
    }
}
