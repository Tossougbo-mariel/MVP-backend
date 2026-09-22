<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    // GET /api/projects/{project}/tasks
    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $tasks = $project->tasks()
            ->with([
                'assignee:id,name,email,avatar,first_name,last_name',
                'creator:id,name,email,avatar,first_name,last_name',
                'tags:id,name,color',
                'dependencies:id,title,status',
            ])
            ->get();

        return response()->json($tasks);
    }

    // POST /api/projects/{project}/tasks
    public function store(Request $request, Project $project)
    {
        $this->authorize('create', [Task::class, $project->id]);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', 'string', 'in:basse,moyenne,haute,urgente'],
            'assigned_to' => [
                'nullable',
                'exists:users,id',
                Rule::exists('project_members', 'user_id')->where('project_id', $project->id),
            ],
            'start_date' => ['nullable', 'date', 'before_or_equal:due_date', function ($attribute, $value, $fail) use ($project) {
                if ($value && $project->start_date && $value < $project->start_date) {
                    $fail("La date de début doit être postérieure ou égale au début du projet ({$project->start_date}).");
                }
            }],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date', function ($attribute, $value, $fail) use ($project) {
                if ($value && $project->due_date && $value > $project->due_date) {
                    $fail("La date d'échéance doit être antérieure ou égale à l'échéance du projet ({$project->due_date}).");
                }
            }],
        ], [
            'assigned_to.exists' => "Cette personne doit d'abord être membre du projet pour qu'on puisse lui assigner une tâche.",
        ]);

        $task = Task::create([
            ...$data,
            'project_id' => $project->id,
            'created_by' => $request->user()->id,
            'status' => 'a_faire',
        ]);

        return response()->json($task, 201);
    }

    // GET /api/tasks/{task}
    public function show(Task $task)
    {
        $this->authorize('view', $task);

        $task->load(['assignee:id,name,email,avatar,first_name,last_name', 'creator:id,name,email,avatar,first_name,last_name', 'comments.user:id,name,email,avatar,first_name,last_name', 'tags:id,name,color', 'subtasks', 'attachments.user:id,name,email,avatar,first_name,last_name', 'dependencies:id,title,status,due_date', 'dependents:id,title,status,due_date']);

        return response()->json($task);
    }

    // PUT /api/tasks/{task}/tags
    public function updateTags(Request $request, Task $task)
    {
        $this->authorize('manageTags', $task);

        $data = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('agency_id', $task->project->agency_id),
            ],
        ]);

        $task->tags()->sync($data['tag_ids']);

        return response()->json($task->load('tags:id,name,color'));
    }

    // PUT /api/tasks/{task}
    public function update(Request $request, Task $task)
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', 'string', 'in:basse,moyenne,haute,urgente'],
            'assigned_to' => [
                'nullable',
                'exists:users,id',
                Rule::exists('project_members', 'user_id')->where('project_id', $task->project_id),
            ],
            'start_date' => ['nullable', 'date', 'before_or_equal:due_date', function ($attribute, $value, $fail) use ($task) {
                $project = $task->project;
                if ($value && $project->start_date && $value < $project->start_date) {
                    $fail("La date de début doit être postérieure ou égale au début du projet ({$project->start_date}).");
                }
            }],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date', function ($attribute, $value, $fail) use ($task) {
                $project = $task->project;
                if ($value && $project->due_date && $value > $project->due_date) {
                    $fail("La date d'échéance doit être antérieure ou égale à l'échéance du projet ({$project->due_date}).");
                }
            }],
            'completed_at' => ['nullable', 'date'],
        ], [
            'assigned_to.exists' => "Cette personne doit d'abord être membre du projet pour qu'on puisse lui assigner une tâche.",
        ]);

        $task->update($data);

        return response()->json($task);
    }

    // PATCH /api/tasks/{task}/status
    public function updateStatus(Request $request, Task $task)
    {
        $this->authorize('updateStatus', $task);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:a_faire,en_cours,en_revision,terminee'],
        ]);

        if ($data['status'] === 'terminee') {
            $blocking = $task->dependencies()->where('status', '!=', 'terminee')->get();
            if ($blocking->isNotEmpty()) {
                return response()->json([
                    'message' => 'Impossible de terminer : cette tâche dépend de « '.$blocking->pluck('title')->implode(' », « ').' » qui n\'est pas encore terminée.',
                ], 422);
            }

            $openSubtasks = $task->subtasks()->where('done', false)->count();
            if ($openSubtasks > 0) {
                $force = filter_var($request->input('force'), FILTER_VALIDATE_BOOLEAN);
                if (! $force) {
                    return response()->json([
                        'message' => 'Impossible de terminer : '.$openSubtasks.' sous-tâche(s) encore non cochée(s).',
                        'requires_force' => true,
                        'open_subtasks' => $openSubtasks,
                    ], 422);
                }
                if (! $this->canForceTaskStatus($request, $task)) {
                    return response()->json([
                        'message' => 'Seuls le propriétaire ou les administrateurs de l\'agence peuvent forcer le statut « Terminée ».',
                    ], 403);
                }
            }
        }

        $task->update($data);

        if ($task->status === 'terminee') {
            $task->update(['completed_at' => now()]);
        }

        return response()->json($task);
    }

    private function canForceTaskStatus(Request $request, Task $task): bool
    {
        $user = $request->user();
        if (! $user || ! $task->project || ! $task->project->agency) {
            return false;
        }

        $agency = $task->project->agency;

        return $agency->owner_id === $user->id
            || $user->roleInAgency($agency->id) === 'admin';
    }

    // POST /api/projects/{project}/tasks/bulk — actions groupées (admin)
    public function bulkUpdate(Request $request, Project $project)
    {
        $this->authorize('create', [Task::class, $project->id]);

        $data = $request->validate([
            'task_ids' => ['required', 'array'],
            'task_ids.*' => ['integer'],
            'status' => ['sometimes', 'nullable', 'string', 'in:a_faire,en_cours,en_revision,terminee'],
            'priority' => ['sometimes', 'nullable', 'string', 'in:basse,moyenne,haute,urgente'],
            'assigned_to' => [
                'sometimes',
                'nullable',
                'exists:users,id',
                Rule::exists('project_members', 'user_id')->where('project_id', $project->id),
            ],
            'add_tag_ids' => ['sometimes', 'array'],
            'add_tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('agency_id', $project->agency_id),
            ],
        ]);

        $tasks = $project->tasks()->whereIn('id', $data['task_ids'])->get();

        if ($tasks->isEmpty()) {
            return response()->json(['message' => 'Aucune tâche correspondante dans ce projet.'], 404);
        }

        if (($data['status'] ?? null) === 'terminee') {
            $blocked = $tasks->filter(
                fn (Task $task) => $task->dependencies()->where('status', '!=', 'terminee')->exists()
            );

            if ($blocked->isNotEmpty()) {
                return response()->json([
                    'message' => 'Impossible de terminer : « '.$blocked->pluck('title')->implode(' », « ').' » dépend(ent) de tâches non terminées.',
                ], 422);
            }

            $withOpenSubtasks = $tasks->filter(
                fn (Task $task) => $task->subtasks()->where('done', false)->exists()
            );

            if ($withOpenSubtasks->isNotEmpty()) {
                $force = filter_var($request->input('force'), FILTER_VALIDATE_BOOLEAN);
                if (! $force) {
                    return response()->json([
                        'message' => 'Impossible de terminer : « '.$withOpenSubtasks->pluck('title')->implode(' », « ').' » a des sous-tâches non cochées.',
                        'requires_force' => true,
                    ], 422);
                }
                if (! $this->canForceTaskStatus($request, $tasks->first())) {
                    return response()->json([
                        'message' => 'Seuls le propriétaire ou les administrateurs de l\'agence peuvent forcer le statut « Terminée ».',
                    ], 403);
                }
            }
        }

        $updates = [];
        foreach (['status', 'priority', 'assigned_to'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }
        if (($data['status'] ?? null) === 'terminee') {
            $updates['completed_at'] = now();
        }

        foreach ($tasks as $task) {
            if ($updates !== []) {
                $task->update($updates);
            }
            if (! empty($data['add_tag_ids'])) {
                $task->tags()->syncWithoutDetaching($data['add_tag_ids']);
            }
        }

        return response()->json(
            $tasks->map(fn (Task $task) => $task->fresh()->load('tags:id,name,color'))
        );
    }

    // GET /api/agencies/{agency}/tasks/export — export CSV des tâches (admin)
    public function export(Request $request, Agency $agency)
    {
        abort_unless($request->user()->roleInAgency($agency->id) === 'admin', 403);

        $tasks = Task::query()
            ->whereHas('project', fn ($query) => $query->where('agency_id', $agency->id))
            ->with(['project:id,name', 'assignee:id,name', 'tags:id,name'])
            ->orderBy('project_id')
            ->orderBy('id')
            ->get();

        $statusLabels = [
            'a_faire' => 'À faire',
            'en_cours' => 'En cours',
            'en_revision' => 'En révision',
            'terminee' => 'Terminée',
        ];
        $priorityLabels = [
            'basse' => 'Basse',
            'moyenne' => 'Moyenne',
            'haute' => 'Haute',
            'urgente' => 'Urgente',
        ];

        $callback = function () use ($tasks, $statusLabels, $priorityLabels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Projet', 'Titre', 'Statut', 'Priorité', 'Responsable', 'Début', 'Échéance', 'Étiquettes']);

            foreach ($tasks as $task) {
                fputcsv($out, [
                    $task->id,
                    $task->project?->name,
                    $task->title,
                    $statusLabels[$task->status] ?? $task->status,
                    $priorityLabels[$task->priority] ?? $task->priority,
                    $task->assignee?->name,
                    $task->start_date,
                    $task->due_date,
                    $task->tags->pluck('name')->implode(', '),
                ]);
            }

            fclose($out);
        };

        return response()->streamDownload(
            $callback,
            'taches-'.$agency->id.'-'.now()->format('Y-m-d').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    // DELETE /api/tasks/{task}
    public function destroy(Task $task)
    {
        $this->authorize('delete', $task);

        $task->delete();

        return response()->json(null, 204);
    }

    // PATCH /api/tasks/{task}/archive — archiver la tâche (au lieu de la supprimer)
    public function archive(Task $task)
    {
        $this->authorize('update', $task);

        $task->update(['archived_at' => now()]);

        return response()->json($task->load('tags:id,name,color'));
    }

    // PATCH /api/tasks/{task}/restore — désarchiver la tâche
    public function restore(Task $task)
    {
        $this->authorize('update', $task);

        $task->update(['archived_at' => null]);

        return response()->json($task->load('tags:id,name,color'));
    }
}
