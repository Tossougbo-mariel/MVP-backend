<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    // GET /api/agencies/{agency}/projects
    public function index(Request $request, Agency $agency)
    {
        $this->authorize('view', $agency);

        $user = $request->user();

        $projects = $user->roleInAgency($agency->id) === 'admin'
             ? $agency->projects()->get()
             : $agency->projects()->whereHas('members', fn ($q) => $q->where('user_id', $user->id))->get();

        return response()->json($projects->load('tasks'));
    }

    // POST /api/agencies/{agency}/projects
    public function store(Request $request, Agency $agency)
    {
        $this->authorize('create', [Project::class, $agency->id]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'wallpaper' => ['nullable', 'string'],
        ]);

        $project = Project::create([
            ...$data,
            'agency_id' => $agency->id,
            'owner_id' => $request->user()->id,
            'status' => 'a_venir',
        ]);

        // Le créateur du projet en devient automatiquement membre
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json($project, 201);
    }

    // GET /api/projects/{project}
    public function show(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json($project->load('tasks'));
    }

    // PUT /api/projects/{project}
    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:a_venir,en_cours,termine,archive'],
            'wallpaper' => ['nullable', 'string'],
        ]);

        $project->update($data);

        return response()->json($project);
    }

    // DELETE /api/projects/{project}
    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->json(null, 204);
    }
}
