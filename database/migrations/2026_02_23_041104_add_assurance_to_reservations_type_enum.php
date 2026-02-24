<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE reservations
            MODIFY type ENUM('billet_avion','hotel','voiture','evenement','forfait','assurance')
            NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE reservations
            MODIFY type ENUM('billet_avion','hotel','voiture','evenement','forfait')
            NOT NULL
        ");
    }
};