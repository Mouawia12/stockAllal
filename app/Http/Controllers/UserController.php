<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Comptes du tableau de bord (page reservee aux administrateurs).
 * Employe = recherche et consultation du stock ; administrateur = tout (branches, agent, comptes).
 * Un administrateur ne peut ni se retirer ses droits ni supprimer son propre compte : il en reste toujours un.
 */
class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::orderByDesc('is_admin')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $user = new User(['name' => $data['name'], 'username' => $data['username'], 'password' => $data['password']]);
        $user->is_admin = $request->boolean('is_admin');
        $user->save();

        return redirect()->route('users.index')->with('status', __('users.created_flash', ['username' => $user->username]));
    }

    public function edit(User $user): View
    {
        return view('users.edit', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        $isAdmin = $request->boolean('is_admin');
        if ($user->is($request->user()) && ! $isAdmin) {
            // jamais le mot de passe dans la session (dontFlash ne vaut que pour les erreurs de validation)
            return back()->withErrors(['is_admin' => __('users.cannot_demote_self')])->withInput($request->except('password'));
        }

        $passwordChanged = ! empty($data['password']);
        $user->name = $data['name'];
        $user->username = $data['username'];
        if ($passwordChanged) {
            $user->password = $data['password'];
        }
        $user->is_admin = $isAdmin;
        $user->save();

        if ($passwordChanged) {
            $this->closeOtherSessions($request, $user);
        }

        return redirect()->route('users.index')->with('status', __('users.saved_flash', ['username' => $user->username]));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => __('users.cannot_delete_self')]);
        }
        $username = $user->username;
        $user->delete();

        return redirect()->route('users.index')->with('status', __('users.deleted_flash', ['username' => $username]));
    }

    private function validated(Request $request, ?User $user): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
        ], [
            'name.required' => __('users.name_required'),
            'username.required' => __('users.username_required'),
            'username.unique' => __('users.username_taken'),
            'password.required' => __('users.password_required'),
            'password.min' => __('users.password_min'),
        ]);
    }
}
