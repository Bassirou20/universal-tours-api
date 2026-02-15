<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
             $table->string('import_source', 120)->nullable()->after('import_hash'); // ex: ETATUTJANVIER2026.xlsx
    $table->unsignedInteger('import_row')->nullable()->after('import_source'); // numéro de ligne Excel
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            //
        });
    }
};
