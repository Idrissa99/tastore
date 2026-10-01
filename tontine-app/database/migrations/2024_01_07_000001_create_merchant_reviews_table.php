<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tontine_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1 à 5
            $table->text('comment')->nullable();
            $table->timestamps();

            // un seul avis par utilisateur et par tontine (pas par mérite : évite le spam d'avis)
            $table->unique(['user_id', 'tontine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_reviews');
    }
};
