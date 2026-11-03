<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Activity;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Comptes et leurs permissions, selon le role de l'auteur (App\Support\Roles) :
 * developer gere tout le monde, owner les directeurs et employes de ses branches, directeur de branche les employes
 * de la sienne. Personne ne donne un role, une branche ou une permission qu'il n'a pas, et personne ne modifie
 * son propre compte ici (il passe par "حسابي") : on ne peut ni s'ajouter des droits ni retirer le dernier developer.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $me = $request->user();
        $users = User::with('branch')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->is($me) || $me->canManage($u));

        return view('users.index', [
            'groups' => $this->grouped($users),
            'me' => $me,
        ]);
    }

    public function create(Request $request): View
    {
        return view('users.create', $this->formData($request->user(), null));
    }

    public function store(Request $request): RedirectResponse
    {
        $me = $request->user();
        $data = $this->validated($request, $me, null);

        $user = new User(['name' => $data['name'], 'username' => $data['username'], 'password' => $data['password']]);
        $user->created_by = $me->id;
        $this->apply($user, $data, $me, $request);
        $user->save();
        $this->syncOwnerBranches($user, $data);
        Activity::log('user.created', $user, ['state' => $this->snapshot($user)]);

        return redirect()->route('users.index')->with('status', __('users.created_flash', ['username' => $user->username]));
    }

    public function edit(Request $request, User $user): View|RedirectResponse
    {
        if ($user->is($request->user())) {
            return redirect()->route('account.edit');
        }
        abort_unless($request->user()->canManage($user), 403);

        return view('users.edit', $this->formData($request->user(), $user));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $me = $request->user();
        abort_unless($me->canManage($user), 403);
        $data = $this->validated($request, $me, $user);
        $before = $this->snapshot($user);

        $passwordChanged = ! empty($data['password']);
        $user->name = $data['name'];
        $user->username = $data['username'];
        if ($passwordChanged) {
            $user->password = $data['password'];
        }
        $this->apply($user, $data, $me, $request);
        $user->save();
        $this->syncOwnerBranches($user, $data);

        $changes = Activity::diff($before, $this->snapshot($user->fresh()));
        if ($passwordChanged) {
            $changes['password'] = [null, 'changed'];
            $this->closeOtherSessions($request, $user);
        }
        if ($changes !== []) {
            Activity::log('user.updated', $user, $changes);
        }

        return redirect()->route('users.index')->with('status', __('users.saved_flash', ['username' => $user->username]));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => __('users.cannot_delete_self')]);
        }
        abort_unless($request->user()->canManage($user), 403);
        $username = $user->username;
        Activity::log('user.deleted', $user, ['state' => $this->snapshot($user)]);
        $user->delete();

        return redirect()->route('users.index')->with('status', __('users.deleted_flash', ['username' => $username]));
    }

    /** Role, branche(s), permissions et etat : seulement dans ce que l'auteur peut donner. */
    private function apply(User $user, array $data, User $me, Request $request): void
    {
        $user->role = $data['role'];
        $user->branch_id = Roles::inBranch($data['role']) ? (int) $data['branch_id'] : null;
        // la page envoie toujours active (0 cache + case) ; sans le champ, le compte garde son etat
        $user->active = $request->has('active') ? $request->boolean('active') : ($user->active ?? true);

        // les permissions que l'auteur n'a pas ne sont pas sur sa page : celles du compte restent telles quelles
        $grantable = $me->grantablePermissions();
        $kept = array_diff($user->permissions ?? [], $grantable);
        $given = array_intersect($data['permissions'] ?? [], $grantable);
        $user->permissions = Permissions::normalize([...$kept, ...$given]);
    }

    /** Branches d'un directeur de la societe : choisies par le developpeur (seul a pouvoir donner ce role). */
    private function syncOwnerBranches(User $user, array $data): void
    {
        $user->branches()->sync($user->isOwner() ? array_map('intval', $data['branches'] ?? []) : []);
    }

    private function validated(Request $request, User $me, ?User $user): array
    {
        $branchIds = $me->managedBranches()->pluck('id')->all();

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::in($me->assignableRoles())],
            'branch_id' => [Rule::requiredIf(fn () => Roles::inBranch((string) $request->input('role'))), 'nullable', 'integer', Rule::in($branchIds)],
            'branches' => ['nullable', 'array'],
            'branches.*' => ['integer', Rule::in($branchIds)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ], [
            'name.required' => __('users.name_required'),
            'username.required' => __('users.username_required'),
            'username.unique' => __('users.username_taken'),
            'password.required' => __('users.password_required'),
            'password.min' => __('users.password_min'),
            'role.required' => __('users.role_invalid'),
            'role.in' => __('users.role_invalid'),
            'branch_id.required' => __('users.branch_required'),
            'branch_id.in' => __('users.branch_invalid'),
            'branches.*.in' => __('users.branch_invalid'),
            'permissions.*.in' => __('users.permission_invalid'),
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(User $me, ?User $user): array
    {
        return [
            'user' => $user,
            'roles' => $me->assignableRoles(),
            'branches' => $me->managedBranches(),
            'grantable' => $me->grantablePermissions(),
            'ownerBranches' => $user?->branches()->pluck('branches.id')->all() ?? [],
        ];
    }

    /**
     * Arbre de la page : la direction (developer, owner), puis chaque branche (son directeur d'abord), puis les comptes
     * sans branche (employes d'avant les roles, a rattacher).
     *
     * @param  Collection<int, User>  $users
     * @return list<array{title: ?string, warn: bool, users: Collection<int, User>}>
     */
    private function grouped(Collection $users): array
    {
        $rank = fn (User $u) => array_search($u->role, Roles::ALL, true);
        $groups = [];
        $top = $users->filter(fn (User $u) => ! Roles::inBranch($u->role))->sortBy($rank)->values();
        if ($top->isNotEmpty()) {
            $groups[] = ['title' => __('users.group_direction'), 'warn' => false, 'users' => $top];
        }
        foreach ($users->filter(fn (User $u) => Roles::inBranch($u->role) && $u->branch)->groupBy('branch_id')->sortBy(fn (Collection $list) => $list->first()->branch->name) as $list) {
            $groups[] = ['title' => $list->first()->branch->name, 'warn' => false, 'users' => $list->sortBy($rank)->values()];
        }
        $none = $users->filter(fn (User $u) => Roles::inBranch($u->role) && ! $u->branch)->values();
        if ($none->isNotEmpty()) {
            $groups[] = ['title' => __('users.group_no_branch'), 'warn' => true, 'users' => $none];
        }

        return $groups;
    }

    /** @return array<string, mixed> ce que le journal compare avant / apres */
    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role,
            'branch_id' => $user->branch_id,
            'branches' => $user->isOwner() ? $user->branches()->pluck('branches.id')->sort()->values()->all() : [],
            'active' => (bool) $user->active,
            'permissions' => $user->permissions ?? [],
        ];
    }
}
