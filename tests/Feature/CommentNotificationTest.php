<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notifications issues des commentaires.
 *
 * CommentObserver doit prevenir deux publics distincts : le responsable et le
 * createur de la tache (type "nouveau_commentaire"), puis les personnes
 * explicitement mentionnees (type "mention"). L'auteur du commentaire ne
 * doit jamais recevoir la sienne, meme quand c'est lui le responsable.
 */
class CommentNotificationTest extends TestCase
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
            'name' => 'Agence Commentaires',
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

    private function makeTask(array $attrs = []): Task
    {
        return Task::create(array_merge([
            'project_id' => $this->project->id,
            'title' => 'Maquettes',
            'status' => 'a_faire',
            'priority' => 'normale',
            'created_by' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'due_date' => now()->addDays(5)->toDateString(),
        ], $attrs));
    }

    private function addMember(): User
    {
        $member = User::factory()->create();
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $member->id,
        ]);

        return $member;
    }

    public function test_a_comment_notifies_the_assignee_but_never_its_author(): void
    {
        $assignee = $this->addMember();
        $task = $this->makeTask(['assigned_to' => $assignee->id]);

        Comment::create([
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'content' => "Je m'en occupe.",
            'mention_ids' => [],
        ]);

        $notified = Notification::where('type', 'nouveau_commentaire')->pluck('user_id')->all();

        // Le createur est prevenu, le responsable qui vient de commenter non.
        $this->assertSame([$this->owner->id], $notified);
    }

    public function test_the_creator_of_the_task_is_not_notified_of_his_own_comment(): void
    {
        // Le createur est ici aussi responsable : aucun des deux roles ne
        // doit declencher de notification.
        $task = $this->makeTask();

        Comment::create([
            'task_id' => $task->id,
            'user_id' => $this->owner->id,
            'content' => 'Avancement ?',
            'mention_ids' => [],
        ]);

        $this->assertSame(0, Notification::where('type', 'nouveau_commentaire')->count());
    }

    public function test_a_mentioned_member_receives_a_mention_notification(): void
    {
        $assignee = $this->addMember();
        $mentioned = $this->addMember();
        $task = $this->makeTask(['assigned_to' => $assignee->id]);

        Comment::create([
            'task_id' => $task->id,
            'user_id' => $this->owner->id,
            'content' => '@team on peut avancer ?',
            'mention_ids' => [$mentioned->id],
        ]);

        $mention = Notification::where('type', 'mention')->firstOrFail();

        $this->assertSame($mentioned->id, $mention->user_id);
        // Le responsable est deja prevenu par "nouveau_commentaire" : une
        // seule notification par personne et par commentaire.
        $this->assertSame(
            1,
            Notification::whereIn('type', ['nouveau_commentaire', 'mention'])->where('user_id', $assignee->id)->count(),
        );
    }

    public function test_the_notification_links_to_the_task_and_carries_the_agency(): void
    {
        $assignee = $this->addMember();
        $task = $this->makeTask(['assigned_to' => $assignee->id]);

        Comment::create([
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'content' => 'Pret pour la recette.',
            'mention_ids' => [],
        ]);

        $notification = Notification::where('type', 'nouveau_commentaire')->firstOrFail();

        // Sans agency_id, la notification ne s'affiche pas dans le centre de
        // notifications de l'agence.
        $this->assertSame(
            "/agences/{$this->agency->id}/projets/{$this->project->id}/taches/{$task->id}",
            $notification->link,
        );
        $this->assertSame($this->agency->id, $notification->agency_id);
    }

    public function test_the_comment_notification_respects_the_comment_preference(): void
    {
        $assignee = User::factory()->create([
            'notification_preferences' => ['comment' => false],
        ]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $assignee->id,
        ]);
        $task = $this->makeTask(['assigned_to' => $assignee->id]);

        Comment::create([
            'task_id' => $task->id,
            'user_id' => $this->owner->id,
            'content' => 'Tu as vu ma correction ?',
            'mention_ids' => [],
        ]);

        $this->assertSame(
            0,
            Notification::where('user_id', $assignee->id)->where('type', 'nouveau_commentaire')->count(),
            "L'utilisateur a coupe les notifications de commentaire.",
        );
    }
}
