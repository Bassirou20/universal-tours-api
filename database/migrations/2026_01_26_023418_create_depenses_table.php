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
        Schema::create('depenses', function (Blueprint $table) {
             $table->id();
            $table->date('date_depense');
            $table->string('categorie'); // billet_externe, hotel_externe, transport, bureau, marketing, salaires, autre
            $table->string('libelle');
            $table->string('fournisseur_nom')->nullable();
            $table->string('reference')->nullable(); // num billet / facture fournisseur
            $table->decimal('montant', 12, 2);
            $table->string('mode_paiement')->nullable(); // cash, wave, orange_money, virement, cheque, carte, autre
            $table->enum('statut', ['paye', 'en_attente'])->default('paye');
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['date_depense']);
            $table->index(['categorie']);
            $table->index(['statut']);
            $table->index(['reservation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('depenses');
    }
};
