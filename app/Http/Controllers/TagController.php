<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    // GET /api/projects/{project}/tags
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json($project->tags()->orderBy('name')->get());
    }

    // POST /api/projects/{project}/tags
    public function store(Request $request, Project $project)
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $tag = $project->tags()->firstOrCreate(
            ['name' => $data['name']],
            ['color' => $data['color'] ?? '#056cf2'],
        );

        return response()->json($tag, 201);
    }

    // PUT /api/tags/{tag}
    public function update(Request $request, Tag $tag)
    {
        $this->authorize('manageMembers', $tag->project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:50'],
            'color' => ['sometimes', 'string', 'max:20'],
        ]);

        $tag->update($data);

        return response()->json($tag);
    }

    // DELETE /api/tags/{tag}
    public function destroy(Tag $tag)
    {
        // Tout membre du projet peut supprimer une étiquette de ce projet.
        $this->authorize('view', $tag->project);

        $tag->delete();

        return response()->json(null, 204);
    }
}
