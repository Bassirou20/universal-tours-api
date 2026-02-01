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
        // prix doit pouvoir être NULL (cas famille)
        DB::statement("ALTER TABLE forfaits MODIFY prix DECIMAL(10,2) NULL");

        // prix_adulte/enfant doivent pouvoir être NULL (cas solo/couple)
        DB::statement("ALTER TABLE forfaits MODIFY prix_adulte DECIMAL(10,2) NULL");
        DB::statement("ALTER TABLE forfaits MODIFY prix_enfant DECIMAL(10,2) NULL");
    }

    public function down(): void
    {
        // Revenir strict (attention si tu as des NULL en base)
        DB::statement("UPDATE forfaits SET prix = 0 WHERE prix IS NULL");
        DB::statement("UPDATE forfaits SET prix_adulte = 0 WHERE prix_adulte IS NULL");
        DB::statement("UPDATE forfaits SET prix_enfant = 0 WHERE prix_enfant IS NULL");

        DB::statement("ALTER TABLE forfaits MODIFY prix DECIMAL(10,2) NOT NULL");
        DB::statement("ALTER TABLE forfaits MODIFY prix_adulte DECIMAL(10,2) NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE forfaits MODIFY prix_enfant DECIMAL(10,2) NOT NULL DEFAULT 0");
    }
};
