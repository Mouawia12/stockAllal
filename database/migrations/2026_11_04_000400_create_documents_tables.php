<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent 1.0.7 : ventes, achats, clients, fournisseurs et versements de Gescomm, dans les tables que la branche
 * approuvee utilisera aussi (docs/roadmap.md, section 3). Une ligne venue de Gescomm porte :
 * origin = gescomm, gescomm_key (la cle dans Gescomm, unique par branche), sync_group (le jour, ou un paquet de clients)
 * et sync_digest (empreinte calculee par l'agent : une ligne renvoyee sans changement n'est pas reecrite).
 * Plus les seuils et la famille des articles (GC003), envoyes avec le stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL : collation binaire sur les codes, comme branch_stock
        $binary = DB::getDriverName() === 'mysql';
        $code = function (Blueprint $table, string $name, int $length, bool $nullable = true) use ($binary) {
            $column = $table->string($name, $length);
            if ($nullable) {
                $column->nullable();
            }
            if ($binary) {
                $column->collation('utf8mb4_bin');
            }
        };
        $sync = function (Blueprint $table) use ($code) {
            $table->string('origin', 10)->default('gescomm');
            $code($table, 'gescomm_key', 40);
            $table->string('sync_group', 20)->nullable();
            $table->unsignedBigInteger('sync_digest')->nullable();
            $table->timestamps();
        };

        Schema::table('branch_stock', function (Blueprint $table) {
            $table->decimal('stock_alert', 15, 2)->nullable();   // STOCKALERT : au seuil ou en dessous = vert
            $table->decimal('stock_max', 15, 2)->nullable();     // STOCKMAX : au-dessus = orange
            $table->string('family', 20)->nullable();            // CODEFAM
            $table->string('unit', 20)->nullable();              // UNITMESURE
        });

        Schema::create('partners', function (Blueprint $table) use ($code, $sync) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);   // customer (GC002), supplier (GC004)
            $code($table, 'code', 20);
            $table->string('name', 200)->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('mobile', 60)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('wilaya', 100)->nullable();
            $table->string('contact', 60)->nullable();
            $table->string('activity', 100)->nullable();
            $table->string('type', 40)->nullable();
            $table->decimal('opening_balance', 15, 2)->nullable();
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->integer('credit_days')->nullable();
            $table->boolean('blocked')->nullable();
            $table->string('nif', 100)->nullable();
            $table->string('nis', 100)->nullable();
            $table->string('rc', 100)->nullable();
            $table->string('ai', 100)->nullable();
            $sync($table);

            $table->unique(['branch_id', 'kind', 'gescomm_key']);
            $table->index(['branch_id', 'kind', 'code']);
            $table->index(['branch_id', 'kind', 'sync_group']);
        });

        Schema::create('documents', function (Blueprint $table) use ($code, $sync) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);   // sale (GC010), purchase (GC007) ; un retour = une ligne de quantite negative
            $table->unsignedBigInteger('number')->nullable();
            $table->date('date')->nullable();
            $table->string('time', 8)->nullable();
            $code($table, 'partner_code', 20);
            $table->decimal('amount', 15, 2)->nullable();     // MONTANT = somme des TOTAL des lignes
            $table->decimal('discount', 15, 2)->nullable();   // MONTANTREMISE
            $table->decimal('gross', 15, 2)->nullable();      // TOTALSANSREMISE
            $table->string('payment', 20)->nullable();
            $table->string('entered_by', 30)->nullable();
            $table->string('served_by', 100)->nullable();
            $table->integer('credit_days')->nullable();
            $table->string('note', 255)->nullable();
            $sync($table);

            $table->unique(['branch_id', 'kind', 'gescomm_key']);
            $table->index(['branch_id', 'kind', 'sync_group']);
            $table->index(['branch_id', 'kind', 'date']);
        });

        Schema::create('document_lines', function (Blueprint $table) use ($code) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // copies de l'en-tete : la page d'un article lit les lignes sans joindre les documents
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->date('date')->nullable();
            $table->integer('position')->nullable();
            $code($table, 'code_raw', 30, false);
            $code($table, 'code_key', 30, false);   // UPPER(TRIM(code)), comme branch_stock
            $table->decimal('qty', 15, 2)->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('discount', 15, 2)->nullable();
            $table->decimal('total', 15, 2)->nullable();
            $table->decimal('cost', 15, 2)->nullable();   // PRIXACHAT du jour de la vente : la marge
            $table->decimal('vat', 15, 2)->nullable();

            $table->index(['code_key', 'kind', 'branch_id', 'date']);
        });

        Schema::create('payments', function (Blueprint $table) use ($code, $sync) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('partner_kind', 10);   // customer (GC62), supplier (GC60)
            $code($table, 'partner_code', 20);
            $table->date('date')->nullable();
            $table->unsignedBigInteger('number')->nullable();
            $table->string('time', 8)->nullable();
            $table->string('type', 20)->nullable();   // TYPEVER : Espéce, Autre (sens de "Autre" encore a verifier)
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('cheque', 60)->nullable();
            $table->string('cheque_state', 10)->nullable();
            $table->date('paid_on')->nullable();
            $table->unsignedBigInteger('document_number')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('entered_by', 30)->nullable();
            $sync($table);

            $table->unique(['branch_id', 'partner_kind', 'gescomm_key']);
            $table->index(['branch_id', 'partner_kind', 'sync_group']);
            $table->index(['branch_id', 'partner_kind', 'partner_code']);
        });

        // ou en est chaque tache de l'agent dans chaque branche (page des branches)
        Schema::create('branch_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('task', 30);
            $table->dateTime('last_ok_at')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'task']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_tasks');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('document_lines');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('partners');
        Schema::table('branch_stock', function (Blueprint $table) {
            $table->dropColumn(['stock_alert', 'stock_max', 'family', 'unit']);
        });
    }
};
