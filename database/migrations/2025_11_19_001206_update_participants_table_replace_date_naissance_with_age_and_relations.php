<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('participants', function (Blueprint $table) {
            // Supprimer date_naissance si existante
            if (Schema::hasColumn('participants', 'date_naissance')) {
                $table->dropColumn('date_naissance');
            }

            // Ajouter age
            if (!Schema::hasColumn('participants', 'age')) {
                $table->integer('age')->nullable()->after('prenom');
            }

            // Lier participant à un produit (événement) — nullable
            if (!Schema::hasColumn('participants', 'produit_id')) {
                $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            }

            // Lier participant à une réservation (traçabilité) — nullable
            if (!Schema::hasColumn('participants', 'reservation_id')) {
                $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            }
        });
    }

    public function down()
    {
        Schema::table('participants', function (Blueprint $table) {
            if (Schema::hasColumn('participants', 'reservation_id')) {
                $table->dropConstrainedForeignId('reservation_id');
            }
            if (Schema::hasColumn('participants', 'produit_id')) {
                $table->dropConstrainedForeignId('produit_id');
            }
            if (Schema::hasColumn('participants', 'age')) {
                $table->dropColumn('age');
            }
            // Recréer date_naissance si nécessaire
            // $table->date('date_naissance')->nullable();
        });
    }
};
