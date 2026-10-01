<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_purchase_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('installment_number'); // 1, 2, 3...
            $table->decimal('amount', 12, 2);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->string('transaction_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['installment_purchase_id', 'installment_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
