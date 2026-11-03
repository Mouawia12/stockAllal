<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles (App\Support\Roles) a la place de is_admin : developer > owner (مدير الشركة) > branch_manager > user.
 * Un administrateur devient developer (il garde tout ce qu'il avait), un employe devient user sans branche :
 * il voit toutes les branches comme avant, jusqu'a ce qu'on le rattache a la sienne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('user')->after('password');
            // branche du compte (directeur de branche, employe) ; vide pour developer et owner
            $table->foreignId('branch_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->boolean('active')->default(true)->after('branch_id');
            $table->foreignId('created_by')->nullable()->after('active')->constrained('users')->nullOnDelete();
            $table->dateTime('last_login_at')->nullable()->after('created_by');
        });

        DB::table('users')->where('is_admin', true)->update(['role' => 'developer']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });
        DB::table('users')->whereIn('role', ['developer', 'owner'])->update(['is_admin' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['role', 'active', 'last_login_at']);
        });
    }
};
