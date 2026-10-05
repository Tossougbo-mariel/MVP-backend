<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Attachment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Alertes d'echeance et pieces jointes.
 *
 * Ces deux domaines etaient deja implementes mais comportaient des defauts
 * reels : les alertes a 3 jours et de retard n'etaient jamais planifiees donc
 * jamais envoyees, elles ignoraient les preferences et la diffusion temps
 * reel, leur deduplication comparait des titres (deux taches homonymes se
 * neutralisaient), et l'API exposait le chemin de stockage des fichiers.
 */
class DeadlineReminderTest extends TestCase
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
        $this->agency = Agency::create([
            'name' => 'Agence Echeances',
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

    protected function tearDown(): void
    {
        TaskStatusResolver::flush();

        parent::tearDown();
    }

    private function makeTask(array $attrs = []): Task
    {
        return Task::create(array_merge([
            'project_id' => $this->project->id,
            'title' => 'Maquettes',
            'status' => 'a_faire',
            'priority' => 'normale',
            'created_by' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'due_date' => now()->addDays(3)->toDateString(),
        ], $attrs));
    }

    /**
     * Notifications d'echeance uniquement.
     *
     * Creer une tache genere deja une notification "tache assignee" via
     * TaskObserver : compter toutes les notifications confondrait l'echec
     * d'une alerte avec le bruit de fond attendu.
     */
    private function deadlineNotifications(): \Illuminate\Database\Eloquent\Builder
    {
        return Notification::whereIn('type', ['echeance_proche', 'tache_en_retard', 'rappel_echeance']);
    }

    // ---------- Alertes d'echeance ----------

    public function test_a_task_due_in_three_days_warns_the_assignee_the_owner_and_the_admins(): void
    {
        $assignee = User::factory()->create();
        $admin = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        $this->makeTask(['assigned_to' => $assignee->id]);

        $this->artisan('app:check-task-deadlines')->assertSuccessful();

        $notified = Notification::where('type', 'echeance_proche')->pluck('user_id')->all();

        // Le proprietaire est aussi l'admin de l'agence ici : il ne doit
        // recevoir qu'une seule notification.
        $this->assertEqualsCanonicalizing([$assignee->id, $admin->id, $this->owner->id], $notified);
    }

    public function test_the_alert_carries_the_link_to_the_task(): void
    {
        $task = $this->makeTask();

        $this->artisan('app:check-task-deadlines')->assertSuccessful();

        $notification = Notification::where('type', 'echeance_proche')->firstOrFail();

        // Sans lien, le clic dans le centre de notifications ne mene nulle part.
        $this->assertSame(
            "/agences/{$this->agency->id}/projets/{$this->project->id}/taches/{$task->id}",
            $notification->link,
        );
    }

    public function test_the_alert_respects_the_deadline_preference(): void
    {
        // Regression : sans correspondance dans NOTIFICATION_TYPE_MAP, ces deux
        // types tombaient dans le "toujours envoyer" et ne pouvaient pas etre
        // desactives.
        $assignee = User::factory()->create([
            'notification_preferences' => ['deadline_reminder' => false],
        ]);

        $this->makeTask(['assigned_to' => $assignee->id]);

        $this->artisan('app:check-task-deadlines')->assertSuccessful();

        // La creation de la tache genere deja une "tache assignee" : on ne
        // compte que les types d'echeance.
        $this->assertSame(
            0,
            Notification::where('user_id', $assignee->id)
                ->whereIn('type', ['echeance_proche', 'tache_en_retard'])
                ->count(),
            "L'utilisateur a coupe les rappels d'echeance.",
        );
    }

    public function test_running_the_command_twice_does_not_duplicate_notifications(): void
    {
        $this->makeTask();

        $this->artisan('app:check-task-deadlines')->assertSuccessful();
        $this->artisan('app:check-task-deadlines')->assertSuccessful();

        $this->assertSame(1, Notification::where('type', 'echeance_proche')->count());
    }

    public function test_two_tasks_sharing_a_title_in_two_agencies_both_notify(): void
    {
        // Regression : la deduplication comparait le titre. Un nom de tache
        // courant ("Migration") dans deux agences ne notifiait que le premier.
        $otherAgency = Agency::create([
            'name' => 'Agence Seconde',
            'owner_id' => $this->owner->id,
        ]);
        $otherProject = Project::create([
            'agency_id' => $otherAgency->id,
            'name' => 'Site vitrine',
            'owner_id' => $this->owner->id,
            'status' => 'en_cours',
            'start_date' => '2026-10-01',
            'due_date' => '2026-11-30',
        ]);

        $this->makeTask(['title' => 'Migration']);
        Task::create([
            'project_id' => $otherProject->id,
            'title' => 'Migration',
            'status' => 'a_faire',
            'priority' => 'normale',
            'created_by' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('app:check-task-deadlines')->assertSuccessful();

        // Le proprietaire est membre des deux agences : il doit etre prevenu
        // pour chacune, via deux notifications distinctes.
        $links = Notification::where('user_id', $this->owner->id)
            ->where('type', 'echeance_proche')
            ->pluck('link')
            ->unique();

        $this->assertCount(2, $links);
    }

    public function test_an_overdue_task_is_reported(): void
    {
        $this->makeTask(['due_date' => now()->subDays(2)->toDateString()]);

        $this->artisan('app:check-task-deadlines')->assertSuccessful();

        $this->assertSame(1, Notification::where('type', 'tache_en_retard')->count());
    }

    public function test_a_finished_task_is_not_reported(): void
    {
        $this->makeTask(['status' => 'terminee']);

        $this->artisan('app:check-task-deadlines')->assertSuccessful();

$this->assertSame(0, $this->deadlineNotifications()->count());
    }

    public function test_the_command_is_actually_scheduled(): void
    {
        // Regression : CheckTaskDeadlines n'apparait dans aucun
        // enregistrement de planification, ses notifications ne partaient donc
        // jamais. On passe par schedule:list : les entrees declarees dans
        // routes/console.php ne sont enregistrees qu'au demarrage de la
        // console, pas dans un contexte HTTP de test.
        $this->withoutMockingConsoleOutput();

        // Sans le mock, artisan() renvoie le code de sortie et non un objet
        // PendingCommand : on ne peut plus enchaîner assertSuccessful().
        $this->assertSame(0, $this->artisan('schedule:list'));

        $output = Artisan::output();

        $this->assertStringContainsString('tasks:send-deadline-reminders', $output);
        $this->assertStringContainsString('app:check-task-deadlines', $output);
    }

    // ---------- Pieces jointes ----------

    public function test_the_storage_path_never_leaves_the_api(): void
    {
        Storage::fake('local');

        $task = $this->makeTask();

        $created = $this->actingAs($this->owner)
            ->postJson("/api/tasks/{$task->id}/attachments", [
                'file' => UploadedFile::fake()->create('contrat.pdf', 12, 'application/pdf'),
            ])
            ->assertCreated()
            ->json();

        $this->assertArrayNotHasKey('file_path', $created);

        $list = $this->actingAs($this->owner)
            ->getJson("/api/tasks/{$task->id}/attachments")
            ->assertOk()
            ->json();

        $this->assertCount(1, $list);
        $this->assertArrayNotHasKey('file_path', $list[0]);
    }

    public function test_downloading_a_file_that_left_the_disk_returns_404_not_500(): void
    {
        Storage::fake('local');

        $attachment = $this->storeAttachment('plan.pdf');

        // Le disque a ete vide par-dessous (sauvegarde incomplete, nettoyage).
        Storage::disk('local')->delete($attachment->file_path);

        $this->actingAs($this->owner)
            ->getJson("/api/attachments/{$attachment->id}/download")
            ->assertNotFound();
    }

    public function test_a_member_can_download_an_attachment(): void
    {
        Storage::fake('local');

        $attachment = $this->storeAttachment('cahier-des-charges.pdf');

        $response = $this->actingAs($this->owner)
            ->get("/api/attachments/{$attachment->id}/download")
            ->assertOk();

        $this->assertStringContainsString(
            'cahier-des-charges.pdf',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_a_stranger_cannot_see_or_download_attachments(): void
    {
        Storage::fake('local');

        $attachment = $this->storeAttachment('interne.pdf');
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson("/api/tasks/{$attachment->task_id}/attachments")
            ->assertForbidden();

        $this->actingAs($stranger)
            ->getJson("/api/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_reach_the_attachment_endpoints(): void
    {
        // Pièce jointe créée en base et non via l'API : un appel à actingAs()
        // resterait actif pour la suite du test, et le visiteur anonyme ne
        // serait plus vraiment anonyme.
        $attachment = $this->seedAttachment();

        $this->getJson("/api/tasks/{$attachment->task_id}/attachments")
            ->assertUnauthorized();

        $this->getJson("/api/attachments/{$attachment->id}/download")
            ->assertUnauthorized();

        $this->deleteJson("/api/attachments/{$attachment->id}")
            ->assertUnauthorized();
    }

    public function test_only_the_uploader_or_an_admin_can_delete_an_attachment(): void
    {
        Storage::fake('local');

        $attachment = $this->storeAttachment('brouillon.pdf', uploader: $this->owner);

        $stranger = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $stranger->id,
            'role' => 'membre',
            'status' => 'actif',
        ]);

        // Un membre du projet voit la tache mais ne peut pas supprimer le
        // fichier d'un autre.
        $this->actingAs($stranger)
            ->deleteJson("/api/attachments/{$attachment->id}")
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->deleteJson("/api/attachments/{$attachment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    private function storeAttachment(string $name, ?User $uploader = null): Attachment
    {
        $uploader ??= $this->owner;

        $task = $this->makeTask();

        // L'API renvoie l'attacheMENT a plat, sans enveloppe "data".
        $id = $this->actingAs($uploader)
            ->postJson("/api/tasks/{$task->id}/attachments", [
                'file' => UploadedFile::fake()->create($name, 8, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('id');

        return Attachment::findOrFail($id);
    }

    /**
     * Crée une pièce jointe sans passer par l'API.
     *
     * Utile quand le test doit ensuite vérifier le comportement d'un visiteur
     * anonyme : passer par l'API imposerait un actingAs() qui resterait actif.
     */
    private function seedAttachment(string $name = 'interne.pdf'): Attachment
    {
        Storage::fake('local');

        $task = $this->makeTask();
        $path = "attachments/{$task->id}/".uniqid().'.pdf';

        Storage::disk('local')->put($path, 'contenu de test');

        return Attachment::create([
            'task_id' => $task->id,
            'user_id' => $this->owner->id,
            'file_name' => $name,
            'file_path' => $path,
            'file_size' => 15,
            'mime_type' => 'application/pdf',
        ]);
    }
}