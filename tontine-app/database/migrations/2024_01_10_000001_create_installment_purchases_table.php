<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('installments_count');
            $table->decimal('installment_amount', 12, 2);
            $table->decimal('product_price', 12, 2); // prix figé au moment de l'achat
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            $table->enum('delivery_status', ['not_applicable', 'pending', 'delivered'])->default('not_applicable');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_purchases');
    }
};
