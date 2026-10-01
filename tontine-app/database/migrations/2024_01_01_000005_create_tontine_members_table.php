<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tontine_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tontine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->nullable(); // ordre de passage pour le bénéfice
            $table->enum('status', ['active', 'beneficiary', 'completed', 'withdrawn'])->default('active');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            $table->unique(['tontine_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tontine_members');
    }
};
