<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskTimeEntry;
use Illuminate\Http\Request;

class TaskTimeEntryController extends Controller
{
    public function index(Task $task)
    {
        $this->authorize('view', $task);

        return response()->json($task->timeEntries()->with('user:id,name,email')->orderByDesc('created_at')->get());
    }

    public function store(Request $request, Task $task)
    {
        $this->authorize('updateStatus', $task);

        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = $task->timeEntries()->create([
            ...$data,
            'user_id' => $request->user()->id,
        ])->load('user:id,name,email');

        return response()->json($entry, 201);
    }

    public function destroy(Request $request, TaskTimeEntry $entry)
    {
        $this->authorize('view', $entry->task);
        $isAdmin = $request->user()->roleInAgency($entry->task->project->agency_id) === 'admin';
        abort_unless($isAdmin || $entry->user_id === $request->user()->id, 403);

        $entry->delete();

        return response()->json(null, 204);
    }
}
