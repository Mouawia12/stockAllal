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

        Schema::table('refresh_jobs', function (Blueprint $table) use ($binary) {
            // "code" = lire un produit dans la branche ; "sync" = renvoyer tout le stock de la branche maintenant
            $table->string('type', 10)->default('code')->after('batch');
            $codeKey = $table->string('code_key', 30)->nullable()->change();
            if ($binary) {
                $codeKey->collation('utf8mb4_bin');
            }
        });
    }

    public function down(): void
    {
        DB::table('refresh_jobs')->where('type', 'sync')->delete();
        $binary = DB::getDriverName() === 'mysql';

        Schema::table('refresh_jobs', function (Blueprint $table) use ($binary) {
            $table->dropColumn('type');
            $codeKey = $table->string('code_key', 30)->nullable(false)->change();
            if ($binary) {
                $codeKey->collation('utf8mb4_bin');
            }
        });
    }
};
