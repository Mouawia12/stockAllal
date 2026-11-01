<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // branche de reference des prix : son prix s'affiche dans la recherche (une seule a la fois)
            $table->boolean('is_main')->default(false)->after('active');
        });

        Schema::table('branch_stock', function (Blueprint $table) {
            // prix de vente lu dans le Gescomm de la branche ; null tant que l'agent ne l'envoie pas (agent < 1.0.5)
            $table->decimal('price', 15, 2)->nullable()->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('branch_stock', function (Blueprint $table) {
            $table->dropColumn('price');
        });
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('is_main');
        });
    }
};
