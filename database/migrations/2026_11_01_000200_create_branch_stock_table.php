<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL : collation binaire sur les codes, sinon "820ac" = "820AC" et "TP¨PA" ~ "TPPA" entreraient en collision
        $binary = DB::getDriverName() === 'mysql';

        Schema::create('branch_stock', function (Blueprint $table) use ($binary) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $codeRaw = $table->string('code_raw', 30);   // code tel qu'ecrit dans le Gescomm de la branche
            $codeKey = $table->string('code_key', 30);   // UPPER(TRIM(code_raw)) : cle de regroupement entre branches
            if ($binary) {
                $codeRaw->collation('utf8mb4_bin');
                $codeKey->collation('utf8mb4_bin');
            }
            $table->string('designation', 200)->nullable();
            $table->decimal('stock', 15, 2)->nullable();
            $table->dateTime('seen_at');      // derniere synchro complete qui contenait l'article
            $table->dateTime('updated_at');   // derniere valeur recue (synchro ou "mettre a jour")

            $table->unique(['branch_id', 'code_raw']);
            $table->index('code_key');
        });

        Schema::create('refresh_jobs', function (Blueprint $table) use ($binary) {
            $table->id();
            $table->uuid('batch')->index();   // un clic "mettre a jour" = un batch, une ligne par branche
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $codeKey = $table->string('code_key', 30);
            if ($binary) {
                $codeKey->collation('utf8mb4_bin');
            }
            $table->string('status', 12)->default('pending');   // pending, taken, done, not_found, error
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('taken_at')->nullable();
            $table->dateTime('answered_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_jobs');
        Schema::dropIfExists('branch_stock');
    }
};
