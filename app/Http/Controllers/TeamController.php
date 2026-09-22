<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    // GET /api/agencies/{agency}/teams
    public function index(Agency $agency)
    {
        $this->authorize('view', $agency);

        $teams = $agency->teams()->with('members:id,name,email,avatar,first_name,last_name')
            ->orderBy('name')
            ->get();

        return response()->json($teams->map(fn (Team $team) => $this->serialize($team))->values());
    }

    // POST /api/agencies/{agency}/teams
    public function store(Request $request, Agency $agency)
    {
        $this->authorize('manageTeams', $agency);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'membership' => ['sometimes', 'in:ouverte,fermee'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer'],
        ]);

        $team = $agency->teams()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'membership' => $data['membership'] ?? $agency->setting('defaultTeamMembership', 'fermee'),
            'created_by' => $request->user()->id,
        ]);

        $this->syncMembers($team, $data['member_ids'] ?? []);

        $team->load('members:id,name,email,avatar,first_name,last_name');

        return response()->json($this->serialize($team), 201);
    }

    // PUT /api/teams/{team}
    public function update(Request $request, Team $team)
    {
        $this->authorize('manageTeams', $team->agency);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'membership' => ['sometimes', 'in:ouverte,fermee'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer'],
        ]);

        $team->update([
            'name' => $data['name'] ?? $team->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $team->description,
            'membership' => $data['membership'] ?? $team->membership,
        ]);

        if (array_key_exists('member_ids', $data)) {
            $this->syncMembers($team, $data['member_ids'] ?? []);
        }

        $team->load('members:id,name,email,avatar,first_name,last_name');

        return response()->json($this->serialize($team));
    }

    // DELETE /api/teams/{team}
    public function destroy(Team $team)
    {
        $this->authorize('manageTeams', $team->agency);

        $team->delete();

        return response()->json(null, 204);
    }

    /** Harmonise les membres : on ne garde que les membres réels de l'agence (actifs ou en attente). */
    private function syncMembers(Team $team, array $memberIds): void
    {
        $validIds = $team->agency->members()
            ->whereIn('user_id', $memberIds)
            ->whereIn('status', ['actif', 'en_attente'])
            ->pluck('user_id')
            ->all();

        $team->members()->sync($validIds);
    }

    private function serialize(Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'description' => $team->description,
            'membership' => $team->membership,
            'created_by' => $team->created_by,
            'member_count' => $team->members->count(),
            'members' => $team->members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'avatar' => $member->avatar,
            ])->values(),
        ];
    }
}