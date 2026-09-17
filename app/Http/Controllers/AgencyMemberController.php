<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\AgencyMember;
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
            'status' => ['sometimes', 'string', 'in:en_attente,actif'],
        ]);

        $agencyMember->update($data);

        return response()->json($agencyMember);
    }

    // DELETE /api/agencies/{agency}/members/{agencyMember}
    public function destroy(Agency $agency, AgencyMember $agencyMember)
    {
        $this->authorize('manageMembers', $agency);

        $agencyMember->delete();

        return response()->json(null, 204);
    }
}
