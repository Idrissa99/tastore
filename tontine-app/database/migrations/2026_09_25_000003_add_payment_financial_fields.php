<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rate = (float) (DB::table('settings')->where('key', 'commission_rate')->value('value') ?? config('commissions.rate', 0));

        Schema::table('tontines', function (Blueprint $table) {
            $table->decimal('commission_rate', 8, 6)->nullable();
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->decimal('commission_rate', 8, 6)->nullable();
            $table->string('currency', 3)->default('XOF');
            $table->string('payment_failure_reason')->nullable();
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->decimal('commission_rate', 8, 6)->nullable();
            $table->string('currency', 3)->default('XOF');
            $table->string('payment_failure_reason')->nullable();
        });

        DB::table('tontines')->update(['commission_rate' => $rate]);
        DB::table('contributions')->update(['commission_rate' => $rate]);
        DB::table('installments')->update(['commission_rate' => $rate]);
    }

    public function down(): void
    {
        Schema::table('tontines', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'currency', 'payment_failure_reason']);
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'currency', 'payment_failure_reason']);
        });
    }
};
