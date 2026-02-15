<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // nullable pour ne pas casser les anciennes données
            $table->string('import_hash', 64)->nullable()->after('reference');

            // important: unique pour empêcher les doublons à l’import
            $table->unique('import_hash', 'reservations_import_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique('reservations_import_hash_unique');
            $table->dropColumn('import_hash');
        });
    }
};
