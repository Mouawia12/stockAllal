<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\InstallCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('branches.index', [
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    /** Code a usage unique a saisir dans install.ps1 sur le serveur de la nouvelle branche. */
    public function installCode(Request $request): RedirectResponse
    {
        return back()->with('install_code', InstallCode::generate($request->user()->id));
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'اسم الفرع مطلوب',
        ]);
        $branch->update($data);

        return back()->with('status', 'تم الحفظ');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $name = $branch->name;
        $branch->delete();

        return back()->with('status', "تم حذف الفرع «{$name}» ومخزونه");
    }
}
