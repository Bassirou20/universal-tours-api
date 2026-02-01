<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->enum('type',['billet_avion','hotel','voiture','evenement']);
            $table->string('nom');
            $table->text('description')->nullable();
            $table->decimal('prix_base', 12, 2)->default(0);
            $table->string('devise', 10)->default('XOF');
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
