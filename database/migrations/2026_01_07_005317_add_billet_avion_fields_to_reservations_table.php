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
            $table->string('ville_depart')->nullable();
            $table->string('ville_arrivee')->nullable();
            $table->date('date_depart')->nullable();
            $table->date('date_arrivee')->nullable();
            $table->string('compagnie')->nullable();
            $table->decimal('montant_total', 12, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
                    $table->dropColumn([
            'ville_depart',
            'ville_arrivee',
            'date_depart',
            'date_arrivee',
            'compagnie'
        ]);

        });
    }
};
