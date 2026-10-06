<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Subtask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sous-tâches imposées par l'admin + suppression d'étiquettes par les membres.
 */
class SubtaskAndTagTest extends TestCase
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
            'assigned_to' => $this->member->id,
            'status' => 'en_cours',
            'priority' => 'moyenne',
            'start_date' => '2026-10-01',
            'due_date' => '2026-10-31',
        ]);
    }

    private function actingAsUser(User $user): self
    {
        return $this->actingAs($user, 'sanctum');
    }

    public function test_admin_created_subtask_is_imposed(): void
    {
        $this->actingAsUser($this->owner)->postJson("/api/tasks/{$this->task->id}/subtasks", [
            'title' => 'Étape obligatoire',
        ])->assertCreated()->assertJson(['imposed' => true]);
    }

    public function test_member_created_subtask_is_not_imposed(): void
    {
        $this->actingAsUser($this->member)->postJson("/api/tasks/{$this->task->id}/subtasks", [
            'title' => 'Étape personnelle',
        ])->assertCreated()->assertJson(['imposed' => false]);
    }

    public function test_member_cannot_delete_an_imposed_subtask(): void
    {
        $subtask = Subtask::create([
            'task_id' => $this->task->id,
            'title' => 'Imposée',
            'created_by' => $this->owner->id,
            'imposed' => true,
        ]);

        $this->actingAsUser($this->member)->deleteJson("/api/subtasks/{$subtask->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('subtasks', ['id' => $subtask->id]);
    }

    public function test_member_can_delete_his_own_subtask(): void
    {
        $subtask = Subtask::create([
            'task_id' => $this->task->id,
            'title' => 'Personnelle',
            'created_by' => $this->member->id,
            'imposed' => false,
        ]);

        $this->actingAsUser($this->member)->deleteJson("/api/subtasks/{$subtask->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('subtasks', ['id' => $subtask->id]);
    }

    public function test_admin_can_delete_an_imposed_subtask(): void
    {
        $subtask = Subtask::create([
            'task_id' => $this->task->id,
            'title' => 'Imposée',
            'created_by' => $this->owner->id,
            'imposed' => true,
        ]);

        $this->actingAsUser($this->owner)->deleteJson("/api/subtasks/{$subtask->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('subtasks', ['id' => $subtask->id]);
    }

    public function test_complete_all_checks_every_open_subtask(): void
    {
        Subtask::create(['task_id' => $this->task->id, 'title' => 'A', 'done' => false, 'imposed' => true]);
        Subtask::create(['task_id' => $this->task->id, 'title' => 'B', 'done' => true, 'imposed' => false]);
        Subtask::create(['task_id' => $this->task->id, 'title' => 'C', 'done' => false, 'imposed' => false]);

        $this->actingAsUser($this->member)->postJson("/api/tasks/{$this->task->id}/subtasks/complete-all")
            ->assertOk();

        $this->assertSame(0, $this->task->subtasks()->where('done', false)->count());
        $this->assertSame(3, $this->task->subtasks()->where('done', true)->count());
    }

    public function test_active_member_can_delete_an_agency_tag(): void
    {
        $tag = Tag::create(['agency_id' => $this->agency->id, 'name' => 'Urgent', 'color' => '#ef4444']);

        $this->actingAsUser($this->member)->deleteJson("/api/tags/{$tag->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_admin_can_delete_an_agency_tag(): void
    {
        $tag = Tag::create(['agency_id' => $this->agency->id, 'name' => 'Urgent', 'color' => '#ef4444']);

        $this->actingAsUser($this->owner)->deleteJson("/api/tags/{$tag->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }
}