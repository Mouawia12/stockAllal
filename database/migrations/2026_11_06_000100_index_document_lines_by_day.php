<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "لوحة القيادة" (App\Support\SalesDashboard) : les lignes vendues d'une periode, dans les branches affichees. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->index(['branch_id', 'kind', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'kind', 'date']);
        });
    }
};
