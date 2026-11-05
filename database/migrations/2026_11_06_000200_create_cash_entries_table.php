<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent 1.0.8 : la caisse de Gescomm, ligne par ligne (docs/roadmap.md, "cash_entries"). direction in = C008 (versement
 * d'un client, vente au comptoir, entree a la main), out = C002 (versement a un fournisseur, depense).
 * Solde = somme des in - somme des out, comme MONTANTCAISSE de Gescomm. Memes colonnes de synchro que payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);   // in (C008), out (C002)
            $table->date('date')->nullable();
            $table->unsignedBigInteger('number')->nullable();   // ORDERE
            $table->string('time', 8)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('label', 250)->nullable();       // "Client: ..." (OBSERVATION2), "Fourn: ..." (CHARGE2)
            $table->string('category', 40)->nullable();     // depense : CHARGE (C0011)
            $table->string('category_code', 20)->nullable();
            $table->boolean('expense')->nullable();         // UNECHARGE
            $table->boolean('counter')->nullable();         // COMPTOIR : vente au comptoir
            $table->string('person', 60)->nullable();
            $table->string('type', 20)->nullable();
            $table->string('cheque', 60)->nullable();
            $table->unsignedBigInteger('payment_number')->nullable();   // NUMVER = NUMEROTATION du versement (GC62 / GC60)
            $table->unsignedBigInteger('document_number')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('entered_by', 30)->nullable();
            $table->string('origin', 10)->default('gescomm');
            $column = $table->string('gescomm_key', 40)->nullable();
            if (DB::getDriverName() === 'mysql') {
                $column->collation('utf8mb4_bin');
            }
            $table->string('sync_group', 20)->nullable();
            $table->unsignedBigInteger('sync_digest')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'direction', 'gescomm_key']);
            $table->index(['branch_id', 'direction', 'sync_group']);
            $table->index(['branch_id', 'direction', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
    }
};
