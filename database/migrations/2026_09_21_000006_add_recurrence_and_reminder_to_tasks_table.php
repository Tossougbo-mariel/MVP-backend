<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('recurrence')->nullable()->after('completed_at');
            $table->date('recurrence_until')->nullable()->after('recurrence');
            $table->timestamp('reminder_sent_at')->nullable()->after('recurrence_until');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['recurrence', 'recurrence_until', 'reminder_sent_at']);
        });
    }
};
