<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tontines', function (Blueprint $table) {
            $table->enum('type', ['product', 'cash'])->default('product')->after('name');
        });

        // SQLite ne permet pas de modifier une contrainte de clé étrangère existante
        // directement : on passe par une nouvelle colonne nullable "propre".
        Schema::table('tontines', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });
        Schema::table('tontines', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tontines', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->dropForeign(['product_id']);
        });
        Schema::table('tontines', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
