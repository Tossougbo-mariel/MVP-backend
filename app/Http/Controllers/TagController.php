<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    // GET /api/agencies/{agency}/tags
    public function index(Agency $agency)
    {
        $this->authorize('view', $agency);

        return response()->json($agency->tags()->orderBy('name')->get());
    }

    // POST /api/agencies/{agency}/tags
    public function store(Request $request, Agency $agency)
    {
        $this->authorize('manageMembers', $agency);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $tag = $agency->tags()->firstOrCreate(
            ['name' => $data['name']],
            ['color' => $data['color'] ?? '#056cf2'],
        );

        return response()->json($tag, 201);
    }

    // PUT /api/tags/{tag}
    public function update(Request $request, Tag $tag)
    {
        $this->authorize('manageMembers', $tag->agency);

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
        $this->authorize('manageMembers', $tag->agency);

        $tag->delete();

        return response()->json(null, 204);
    }
}
