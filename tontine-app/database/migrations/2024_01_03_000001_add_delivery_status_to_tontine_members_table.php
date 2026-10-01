<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tontine_members', function (Blueprint $table) {
            $table->enum('delivery_status', ['not_applicable', 'pending', 'delivered'])
                  ->default('not_applicable')
                  ->after('status');
            $table->timestamp('delivered_at')->nullable()->after('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::table('tontine_members', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'delivered_at']);
        });
    }
};
