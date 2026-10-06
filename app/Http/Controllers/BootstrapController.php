<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Notification;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    // GET /api/bootstrap
    // Charge tout ce dont l'application a besoin au démarrage en UNE seule requête :
    // agences (avec membres + projets + tâches) + notifications.
    public function index(Request $request)
    {
        $user = $request->user();

        // Mêmes règles que AgencyController@index : les agences où l'utilisateur est membre actif,
        // avec les membres embarqués (comme AgencyMemberController@index).
        $agencies = Agency::with(['members.user:id,name,email,avatar,first_name,last_name,job_title'])
            ->whereHas('members', function ($query) use ($user) {
                $query->where('user_id', $user->id)->where('status', 'actif');
            })
            ->get();

        foreach ($agencies as $agency) {
            // Rôle de l'utilisateur pour chaque agence (comme AgencyController@index).
            $agency->my_role = $user->roleInAgency($agency->id);

            // Même filtre de visibilité que ProjectController@index :
            // l'admin/owner voit tout, le simple membre n'entre que dans ses projets.
            $projects = $user->isAdminOfAgency($agency->id)
                ? $agency->projects()
                : $agency->projects()->whereHas('members', fn ($q) => $q->where('user_id', $user->id));

            // Tâches embarquées avec leurs assignés/créateurs (zéro N+1).
            $agency->setRelation('projects', $projects
                ->with([
                    'tasks.assignee:id,name,email,avatar,first_name,last_name',
                    'tasks.creator:id,name,email,avatar,first_name,last_name',
                ])
                ->get());
        }

        // Même shape que NotificationController@index (limit 100).
        $notifications = Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'agencies' => $agencies,
            'notifications' => $notifications,
        ]);
    }
}