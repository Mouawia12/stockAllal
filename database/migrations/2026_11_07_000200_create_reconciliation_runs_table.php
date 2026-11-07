<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque analyse de "المطابقة", gardee : on revient a un rapport, on voit l'historique d'une paire, et un nouveau fichier
 * dit ce qui a ete corrige depuis le precedent. report = le rapport de App\Support\Reconciliation (JSON, ~300 Ko pour
 * une grosse paire), avec la conclusion de l'IA. Les 30 derniers par paire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('branch_a_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('branch_b_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('gap', 15, 2);
            $table->unsignedInteger('problems');
            $table->boolean('synced')->default(false);   // "إعادة الفحص" : les branches ont renvoye leurs donnees avant
            $table->longText('report');
            $table->timestamps();

            $table->index(['customer_id', 'supplier_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
