<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Http\Request;

class TaskDependencyController extends Controller
{
    // GET /api/tasks/{task}/dependencies
    public function index(Task $task)
    {
        $this->authorize('view', $task);

        return response()->json([
            'dependencies' => $task->dependencies()->get(['tasks.id', 'tasks.title', 'tasks.status', 'tasks.due_date']),
            'dependents' => $task->dependents()->get(['tasks.id', 'tasks.title', 'tasks.status', 'tasks.due_date']),
        ]);
    }

    // POST /api/tasks/{task}/dependencies
    public function store(Request $request, Task $task)
    {
        $this->authorize('manageSubtasks', $task);

        $data = $request->validate([
            'depends_on_task_id' => ['required', 'integer', 'exists:tasks,id'],
        ]);

        $dependencyId = (int) $data['depends_on_task_id'];

        abort_if($dependencyId === $task->id, 422, 'Une tâche ne peut pas dépendre d\'elle-même.');

        $dependency = Task::findOrFail($dependencyId);

        abort_if(
            $dependency->project_id !== $task->project_id,
            422,
            'La dépendance doit appartenir au même projet.'
        );

        $exists = TaskDependency::where('task_id', $task->id)
            ->where('depends_on_task_id', $dependencyId)
            ->exists();

        abort_if($exists, 422, 'Cette dépendance existe déjà.');

        abort_if(
            $this->wouldCreateCycle($task, $dependencyId),
            422,
            'Cette dépendance créerait une boucle entre les tâches.'
        );

        TaskDependency::create([
            'task_id' => $task->id,
            'depends_on_task_id' => $dependencyId,
        ]);

        return response()->json([
            'dependencies' => $task->dependencies()->get(['tasks.id', 'tasks.title', 'tasks.status', 'tasks.due_date']),
            'dependents' => $task->dependents()->get(['tasks.id', 'tasks.title', 'tasks.status', 'tasks.due_date']),
        ], 201);
    }

    // DELETE /api/tasks/{task}/dependencies/{dependency}
    public function destroy(Task $task, Task $dependency)
    {
        $this->authorize('manageSubtasks', $task);

        TaskDependency::where('task_id', $task->id)
            ->where('depends_on_task_id', $dependency->id)
            ->delete();

        return response()->json([
            'dependencies' => $task->dependencies()->get(['tasks.id', 'tasks.title', 'tasks.status', 'tasks.due_date']),
            'dependents' => $task->dependents()->get(['tasks.id', 'tasks.title', 'tasks.status', 'tasks.due_date']),
        ]);
    }

    // Vérifie qu'ajouter task -> dependencyId ne crée pas de cycle
    private function wouldCreateCycle(Task $task, int $dependencyId): bool
    {
        $stack = [$dependencyId];
        $seen = [];

        while ($stack) {
            $current = (int) array_pop($stack);

            if ($current === $task->id) {
                return true;
            }

            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            $parents = TaskDependency::where('task_id', $current)
                ->pluck('depends_on_task_id')
                ->all();

            foreach ($parents as $parent) {
                $stack[] = (int) $parent;
            }
        }

        return false;
    }
}
