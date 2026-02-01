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
        Schema::create('reservation_flight_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reservation_id')
                ->constrained('reservations')
                ->cascadeOnDelete();

            $table->string('ville_depart');
            $table->string('ville_arrivee');
            $table->date('date_depart');
            $table->date('date_arrivee')->nullable();
            $table->string('compagnie')->nullable();

            // Optionnel (très utile plus tard)
            $table->string('pnr')->nullable();
            $table->string('classe')->nullable(); // eco/business/etc.

            $table->timestamps();

            // 1 seul flight_details par réservation
            $table->unique('reservation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservation_flight_details');
    }
};
