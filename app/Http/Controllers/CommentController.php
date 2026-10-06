<?php

namespace App\Http\Controllers;

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
            'mention_ids' => ['sometimes', 'array'],
            'mention_ids.*' => ['integer'],
        ]);

        // On ne garde que les personnes réellement membres du projet
        $allowed = $task->project->members()->pluck('user_id')->all();
        $mentionIds = collect($data['mention_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => in_array($id, $allowed, true))
            ->unique()
            ->values()
            ->all();

        $comment = Comment::create([
            'content' => $data['content'],
            'mention_ids' => $mentionIds,
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
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
