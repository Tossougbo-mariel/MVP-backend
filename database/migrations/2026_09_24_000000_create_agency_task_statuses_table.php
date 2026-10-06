<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent : la table peut avoir été créée lors d'une reprise.
        if (Schema::hasTable('agency_task_statuses')) {
            return;
        }

        Schema::create('agency_task_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();

            // `key` est ce qui est réellement stocké dans `tasks.status`.
            // Les clés par défaut reprennent les valeurs historiques
            // (a_faire, en_cours, en_revision, terminee) : aucune donnée
            // existante n'a donc besoin d'être migrée.
            $table->string('key', 50);
            $table->string('label', 80);
            $table->string('color', 20)->default('#056cf2');
            $table->unsignedSmallInteger('position')->default(0);

            // Un statut terminal "clôture" la tâche (completed_at, rappels,
            // considers comme terminées partout dans l'application).
            $table->boolean('is_terminal')->default(false);

            $table->timestamps();

            $table->unique(['agency_id', 'key']);
            $table->index(['agency_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_task_statuses');
    }
};
