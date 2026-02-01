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
        Schema::create('forfaits', function (Blueprint $table) {
        $table->id();
        $table->string('nom');
        $table->text('description')->nullable();
        $table->decimal('prix', 10, 2);
        $table->foreignId('event_id')->constrained('produits')->onDelete('cascade');
        $table->integer('nombre_max_personnes');
        $table->decimal('prix_adulte', 10, 2)->default(0);
        $table->decimal('prix_enfant', 10, 2)->default(0);
        $table->enum('type', ['couple', 'famille', 'solo']);
        $table->boolean('actif')->default(true);
        $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forfaits');
    }
};
