<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    // Voir une tâche en détail — admin, ou membre du projet à qui la tâche est
    // ASSIGNÉE. Un membre non assigné peut suivre la carte sur le Kanban
    // (évolution du statut dans les colonnes) mais n'accède pas aux détails
    // (commentaires, sous-tâches, pièces jointes, activité).
    public function view(User $user, Task $task): bool
    {
        $agencyId = $task->project->agency_id;
        $isAdmin = in_array($user->roleInAgency($agencyId), ['admin'], true);

        return $isAdmin
            || ($task->project->hasMember($user->id) && $task->assigned_to === $user->id);
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

    // L'assigné (ou l'admin) est le seul à pouvoir agir dessus : c'est sa tâche.
    private function isAssignedOrAdmin(User $user, Task $task): bool
    {
        return $user->roleInAgency($task->project->agency_id) === 'admin'
            || $task->assigned_to === $user->id;
    }

    // Changer UNIQUEMENT le statut — admin, OU membre à qui la tâche est assignée
    public function updateStatus(User $user, Task $task): bool
    {
        return $this->isAssignedOrAdmin($user, $task);
    }

    // Supprimer une tâche — admin uniquement
    public function delete(User $user, Task $task): bool
    {
        return $user->roleInAgency($task->project->agency_id) === 'admin';
    }

    // Commenter — admin, ou assigné à la tâche
    public function comment(User $user, Task $task): bool
    {
        return $this->isAssignedOrAdmin($user, $task);
    }

    // Gérer les sous-tâches (créer, cocher, renommer, supprimer)
    // — admin, ou assigné à la tâche
    public function manageSubtasks(User $user, Task $task): bool
    {
        return $this->isAssignedOrAdmin($user, $task);
    }

    // Attacher / détacher des étiquettes — admin, ou assigné à la tâche
    public function manageTags(User $user, Task $task): bool
    {
        return $this->manageSubtasks($user, $task);
    }
}
