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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dépendances entre tâches : contraintes de structure (même projet, pas de
 * doublon, pas de boucle) et cohérence des échéances (prérequis/tâche).
 */
class TaskDependencyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Agency $agency;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->agency = Agency::create(['name' => 'Agence', 'owner_id' => $this->owner->id]);
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->owner->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->member->id,
            'role' => 'membre',
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
        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->member->id]);

        $this->task = Task::create([
            'project_id' => $this->project->id,
            'title' => 'Tâche',
            'created_by' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'status' => 'en_cours',
            'priority' => 'moyenne',
            'start_date' => '2026-10-01',
            'due_date' => '2026-10-31',
        ]);
    }

    private function makeTask(string $title, string $due): Task
    {
        return Task::create([
            'project_id' => $this->project->id,
            'title' => $title,
            'created_by' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'status' => 'en_cours',
            'priority' => 'moyenne',
            'start_date' => '2026-10-01',
            'due_date' => $due,
        ]);
    }

    public function test_conflicting_due_date_requires_confirmation(): void
    {
        $prerequisite = $this->makeTask('Prérequis tardif', '2026-11-15');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/tasks/{$this->task->id}/dependencies", [
                'depends_on_task_id' => $prerequisite->id,
            ])
            ->assertStatus(422)
            ->assertJson(['requires_confirmation' => true]);

        $this->assertDatabaseMissing('task_dependencies', [
            'task_id' => $this->task->id,
            'depends_on_task_id' => $prerequisite->id,
        ]);
    }

    public function test_conflicting_due_date_can_be_forced(): void
    {
        $prerequisite = $this->makeTask('Prérequis tardif', '2026-11-15');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/tasks/{$this->task->id}/dependencies", [
                'depends_on_task_id' => $prerequisite->id,
                'force' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('task_dependencies', [
            'task_id' => $this->task->id,
            'depends_on_task_id' => $prerequisite->id,
        ]);
    }

    public function test_compatible_due_date_is_accepted(): void
    {
        $prerequisite = $this->makeTask('Prérequis antérieur', '2026-10-15');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/tasks/{$this->task->id}/dependencies", [
                'depends_on_task_id' => $prerequisite->id,
            ])
            ->assertCreated();
    }

    public function test_task_cannot_depend_on_itself(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/tasks/{$this->task->id}/dependencies", [
                'depends_on_task_id' => $this->task->id,
            ])
            ->assertStatus(422);
    }

    public function test_dependency_must_belong_to_same_project(): void
    {
        $otherProject = Project::create([
            'agency_id' => $this->agency->id,
            'name' => 'Autre',
            'owner_id' => $this->owner->id,
            'status' => 'en_cours',
            'start_date' => '2026-10-01',
            'due_date' => '2026-11-30',
        ]);
        $foreign = Task::create([
            'project_id' => $otherProject->id,
            'title' => 'Hors projet',
            'created_by' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'status' => 'en_cours',
            'priority' => 'moyenne',
            'start_date' => '2026-10-01',
            'due_date' => '2026-10-10',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/tasks/{$this->task->id}/dependencies", [
                'depends_on_task_id' => $foreign->id,
            ])
            ->assertStatus(422);
    }

    public function test_duplicate_dependency_is_rejected(): void
    {
        $prerequisite = $this->makeTask('Prérequis', '2026-10-15');
        TaskDependency::create([
            'task_id' => $this->task->id,
            'depends_on_task_id' => $prerequisite->id,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/tasks/{$this->task->id}/dependencies", [
                'depends_on_task_id' => $prerequisite->id,
            ])
            ->assertStatus(422);
    }

    public function test_cycle_is_rejected(): void
    {
        $other = $this->makeTask('Autre', '2026-10-15');
        // L'autre tâche dépend déjà de la tâche courante.
        TaskDependency::create([
            'task_id' => $other->id,
            'depends_on_task_id' => $this->task->id,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/tasks/{$this->task->id}/dependencies", [
                'depends_on_task_id' => $other->id,
            ])
            ->assertStatus(422);
    }

    public function test_due_date_edit_conflicting_with_prerequisite_is_rejected(): void
    {
        $prerequisite = $this->makeTask('Prérequis', '2026-10-15');
        TaskDependency::create([
            'task_id' => $this->task->id,
            'depends_on_task_id' => $prerequisite->id,
        ]);

        // Nouvelle échéance (10 oct) antérieure au prérequis (15 oct) -> refus.
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/tasks/{$this->task->id}", [
                'start_date' => '2026-10-01',
                'due_date' => '2026-10-10',
            ])
            ->assertStatus(422);
    }

    public function test_due_date_edit_conflicting_with_dependent_is_rejected(): void
    {
        $dependent = $this->makeTask('Dépendante', '2026-10-31');
        // La dépendante dépend de la tâche courante ; échéances cohérentes.
        TaskDependency::create([
            'task_id' => $dependent->id,
            'depends_on_task_id' => $this->task->id,
        ]);

        // La tâche courante passerait après sa dépendante (31 oct) -> refus.
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/tasks/{$this->task->id}", [
                'start_date' => '2026-10-01',
                'due_date' => '2026-11-15',
            ])
            ->assertStatus(422);
    }

    public function test_compatible_due_date_edit_is_accepted(): void
    {
        $prerequisite = $this->makeTask('Prérequis', '2026-10-15');
        TaskDependency::create([
            'task_id' => $this->task->id,
            'depends_on_task_id' => $prerequisite->id,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/tasks/{$this->task->id}", [
                'start_date' => '2026-10-01',
                'due_date' => '2026-10-20',
            ])
            ->assertOk();
    }

    public function test_overdue_task_without_dependency_is_late(): void
    {
        $this->task->update([
            'due_date' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        $this->assertSame('en_retard', $this->task->fresh()->deadline_status);
    }

    public function test_overdue_task_with_open_dependency_is_not_late(): void
    {
        // Le prérequis reste ouvert (en_cours), donc la tâche est bloquée.
        $prerequisite = $this->makeTask('Prérequis', Carbon::today()->subDays(2)->toDateString());
        $this->task->update([
            'due_date' => Carbon::today()->subDays(5)->toDateString(),
        ]);
        TaskDependency::create([
            'task_id' => $this->task->id,
            'depends_on_task_id' => $prerequisite->id,
        ]);

        $this->assertSame('a_venir', $this->task->fresh()->deadline_status);
    }

    public function test_overdue_task_with_terminal_dependency_is_late(): void
    {
        $prerequisite = $this->makeTask('Prérequis', Carbon::today()->subDays(2)->toDateString());
        $prerequisite->update(['status' => 'terminee']);
        $this->task->update([
            'due_date' => Carbon::today()->subDays(5)->toDateString(),
        ]);
        TaskDependency::create([
            'task_id' => $this->task->id,
            'depends_on_task_id' => $prerequisite->id,
        ]);

        $this->assertSame('en_retard', $this->task->fresh()->deadline_status);
    }

    public function test_approaching_task_with_open_dependency_is_not_flagged(): void
    {
        $prerequisite = $this->makeTask('Prérequis', Carbon::today()->subDays(2)->toDateString());
        $this->task->update([
            'due_date' => Carbon::today()->addDays(2)->toDateString(),
        ]);
        TaskDependency::create([
            'task_id' => $this->task->id,
            'depends_on_task_id' => $prerequisite->id,
        ]);

        $this->assertSame('a_venir', $this->task->fresh()->deadline_status);
    }

    public function test_deadline_status_is_exposed_in_serialization(): void
    {
        $this->task->update([
            'due_date' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        $this->assertSame(
            'en_retard',
            $this->task->fresh()->toArray()['deadline_status'],
        );
    }
}
