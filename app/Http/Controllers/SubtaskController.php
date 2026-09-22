<?php

namespace App\Http\Controllers;

use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Http\Request;

class SubtaskController extends Controller
{
    // GET /api/tasks/{task}/subtasks
    public function index(Task $task)
    {
        $this->authorize('view', $task);

        return response()->json(
            $task->subtasks()->orderBy('position')->orderBy('id')->get()
        );
    }

    // POST /api/tasks/{task}/subtasks
    public function store(Request $request, Task $task)
    {
        $this->authorize('manageSubtasks', $task);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $position = (int) $task->subtasks()->max('position') + 1;

        $subtask = $task->subtasks()->create([
            'title' => $data['title'],
            'position' => $position,
        ]);

        return response()->json($subtask, 201);
    }

    // PUT /api/subtasks/{subtask}
    public function update(Request $request, Subtask $subtask)
    {
        $this->authorize('manageSubtasks', $subtask->task);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'done' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $subtask->update($data);

        return response()->json($subtask);
    }

    // DELETE /api/subtasks/{subtask}
    public function destroy(Subtask $subtask)
    {
        $this->authorize('manageSubtasks', $subtask->task);

        $subtask->delete();

        return response()->json(null, 204);
    }
}
