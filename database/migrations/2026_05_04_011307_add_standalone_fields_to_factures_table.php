<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            // Rendre reservation_id nullable pour permettre les factures standalone
            $table->foreignId('reservation_id')->nullable()->change();

            // Date d'échéance
            if (!Schema::hasColumn('factures', 'due_date')) {
                $table->date('due_date')->nullable()->after('date_facture');
            }
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->foreignId('reservation_id')->nullable(false)->change();

            if (Schema::hasColumn('factures', 'due_date')) {
                $table->dropColumn('due_date');
            }
        });
    }
};
