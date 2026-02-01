<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('forfaits', function (Blueprint $table) {
            // Lier un forfait à un produit (événement)
            if (!Schema::hasColumn('forfaits', 'event_id')) {
                $table->foreignId('event_id')->nullable()->constrained('produits')->nullOnDelete();
            }

            // Rendre les colonnes prix/prix_adulte/prix_enfant nullables si elles existent
            if (Schema::hasColumn('forfaits', 'prix')) {
                $table->decimal('prix', 15, 2)->nullable()->change();
            }
            if (Schema::hasColumn('forfaits', 'prix_adulte')) {
                $table->decimal('prix_adulte', 15, 2)->nullable()->change();
            }
            if (Schema::hasColumn('forfaits', 'prix_enfant')) {
                $table->decimal('prix_enfant', 15, 2)->nullable()->change();
            }

            // s'assurer nombre_max_personnes existe
            if (!Schema::hasColumn('forfaits', 'nombre_max_personnes')) {
                $table->integer('nombre_max_personnes')->default(1);
            }
        });
    }

    public function down()
    {
        Schema::table('forfaits', function (Blueprint $table) {
            if (Schema::hasColumn('forfaits', 'nombre_max_personnes')) {
                $table->dropColumn('nombre_max_personnes');
            }
            if (Schema::hasColumn('forfaits', 'event_id')) {
                $table->dropConstrainedForeignId('event_id');
            }
            // revert price nullability if needed (manuellement)
        });
    }
};
