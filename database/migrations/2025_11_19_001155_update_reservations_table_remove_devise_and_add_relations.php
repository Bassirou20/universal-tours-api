<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Supprimer la colonne 'devise' si elle existe
            if (Schema::hasColumn('reservations', 'devise')) {
                $table->dropColumn('devise');
            }

            // Ajouter la relation vers produits (l'événement ou autre produit)
            if (!Schema::hasColumn('reservations', 'produit_id')) {
                $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            }

            // Ajouter la relation forfait (nullable) — ne s'applique qu'aux événements
            if (!Schema::hasColumn('reservations', 'forfait_id')) {
                $table->foreignId('forfait_id')->nullable()->constrained('forfaits')->nullOnDelete();
            }

            // Ajouter référence si manquante
            if (!Schema::hasColumn('reservations', 'reference')) {
                $table->string('reference')->unique()->after('id');
            }

            // Nombre de personnes si manquant
            if (!Schema::hasColumn('reservations', 'nombre_personnes')) {
                $table->integer('nombre_personnes')->default(1)->after('statut');
            }

            // Montants (assure les colonnes existent)
            if (!Schema::hasColumn('reservations', 'montant_sous_total')) {
                $table->decimal('montant_sous_total', 15, 2)->default(0)->after('nombre_personnes');
            }
            if (!Schema::hasColumn('reservations', 'montant_taxes')) {
                $table->decimal('montant_taxes', 15, 2)->default(0)->after('montant_sous_total');
            }
            if (!Schema::hasColumn('reservations', 'montant_total')) {
                $table->decimal('montant_total', 15, 2)->default(0)->after('montant_taxes');
            }
        });
    }

    public function down()
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'montant_total')) {
                $table->dropColumn('montant_total');
            }
            if (Schema::hasColumn('reservations', 'montant_taxes')) {
                $table->dropColumn('montant_taxes');
            }
            if (Schema::hasColumn('reservations', 'montant_sous_total')) {
                $table->dropColumn('montant_sous_total');
            }
            if (Schema::hasColumn('reservations', 'nombre_personnes')) {
                $table->dropColumn('nombre_personnes');
            }
            if (Schema::hasColumn('reservations', 'reference')) {
                $table->dropColumn('reference');
            }
            if (Schema::hasColumn('reservations', 'forfait_id')) {
                $table->dropConstrainedForeignId('forfait_id');
            }
            if (Schema::hasColumn('reservations', 'produit_id')) {
                $table->dropConstrainedForeignId('produit_id');
            }

            // Ré-créer la colonne devise si tu souhaites l'annuler (par défaut non)
            // $table->string('devise', 10)->nullable();
        });
    }
};
