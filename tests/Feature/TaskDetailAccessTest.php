<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un membre du projet ouvre la fiche de n'importe quelle tâche du projet
 * (lecture seule). L'assignation ne conditionne que l'édition : commentaires,
 * sous-tâches, pièces jointes, dépendances restent réservés à l'assigné ou à
 * l'admin. L'admin accède à tout.
 */
class TaskDetailAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $assigned;

    private User $outsider;

    private Agency $agency;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->assigned = User::factory()->create();
        $this->outsider = User::factory()->create();

        $this->agency = Agency::create(['name' => 'Agence', 'owner_id' => $this->owner->id]);

        foreach ([$this->owner, $this->assigned, $this->outsider] as $user) {
            AgencyMember::create([
                'agency_id' => $this->agency->id,
                'user_id' => $user->id,
                'role' => $user->is($this->owner) ? 'admin' : 'membre',
                'status' => 'actif',
            ]);
        }

        $this->project = Project::create([
            'agency_id' => $this->agency->id,
            'name' => 'Projet',
            'owner_id' => $this->owner->id,
            'status' => 'en_cours',
            'start_date' => '2026-10-01',
            'due_date' => '2026-11-30',
        ]);

        foreach ([$this->owner, $this->assigned, $this->outsider] as $user) {
            ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $user->id]);
        }

        $this->task = Task::create([
            'project_id' => $this->project->id,
            'title' => 'Tâche sensible',
            'created_by' => $this->owner->id,
            'assigned_to' => $this->assigned->id,
            'status' => 'a_faire',
            'priority' => 'moyenne',
            'start_date' => '2026-10-05',
            'due_date' => '2026-10-09',
        ]);
    }

    public function test_the_assignee_can_open_the_task_details(): void
    {
        $this->actingAs($this->assigned, 'sanctum')
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertOk();
    }

    public function test_a_project_member_can_open_an_unassigned_task(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertOk();
    }

    public function test_a_non_project_member_cannot_open_a_task(): void
    {
        $bystander = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $bystander->id,
            'role' => 'membre',
            'status' => 'actif',
        ]);

        $this->actingAs($bystander, 'sanctum')
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertForbidden();
    }

    public function test_the_owner_can_open_any_task_details(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertOk();
    }

    public function test_the_kanban_list_stays_visible_to_the_unassigned_member(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/tasks")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->task->id);
    }

    public function test_a_member_can_read_subtasks_of_an_unassigned_task(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/tasks/{$this->task->id}/subtasks")
            ->assertOk();
    }

    public function test_a_member_can_read_comments_of_an_unassigned_task(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/tasks/{$this->task->id}/comments")
            ->assertOk();
    }

    public function test_a_member_cannot_comment_on_an_unassigned_task(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->postJson("/api/tasks/{$this->task->id}/comments", ['content' => 'Spam'])
            ->assertForbidden();
    }

    public function test_a_member_can_read_activity_of_an_unassigned_task(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/activity?task_id='.$this->task->id)
            ->assertOk();
    }

    public function test_the_assignee_can_manage_the_subtasks(): void
    {
        $this->actingAs($this->assigned, 'sanctum')
            ->postJson("/api/tasks/{$this->task->id}/subtasks", ['title' => 'Étape'])
            ->assertCreated();
    }
}
