{{--
    Selecteur de branche de la barre du haut, facon Odoo (App\Support\BranchScope) : "كل فروعي" ou une seule branche.
    Il change ce que montrent la recherche, la fenetre "معاينة", la page d'un article et "تحديث الآن".
    Seulement si le compte voit plus d'une branche. Un POST, comme les boutons de langue (public/sw.js).
--}}
@php($scopeChoices = App\Support\BranchScope::choices(auth()->user()))
@if ($scopeChoices->count() > 1)
    @php($scopeSelected = App\Support\BranchScope::selected(request()))
    <details @class(['scope', 'picked' => $scopeSelected])>
        <summary title="{{ __('common.scope.title') }}" data-key="Alt+B">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18M5 21V9l7-5 7 5v12"/><path d="M9 21v-6h6v6"/></svg>
            @if ($scopeSelected)<span class="bdot" style="--bh: {{ $scopeSelected->hue() }}"></span>@endif
            <span class="scope-label">{{ $scopeSelected?->name ?? __('common.scope.all') }}</span>
            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
        </summary>
        <form class="scope-menu" method="post" action="{{ route('branch-scope') }}">
            @csrf
            <button type="submit" name="branch_id" value="" @class(['on' => ! $scopeSelected])>
                <span>{{ __('common.scope.all') }}</span>
                <span class="muted small">{{ __('common.scope.count', ['count' => $scopeChoices->count()]) }}</span>
            </button>
            @foreach ($scopeChoices as $b)
                <button type="submit" name="branch_id" value="{{ $b->id }}" @class(['on' => $scopeSelected?->id === $b->id])>
                    <span><span class="bdot" style="--bh: {{ $b->hue() }}"></span> {{ $b->name }}</span>
                    @if ($b->is_main)<span class="muted small">{{ __('stock.js.main') }}</span>@endif
                </button>
            @endforeach
        </form>
    </details>
    @once
    <style>
        .scope { position: relative; flex: none; }
        .scope summary { list-style: none; display: flex; align-items: center; gap: 6px; cursor: pointer; padding: 5px 10px; border: 1px solid var(--border);
            border-radius: 99px; color: var(--text); font-size: 14px; font-weight: 500; white-space: nowrap; max-width: 220px; }
        .scope summary::-webkit-details-marker { display: none; }
        .scope summary:hover { background: var(--row-hover); }
        .scope[open] summary, .scope.picked summary { border-color: var(--accent); }
        .scope summary svg { width: 17px; height: 17px; flex: none; color: var(--accent); }
        .scope summary .chev { width: 14px; height: 14px; color: var(--muted); }
        .scope-label { overflow: hidden; text-overflow: ellipsis; }
        .scope-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); min-width: 220px; background: var(--card); border: 1px solid var(--border);
            border-radius: 12px; box-shadow: var(--shadow); padding: 6px; display: flex; flex-direction: column; z-index: 20; margin: 0; }
        .scope-menu button { display: flex; justify-content: space-between; gap: 12px; border: 0; background: none; text-align: start; padding: 8px 10px; border-radius: 8px; }
        .scope-menu button.on { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
        @media (max-width: 1000px) { .scope-label { max-width: 130px; } }
        @media (max-width: 700px) { .scope-label { max-width: 90px; } }
    </style>
    <script>
        // un clic ailleurs ou Echap ferme le menu ; ouvert (aussi par Alt+B), le clavier est sur la branche choisie, les fleches passent aux autres
        document.addEventListener('click', e => document.querySelectorAll('details.scope[open]').forEach(d => { if (!d.contains(e.target)) d.open = false; }));
        document.querySelectorAll('details.scope').forEach(d => d.addEventListener('toggle', () => {
            if (d.open) (d.querySelector('.scope-menu button.on') || d.querySelector('.scope-menu button'))?.focus();
        }));
        document.addEventListener('keydown', e => {
            const open = document.querySelector('details.scope[open]');
            if (!open) return;
            if (e.key === 'Escape') { e.preventDefault(); open.open = false; open.querySelector('summary').focus(); return; }
            const menu = e.target.closest?.('.scope-menu');
            if (!menu || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) return;
            e.preventDefault();
            const list = [...menu.querySelectorAll('button')];
            list[(list.indexOf(e.target.closest('button')) + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length].focus();
        });
    </script>
    @endonce
@endif
