<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->string('payment_method')->default('mobile_money')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->enum('payment_method', ['mobile_money', 'bank', 'card', 'wallet'])
                  ->default('mobile_money')
                  ->after('amount');
        });
    }
};
