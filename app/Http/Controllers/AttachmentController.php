<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    // GET /api/tasks/{task}/attachments
    public function index(Task $task)
    {
        $this->authorize('view', $task);

        return response()->json(
            $task->attachments()
                ->with('user:id,name,email,avatar,first_name,last_name')
                ->latest()
                ->get()
        );
    }

    // POST /api/tasks/{task}/attachments
    public function store(Request $request, Task $task)
    {
        $this->authorize('manageSubtasks', $task);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ], [
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
        ]);

        $file = $request->file('file');
        $path = $file->store('attachments/'.$task->id, 'local');

        $attachment = $task->attachments()->create([
            'user_id' => $request->user()->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getClientMimeType(),
        ]);

        return response()->json(
            $attachment->load('user:id,name,email,avatar,first_name,last_name'),
            201
        );
    }

    // GET /api/attachments/{attachment}/download
    public function download(Attachment $attachment)
    {
        $this->authorize('view', $attachment->task);

        // Le fichier a pu disparaitre du disque (nettoyage, restauration
        // incomplete). Sans ce controle, Storage leve une exception et la
        // reponse est une 500 alors que la situation est un 404.
        abort_unless(
            Storage::disk('local')->exists($attachment->file_path),
            404,
            "Ce fichier n'est plus disponible.",
        );

        return Storage::disk('local')->download($attachment->file_path, $attachment->file_name);
    }

    // DELETE /api/attachments/{attachment}
    public function destroy(Attachment $attachment)
    {
        $user = request()->user();
        $task = $attachment->task;

        $isUploader = $attachment->user_id === $user->id;
        $isAdmin = $user->roleInAgency($task->project->agency_id) === 'admin';

        abort_unless($isUploader || $isAdmin, 403, 'Vous ne pouvez pas supprimer cette pièce jointe.');

        Storage::disk('local')->delete($attachment->file_path);
        $attachment->delete();

        return response()->json(null, 204);
    }
}
