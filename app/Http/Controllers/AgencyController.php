<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\AgencyMember;
use Illuminate\Http\Request;

class AgencyController extends Controller
{
    // GET /api/agencies
    // Le dashboard personnel : toutes les agences où l'utilisateur est membre actif
    public function index(Request $request)
    {
        $user = $request->user();

        $agencies = Agency::whereHas('members', function ($query) use ($user) {
            $query->where('user_id', $user->id)->where('status', 'actif');
        })->get();

        // On ajoute le rôle de l'utilisateur pour CHAQUE agence,
        // pour que le frontend sache directement quelle interface afficher
        $agencies->each(function ($agency) use ($user) {
            $agency->my_role = $user->roleInAgency($agency->id);
        });

        return response()->json($agencies);
    }

    // POST /api/agencies
    // "Créer une agence" — action permanente, disponible à tout utilisateur connecté
    public function store(Request $request)
    {
        $this->authorize('create', Agency::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $agency = Agency::create([
            ...$data,
            'owner_id' => $request->user()->id,
        ]);

        // Règle validée plus tôt : celui qui crée l'agence en devient automatiquement Admin
        AgencyMember::create([
            'agency_id' => $agency->id,
            'user_id' => $request->user()->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        return response()->json($agency, 201);
    }

    // GET /api/agencies/{agency}
    public function show(Request $request, Agency $agency)
    {
        $this->authorize('view', $agency);

        $agency->my_role = $request->user()->roleInAgency($agency->id);

        return response()->json($agency);
    }

    // PUT /api/agencies/{agency}
    public function update(Request $request, Agency $agency)
    {
        $this->authorize('update', $agency);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $agency->update($data);

        return response()->json($agency);
    }

    // DELETE /api/agencies/{agency}
    public function destroy(Agency $agency)
    {
        $this->authorize('delete', $agency);

        $agency->delete();

        return response()->json(null, 204);
    }
}
