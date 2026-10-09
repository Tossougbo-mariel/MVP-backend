<?php

namespace App\Policies;

use App\Models\Agency;
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

    // Créer un projet — respecte le réglage « qui peut créer des projets »
    public function create(User $user, int $agencyId): bool
    {
        $agency = Agency::find($agencyId);

        if (! $agency) {
            return false;
        }

        $who = $agency->setting('whoCanCreateProjects', 'admin');

        if ($who === 'all') {
            return $user->isActiveMemberOfAgency($agencyId);
        }

        if ($who === 'owner') {
            return $agency->owner_id === $user->id
                || $user->roleInAgency($agencyId) === 'admin';
        }

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
