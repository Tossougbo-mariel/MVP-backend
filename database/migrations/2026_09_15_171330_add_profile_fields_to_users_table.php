<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone')->nullable()->after('avatar');
            $table->string('city')->nullable()->after('phone');
            $table->text('bio')->nullable()->after('city');
            $table->string('job_title')->nullable()->after('bio');
        });

        // Backfill : pour les comptes existants, on déduit first_name/last_name
        // depuis le champ `name` ("Jean Dupont" -> first_name="Jean", last_name="Dupont").
        DB::table('users')->whereNull('first_name')->orderBy('id')->each(function ($user) {
            $parts = array_values(array_filter(array_map('trim', explode(' ', $user->name ?? ''))));

            $first = $parts[0] ?? null;
            $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $first,
                'last_name' => $last,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'phone', 'city', 'bio', 'job_title']);
        });
    }
};
