<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('email');
            $table->string('role')->default('membre'); // admin | membre
            $table->string('token', 64)->unique();      // le code secret du lien
            $table->string('status')->default('en_attente'); // en_attente | acceptee | annulee | expiree
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable(); // expiration (7 jours)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_invitations');
    }
};