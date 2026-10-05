<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent : la colonne peut avoir été ajoutée lors d'une reprise.
        if (! Schema::hasColumn('users', 'two_factor_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                // Double authentification par code email, activable dans le profil.
                $table->boolean('two_factor_enabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'two_factor_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('two_factor_enabled');
            });
        }
    }
};
