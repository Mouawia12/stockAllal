<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les parametres d'une demande a l'agent. Agent 1.0.10, type "data" ("إعادة الفحص" de "المطابقة") : renvoyer maintenant
 * les bons et versements d'une periode ({from, to, sets}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refresh_jobs', function (Blueprint $table) {
            $table->json('params')->nullable()->after('code_key');
        });
    }

    public function down(): void
    {
        Schema::table('refresh_jobs', function (Blueprint $table) {
            $table->dropColumn('params');
        });
    }
};
