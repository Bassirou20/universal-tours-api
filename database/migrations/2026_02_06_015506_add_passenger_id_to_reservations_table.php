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
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('passenger_id')->nullable()->after('client_id');

            $table->index('passenger_id');
            $table->foreign('passenger_id')
                ->references('id')->on('participants')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['passenger_id']);
            $table->dropIndex(['passenger_id']);
            $table->dropColumn('passenger_id');
        });
    }
};
