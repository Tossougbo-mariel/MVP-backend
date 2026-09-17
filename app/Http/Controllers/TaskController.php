<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    // GET /api/projects/{project}/tasks
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        $tasks = $project->tasks()
            ->with(['assignee:id,name,email,avatar,first_name,last_name', 'creator:id,name,email,avatar,first_name,last_name'])
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

        $task->load(['assignee:id,name,email,avatar,first_name,last_name', 'creator:id,name,email,avatar,first_name,last_name', 'comments.user:id,name,email,avatar,first_name,last_name']);

        return response()->json($task);
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

        $task->update($data);

        if ($task->status === 'terminee') {
            $task->update(['completed_at' => now()]);
        }

        return response()->json($task);
    }

    // DELETE /api/tasks/{task}
    public function destroy(Task $task)
    {
        $this->authorize('delete', $task);

        $task->delete();

        return response()->json(null, 204);
    }
}
