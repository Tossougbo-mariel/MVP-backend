<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reparation d'une derive de schema.
 *
 * Ces tables existaient deja en base (ancienne reprise), ce qui declenchait
 * le garde idempotent des migrations d'origine et empechait toute correction :
 *
 *  - `agency_task_statuses` porte la colonne historique `is_done` au lieu de
 *    `is_terminal` attendu par `TaskStatusResolver` : la lecture devenait
 *    `null` puis `false`, donc `terminee` n'etait plus jamais terminal
 *    (`completed_at` jamais pose, `TaskObserver` jamais declenche).
 *  - `teams` / `team_members` figuraient comme migrees mais etaient
 *    absentes : toute route d'equipe renvoyait une erreur SQL.
 *
 * Aucun changement de semantique : `is_done` valait 1 exactement pour les
 * lignes `terminee`, la meme signation que `is_terminal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renameIsDoneToIsTerminal();
        $this->createTeamsTables();
    }

    private function renameIsDoneToIsTerminal(): void
    {
        if (! Schema::hasTable('agency_task_statuses')) {
            return;
        }

        if (Schema::hasColumn('agency_task_statuses', 'is_done')
            && ! Schema::hasColumn('agency_task_statuses', 'is_terminal')) {
            Schema::table('agency_task_statuses', function (Blueprint $table) {
                $table->renameColumn('is_done', 'is_terminal');
            });
        }

        if (! Schema::hasColumn('agency_task_statuses', 'is_terminal')) {
            return;
        }

        DB::table('agency_task_statuses')
            ->whereNull('is_terminal')
            ->update(['is_terminal' => false]);

        Schema::table('agency_task_statuses', function (Blueprint $table) {
            $table->boolean('is_terminal')->default(false)->nullable(false)->change();
        });
    }

    private function createTeamsTables(): void
    {
        if (! Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
                $table->string('name', 80);
                $table->text('description')->nullable();
                $table->string('membership', 20)->default('fermee'); // ouverte | fermee
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('team_members')) {
            Schema::create('team_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['team_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        // Irreversible : la migration d'origine ne peut plus etre rejouee car
        // elle est deja marquee comme executee dans la table `migrations`.
    }
};
