<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->string('transfer_code', 100)->nullable()->after('transaction_reference');
            // not_applicable : moyen de paiement classique (pas de vérification manuelle)
            // pending        : code soumis, en attente de vérification admin
            // accepted       : admin a validé le code -> cotisation marquée payée
            // rejected       : admin a refusé le code -> le client peut resoumettre
            $table->enum('verification_status', ['not_applicable', 'pending', 'accepted', 'rejected'])
                  ->default('not_applicable')
                  ->after('transfer_code');
            $table->timestamp('submitted_at')->nullable()->after('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn(['transfer_code', 'verification_status', 'submitted_at']);
        });
    }
};
