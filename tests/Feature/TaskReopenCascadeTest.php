<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\AgencyTaskStatus;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Support\TaskStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Réouverture d'un prérequis : sortir une tâche terminée de son statut
 * terminal peut invalider les tâches dépendantes déjà terminées. Sans
 * confirmation explicite, le backend refuse et liste les dépendants ; avec
 * `cascade`, il les rouvre (statut, journal d'activité, notification).
 */
class TaskReopenCascadeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Agency $agency;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        TaskStatusResolver::flush();

        $this->owner = User::factory()->create();

        $this->agency = Agency::create(['name' => 'Agence', 'owner_id' => $this->owner->id]);
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->owner->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        $this->project = Project::create([
            'agency_id' => $this->agency->id,
            'name' => 'Projet',
            'owner_id' => $this->owner->id,
            'status' => 'en_cours',
            'start_date' => '2026-10-01',
            'due_date' => '2026-11-30',
        ]);
        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
    }

    protected function tearDown(): void
    {
        TaskStatusResolver::flush();

        parent::tearDown();
    }

    private function makeTask(string $title, string $status, ?int $assignedTo = null): Task
    {
        return Task::create([
            'project_id' => $this->project->id,
            'title' => $title,
            'created_by' => $this->owner->id,
            'assigned_to' => $assignedTo ?? $this->owner->id,
            'status' => $status,
            'priority' => 'moyenne',
            'start_date' => '2026-10-01',
            'due_date' => '2026-10-31',
        ]);
    }

    private function link(Task $dependent, Task $prerequisite): void
    {
        TaskDependency::create([
            'task_id' => $dependent->id,
            'depends_on_task_id' => $prerequisite->id,
        ]);
    }

    public function test_reopening_with_terminal_dependent_requires_cascade(): void
    {
        $prerequisite = $this->makeTask('Prérequis', 'terminee');
        $dependent = $this->makeTask('Dépendante', 'terminee');
        $this->link($dependent, $prerequisite);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$prerequisite->id}/status", ['status' => 'en_cours'])
            ->assertStatus(422)
            ->assertJson(['requires_cascade' => true])
            ->assertJsonPath('dependents.0.id', $dependent->id);

        // Rien n'a bougé sans confirmation.
        $this->assertSame('terminee', $prerequisite->fresh()->status);
        $this->assertSame('terminee', $dependent->fresh()->status);
    }

    public function test_cascade_reopens_terminal_dependent(): void
    {
        $prerequisite = $this->makeTask('Prérequis', 'terminee');
        $dependent = $this->makeTask('Dépendante', 'terminee');
        $dependent->forceFill(['completed_at' => now()])->saveQuietly();
        $this->link($dependent, $prerequisite);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$prerequisite->id}/status", [
                'status' => 'en_cours',
                'cascade' => true,
            ])
            ->assertOk();

        $dependent->refresh();
        $this->assertSame('en_revision', $dependent->status);
        $this->assertNull($dependent->completed_at);

        $this->assertDatabaseHas('activity_logs', [
            'task_id' => $dependent->id,
            'action' => 'reouverture',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->owner->id,
            'type' => 'tache_reouverte',
        ]);
    }

    public function test_cascade_reopen_notifies_assignee_owner_and_admins(): void
    {
        $assignee = User::factory()->create();
        $admin = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        $prerequisite = $this->makeTask('Prérequis', 'terminee');
        $dependent = $this->makeTask('Dépendante', 'terminee', $assignee->id);
        $this->link($dependent, $prerequisite);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$prerequisite->id}/status", [
                'status' => 'en_cours',
                'cascade' => true,
            ])
            ->assertOk();

        // L'assigné, le propriétaire et l'admin reçoivent la notification,
        // une seule fois chacun (le propriétaire est aussi admin : pas de
        // doublon).
        foreach ([$assignee, $this->owner, $admin] as $user) {
            $this->assertSame(
                1,
                Notification::query()
                    ->where('user_id', $user->id)
                    ->where('type', 'tache_reouverte')
                    ->count(),
                "L'utilisateur {$user->id} devrait recevoir exactement une notification de réouverture."
            );
        }
    }

    public function test_reopening_without_terminal_dependent_is_a_plain_update(): void
    {
        $prerequisite = $this->makeTask('Prérequis', 'terminee');
        // La dépendante n'est pas terminée : elle ne doit pas être touchée.
        $dependent = $this->makeTask('Dépendante', 'en_cours');
        $this->link($dependent, $prerequisite);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$prerequisite->id}/status", ['status' => 'a_faire'])
            ->assertOk();

        $this->assertSame('a_faire', $prerequisite->fresh()->status);
        $this->assertSame('en_cours', $dependent->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'reouverture']);
    }

    public function test_reopening_clears_completed_at_on_the_task(): void
    {
        $prerequisite = $this->makeTask('Prérequis', 'terminee');
        $prerequisite->forceFill(['completed_at' => now()])->saveQuietly();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$prerequisite->id}/status", ['status' => 'en_cours'])
            ->assertOk();

        $this->assertNull($prerequisite->fresh()->completed_at);
    }

    public function test_reopen_falls_back_to_first_open_status_when_revision_is_terminal(): void
    {
        // L'agence rend « en révision » terminal : le repli doit alors viser le
        // premier statut ouvert, pas rester bloqué sur une clé fermée.
        AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'en_revision',
            'label' => 'En révision',
            'color' => '#000000',
            'position' => 2,
            'is_terminal' => true,
        ]);
        TaskStatusResolver::flush();

        $prerequisite = $this->makeTask('Prérequis', 'terminee');
        $dependent = $this->makeTask('Dépendante', 'terminee');
        $this->link($dependent, $prerequisite);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$prerequisite->id}/status", [
                'status' => 'en_cours',
                'cascade' => true,
            ])
            ->assertOk();

        $this->assertSame('a_faire', $dependent->fresh()->status);
    }

    public function test_bulk_reopening_with_terminal_dependent_requires_cascade(): void
    {
        $prerequisite = $this->makeTask('Prérequis', 'terminee');
        $dependent = $this->makeTask('Dépendante', 'terminee');
        $this->link($dependent, $prerequisite);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/tasks/bulk", [
                'task_ids' => [$prerequisite->id],
                'status' => 'en_cours',
            ])
            ->assertStatus(422)
            ->assertJson(['requires_cascade' => true])
            ->assertJsonPath('dependents.0.id', $dependent->id);

        // Le bulk ne cascade pas : rien ne doit avoir bougé.
        $this->assertSame('terminee', $prerequisite->fresh()->status);
        $this->assertSame('terminee', $dependent->fresh()->status);
    }

    public function test_bulk_reopening_clears_completed_at(): void
    {
        $task = $this->makeTask('Tâche terminée', 'terminee');
        $task->forceFill(['completed_at' => now()])->saveQuietly();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/tasks/bulk", [
                'task_ids' => [$task->id],
                'status' => 'en_cours',
            ])
            ->assertOk();

        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_bulk_priority_change_keeps_completed_at(): void
    {
        $task = $this->makeTask('Tâche terminée', 'terminee');
        $task->forceFill(['completed_at' => now()->subDay()])->saveQuietly();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/tasks/bulk", [
                'task_ids' => [$task->id],
                'priority' => 'haute',
            ])
            ->assertOk();

        // Un changement de priorité seul ne doit pas effacer la date de fin.
        $this->assertNotNull($task->fresh()->completed_at);
    }
}
