<?php

use App\Models\InstallCode;
use App\Models\RefreshJob;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('user:create {username} {--name=} {--password=} {--admin : droits administrateur (branches, agent, comptes)}', function (string $username) {
    $password = $this->option('password') ?: $this->secret('Mot de passe (8 caracteres minimum)');
    if (strlen((string) $password) < 8) {
        $this->error('Mot de passe trop court (8 caracteres minimum).');

        return 1;
    }

    $user = User::firstOrNew(['username' => $username]);
    $user->name = $this->option('name') ?: ($user->name ?: $username);
    $user->password = $password;
    // --admin donne les droits ; sans l'option, un compte existant garde les siens
    $user->is_admin = $this->option('admin') || $user->is_admin;
    $user->save();
    $this->info(sprintf('Utilisateur %s pret (%s).', $user->username, $user->is_admin ? 'administrateur' : 'employe : stock seulement'));
})->purpose('Cree ou met a jour un utilisateur du tableau de bord');

Artisan::command('agent:install-code', function () {
    $code = InstallCode::generate(null);
    $this->info("Code d'installation : {$code}  (une seule utilisation, valable 24 h)");
})->purpose("Genere un code d'installation pour l'agent d'une nouvelle branche");

Schedule::call(function () {
    RefreshJob::where('created_at', '<', now()->subDays(7))->delete();
    InstallCode::whereNull('used_at')->where('expires_at', '<', now()->subDays(7))->delete();
})->daily()->name('nettoyage-demandes')->withoutOverlapping();
