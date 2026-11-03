<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Qui a fait quoi et quand (comptes, roles, permissions, branches) : App\Support\Activity::log(). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);                       // ex. user.updated, branch.access
            $table->string('subject_type', 40)->nullable();      // user, branch, install_code
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('data')->nullable();                    // ce qui a change : {champ: [avant, apres]}
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at');

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
