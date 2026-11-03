<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // permissions cochees par un administrateur (App\Support\Permissions) ; ignorees pour un administrateur
            $table->json('permissions')->nullable()->after('is_admin');
        });

        // employes existants : ce qu'ils pouvaient faire (stock, "تحديث الآن", prix de vente) + succursales et "مزامنة الآن"
        DB::table('users')->where('is_admin', false)->update(['permissions' => json_encode(Permissions::DEFAULTS)]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
