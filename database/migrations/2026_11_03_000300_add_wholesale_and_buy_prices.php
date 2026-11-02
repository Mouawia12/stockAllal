<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prix comme dans la liste des articles de Gescomm. "price" (deja la) = Pu Detail (PUGROS, prix de detail/تجزئة).
        Schema::table('branch_stock', function (Blueprint $table) {
            $table->decimal('price_wholesale', 15, 2)->nullable()->after('price');   // Prix Vente Gros (PRIXVNFACT) = الجملة
            $table->decimal('price_buy', 15, 2)->nullable()->after('price_wholesale'); // Pu Achat (PRIX) = الشراء, administrateurs seulement
        });
    }

    public function down(): void
    {
        Schema::table('branch_stock', function (Blueprint $table) {
            $table->dropColumn(['price_wholesale', 'price_buy']);
        });
    }
};
