<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En-tete et pied des impressions de chaque branche (le nom de la societe, son activite, RC / NIF, RIB...), comme les
 * LIGNE1..5 et BAS1..2 de GC032 dans Gescomm. Saisis sur la page des branches : l'agent ne lit pas encore GC032.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->text('print_header')->nullable();
            $table->text('print_footer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['print_header', 'print_footer']);
        });
    }
};
