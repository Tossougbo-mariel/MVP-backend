<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    // Voir un projet — soit on est explicitement membre du projet,
    // soit on est admin de l'agence qui le possède (vue globale)
    public function view(User $user, Project $project): bool
    {
        return $project->hasMember($user->id)
            || $user->roleInAgency($project->agency_id) === 'admin';
    }

    // Créer un projet — admin de l'agence uniquement
    public function create(User $user, int $agencyId): bool
    {
        return $user->roleInAgency($agencyId) === 'admin';
    }

    // Modifier un projet (dates, statut, description...) — admin uniquement
    public function update(User $user, Project $project): bool
    {
        return $user->roleInAgency($project->agency_id) === 'admin';
    }

    // Supprimer un projet — admin uniquement
    public function delete(User $user, Project $project): bool
    {
        return $user->roleInAgency($project->agency_id) === 'admin';
    }

    // Ajouter/retirer des membres sur CE projet précis — admin uniquement
    public function manageMembers(User $user, Project $project): bool
    {
        return $user->roleInAgency($project->agency_id) === 'admin';
    }
}
