<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE factures
            MODIFY statut ENUM(
                'brouillon','emis','impayee','partielle','payee',
                'paye_partiellement','paye_totalement','annule'
            ) NOT NULL DEFAULT 'emis'
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE factures SET statut = 'emis' WHERE statut IN ('impayee','partielle','payee')");
        DB::statement("
            ALTER TABLE factures
            MODIFY statut ENUM('brouillon','emis','paye_partiellement','paye_totalement','annule')
            NOT NULL DEFAULT 'brouillon'
        ");
    }
};
