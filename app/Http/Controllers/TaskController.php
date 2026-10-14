<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Agency;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Support\TaskStatusResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'start_date' => ['required', 'date', 'before_or_equal:due_date', function ($attribute, $value, $fail) use ($project) {
                if ($value && $project->start_date && $value < $project->start_date) {
                    $fail("La date de début doit être postérieure ou égale au début du projet ({$project->start_date}).");
                }
            }],
            'due_date' => ['required', 'date', 'after_or_equal:start_date', function ($attribute, $value, $fail) use ($project) {
                if ($value && $project->due_date && $value > $project->due_date) {
                    $fail("La date d'échéance doit être antérieure ou égale à l'échéance du projet ({$project->due_date}).");
                }
            }],
        ], [
            'assigned_to.exists' => "Cette personne doit d'abord être membre du projet pour qu'on puisse lui assigner une tâche.",
            'start_date.required' => "La date de début est obligatoire.",
            'due_date.required' => "La date d'échéance est obligatoire.",
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
                Rule::exists('tags', 'id')->where('project_id', $task->project_id),
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
            'start_date' => ['required', 'date', 'before_or_equal:due_date', function ($attribute, $value, $fail) use ($task) {
                $project = $task->project;
                if ($value && $project->start_date && $value < $project->start_date) {
                    $fail("La date de début doit être postérieure ou égale au début du projet ({$project->start_date}).");
                }
            }],
            'due_date' => ['required', 'date', 'after_or_equal:start_date', function ($attribute, $value, $fail) use ($task) {
                $project = $task->project;
                if ($value && $project->due_date && $value > $project->due_date) {
                    $fail("La date d'échéance doit être antérieure ou égale à l'échéance du projet ({$project->due_date}).");
                }
            }],
            'completed_at' => ['nullable', 'date'],
        ], [
            'assigned_to.exists' => "Cette personne doit d'abord être membre du projet pour qu'on puisse lui assigner une tâche.",
            'start_date.required' => "La date de début est obligatoire.",
            'due_date.required' => "La date d'échéance est obligatoire.",
        ]);

        // Une nouvelle échéance ne doit pas contredire les dépendances : un
        // prérequis dû après la tâche, ou une tâche dépendante due avant elle.
        // Erreur ferme (sans confirmation) : l'utilisateur doit corriger une date.
        if (! empty($data['due_date'])) {
            $current = $task->due_date ? Carbon::parse($task->due_date) : null;
            $newDue = Carbon::parse($data['due_date']);

            if (! $current || ! $current->equalTo($newDue)) {
                $conflict = $this->dependencyDueDateConflict($task, $newDue->toDateString());
                if ($conflict !== null) {
                    return response()->json(['message' => $conflict], 422);
                }
            }
        }

        $task->update($data);

        return response()->json($task);
    }

    // PATCH /api/tasks/{task}/status
    public function updateStatus(Request $request, Task $task)
    {
        $this->authorize('updateStatus', $task);

        $agencyId = (int) $task->project->agency_id;

        // Le statut est valide s'il appartient a l'agence (statut dynamique),
        // avec repli sur les cles historiques.
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(TaskStatusResolver::keys($agencyId))],
            'cascade' => ['sometimes', 'boolean'],
        ]);

        // Quitte-t-on un statut terminal ? C'est une reouverture : les
        // dependants deja termines ne sont plus valides et devront peut-etre
        // etre rouverts (cascade).
        $wasTerminal = TaskStatusResolver::isTerminal($agencyId, $task->status);

        if (TaskStatusResolver::isTerminal($agencyId, $data['status'])) {
            $terminalKeys = TaskStatusResolver::terminalKeys($agencyId);

            $blocking = $task->dependencies()->whereNotIn('status', $terminalKeys)->get();
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

        // Passage d'un statut terminal vers un statut ouvert : on liste les
        // dependants deja termines qui seraient incoherents une fois la tache
        // rouverte. Sans confirmation explicite (cascade), on refuse.
        $isReopening = $wasTerminal && ! TaskStatusResolver::isTerminal($agencyId, $data['status']);

        $terminalDependents = $isReopening
            ? $task->dependents()
                ->get()
                ->filter(fn (Task $dependent) => TaskStatusResolver::isTerminal($agencyId, $dependent->status))
                ->values()
            : collect();

        $cascade = filter_var($request->input('cascade'), FILTER_VALIDATE_BOOLEAN);

        if ($terminalDependents->isNotEmpty() && ! $cascade) {
            return response()->json([
                'message' => 'La réouverture de cette tâche va rouvrir '.$terminalDependents->count().' tâche(s) déjà terminée(s).',
                'requires_cascade' => true,
                'dependents' => $terminalDependents->map(fn (Task $dependent) => [
                    'id' => $dependent->id,
                    'title' => $dependent->title,
                    'status' => $dependent->status,
                ])->all(),
            ], 422);
        }

        $task->update($data);

        // Le statut terminal pose completed_at ; le quitter doit le retirer,
        // sinon une tache rouverte resterait comptee comme terminee cote front.
        $task->update([
            'completed_at' => TaskStatusResolver::isTerminal($agencyId, $task->status) ? now() : null,
        ]);

        if ($terminalDependents->isNotEmpty()) {
            $reopenKey = TaskStatusResolver::reopenKey($agencyId);
            $actorId = $request->user()?->id;

            foreach ($terminalDependents as $dependent) {
                // saveQuietly : TaskObserver ne doit pas ajouter son propre log
                // generique en plus de celui, explicite, de la cascade.
                $dependent->forceFill([
                    'status' => $reopenKey,
                    'completed_at' => null,
                ])->saveQuietly();

                ActivityLog::create([
                    'user_id' => $actorId,
                    'agency_id' => $agencyId,
                    'project_id' => $dependent->project_id,
                    'task_id' => $dependent->id,
                    'action' => 'reouverture',
                    'description' => "a rouvert la tâche « {$dependent->title} » : son prérequis « {$task->title} » n'est plus terminé.",
                ]);

                if ($dependent->assigned_to) {
                    Notification::notifyUser(
                        (int) $dependent->assigned_to,
                        'tache_reouverte',
                        'Tâche réouverte',
                        "La tâche « {$dependent->title} » a été rouverte : son prérequis « {$task->title} » n'est plus terminé.",
                        "/agences/{$agencyId}/projets/{$dependent->project_id}/taches/{$dependent->id}",
                        $agencyId,
                    );
                }
            }
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in(TaskStatusResolver::keys((int) $project->agency_id))],
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
                Rule::exists('tags', 'id')->where('project_id', $project->id),
            ],
        ]);

        $tasks = $project->tasks()->whereIn('id', $data['task_ids'])->get();

        if ($tasks->isEmpty()) {
            return response()->json(['message' => 'Aucune tâche correspondante dans ce projet.'], 404);
        }

        if (TaskStatusResolver::isTerminal((int) $project->agency_id, $data['status'] ?? null)) {
            $terminalKeys = TaskStatusResolver::terminalKeys((int) $project->agency_id);

            $blocked = $tasks->filter(
                fn (Task $task) => $task->dependencies()->whereNotIn('status', $terminalKeys)->exists()
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

        // Reouverture en lot : le bulk ne cascade pas. Si une des taches
        // rouvertes a des dependants deja termines, on refuse et on liste les
        // dependants concernes (a rouvrir carte par carte).
        if (array_key_exists('status', $data) && $data['status'] !== null
            && ! TaskStatusResolver::isTerminal((int) $project->agency_id, $data['status'])) {
            $agencyId = (int) $project->agency_id;

            $terminalDependents = collect();
            foreach ($tasks as $task) {
                if (! TaskStatusResolver::isTerminal($agencyId, $task->status)) {
                    continue;
                }
                $terminalDependents = $terminalDependents->merge(
                    $task->dependents()
                        ->get()
                        ->filter(fn (Task $dependent) => TaskStatusResolver::isTerminal($agencyId, $dependent->status))
                );
            }

            $terminalDependents = $terminalDependents->unique('id')->values();

            if ($terminalDependents->isNotEmpty()) {
                return response()->json([
                    'message' => 'La réouverture va rouvrir '.$terminalDependents->count().' tâche(s) déjà terminée(s) : rouvrez-les carte par carte.',
                    'requires_cascade' => true,
                    'dependents' => $terminalDependents->map(fn (Task $dependent) => [
                        'id' => $dependent->id,
                        'title' => $dependent->title,
                        'status' => $dependent->status,
                    ])->all(),
                ], 422);
            }
        }

        $updates = [];
        foreach (['status', 'priority', 'assigned_to'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }
        // Le statut terminal pose completed_at ; le quitter doit le retirer.
        // Le garde array_key_exists est essentiel : un bulk de priorite ou de
        // responsable ne doit pas effacer la date de fin existante.
        if (array_key_exists('status', $updates)) {
            $updates['completed_at'] = TaskStatusResolver::isTerminal((int) $project->agency_id, $updates['status'])
                ? now()
                : null;
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
            $tasks->map(fn (Task $task) => $task->fresh()->load([
                'tags:id,name,color',
                // `deadline_status` a besoin des prérequis pour savoir si la
                // tâche est bloquée ; on les charge pour éviter une requête
                // par tâche lors de la sérialisation.
                'dependencies:id,title,status',
            ]))
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

        // Libellés issus des statuts réels de l'agence (repli sur les
        // defaults si l'agence n'a rien personnalisé).
        $statusLabels = TaskStatusResolver::forAgency((int) $agency->id)
            ->mapWithKeys(fn (array $s) => [$s['key'] => $s['label']])
            ->all();
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

    /**
     * Message d'incohérence entre une nouvelle échéance et les dépendances de
     * la tâche, ou null si tout est cohérent. Deux cas :
     *  - un prérequis est dû après la nouvelle échéance ;
     *  - une tâche qui dépend de celle-ci est due avant la nouvelle échéance.
     */
    private function dependencyDueDateConflict(Task $task, string $newDue): ?string
    {
        $prerequisites = $task->dependencies()
            ->where('due_date', '>', $newDue)
            ->get(['tasks.id', 'tasks.title', 'tasks.due_date']);

        if ($prerequisites->isNotEmpty()) {
            $liste = $prerequisites
                ->map(fn (Task $t) => "« {$t->title} » (le {$t->due_date})")
                ->implode(', ');

            return "Impossible de modifier l'échéance : elle deviendrait antérieure à celle de son prérequis {$liste}.";
        }

        $dependents = $task->dependents()
            ->where('due_date', '<', $newDue)
            ->get(['tasks.id', 'tasks.title', 'tasks.due_date']);

        if ($dependents->isNotEmpty()) {
            $liste = $dependents
                ->map(fn (Task $t) => "« {$t->title} » (le {$t->due_date})")
                ->implode(', ');

            return "Impossible de modifier l'échéance : elle deviendrait postérieure à celle de la tâche dépendante {$liste}.";
        }

        return null;
    }
}
