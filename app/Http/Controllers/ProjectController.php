<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    // GET /api/agencies/{agency}/projects
    public function index(Request $request, Agency $agency)
    {
        $this->authorize('view', $agency);

        $user = $request->user();

        $projects = $user->isAdminOfAgency($agency->id)
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
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
            'wallpaper' => ['nullable', 'string'],
        ], [
            'name.required' => 'Le nom du projet est obligatoire.',
            'name.string' => 'Le nom du projet doit être une chaîne de caractères.',
            'name.max' => 'Le nom du projet ne doit pas dépasser 255 caractères.',
            'start_date.required' => 'La date de début est obligatoire.',
            'start_date.date' => 'La date de début doit être une date valide.',
            'start_date.after_or_equal' => 'La date de début ne peut pas être antérieure à aujourd\'hui.',
            'due_date.required' => 'La date d\'échéance est obligatoire.',
            'due_date.date' => 'La date d\'échéance doit être une date valide.',
            'due_date.after_or_equal' => 'La date d\'échéance doit être postérieure ou égale à la date de début.',
        ]);

        $project = Project::create([
            ...$data,
            'agency_id' => $agency->id,
            'owner_id' => $request->user()->id,
            'status' => 'a_venir',
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
            'start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'due_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:a_venir,en_cours,termine,archive'],
            'wallpaper' => ['nullable', 'string'],
        ], [
            'name.string' => 'Le nom du projet doit être une chaîne de caractères.',
            'name.max' => 'Le nom du projet ne doit pas dépasser 255 caractères.',
            'start_date.date' => 'La date de début doit être une date valide.',
            'start_date.after_or_equal' => 'La date de début ne peut pas être antérieure à aujourd\'hui.',
            'due_date.date' => 'La date d\'échéance doit être une date valide.',
            'status.in' => 'Le statut du projet est invalide.',
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
