<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tontines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('contribution_amount', 12, 2);
            $table->enum('frequency', ['daily', 'weekly', 'monthly'])->default('monthly');
            $table->unsignedInteger('max_members');
            $table->enum('status', ['open', 'active', 'completed', 'cancelled'])->default('open');
            $table->date('start_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tontines');
    }
};
