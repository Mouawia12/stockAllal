<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('hostname', 100)->nullable();
            // sha256 du token de l'agent ; le token en clair n'est jamais stocke
            $table->string('token_hash', 64)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->string('agent_version', 20)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('last_sync_at')->nullable();
            // derniere ecriture du fichier .IB : s'il ne bouge plus, la base active a peut-etre ete remplacee
            $table->dateTime('db_file_mtime')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->timestamps();
        });

        Schema::create('install_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('install_codes');
        Schema::dropIfExists('branches');
    }
};
