<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_otp_codes')) {
            return;
        }

        Schema::create('email_otp_codes', function (Blueprint $table) {
            $table->id();

            // Renseigné pour la 2FA ; pour la connexion par code il peut être
            // absent si le compte est supprimé entre-temps.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('email', 255);

            // `login`      : connexion sans mot de passe par code email.
            // `two_factor` : second facteur demandé après le mot de passe.
            $table->string('purpose', 20);

            // Jamais le code en clair : seule une empreinte HMAC est stockée,
            // ce qui rend une fuite de la table inexploitable.
            $table->string('code_hash', 64);

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            // Recherche du code actif le plus récent pour un couple
            // (email, usage) : c'est le chemin chaud de la vérification.
            $table->index(['email', 'purpose']);
            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otp_codes');
    }
};
