<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dérive de schéma : `2026_09_21_000000` ajoute déjà `settings`.
        // Cette migration est un doublon, on la rend donc idempotente pour ne
        // pas casser les installations existantes ni les bases neuves.
        if (Schema::hasColumn('agencies', 'settings')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agencies', 'settings')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
