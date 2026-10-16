<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/bootstrap` alimente tout le cache front (agences, projets, taches).
 * Les vues Kanban (badge « Bloquee », etiquettes) et Planning (liens de
 * dependances du Gantt) lisent `dependencies` et `tags` sur chaque tache :
 * ces relations doivent donc etre presentes dans la reponse, sinon le front
 * recoit des tableaux vides et n'affiche rien.
 */
class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Agency $agency;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->agency = Agency::create([
            'name' => 'Agence Bootstrap',
            'owner_id' => $this->user->id,
        ]);
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->user->id,
            'role' => 'proprietaire',
            'status' => 'actif',
        ]);

        $this->project = Project::create([
            'agency_id' => $this->agency->id,
            'name' => 'Refonte site',
            'owner_id' => $this->user->id,
            'status' => 'en_cours',
            'start_date' => '2026-10-01',
            'due_date' => '2026-11-30',
        ]);

        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);
    }

    private function task(array $overrides = []): Task
    {
        return Task::create(array_merge([
            'project_id' => $this->project->id,
            'title' => 'Tache',
            'status' => 'a_faire',
            'priority' => 'moyenne',
            'start_date' => '2026-10-05',
            'due_date' => '2026-10-09',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    public function test_it_returns_task_dependencies_for_the_gantt(): void
    {
        $preparation = $this->task([
            'title' => 'Preparation',
            'status' => 'terminee',
        ]);
        $integration = $this->task(['title' => 'Integration']);

        TaskDependency::create([
            'task_id' => $integration->id,
            'depends_on_task_id' => $preparation->id,
        ]);

        $tasks = collect(
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/bootstrap')
                ->assertOk()
                ->json('agencies.0.projects.0.tasks')
        )->keyBy('title');

        $this->assertCount(1, $tasks['Integration']['dependencies']);
        $this->assertSame('Preparation', $tasks['Integration']['dependencies'][0]['title']);
        $this->assertSame('terminee', $tasks['Integration']['dependencies'][0]['status']);
    }

    public function test_it_returns_task_tags_for_the_kanban(): void
    {
        $task = $this->task(['title' => 'Maquettes']);

        $tag = Tag::create([
            'project_id' => $this->project->id,
            'name' => 'UI',
            'color' => '#123456',
        ]);
        $task->tags()->attach($tag->id);

        $tasks = collect(
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/bootstrap')
                ->assertOk()
                ->json('agencies.0.projects.0.tasks')
        )->keyBy('title');

        $this->assertCount(1, $tasks['Maquettes']['tags']);
        $this->assertSame('UI', $tasks['Maquettes']['tags'][0]['name']);
        $this->assertSame('#123456', $tasks['Maquettes']['tags'][0]['color']);
    }

    public function test_it_keeps_archived_tasks_flagged(): void
    {
        $this->task(['title' => 'Visible']);
        $this->task(['title' => 'Archivee', 'archived_at' => now()]);

        $tasks = collect(
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/bootstrap')
                ->assertOk()
                ->json('agencies.0.projects.0.tasks')
        )->keyBy('title');

        $this->assertNull($tasks['Visible']['archived_at']);
        $this->assertNotNull($tasks['Archivee']['archived_at']);
    }

    public function test_a_guest_cannot_read_the_bootstrap(): void
    {
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }
}
