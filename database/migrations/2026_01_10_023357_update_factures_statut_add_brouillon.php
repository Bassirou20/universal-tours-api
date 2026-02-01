<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Étendre l'ENUM pour inclure brouillon (sans casser l'existant)
        DB::statement("
            ALTER TABLE factures
            MODIFY statut ENUM('brouillon','emis','paye_partiellement','paye_totalement','annule')
            NOT NULL DEFAULT 'brouillon'
        ");

        // Optionnel: si tu veux que les anciennes factures restent "emis" (c'est déjà le cas),
        // rien à faire. Si tu veux convertir certaines factures en brouillon, fais un UPDATE ici.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revenir à l'ancien ENUM (ATTENTION: si des factures sont en 'brouillon', il faut les remapper)
        DB::statement("UPDATE factures SET statut = 'emis' WHERE statut = 'brouillon'");

        DB::statement("
            ALTER TABLE factures
            MODIFY statut ENUM('emis','paye_partiellement','paye_totalement','annule')
            NOT NULL DEFAULT 'emis'
        ");
    }
};
