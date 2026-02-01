<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->enum('statut',['brouillon','confirmee','annulee'])->default('brouillon');
            $table->unsignedInteger('nombre_personnes')->default(1);
            $table->decimal('montant_sous_total',12,2)->default(0);
            $table->decimal('montant_taxes',12,2)->default(0);
            $table->decimal('montant_total',12,2)->default(0);
            $table->string('devise',10)->default('XOF');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
