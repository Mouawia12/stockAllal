<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La periode demandee d'une analyse "المطابقة" (null = automatique : depuis le debut de l'exercice en cours le plus
 * recent des deux branches ; jusqu'a aujourd'hui) : une analyse se compare a la precedente de la meme periode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_runs', function (Blueprint $table) {
            $table->date('from_day')->nullable();
            $table->date('to_day')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_runs', function (Blueprint $table) {
            $table->dropColumn(['from_day', 'to_day']);
        });
    }
};
