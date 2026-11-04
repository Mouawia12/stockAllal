<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Support\Activity;
use App\Support\AiProviderError;
use App\Support\AiProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Page "الذكاء الاصطناعي" (developpeur seulement, gate manage-ai) : le lien avec Claude, ChatGPT et Gemini.
 * Une nouvelle cle est testee avant d'etre gardee : refusee par le fournisseur, elle n'est pas enregistree ;
 * sans reponse (reseau), elle l'est, avec l'erreur du test. Le journal note qu'une cle a change, jamais la cle.
 */
class AiSettingsController extends Controller
{
    public function index(): View
    {
        $saved = AiProvider::all()->keyBy('provider');

        return view('ai.index', [
            'providers' => collect(AiProviders::PROVIDERS)->map(fn (array $p, string $id) => $p + ['row' => $saved[$id] ?? new AiProvider(['provider' => $id])]),
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:300'],
            'model' => ['nullable', 'string', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $row = AiProvider::for($provider);
        $name = AiProviders::name($provider);
        $key = trim((string) ($data['api_key'] ?? ''));
        $enabled = $request->boolean('enabled');
        if ($enabled && $key === '' && $row->api_key === null) {
            return back()->withErrors(['api_key' => __('ai.need_key', ['name' => $name])]);
        }

        $before = ['model' => $row->model, 'enabled' => (bool) $row->enabled];
        if ($key !== '') {
            try {
                $row->models = AiProviders::models($provider, $key);
                $row->check_error = $row->check_detail = null;
            } catch (AiProviderError $e) {
                if ($e->reason === 'auth') {
                    return back()->withErrors(['api_key' => __('ai.errors.auth', ['name' => $name]).' '.__('ai.not_saved')]);
                }
                [$row->check_error, $row->check_detail] = [$e->reason, $e->detail];
            }
            $row->api_key = $key;
            $row->checked_at = now();
        }
        $model = trim((string) ($data['model'] ?? ''));
        $row->model = $model !== '' ? $model : ($row->model ?? ($row->models[0] ?? null));
        $row->enabled = $enabled;
        $row->save();

        $changes = Activity::diff($before, ['model' => $row->model, 'enabled' => $row->enabled]) + ($key !== '' ? ['api_key' => [null, 'changed']] : []);
        if ($changes) {
            Activity::log('ai.updated', $row, $changes);
        }

        return $row->check_error && $key !== ''
            ? back()->withErrors(['api_key' => __('ai.saved_untested', ['name' => $name]).' '.__("ai.errors.{$row->check_error}", ['name' => $name])])
            : back()->with('status', __('ai.saved', ['name' => $name]));
    }

    /** Refaire le test avec la cle gardee ; met aussi a jour la liste des modeles. */
    public function test(string $provider): RedirectResponse
    {
        $row = AiProvider::for($provider);
        $name = AiProviders::name($provider);
        if ($row->api_key === null) {
            return back()->withErrors(['api_key' => __($row->hasStoredKey() ? 'ai.key_unreadable' : 'ai.no_key', ['name' => $name])]);
        }

        try {
            $row->models = AiProviders::models($provider, $row->api_key);
            $row->check_error = $row->check_detail = null;
            $row->model ??= $row->models[0] ?? null;
        } catch (AiProviderError $e) {
            [$row->check_error, $row->check_detail] = [$e->reason, $e->detail];
        }
        $row->checked_at = now();
        $row->save();

        return $row->check_error
            ? back()->withErrors(['api_key' => __("ai.errors.{$row->check_error}", ['name' => $name])])
            : back()->with('status', __('ai.test_ok', ['name' => $name]));
    }

    public function destroy(string $provider): RedirectResponse
    {
        $row = AiProvider::for($provider);
        if ($row->exists && $row->hasStoredKey()) {
            $row->api_key = null;
            $row->forceFill(['models' => null, 'enabled' => false, 'checked_at' => null, 'check_error' => null, 'check_detail' => null])->save();
            Activity::log('ai.key_removed', $row);
        }

        return back()->with('status', __('ai.removed', ['name' => AiProviders::name($provider)]));
    }
}
