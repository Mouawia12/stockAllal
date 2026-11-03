<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Permissions;
use App\Support\Roles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// role, branche, permissions et active volontairement hors Fillable : ils ne changent que par une affectation explicite
// (page des comptes, user:create), apres les controles de UserController
#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'role' => Roles::USER,
        'active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'branch_id' => 'integer',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** Branche du compte (directeur de branche, employe). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Branches d'un directeur de la societe (owner), choisies par le developpeur. */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    public function isDeveloper(): bool
    {
        return $this->role === Roles::DEVELOPER;
    }

    public function isOwner(): bool
    {
        return $this->role === Roles::OWNER;
    }

    /** Page des comptes : developer, owner et directeur de branche (chacun pour ce qu'il gere). */
    public function managesAccounts(): bool
    {
        return Roles::MANAGES[$this->role] !== [];
    }

    /** Une permission de App\Support\Permissions ; le developpeur les a toutes. Dans les vues : @can(...). */
    public function hasPermission(string $permission): bool
    {
        return $this->isDeveloper() || in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * Branches dont ce compte voit le stock (null = toutes) :
     * developer toutes ; owner les siennes ; directeur de branche et employe la sienne + celles que branch_access
     * lui ouvre. Un employe d'avant les roles, sans branche, voit tout comme avant.
     *
     * @return list<int>|null
     */
    public function stockBranchIds(): ?array
    {
        if ($this->isDeveloper()) {
            return null;
        }
        if ($this->isOwner()) {
            return $this->branches()->pluck('branches.id')->all();
        }
        if ($this->branch_id === null) {
            return null;
        }

        return array_values(array_unique([$this->branch_id, ...BranchAccess::visibleFrom($this->branch_id, 'stock')]));
    }

    public function seesBranch(Branch $branch): bool
    {
        $ids = $this->stockBranchIds();

        return $ids === null || in_array($branch->id, $ids, true);
    }

    /**
     * Branches que ce compte gere (comptes, acces entre branches) : developer toutes, owner les siennes,
     * directeur de branche la sienne.
     *
     * @return Collection<int, Branch>
     */
    public function managedBranches(): Collection
    {
        return match ($this->role) {
            Roles::DEVELOPER => Branch::orderBy('name')->get(),
            Roles::OWNER => $this->branches()->orderBy('name')->get(),
            Roles::BRANCH_MANAGER => Branch::whereKey($this->branch_id)->get(),
            default => new Collection,
        };
    }

    /** @return list<string> roles que ce compte peut donner */
    public function assignableRoles(): array
    {
        return Roles::MANAGES[$this->role];
    }

    /** @return list<string> permissions que ce compte peut cocher pour un autre : celles qu'il a lui-meme */
    public function grantablePermissions(): array
    {
        return array_values(array_filter(Permissions::all(), fn (string $p) => $this->hasPermission($p)));
    }

    /**
     * Peut-il modifier ou supprimer ce compte ? Jamais le sien ici (il passe par "حسابي") ; seulement un role
     * qu'il peut donner, dans une branche qu'il gere. Un employe sans branche (d'avant les roles) : developer et owner.
     */
    public function canManage(User $other): bool
    {
        if ($other->is($this) || ! in_array($other->role, $this->assignableRoles(), true)) {
            return false;
        }
        if ($this->isDeveloper()) {
            return true;
        }
        if ($other->branch_id === null) {
            return $this->isOwner() && Roles::inBranch($other->role);
        }

        return $this->managedBranches()->contains('id', $other->branch_id);
    }
}
