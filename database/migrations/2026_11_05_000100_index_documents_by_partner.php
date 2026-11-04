<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Les bons d'un client ou d'un fournisseur (PartnerController) : sa fiche et la liste des clients les comptent par code. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['branch_id', 'kind', 'partner_code']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'kind', 'partner_code']);
        });
    }
};
