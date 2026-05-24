<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_evisa_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')
                  ->constrained('reservations')
                  ->cascadeOnDelete();

            $table->string('pays_destination', 150);
            $table->string('type_visa', 50)->nullable();
            $table->date('date_voyage')->nullable();
            $table->string('duree_sejour', 100)->nullable();

            $table->timestamps();

            $table->unique('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_evisa_details');
    }
};
