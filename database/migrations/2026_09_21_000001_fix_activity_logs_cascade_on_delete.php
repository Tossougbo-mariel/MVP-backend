<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Les clés étrangères passaient "agency_id/project_id/task_id" à NULL
        // lors d'une suppression, ce qui violait la contrainte
        // chk_activity_log_target (au moins une cible requise).
        // On passe en suppression en cascade : si la cible disparaît,
        // ses entrées de journal disparaissent aussi.
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_agency_id_foreign');
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_project_id_foreign');
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_task_id_foreign');

        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_task_id_foreign FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_agency_id_foreign');
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_project_id_foreign');
        DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_task_id_foreign');

        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_task_id_foreign FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL');
    }
};
