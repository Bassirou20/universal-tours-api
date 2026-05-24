<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservation_penalites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('imposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('avoir_id')->nullable()->constrained('client_avoirs')->nullOnDelete();
            $table->foreignId('facture_id')->nullable()->constrained('factures')->nullOnDelete();

            // Type de pénalité : annulation, modification, no_show, autre
            $table->enum('type', ['annulation', 'modification', 'no_show', 'autre'])->default('annulation');

            // Mode de traitement de la pénalité
            // - deduit_avoir   : crée un avoir client de (paye - penalite), garde penalite
            // - facture_separee: crée une nouvelle facture de pénalité à régler
            // - retenu_paiement: aucun paiement encore, le client doit déjà la pénalité
            $table->enum('traitement', ['deduit_avoir', 'facture_separee', 'retenu_paiement'])->default('deduit_avoir');

            $table->decimal('montant', 14, 2);
            $table->string('motif', 500)->nullable();
            $table->timestamp('imposed_at')->useCurrent();
            $table->timestamps();

            $table->index(['reservation_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_penalites');
    }
};
