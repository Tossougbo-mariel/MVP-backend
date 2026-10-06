<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le planning (calendrier + Gantt) se construit entierement a partir de la
 * reponse de `GET /api/projects/{project}/tasks`. Ces tests verrouillent les
 * donnees dont les vues ont besoin : dates, dependances et exclusion des
 * taches archivees.
 */
class ProjectPlanningTest extends TestCase
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
            'name' => 'Agence Planning',
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

    public function test_it_returns_the_dates_the_calendar_needs(): void
    {
        $this->task(['title' => 'Maquettes']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/tasks")
            ->assertOk()
            ->assertJsonPath('0.title', 'Maquettes')
            ->assertJsonPath('0.start_date', '2026-10-05')
            ->assertJsonPath('0.due_date', '2026-10-09');
    }

    public function test_it_returns_dependencies_for_the_gantt_links(): void
    {
        $preparation = $this->task([
            'title' => 'Preparation',
            'start_date' => '2026-10-05',
            'due_date' => '2026-10-08',
            'status' => 'terminee',
        ]);
        $task = $this->task([
            'title' => 'Integration',
            'start_date' => '2026-10-10',
            'due_date' => '2026-10-15',
        ]);

        TaskDependency::create([
            'task_id' => $task->id,
            'depends_on_task_id' => $preparation->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/tasks")
            ->assertOk();

        $rows = collect($response->json())->keyBy('id');

        $this->assertCount(1, $rows[$task->id]['dependencies']);
        $this->assertSame('Preparation', $rows[$task->id]['dependencies'][0]['title']);
        $this->assertSame('terminee', $rows[$task->id]['dependencies'][0]['status']);
    }

    public function test_archived_tasks_are_flagged_so_views_can_exclude_them(): void
    {
        // L'endpoint renvoie volontairement les tâches archivées : le Kanban
        // en a besoin pour sa section "Archivés" et la restauration. C'est
        // l'UI (lib/planning.ts, CalendarView) qui les exclut du planning,
        // et pour cela le champ `archived_at` doit toujours être présent.
        $this->task(['title' => 'Visible']);
        $this->task(['title' => 'Archivee', 'archived_at' => now()]);

        $rows = collect(
            $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$this->project->id}/tasks")
                ->assertOk()
                ->json()
        )->keyBy('title');

        $this->assertNull($rows['Visible']['archived_at']);
        $this->assertNotNull($rows['Archivee']['archived_at']);
    }

    public function test_completed_tasks_expose_completed_at_for_the_gantt(): void
    {
        $this->task(['title' => 'Finie', 'status' => 'terminee', 'completed_at' => now()]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/tasks")
            ->assertOk()
            ->assertJsonPath('0.completed_at', fn ($v) => $v !== null);
    }

    public function test_a_stranger_cannot_read_the_planning(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/tasks")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_read_the_planning(): void
    {
        $this->getJson("/api/projects/{$this->project->id}/tasks")->assertUnauthorized();
    }
}
