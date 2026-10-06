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

    // Gérer les membres : promouvoir, désactiver, retirer — admin uniquement
    public function manageMembers(User $user, Agency $agency): bool
    {
        return $user->isAdminOfAgency($agency->id);
    }

    // Inviter de nouveaux membres — respecte le réglage « qui peut inviter »
    public function invite(User $user, Agency $agency): Response
    {
        $who = $agency->setting('whoCanInvite', 'owner');

        if ($who === 'all') {
            return $user->isActiveMemberOfAgency($agency->id)
                ? Response::allow()
                : Response::deny('Seul un membre actif peut inviter dans cette agence.');
        }

        if ($who === 'admin') {
            return $user->roleInAgency($agency->id) === 'admin'
                ? Response::allow()
                : Response::deny('Seul un admin peut inviter dans cette agence.');
        }

        return $agency->owner_id === $user->id
            ? Response::allow()
            : Response::deny('Seul le propriétaire peut inviter dans cette agence.');
    }

    // Gérer les équipes (créer, renommer, changer les membres, supprimer) —
    // respecte le réglage « qui peut gérer les équipes »
    public function manageTeams(User $user, Agency $agency): Response
    {
        $who = $agency->setting('whoCanManageTeams', 'admin');

        if ($who === 'all') {
            return $user->isActiveMemberOfAgency($agency->id)
                ? Response::allow()
                : Response::deny('Seul un membre actif peut gérer les équipes.');
        }

        if ($who === 'admin') {
            return $user->roleInAgency($agency->id) === 'admin'
                ? Response::allow()
                : Response::deny('Seul le propriétaire ou un admin peut gérer les équipes.');
        }

        return $agency->owner_id === $user->id
            ? Response::allow()
            : Response::deny('Seul le propriétaire peut gérer les équipes.');
    }
}
