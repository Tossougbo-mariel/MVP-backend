<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;

class ProjectMemberController extends Controller
{
    // GET /api/projects/{project}/members
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        $members = $project->members()->with('user:id,name,email,avatar,first_name,last_name')->get();

        return response()->json($members);
    }

    // POST /api/projects/{project}/members
    public function store(Request $request, Project $project)
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        abort_unless(
            $user->isActiveMemberOfAgency($project->agency_id),
            422,
            "Cette personne doit d'abord être membre de l'agence avant d'être ajoutée à un projet."
        );

        $membership = ProjectMember::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $user->id]
        );

        return response()->json($membership, 201);
    }

    // DELETE /api/projects/{project}/members/{projectMember}
    public function destroy(Request $request, Project $project, ProjectMember $projectMember)
    {
        $this->authorize('manageMembers', $project);

        abort_if($projectMember->project_id !== $project->id, 404);

        $activeTasks = Task::where('project_id', $project->id)
            ->where('assigned_to', $projectMember->user_id)
            ->whereIn('status', ['a_faire', 'en_cours', 'en_revision'])
            ->count();

        if ($activeTasks > 0 && !$request->boolean('confirm')) {
            return response()->json([
                'message' => "Ce membre a {$activeTasks} tâche(s) en cours sur ce projet.",
                'active_tasks_count' => $activeTasks,
                'requires_confirmation' => true,
            ], 409);
        }

        $projectMember->delete();

        return response()->json(null, 204);
    }
}
