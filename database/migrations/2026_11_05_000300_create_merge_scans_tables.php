<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "دمج الأصناف" avec l'IA (App\Support\MergeScanner) : une analyse, ses lots envoyes a l'IA, ses propositions
 * (des codes qui seraient le meme produit), et les paires qu'un humain a declarees differentes (jamais reproposees).
 * Rien n'est fusionne sans un clic : une proposition acceptee passe par ProductGroup::merge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merge_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('model', 100);
            $table->string('locale', 5);                     // langue des raisons ecrites par l'IA
            $table->string('status', 12)->default('running'); // running, done, failed, cancelled
            $table->json('branch_ids');                        // les branches analysees (celles que le compte voyait)
            $table->unsignedInteger('units')->default(0);      // articles analysables (absents d'au moins une branche)
            $table->unsignedInteger('batches')->default(0);
            $table->unsignedInteger('batches_done')->default(0);
            $table->unsignedInteger('batches_failed')->default(0);
            $table->string('error', 20)->nullable();           // lang/*/ai.php errors, quand l'IA a refuse
            $table->string('error_detail', 255)->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('merge_scan_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merge_scan_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                        // verify (candidats trouves ici), discover (articles sans paire)
            $table->json('clusters');                          // [[[code_key, ...], ...], ...] : des groupes d'articles ; discover = un seul
            $table->string('status', 10)->default('pending');  // pending, done, failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->index(['merge_scan_id', 'status']);
        });

        Schema::create('merge_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merge_scan_id')->constrained()->cascadeOnDelete();
            $table->json('units');                             // [[code_key, ...], ...] : un article par branche
            $table->string('confidence', 6);                   // high, medium, low
            $table->string('source', 10);                      // code, name, similar, discover
            $table->string('name', 200)->nullable();           // la designation commune proposee
            $table->text('reason')->nullable();
            $table->string('status', 10)->default('open');     // open, merged, dismissed
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->index(['merge_scan_id', 'status']);
        });

        // MySQL : collation binaire comme les codes (branch_stock), sinon "820ac|X" = "820AC|X"
        $binary = DB::getDriverName() === 'mysql';
        Schema::create('merge_dismissals', function (Blueprint $table) use ($binary) {
            $table->id();
            $pair = $table->string('pair', 70)->unique();     // "CODE_A|CODE_B", tries
            if ($binary) {
                $pair->collation('utf8mb4_bin');
            }
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merge_dismissals');
        Schema::dropIfExists('merge_suggestions');
        Schema::dropIfExists('merge_scan_batches');
        Schema::dropIfExists('merge_scans');
    }
};
