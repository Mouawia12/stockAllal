<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les exercices de Gescomm (App\Models\Exercise). A la cloture, Gescomm copie la base sous LaBaseN<nom>.ib, vide les bons
 * et les versements (procedure CLOTURE1) et reporte chaque solde en un versement "Autre" du jour ("Solde Initial ... de
 * l'exercice <nom>", INITIALISERSOLDENEW). Ici : bons, lignes et versements portent leur exercice ; 0 = celui en cours de
 * leur branche (la base que l'agent lit), sinon l'id d'un exercice clos. Les donnees deja la sont celles en cours (0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->boolean('current')->default(false);
            // le nom donne a la cloture (LaBaseN20263.ib -> 20263) ; null pour celui en cours
            $table->string('name', 40)->nullable();
            // la marque de la base : le jour de ses "Solde Initial" et l'exercice qu'ils reportent ("20262")
            $table->date('opened_on')->nullable();
            $table->string('opening_label', 40)->nullable();
            $table->date('first_day')->nullable();
            $table->date('last_day')->nullable();
            $table->string('file', 120)->nullable();
            $table->json('counts')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'current']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('exercise_id')->default(0);
            $table->unique(['branch_id', 'exercise_id', 'kind', 'gescomm_key']);
            $table->index(['branch_id', 'kind', 'exercise_id', 'sync_group']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'kind', 'gescomm_key']);
            $table->dropIndex(['branch_id', 'kind', 'sync_group']);
        });

        Schema::table('document_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('exercise_id')->default(0);
            // les lignes d'un bon : MySQL a l'index de la cle etrangere, SQLite non (100 bons de la liste : 8,6 s avec
            // les archives d'une branche) ; aussi l'ordre des lignes
            $table->index(['document_id', 'position']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('exercise_id')->default(0);
            $table->unique(['branch_id', 'exercise_id', 'partner_kind', 'gescomm_key']);
            $table->index(['branch_id', 'partner_kind', 'exercise_id', 'sync_group']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'partner_kind', 'gescomm_key']);
            $table->dropIndex(['branch_id', 'partner_kind', 'sync_group']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['branch_id', 'partner_kind', 'gescomm_key']);
            $table->index(['branch_id', 'partner_kind', 'sync_group']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'exercise_id', 'partner_kind', 'gescomm_key']);
            $table->dropIndex(['branch_id', 'partner_kind', 'exercise_id', 'sync_group']);
            $table->dropColumn('exercise_id');
        });
        Schema::table('document_lines', function (Blueprint $table) {
            $table->dropIndex(['document_id', 'position']);
            $table->dropColumn('exercise_id');
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->unique(['branch_id', 'kind', 'gescomm_key']);
            $table->index(['branch_id', 'kind', 'sync_group']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'exercise_id', 'kind', 'gescomm_key']);
            $table->dropIndex(['branch_id', 'kind', 'exercise_id', 'sync_group']);
            $table->dropColumn('exercise_id');
        });
        Schema::dropIfExists('exercises');
    }
};
