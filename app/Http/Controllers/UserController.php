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

        return redirect()->route('users.index')->with('status', "تم إنشاء الحساب «{$user->username}»");
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
            return back()->withErrors(['is_admin' => 'لا يمكنك إزالة صلاحية المدير عن حسابك أنت.'])->withInput();
        }

        $user->name = $data['name'];
        $user->username = $data['username'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->is_admin = $isAdmin;
        $user->save();

        return redirect()->route('users.index')->with('status', "تم حفظ الحساب «{$user->username}»");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'لا يمكنك حذف حسابك أنت.']);
        }
        $username = $user->username;
        $user->delete();

        return redirect()->route('users.index')->with('status', "تم حذف الحساب «{$username}»");
    }

    private function validated(Request $request, ?User $user): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
        ], [
            'name.required' => 'الاسم مطلوب',
            'username.required' => 'اسم المستخدم مطلوب',
            'username.unique' => 'اسم المستخدم مستعمل من حساب آخر',
            'password.required' => 'كلمة السر مطلوبة',
            'password.min' => 'كلمة السر: 8 أحرف على الأقل',
        ]);
    }
}
