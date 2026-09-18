<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Task;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    // GET /api/tasks/{task}/comments
    public function index(Task $task)
    {
        $this->authorize('view', $task);

        $comments = $task->comments()->with('user:id,name,email,avatar')->get();

        return response()->json($comments);
    }

    // POST /api/tasks/{task}/comments
    public function store(Request $request, Task $task)
    {
        $this->authorize('comment', $task);

        $data = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $comment = Comment::create([
            ...$data,
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
        ]);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'agency_id' => $task->project?->agency_id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'action' => 'commentaire',
            'description' => "a commenté la tâche « {$task->title} »",
        ]);

        $comment->load('user:id,name,email,avatar');

        return response()->json($comment, 201);
    }

    // DELETE /api/comments/{comment}
    public function destroy(Comment $comment)
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return response()->json(null, 204);
    }
}
