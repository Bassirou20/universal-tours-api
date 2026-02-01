<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('factures')) {
            return;
        }

        // Renommer et supprimer colonne 'devise' sans doctrine/dbal
        // ATTENTION : adapte DECIMAL(12,2) si tu avais une autre précision
        // Vérification existence pour éviter erreurs
        if (Schema::hasColumn('factures', 'montant_ht') && !Schema::hasColumn('factures', 'montant_sous_total')) {
            DB::statement("ALTER TABLE `factures` CHANGE `montant_ht` `montant_sous_total` DECIMAL(12,2) NOT NULL");
        }

        if (Schema::hasColumn('factures', 'montant_tva') && !Schema::hasColumn('factures', 'montant_taxes')) {
            DB::statement("ALTER TABLE `factures` CHANGE `montant_tva` `montant_taxes` DECIMAL(12,2) NOT NULL DEFAULT 0");
        }

        if (Schema::hasColumn('factures', 'montant_ttc') && !Schema::hasColumn('factures', 'montant_total')) {
            DB::statement("ALTER TABLE `factures` CHANGE `montant_ttc` `montant_total` DECIMAL(12,2) NOT NULL");
        }

        if (Schema::hasColumn('factures', 'devise')) {
            DB::statement("ALTER TABLE `factures` DROP COLUMN `devise`");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('factures')) {
            return;
        }

        if (!Schema::hasColumn('factures', 'devise')) {
            DB::statement("ALTER TABLE `factures` ADD `devise` VARCHAR(10) NOT NULL DEFAULT 'XOF' AFTER `montant_total`");
        }

        if (Schema::hasColumn('factures', 'montant_sous_total') && !Schema::hasColumn('factures', 'montant_ht')) {
            DB::statement("ALTER TABLE `factures` CHANGE `montant_sous_total` `montant_ht` DECIMAL(12,2) NOT NULL");
        }

        if (Schema::hasColumn('factures', 'montant_taxes') && !Schema::hasColumn('factures', 'montant_tva')) {
            DB::statement("ALTER TABLE `factures` CHANGE `montant_taxes` `montant_tva` DECIMAL(12,2) NOT NULL DEFAULT 0");
        }

        if (Schema::hasColumn('factures', 'montant_total') && !Schema::hasColumn('factures', 'montant_ttc')) {
            DB::statement("ALTER TABLE `factures` CHANGE `montant_total` `montant_ttc` DECIMAL(12,2) NOT NULL");
        }
    }
};
