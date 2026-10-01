<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->string('transfer_code', 100)->nullable()->after('transaction_reference');
            $table->enum('verification_status', ['not_applicable', 'pending', 'accepted', 'rejected'])
                  ->default('not_applicable')
                  ->after('transfer_code');
            $table->timestamp('submitted_at')->nullable()->after('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropColumn(['transfer_code', 'verification_status', 'submitted_at']);
        });
    }
};
