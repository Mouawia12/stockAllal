{{-- Boutons عربي / FR / EN (config('app.locales')) : la langue reste dans ce navigateur, voir LocaleController. --}}
{{-- Un POST et pas un lien : public/sw.js rejoue les GET avec un Referer "/sw.js", et le retour a la page serait perdu. --}}
<form class="lang-switch" method="post" action="{{ route('locale') }}" role="group" aria-label="{{ __('common.language') }}">
    @csrf
    @foreach (config('app.locales') as $code => $locale)
        <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}" title="{{ $locale['name'] }}"
                @if ($code === app()->getLocale()) class="on" aria-pressed="true" @endif>{{ $locale['short'] }}</button>
    @endforeach
</form>
@once
<style>
    .lang-switch { display: flex; gap: 2px; flex: none; margin: 0; }
    .lang-switch button { font: inherit; font-size: 13px; line-height: 1.4; cursor: pointer; border: 0; background: none; color: var(--muted); padding: 3px 7px; border-radius: 6px; white-space: nowrap; }
    .lang-switch button:hover { background: var(--row-hover); color: var(--text); }
    .lang-switch button.on { color: var(--text); background: var(--accent-soft); font-weight: 600; }
</style>
@endonce
