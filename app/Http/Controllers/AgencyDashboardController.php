<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Support\AgencyPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgencyDashboardController extends Controller
{
    /**
     * GET /api/agencies/{agency}/dashboard
     *
     * Résumé de l'agence pour le tableau de bord : agence + membres + projets
     * + tâches (colonnes utiles uniquement), en UNE requête.
     *
     * Débarasse le front du `GET /bootstrap` complet (toutes agences, tags,
     * dépendants, assignés, notifications) qu'il attendait avant d'afficher
     * le moindre indicateur.
     *
     * Contrat : réservé aux membres actifs ; la visibilité des projets est
     * exactement celle de ProjectController@index.
     */
    public function show(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize('view', $agency);

        $summary = AgencyPayload::summary($request->user(), $agency);

        return response()->json([
            'agencies' => $summary === null ? [] : [$summary],
        ]);
    }
}
