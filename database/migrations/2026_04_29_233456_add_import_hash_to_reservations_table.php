<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Vérifier avant d'ajouter (sécurité idempotence)
            if (!Schema::hasColumn('reservations', 'import_hash')) {
                $table->string('import_hash', 10)
                    ->nullable()
                    ->unique()
                    ->after('notes')
                    ->comment('Hash de déduplication pour les imports Excel');
            }

            if (!Schema::hasColumn('reservations', 'import_source')) {
                $table->string('import_source', 50)
                    ->nullable()
                    ->after('import_hash')
                    ->comment('Source de l\'import (ex: excel_import_ut)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['import_hash', 'import_source']);
        });
    }
};