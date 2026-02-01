<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Étendre l'ENUM pour accepter ancien + nouveau
        DB::statement("
            ALTER TABLE reservations
            MODIFY statut ENUM('brouillon','en_attente','confirmee','annulee')
            NOT NULL DEFAULT 'brouillon'
        ");

        // 2) Migrer les données
        DB::statement("UPDATE reservations SET statut = 'en_attente' WHERE statut = 'brouillon'");
        // DB::statement("UPDATE reservations SET statut = 'annulee' WHERE statut = 'annule'");

        // 3) Resserer l'ENUM final (uniquement les nouvelles valeurs)
        DB::statement("
            ALTER TABLE reservations
            MODIFY statut ENUM('en_attente','confirmee','annulee')
            NOT NULL DEFAULT 'en_attente'
        ");
    }

    public function down(): void
    {
        // 1) Ré-élargir pour permettre le rollback sans erreur
        DB::statement("
            ALTER TABLE reservations
            MODIFY statut ENUM('brouillon','en_attente','confirmee','annulee')
            NOT NULL DEFAULT 'en_attente'
        ");

        // 2) Remapper vers l'ancien format
        DB::statement("UPDATE reservations SET statut = 'brouillon' WHERE statut = 'en_attente'");
        // DB::statement("UPDATE reservations SET statut = 'annule' WHERE statut = 'annulee'");

        // 3) Revenir à l'ancien enum
        DB::statement("
            ALTER TABLE reservations
            MODIFY statut ENUM('brouillon','confirmee','annule')
            NOT NULL DEFAULT 'brouillon'
        ");
    }
};
