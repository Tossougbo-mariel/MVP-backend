<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('action');
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Contrainte {or} définie sur le diagramme de classe :
        // au moins un des trois (agence/projet/tâche) doit être renseigné
        //
        // SQLite ne sait pas ajouter une contrainte CHECK après la création
        // de la table (il faudrait la déduire dans le CREATE TABLE), donc on
        // l'applique uniquement sur les moteurs qui le permettent. En base
        // de test la garantie est vérifiée côté application.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('
                ALTER TABLE activity_logs
                ADD CONSTRAINT chk_activity_log_target
                CHECK (agency_id IS NOT NULL OR project_id IS NOT NULL OR task_id IS NOT NULL)
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
