<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Agency;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::with('user:id,name,email,avatar')
            ->orderBy('created_at', 'desc');

        if ($request->filled('task_id')) {
            $task = Task::findOrFail($request->task_id);
            $this->authorize('view', $task);
            $query->where('task_id', $task->id);

        } elseif ($request->filled('project_id')) {
            $project = Project::findOrFail($request->project_id);
            $this->authorize('view', $project);
            $query->where('project_id', $project->id);

        } elseif ($request->filled('agency_id')) {
            $agency = Agency::findOrFail($request->agency_id);
            abort_unless($request->user()->isAdminOfAgency($agency->id), 403);
            $query->where('agency_id', $agency->id);

        } else {
            abort(422, 'Merci de préciser agency_id, project_id ou task_id.');
        }

        $logs = $query->paginate($request->get('per_page', 25));

        return response()->json($logs);
    }
}
