<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lignes_reservation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->string('designation');
            $table->unsignedInteger('quantite')->default(1);
            $table->decimal('prix_unitaire',12,2)->default(0);
            $table->decimal('taxe',12,2)->default(0);
            $table->decimal('total_ligne',12,2)->default(0);
            $table->json('options')->nullable(); // ex: dates, villes, hotel...
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_reservation');
    }
};
