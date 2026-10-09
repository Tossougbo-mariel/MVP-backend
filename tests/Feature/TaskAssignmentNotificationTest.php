<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notifications d'assignation / de retrait d'une tache.
 *
 * Le message doit preciser QUI a fait l'action, SON ROLE dans l'agence
 * (proprietaire ou admin) et L'AGENCE concernee — et non plus un simple
 * « On vous a assigne » anonyme.
 */
class TaskAssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Agency $agency;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->agency = Agency::create([
            'name' => 'Agence Notifications',
            'owner_id' => $this->owner->id,
        ]);
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->owner->id,
            'role' => 'proprietaire',
            'status' => 'actif',
        ]);

        $this->project = Project::create([
            'agency_id' => $this->agency->id,
            'name' => 'Refonte site',
            'owner_id' => $this->owner->id,
            'status' => 'en_cours',
            'start_date' => '2026-10-01',
            'due_date' => '2026-11-30',
        ]);

        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->owner->id,
        ]);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $member->id,
        ]);

        return $member;
    }

    private function makeTask(array $attrs = []): Task
    {
        return Task::create(array_merge([
            'project_id' => $this->project->id,
            'title' => 'tache4',
            'status' => 'a_faire',
            'priority' => 'normale',
            'created_by' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'due_date' => now()->addDays(5)->toDateString(),
        ], $attrs));
    }

    public function test_the_assignment_notification_names_the_actor_his_role_and_the_agency(): void
    {
        $assignee = $this->member();
        $this->makeTask(['assigned_to' => $assignee->id]);

        $message = Notification::where('type', 'tache_assignee')->firstOrFail()->message;

        $this->assertSame(
            "{$this->owner->name} (propriétaire) de l'agence « {$this->agency->name} » vous a assigné à « tache4 »",
            $message,
        );
    }

    public function test_the_removal_notification_names_the_actor_his_role_and_the_agency(): void
    {
        $previous = $this->member();
        $task = $this->makeTask(['assigned_to' => $previous->id]);

        $task->update(['assigned_to' => $this->member()->id]);

        $message = Notification::where('type', 'tache_retiree')->firstOrFail()->message;

        $this->assertSame(
            "{$this->owner->name} (propriétaire) de l'agence « {$this->agency->name} » vous a retiré de « tache4 »",
            $message,
        );
    }

    public function test_an_admin_is_labelled_admin(): void
    {
        $admin = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        $assignee = $this->member();
        $task = $this->makeTask(['assigned_to' => $this->owner->id]);

        $this->actingAs($admin);
        $task->update(['assigned_to' => $assignee->id]);

        $message = Notification::where('type', 'tache_assignee')->where('user_id', $assignee->id)->firstOrFail()->message;

        $this->assertStringContainsString("{$admin->name} (admin)", $message);
        $this->assertStringContainsString("de l'agence « {$this->agency->name} »", $message);
    }
}
