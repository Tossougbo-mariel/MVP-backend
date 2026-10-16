<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Chargement partagé des agences : membres + projets + tâches.
 *
 * Servi par deux endpoints :
 *  - GET /api/bootstrap                   → toutes les agences visibles, payload complet ;
 *  - GET /agencies/{agency}/dashboard     → UNE agence, résumé léger pour le tableau de bord.
 *
 * Règles (inchangées, reprises de BootstrapController) :
 *  - agences où l'utilisateur est membre actif ;
 *  - l'admin/owner voit tous les projets, le simple membre seulement les siens.
 */
class AgencyPayload
{
    /**
     * Relations embarquées sur chaque tâche : Kanban, planning et page détail
     * lisent assignés/créateurs/étiquettes/dépendants.
     */
    private const FULL_TASK_RELATIONS = [
        'tasks.assignee:id,name,email,avatar,first_name,last_name',
        'tasks.creator:id,name,email,avatar,first_name,last_name',
        'tasks.dependencies:id,title,status,due_date',
        'tasks.dependents:id,title,status,due_date',
        'tasks.tags:id,name,color',
        // Rend l'accessor `deadline_status` sans requête par tâche
        // (Task::isTerminalStatus lit project.agency_id).
        'tasks.project:id,agency_id',
    ];

    /**
     * Résumé du tableau de bord : les tâches sont réduites à ce qui sert aux
     * compteurs et à `deadline_status`. Tout ce qui alimente le Kanban, le
     * planning ou la page détail reste réservé au bootstrap complet.
     */
    private const LIGHT_TASK_RELATIONS = [
        // deadline_status → Task::isBlockedByDependencies.
        'tasks.dependencies:id,status',
        // deadline_status → Task::isTerminalStatus (lit project.agency_id).
        'tasks.project:id,agency_id',
    ];

    /** Toutes les agences de l'utilisateur, payload complet. */
    public static function agencies(User $user): Collection
    {
        return self::load($user, null, false);
    }

    /** Une seule agence, résumé léger. Null si l'utilisateur n'en est pas membre actif. */
    public static function summary(User $user, Agency $agency): ?Agency
    {
        return self::load($user, (int) $agency->id, true)->first();
    }

    private static function load(User $user, ?int $agencyId, bool $light): Collection
    {
        $agencies = Agency::with(['members.user:id,name,email,avatar,first_name,last_name,job_title'])
            // Même règle que AgencyController@index : membres actifs seulement.
            ->whereHas('members', function ($query) use ($user) {
                $query->where('user_id', $user->id)->where('status', 'actif');
            })
            ->when($agencyId !== null, fn ($query) => $query->where('id', $agencyId))
            ->get();

        foreach ($agencies as $agency) {
            // Rôle de l'utilisateur (comme AgencyController@index).
            $agency->my_role = $user->roleInAgency($agency->id);

            // Même filtre de visibilité que ProjectController@index.
            $projects = $user->isAdminOfAgency($agency->id)
                ? $agency->projects()
                : $agency->projects()->whereHas('members', fn ($q) => $q->where('user_id', $user->id));

            $agency->setRelation(
                'projects',
                $projects
                    ->with($light ? self::LIGHT_TASK_RELATIONS : self::FULL_TASK_RELATIONS)
                    ->get(),
            );
        }

        return $agencies;
    }
}
