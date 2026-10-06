<?php

namespace App\Http\Controllers;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
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

        $user = $request->user();

        // Une sous-tache creee par un admin (ou le proprietaire) est « imposee » :
        // le membre a qui la tache est assignee peut la realiser mais pas la supprimer.
        $agency = $task->project->agency;

        $position = (int) $task->subtasks()->max('position') + 1;

        $subtask = $task->subtasks()->create([
            'title' => $data['title'],
            'position' => $position,
            'created_by' => $user->id,
            'imposed' => $this->isAgencyAdmin($user, $task),
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

    // POST /api/tasks/{task}/subtasks/complete-all
    // Coche toutes les sous-taches restantes d'un coup (utilise par
    // « Terminer quand même » avant de forcer le statut terminal).
    public function completeAll(Request $request, Task $task)
    {
        $this->authorize('manageSubtasks', $task);

        $task->subtasks()->where('done', false)->update(['done' => true]);

        return response()->json(
            $task->subtasks()->orderBy('position')->orderBy('id')->get()
        );
    }

    // DELETE /api/subtasks/{subtask}
    public function destroy(Request $request, Subtask $subtask)
    {
        $task = $subtask->task;
        $this->authorize('manageSubtasks', $task);

        // Imposee par un admin : le membre ne peut pas la supprimer.
        if ($subtask->imposed && ! $this->isAgencyAdmin($request->user(), $task)) {
            return response()->json([
                'message' => 'Cette sous-tache a été imposée par un administrateur : seul un admin peut la supprimer.',
            ], 403);
        }

        $subtask->delete();

        return response()->json(null, 204);
    }

    private function isAgencyAdmin(User $user, Task $task): bool
    {
        $agency = $task->project->agency;

        return $agency->owner_id === $user->id
            || $user->roleInAgency($agency->id) === 'admin';
    }
}