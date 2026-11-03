<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que chaque compte voit des branches (docs/roadmap.md) :
 * - branches.mode : synced (Gescomm est la source, l'agent synchronise) ; native viendra avec l'approbation d'une branche.
 * - branch_user : les branches d'un directeur de la societe (owner), choisies par le developpeur.
 * - branch_access : la branche A voit le stock de la branche B (module = stock, plus tard sales...).
 *   Rempli pour toutes les paires : chaque branche continue de voir toutes les autres, comme avant.
 * - install_codes.for_branch_id : code de reinstallation de l'agent d'une branche existante (pas de doublon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('mode', 10)->default('synced')->after('is_main');
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });

        Schema::create('branch_access', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visible_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('module', 20);
            $table->primary(['branch_id', 'visible_branch_id', 'module']);
        });

        $ids = DB::table('branches')->pluck('id');
        $rows = [];
        foreach ($ids as $from) {
            foreach ($ids as $to) {
                if ($from !== $to) {
                    $rows[] = ['branch_id' => $from, 'visible_branch_id' => $to, 'module' => 'stock'];
                }
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('branch_access')->insert($chunk);
        }

        Schema::table('install_codes', function (Blueprint $table) {
            $table->foreignId('for_branch_id')->nullable()->after('branch_id')->constrained('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('install_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('for_branch_id');
        });
        Schema::dropIfExists('branch_access');
        Schema::dropIfExists('branch_user');
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
