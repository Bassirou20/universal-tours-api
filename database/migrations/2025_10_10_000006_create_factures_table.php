<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->string('numero')->unique();
            $table->date('date_facture');
            $table->decimal('montant_ht',12,2);
            $table->decimal('montant_tva',12,2)->default(0);
            $table->decimal('montant_ttc',12,2);
            $table->string('devise',10)->default('XOF');
            $table->enum('statut',['emis','paye_partiellement','paye_totalement','annule'])->default('emis');
            $table->string('pdf_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
    }
};
