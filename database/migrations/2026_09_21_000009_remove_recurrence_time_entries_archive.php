<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Suivi du temps : table entièrement retirée
        Schema::dropIfExists('time_entries');

        // Récurrence + archivage : colonnes retirées
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['recurrence', 'recurrence_until', 'archived_at']);
        });

        // Rôle lecteur supprimé : les membres existants redeviennent des membres
        DB::table('agency_members')->where('role', 'lecteur')->update(['role' => 'membre']);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('recurrence')->nullable()->index();
            $table->date('recurrence_until')->nullable();
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minutes');
            $table->date('spent_on');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }
};
