<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reservation_flight_details', function (Blueprint $table) {
            $table->string('ville_depart')->nullable()->change();
            $table->string('ville_arrivee')->nullable()->change();
            $table->date('date_depart')->nullable()->change();
            $table->date('date_arrivee')->nullable()->change();
            $table->string('compagnie')->nullable()->change();
            // pnr + classe sont déjà nullable
        });
    }

    public function down(): void
    {
        Schema::table('reservation_flight_details', function (Blueprint $table) {
            $table->string('ville_depart')->nullable(false)->change();
            $table->string('ville_arrivee')->nullable(false)->change();
            $table->date('date_depart')->nullable(false)->change();
            $table->date('date_arrivee')->nullable()->change();
            $table->string('compagnie')->nullable()->change();
        });
    }
};