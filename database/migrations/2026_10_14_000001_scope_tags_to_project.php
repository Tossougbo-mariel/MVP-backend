<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les étiquettes deviennent propres à un projet (elles étaient auparavant
     * partagées par toute l'agence). On repart de zéro : on recrée les tables,
     * aucune étiquette d'agence ne subsiste.
     */
    public function up(): void
    {
        Schema::dropIfExists('tag_task');
        Schema::dropIfExists('tags');

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('#056cf2');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('tag_task', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['task_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_task');
        Schema::dropIfExists('tags');

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('#056cf2');
            $table->timestamps();

            $table->unique(['agency_id', 'name']);
        });

        Schema::create('tag_task', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['task_id', 'tag_id']);
        });
    }
};
