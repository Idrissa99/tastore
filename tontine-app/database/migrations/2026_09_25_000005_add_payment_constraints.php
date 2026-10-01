<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoDuplicateReferences('contributions', 'transaction_reference');
        $this->assertNoDuplicateReferences('installments', 'transaction_reference');
        $this->assertNoDuplicatePairs('contributions', ['tontine_member_id', 'round']);
        $this->assertNoDuplicatePairs('refunds', ['contribution_id']);

        Schema::table('contributions', function (Blueprint $table) {
            $table->unique('transaction_reference');
            $table->unique(['tontine_member_id', 'round']);
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->unique('transaction_reference');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->unique('contribution_id');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['contribution_id']);
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->dropUnique(['transaction_reference']);
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->dropUnique(['tontine_member_id', 'round']);
            $table->dropUnique(['transaction_reference']);
        });
    }

    private function assertNoDuplicateReferences(string $table, string $column): void
    {
        $duplicates = DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException("Doublons détectés dans {$table}.{$column} : ".$duplicates->implode(', '));
        }
    }

    private function assertNoDuplicatePairs(string $table, array $columns): void
    {
        $query = DB::table($table)
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1');

        if ($query->get()->isNotEmpty()) {
            throw new RuntimeException("Doublons détectés dans {$table} : ".implode(', ', $columns));
        }
    }
};
