<?php

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AgencyPolicy
{
    // Voir le dashboard/l'espace de l'agence — il faut être membre actif (admin ou membre)
    public function view(User $user, Agency $agency): bool
    {
        return $user->isActiveMemberOfAgency($agency->id);
    }

    // Créer une agence — n'importe quel utilisateur connecté peut le faire
    // (rappel : découplé de l'inscription, disponible en permanence)
    public function create(User $user): bool
    {
        return true;
    }

    // Modifier les paramètres de l'agence (nom, description) — admin uniquement
    public function update(User $user, Agency $agency): Response
    {
        return $user->isAdminOfAgency($agency->id)
            ? Response::allow()
            : Response::deny('Seul un admin peut modifier les paramètres de cette agence.');
    }

    // Supprimer l'agence — réservé au propriétaire (celui qui l'a créée)
    // volontairement plus strict qu'un admin ordinaire, vu la gravité de l'action
    public function delete(User $user, Agency $agency): bool
    {
        return $agency->owner_id === $user->id;
    }

    // Gérer les membres : inviter, promouvoir, retirer — admin uniquement
    public function manageMembers(User $user, Agency $agency): bool
    {
        return $user->isAdminOfAgency($agency->id);
    }
}
