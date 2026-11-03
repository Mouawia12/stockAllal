<?php

use App\Models\Branch;
use App\Models\InstallCode;
use App\Models\RefreshJob;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('user:create {username} {--name=} {--password=} {--role= : developer, owner, branch_manager ou user} {--branch= : id ou nom de la branche (branch_manager, user)} {--admin : = --role=developer}', function (string $username) {
    $password = $this->option('password') ?: $this->secret('Mot de passe (8 caracteres minimum)');
    if (strlen((string) $password) < 8) {
        $this->error('Mot de passe trop court (8 caracteres minimum).');

        return 1;
    }
    $role = $this->option('admin') ? Roles::DEVELOPER : $this->option('role');
    if ($role !== null && ! in_array($role, Roles::ALL, true)) {
        $this->error('Role inconnu : '.$role.' (developer, owner, branch_manager, user).');

        return 1;
    }

    $user = User::firstOrNew(['username' => $username]);
    $user->name = $this->option('name') ?: ($user->name ?: $username);
    $user->password = $password;
    // sans --role / --admin, un compte existant garde le sien ; un nouveau compte est un employe
    if ($role !== null) {
        $user->role = $role;
    }
    if (! Roles::inBranch($user->role)) {
        $user->branch_id = null;
    } elseif ($this->option('branch') !== null) {
        $value = $this->option('branch');
        $branch = Branch::where('name', $value)->first() ?? (ctype_digit($value) ? Branch::find((int) $value) : null);
        if (! $branch) {
            $this->error('Branche introuvable : '.$value);

            return 1;
        }
        $user->branch_id = $branch->id;
    }
    if (! $user->exists && ! $user->isDeveloper()) {
        // nouveau compte : les permissions cochees par defaut sur la page des comptes
        $user->permissions = Permissions::DEFAULTS;
    }
    $user->save();
    $this->info(sprintf('Utilisateur %s pret (%s%s).', $user->username, $user->role,
        $user->isDeveloper() ? '' : ', permissions : '.implode(', ', $user->permissions ?? [])));
})->purpose('Cree ou met a jour un utilisateur du tableau de bord');

Artisan::command('agent:install-code', function () {
    $code = InstallCode::generate(null);
    $this->info("Code d'installation : {$code}  (une seule utilisation, valable 24 h)");
})->purpose("Genere un code d'installation pour l'agent d'une nouvelle branche");

Schedule::call(function () {
    RefreshJob::where('created_at', '<', now()->subDays(7))->delete();
    InstallCode::whereNull('used_at')->where('expires_at', '<', now()->subDays(7))->delete();
})->daily()->name('nettoyage-demandes')->withoutOverlapping();
