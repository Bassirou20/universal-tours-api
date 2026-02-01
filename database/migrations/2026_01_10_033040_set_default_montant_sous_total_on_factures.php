<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mettre une valeur pour les lignes où c'est NULL (si jamais)
        DB::statement("UPDATE factures SET montant_sous_total = 0 WHERE montant_sous_total IS NULL");

        // Appliquer DEFAULT 0 + NOT NULL
        DB::statement("
            ALTER TABLE factures
            MODIFY montant_sous_total DECIMAL(12,2) NOT NULL DEFAULT 0
        ");
    }

    public function down(): void
    {
        // Revenir sans default (si tu veux)
        DB::statement("
            ALTER TABLE factures
            MODIFY montant_sous_total DECIMAL(12,2) NOT NULL
        ");
    }
};
