<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // employe "normal" : les permissions cochees par defaut pour un nouveau compte
            'permissions' => Permissions::DEFAULTS,
        ];
    }

    /** Le developpeur (role le plus fort) : ce qu'etait l'administrateur avant les roles. */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Roles::DEVELOPER]);
    }

    /** Directeur de la societe ; ses branches : ->hasAttached($branches) ou $user->branches()->attach(). */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Roles::OWNER, 'permissions' => Permissions::all()]);
    }

    public function branchManager(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => ['role' => Roles::BRANCH_MANAGER, 'branch_id' => $branch->id]);
    }

    /** Employe rattache a sa branche. */
    public function inBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => ['role' => Roles::USER, 'branch_id' => $branch->id]);
    }

    /** Employe avec exactement ces permissions ([] = recherche dans le stock seulement). */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn (array $attributes) => ['permissions' => $permissions]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
