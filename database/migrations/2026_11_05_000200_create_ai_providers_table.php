<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liens avec Claude, ChatGPT et Gemini (page "الذكاء الاصطناعي", developpeur seulement) : une ligne par fournisseur.
 * La cle d'API est chiffree avec APP_KEY (App\Models\AiProvider), jamais en clair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->unique();      // claude, openai, gemini (App\Support\AiProviders)
            $table->text('api_key')->nullable();            // chiffree
            $table->string('model', 100)->nullable();
            $table->boolean('enabled')->default(false);
            $table->json('models')->nullable();             // les modeles du compte, lus au dernier test
            $table->dateTime('checked_at')->nullable();
            $table->string('check_error', 20)->nullable();  // auth, quota, down, unreachable, other (lang/*/ai.php errors)
            $table->string('check_detail', 255)->nullable(); // ce que le fournisseur a repondu
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
