<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\AgencyTaskStatus;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les statuts de tâches sont configurables par agence. Ces tests verrouillent
 * le contrat : le repli sur les defaults, l'isolement entre agences, et le
 * fait que `is_terminal` (et non la clé 'terminee') décide de ce qui clôt une
 * tâche.
 */
class AgencyTaskStatusTest extends TestCase
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
        $this->agency = $this->makeAgency('Agence Alpha', $this->owner);
        $this->project = Project::create([
            'agency_id' => $this->agency->id,
            'name' => 'Projet',
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

    private function makeAgency(string $name, User $owner): Agency
    {
        $agency = Agency::create(['name' => $name, 'owner_id' => $owner->id]);
        AgencyMember::create([
            'agency_id' => $agency->id,
            'user_id' => $owner->id,
            // Convention de l'app : le créateur de l'agence est 'admin'.
            'role' => 'admin',
            'status' => 'actif',
        ]);

        return $agency;
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
            'created_by' => $this->owner->id,
        ], $overrides));
    }

    // ---------- Repli sur les valeurs par défaut ----------

    public function test_it_falls_back_to_the_historical_defaults(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/agencies/{$this->agency->id}/task-statuses")
            ->assertOk()
            ->assertJsonPath('uses_defaults', true)
            ->assertJsonPath('statuses.0.key', 'a_faire')
            ->assertJsonPath('statuses.3.key', 'terminee')
            ->assertJsonPath('statuses.3.is_terminal', true);
    }

    public function test_task_status_keeps_working_without_any_configuration(): void
    {
        $task = $this->task(['status' => 'terminee']);

        $this->assertTrue($task->isTerminalStatus());

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'en_cours'])
            ->assertOk();

        $this->assertFalse($task->fresh()->isTerminalStatus());
    }

    // ---------- Configuration par agence ----------

    public function test_the_owner_can_add_a_status(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/agencies/{$this->agency->id}/task-statuses", [
                'label' => 'En attente client',
                'color' => '#7c3aed',
            ])
            ->assertCreated()
            ->assertJsonPath('key', 'en_attente_client')
            ->assertJsonPath('label', 'En attente client');

        TaskStatusResolver::flush();

        $this->assertContains(
            'en_attente_client',
            TaskStatusResolver::keys((int) $this->agency->id)
        );
    }

    public function test_duplicate_labels_are_refused(): void
    {
        AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'bloque',
            'label' => 'Bloqué',
            'position' => 1,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/agencies/{$this->agency->id}/task-statuses", [
                'label' => 'Bloqué',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'existe déjà'));
    }

    /**
     * Les colonnes historiques ne sont pas figées : on doit pouvoir les
     * renommer, sinon « En cours » resterait immuable pour toutes les agences.
     */
    public function test_a_default_status_can_be_customized_through_the_api(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/agencies/{$this->agency->id}/task-statuses", [
                'label' => 'En cours de traitement',
                'color' => '#123456',
            ])
            ->assertCreated()
            // La clé est derivée du nouveau libelle, pas de l'ancien.
            ->assertJsonPath('key', 'en_cours_de_traitement');

        TaskStatusResolver::flush();

        $this->assertSame(
            ['a_faire', 'en_cours', 'en_revision', 'terminee', 'en_cours_de_traitement'],
            TaskStatusResolver::keys((int) $this->agency->id)
        );
    }

    public function test_index_exposes_the_row_id_only_for_configured_statuses(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/agencies/{$this->agency->id}/task-statuses")
            ->assertOk();

        $byKey = collect($response->json('statuses'))->keyBy('key');

        // Les statuts par defaut n'ont pas de ligne : pas d'id, donc non
        // modifiables directement.
        $this->assertNull($byKey['a_faire']['id']);

        $statusId = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/agencies/{$this->agency->id}/task-statuses", ['label' => 'Bloqué'])
            ->json('id');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/agencies/{$this->agency->id}/task-statuses")
            ->assertOk()
            ->assertJsonPath('statuses.4.key', 'bloque')
            ->assertJsonPath('statuses.4.id', $statusId)
            ->assertJsonPath('uses_defaults', false);
    }

    public function test_statuses_are_isolated_between_agencies(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->makeAgency('Agence Beta', $otherOwner);

        AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'bloque',
            'label' => 'Bloqué',
            'position' => 1,
        ]);
        TaskStatusResolver::flush();

        $this->assertContains('bloque', TaskStatusResolver::keys((int) $this->agency->id));
        $this->assertNotContains('bloque', TaskStatusResolver::keys((int) $other->id));
    }

    /**
     * Regression : `tasks.status` contient deja les quatre cles historiques.
     * Ajouter une colonne ne doit donc pas retirer les colonnes existantes,
     * sinon toutes les taches de l'agence deviennent invalides.
     */
    public function test_custom_statuses_extend_rather_than_replace_the_defaults(): void
    {
        AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'bloque',
            'label' => 'Bloqué',
            'position' => 1,
        ]);
        TaskStatusResolver::flush();

        $keys = TaskStatusResolver::keys((int) $this->agency->id);

        $this->assertContains('bloque', $keys);
        foreach (['a_faire', 'en_cours', 'en_revision', 'terminee'] as $legacy) {
            $this->assertContains($legacy, $keys, "le statut historique {$legacy} a disparu");
        }

        // La colonne ajoutee se trouve apres les colonnes historiques.
        $this->assertSame('bloque', end($keys));
    }

    /**
     * Une agence doit pouvoir renommer ou rendre terminal une colonne
     * historique sans perdre sa place dans l'ordre.
     */
    public function test_customizing_a_default_status_overrides_it_in_place(): void
    {
        AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'en_cours',
            'label' => 'En cours de Treatment',
            'color' => '#123456',
            'position' => 1,
            'is_terminal' => true,
        ]);
        TaskStatusResolver::flush();

        $statuses = TaskStatusResolver::forAgency((int) $this->agency->id);
        $byKey = $statuses->keyBy('key');

        $this->assertSame('En cours de Treatment', $byKey['en_cours']['label']);
        $this->assertSame('#123456', $byKey['en_cours']['color']);
        $this->assertTrue($byKey['en_cours']['is_terminal']);

        // L'ordre des colonnes d'origine est preserve.
        $this->assertSame(
            ['a_faire', 'en_cours', 'en_revision', 'terminee'],
            $statuses->pluck('key')->all()
        );
    }

    /**
     * Un statut historique reste valide meme apres personnalisation : une
     * tache deja enregistree ne doit pas devenir impossible a sauvegarder.
     */
    public function test_legacy_task_status_stays_valid_after_customization(): void
    {
        AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'bloque',
            'label' => 'Bloqué',
            'position' => 1,
        ]);
        TaskStatusResolver::flush();

        $task = $this->task(['status' => 'en_cours']);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'en_cours'])
            ->assertOk();
    }

    public function test_another_agency_cannot_touch_those_statuses(): void
    {
        $status = AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'bloque',
            'label' => 'Bloqué',
            'position' => 1,
        ]);

        $stranger = User::factory()->create();
        $other = $this->makeAgency('Agence Beta', $stranger);

        // L'ID existe mais appartient a une autre agence : 404, pas fuite.
        $this->actingAs($stranger, 'sanctum')
            ->putJson("/api/agencies/{$other->id}/task-statuses/{$status->id}", [
                'label' => 'Piraté',
            ])
            ->assertNotFound();
    }

    public function test_a_plain_member_cannot_configure_statuses(): void
    {
        $member = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $member->id,
            'role' => 'membre',
            'status' => 'actif',
        ]);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/agencies/{$this->agency->id}/task-statuses", ['label' => 'Interdit'])
            ->assertForbidden();
    }

    // ---------- is_terminal pilote la clôture ----------

    public function test_a_custom_terminal_status_closes_the_task(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/agencies/{$this->agency->id}/task-statuses", [
                'label' => 'Livré',
            ]);

        $id = AgencyTaskStatus::where('agency_id', $this->agency->id)
            ->where('key', 'livre')
            ->value('id');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/agencies/{$this->agency->id}/task-statuses/{$id}", [
                'is_terminal' => true,
            ])
            ->assertOk();

        TaskStatusResolver::flush();

        $task = $this->task(['status' => 'livre']);

        $this->assertTrue($task->isTerminalStatus());
        $this->assertNotContains('livre', TaskStatusResolver::openKeys((int) $this->agency->id));
    }

    public function test_setting_completed_at_uses_the_terminal_flag(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/agencies/{$this->agency->id}/task-statuses", ['label' => 'Livré']);

        $id = AgencyTaskStatus::where('agency_id', $this->agency->id)
            ->where('key', 'livre')
            ->value('id');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/agencies/{$this->agency->id}/task-statuses/{$id}", [
                'is_terminal' => true,
            ]);
        TaskStatusResolver::flush();

        $task = $this->task();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'livre'])
            ->assertOk();

        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $task = $this->task();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'statut_inexistant'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // ---------- Les membres ne déplacent que leurs tâches ----------

    private function memberOnProject(string $role = 'membre'): User
    {
        $member = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $member->id,
            'role' => $role,
            'status' => 'actif',
        ]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $member->id,
        ]);

        return $member;
    }

    /**
     * Règle du Kanban : un membre déplace uniquement les tâches qui lui sont
     * assignées — comme le prévoit TaskPolicy::updateStatus.
     */
    public function test_a_member_can_move_a_task_assigned_to_them(): void
    {
        $member = $this->memberOnProject();
        $task = $this->task(['assigned_to' => $member->id, 'status' => 'a_faire']);

        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'en_cours'])
            ->assertOk();

        $this->assertSame('en_cours', $task->fresh()->status);
    }

    public function test_a_member_cannot_move_a_task_assigned_to_someone_else(): void
    {
        $ownerMember = $this->memberOnProject();
        $other = $this->memberOnProject();
        $task = $this->task(['assigned_to' => $ownerMember->id, 'status' => 'a_faire']);

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'en_cours'])
            ->assertForbidden();

        $this->assertSame('a_faire', $task->fresh()->status);
    }

    public function test_a_member_cannot_move_an_unassigned_task(): void
    {
        $member = $this->memberOnProject();
        $task = $this->task(['assigned_to' => null, 'status' => 'a_faire']);

        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'en_cours'])
            ->assertForbidden();

        $this->assertSame('a_faire', $task->fresh()->status);
    }

    public function test_an_admin_can_move_any_task(): void
    {
        $member = $this->memberOnProject();
        $task = $this->task(['assigned_to' => $member->id, 'status' => 'a_faire']);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'en_cours'])
            ->assertOk();

        $this->assertSame('en_cours', $task->fresh()->status);
    }

    // ---------- Suppression protégée ----------

    public function test_a_status_used_by_tasks_cannot_be_deleted(): void
    {
        $status = AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'bloque',
            'label' => 'Bloqué',
            'position' => 1,
        ]);
        $this->task(['status' => 'bloque']);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/agencies/{$this->agency->id}/task-statuses/{$status->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, "1 tâche(s)"));
    }

    public function test_an_unused_status_can_be_deleted(): void
    {
        $status = AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'inutile',
            'label' => 'Inutile',
            'position' => 1,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/agencies/{$this->agency->id}/task-statuses/{$status->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        TaskStatusResolver::flush();
        $this->assertNotContains('inutile', TaskStatusResolver::keys((int) $this->agency->id));
    }

    public function test_reassign_moves_tasks_before_deleting(): void
    {
        $status = AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'bloque',
            'label' => 'Bloqué',
            'position' => 1,
        ]);
        $task = $this->task(['status' => 'bloque']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/agencies/{$this->agency->id}/task-statuses/{$status->id}/reassign", [
                'to' => 'en_cours',
            ])
            ->assertOk()
            ->assertJsonPath('moved', 1);

        $this->assertSame('en_cours', $task->fresh()->status);
    }

    public function test_the_last_terminal_status_cannot_be_unticked(): void
    {
        // 'terminee' est le seul statut terminal de l'agence par defaut.
        $this->assertSame(['terminee'], TaskStatusResolver::terminalKeys((int) $this->agency->id));

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/agencies/{$this->agency->id}/task-statuses/999", ['is_terminal' => false])
            ->assertNotFound();

        // Le cas reel : on de-customise 'terminee' en la declarant non terminale
        // alors qu'aucun autre terminal n'existe.
        $row = AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'terminee',
            'label' => 'Terminée',
            'position' => 0,
            'is_terminal' => true,
        ]);
        TaskStatusResolver::flush();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/agencies/{$this->agency->id}/task-statuses/{$row->id}", [
                'is_terminal' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'au moins un statut'));
    }

    public function test_unticking_is_allowed_once_another_terminal_exists(): void
    {
        $row = AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'terminee',
            'label' => 'Terminée',
            'position' => 0,
            'is_terminal' => true,
        ]);
        AgencyTaskStatus::create([
            'agency_id' => $this->agency->id,
            'key' => 'livre',
            'label' => 'Livré',
            'position' => 1,
            'is_terminal' => true,
        ]);
        TaskStatusResolver::flush();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/agencies/{$this->agency->id}/task-statuses/{$row->id}", [
                'is_terminal' => false,
            ])
            ->assertOk();

        TaskStatusResolver::flush();

        $this->assertSame(['livre'], TaskStatusResolver::terminalKeys((int) $this->agency->id));
    }
}
