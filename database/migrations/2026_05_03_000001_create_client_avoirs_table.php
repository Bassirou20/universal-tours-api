<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_avoirs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('facture_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['depot', 'utilisation']);
            $table->decimal('montant', 15, 2);           // toujours positif
            $table->decimal('solde_apres', 15, 2);       // solde du client après cette opération
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->date('date_avoir')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_avoirs');
    }
};
