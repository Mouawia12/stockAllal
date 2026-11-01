<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $binary = DB::getDriverName() === 'mysql';

        // un groupe = des code_key differents que l'administrateur a declares comme le meme produit (820AC, 820-AC)
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_group_codes', function (Blueprint $table) use ($binary) {
            $table->id();
            $table->foreignId('product_group_id')->constrained()->cascadeOnDelete();
            $codeKey = $table->string('code_key', 30);
            if ($binary) {
                $codeKey->collation('utf8mb4_bin');
            }
            $table->timestamps();

            // un code appartient a un seul groupe
            $table->unique('code_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_group_codes');
        Schema::dropIfExists('product_groups');
    }
};
