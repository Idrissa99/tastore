<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->update([
            'is_verified' => DB::raw('CASE WHEN email_verified_at IS NULL THEN 0 ELSE 1 END'),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->update([
            'is_verified' => DB::raw('CASE WHEN email_verified_at IS NULL THEN 0 ELSE 1 END'),
        ]);
    }
};
