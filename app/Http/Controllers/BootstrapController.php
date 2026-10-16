<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\AgencyPayload;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    // GET /api/bootstrap
    // Charge tout ce dont l'application a besoin au démarrage en UNE seule requête :
    // agences (avec membres + projets + tâches) + notifications.
    public function index(Request $request)
    {
        $user = $request->user();

        // Agences visibles + projets/tâches (règles de visibilité et relations
        // embarquées centralisées dans AgencyPayload, partagées avec
        // GET /api/agencies/{agency}/dashboard).
        $agencies = AgencyPayload::agencies($user);

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
