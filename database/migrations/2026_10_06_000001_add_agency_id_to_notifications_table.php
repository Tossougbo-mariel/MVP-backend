<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('agency_id')
                ->nullable()
                ->after('user_id')
                ->constrained('agencies')
                ->nullOnDelete();
        });

        $this->backfillFromLink();
    }

    /**
     * Les notifications créées avant cette colonne portent déjà l'agence dans
     * leur lien (/agences/{id}/…). On la relit plutôt que de les laisser
     * invisibles dans le centre de notifications d'une agence.
     *
     * Traitement en PHP et non en SQL : les tests tournent sur SQLite, la
     * production sur PostgreSQL.
     */
    private function backfillFromLink(): void
    {
        DB::table('notifications')
            ->whereNull('agency_id')
            ->where('link', 'like', '/agences/%')
            ->select(['id', 'link'])
            ->chunkById(200, function ($notifications) {
                foreach ($notifications as $notification) {
                    if (! preg_match('#^/agences/(\d+)/#', (string) $notification->link, $matches)) {
                        continue;
                    }

                    DB::table('notifications')
                        ->where('id', $notification->id)
                        ->update(['agency_id' => (int) $matches[1]]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
        });
    }
};
