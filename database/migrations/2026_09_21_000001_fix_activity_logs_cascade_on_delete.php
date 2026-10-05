<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les clés étrangères passaient "agency_id/project_id/task_id" à NULL
        // lors d'une suppression, ce qui violait la contrainte
        // chk_activity_log_target (au moins une cible requise).
        // On passe en suppression en cascade : si la cible disparaît,
        // ses entrées de journal disparaissent aussi.
        //
        // Ces ALTER sont du MySQL. SQLite ne sait ni ajouter une contrainte
        // apres coup, ni gérer `IF EXISTS` sur un DROP CONSTRAINT : on saute
        // la redefinition, la cle creee a la table suffit pour les tests.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_agency_id_foreign');
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_project_id_foreign');
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_task_id_foreign');

        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_task_id_foreign FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_agency_id_foreign');
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_project_id_foreign');
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_task_id_foreign');

        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_task_id_foreign FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL');
    }
};
