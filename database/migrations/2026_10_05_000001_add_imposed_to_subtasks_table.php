<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtasks', function (Blueprint $table) {
            if (! Schema::hasColumn('subtasks', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('subtasks', 'imposed')) {
                $table->boolean('imposed')->default(false)->after('done');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subtasks', function (Blueprint $table) {
            if (Schema::hasColumn('subtasks', 'imposed')) {
                $table->dropColumn('imposed');
            }
            if (Schema::hasColumn('subtasks', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });
    }
};