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
        // 1) Normaliser les anciennes valeurs existantes
        // - Si tu as déjà enregistré "billet d'avion", on le convertit en valeur "propre" pour l'enum
        DB::statement("UPDATE reservations SET type = 'billet_avion' WHERE type IS NULL OR type = ''");

        DB::statement("
            UPDATE reservations
            SET type = 'billet_avion'
            WHERE LOWER(type) IN ('billet d''avion', 'billet avion', 'avion', 'flight')
        ");

        // 2) Convertir la colonne en ENUM (MySQL/MariaDB)
        DB::statement("
            ALTER TABLE reservations
            MODIFY COLUMN type ENUM('billet_avion','hotel','voiture','evenement','forfait')
            NOT NULL DEFAULT 'billet_avion'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            DB::statement("
            ALTER TABLE reservations
            MODIFY COLUMN type VARCHAR(255) NOT NULL
        ");
        });
    }
};
