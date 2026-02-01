<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            // Optionnel : ID transaction opérateur (Wave/OM/etc.)
            if (!Schema::hasColumn('paiements', 'transaction_id')) {
                $table->string('transaction_id', 120)->nullable()->after('reference');
            }

            // Index utiles
            $table->index('facture_id');
            $table->index('statut');
            $table->index('date_paiement');
        });

        Schema::table('factures', function (Blueprint $table) {
            $table->index('reservation_id');
            $table->index('statut');
            $table->index('date_facture');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropIndex(['facture_id']);
            $table->dropIndex(['statut']);
            $table->dropIndex(['date_paiement']);

            if (Schema::hasColumn('paiements', 'transaction_id')) {
                $table->dropColumn('transaction_id');
            }
        });

        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex(['reservation_id']);
            $table->dropIndex(['statut']);
            $table->dropIndex(['date_facture']);
        });
    }
};
