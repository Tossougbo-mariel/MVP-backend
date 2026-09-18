<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AgencyInvitation;
use Illuminate\Http\Request;

class AgencyMemberController extends Controller
{
    // GET /api/agencies/{agency}/members
    public function index(Agency $agency)
    {
        $this->authorize('view', $agency);

        $members = $agency->members()->with('user:id,name,email,avatar,first_name,last_name')->get();

        return response()->json($members);
    }

    // POST /api/agencies/{agency}/members
    public function store(Request $request, Agency $agency)
    {
        $this->authorize('manageMembers', $agency);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['sometimes', 'string', 'in:admin,membre'],
        ]);

        $user = User::firstOrCreate(
            ['email' => $data['email']],
            ['name' => $data['email'], 'password' => null, 'status' => 'invite']
        );

        $membership = AgencyMember::updateOrCreate(
            ['agency_id' => $agency->id, 'user_id' => $user->id],
            [
                'role' => $data['role'] ?? 'membre',
                'status' => 'en_attente',
            ]
        );

        if ($user->wasRecentlyCreated) {
            $user->notify(new AgencyInvitation($agency));
        }

        return response()->json($membership, 201);
    }

    // L'INVITÉ accepte lui-même — c'est ce qui manquait
    public function accept(Request $request, AgencyMember $agencyMember)
    {
        // Sécurité : seul le concerné peut accepter SA PROPRE invitation
        abort_if($agencyMember->user_id !== $request->user()->id, 403);

        $agencyMember->update(['status' => 'actif']);

        return response()->json($agencyMember);
    }

    // PUT /api/agencies/{agency}/members/{agencyMember}
    public function update(Request $request, Agency $agency, AgencyMember $agencyMember)
    {
        $this->authorize('manageMembers', $agency);

        $data = $request->validate([
            'role' => ['sometimes', 'string', 'in:admin,membre'],
            'status' => ['sometimes', 'string', 'in:en_attente,actif,inactif'],
        ]);

        abort_if(
            $agencyMember->user_id === $agency->owner_id
            && array_key_exists('role', $data)
            && $data['role'] !== 'admin',
            422,
            "Le rôle du propriétaire de l'agence ne peut pas être modifié."
        );

        $agencyMember->update($data);

        return response()->json($agencyMember);
    }

    // DELETE /api/agencies/{agency}/members/{agencyMember}
    public function destroy(Request $request, Agency $agency, AgencyMember $agencyMember)
    {
        $this->authorize('manageMembers', $agency);

        abort_if($agencyMember->agency_id !== $agency->id, 404);

        abort_if(
            $agencyMember->user_id === $agency->owner_id,
            422,
            "Le propriétaire de l'agence ne peut pas être retiré."
        );

        $activeTasks = Task::whereHas('project', fn ($q) => $q->where('agency_id', $agency->id))
            ->where('assigned_to', $agencyMember->user_id)
            ->whereIn('status', ['a_faire', 'en_cours', 'en_revision'])
            ->count();

        if ($activeTasks > 0 && !$request->boolean('confirm')) {
            return response()->json([
                'message' => "Ce membre a {$activeTasks} tâche(s) en cours dans les projets de l'agence.",
                'active_tasks_count' => $activeTasks,
                'requires_confirmation' => true,
            ], 409);
        }

        $agencyMember->delete();

        return response()->json(null, 204);
    }
}
