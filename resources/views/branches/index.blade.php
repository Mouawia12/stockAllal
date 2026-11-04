@extends('layouts.app')

@section('title', __('branches.title').' — '.__('common.app_name'))

@push('head')
<style>
    .top { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .top h1 { font-size: 20px; margin: 0; flex: 1; }
    .code-box { border: 2px dashed var(--accent); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; background: var(--card); }
    .code-box .code { font: 700 32px/1.2 var(--font-code); letter-spacing: 2px; direction: ltr; unicode-bidi: isolate; user-select: all; }
    .code-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 4px 0 6px; }
    .copy { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; }
    .copy svg { width: 17px; height: 17px; flex: none; }
    /* le menu ⋮ d'une ligne deborde de la carte au lieu d'y ouvrir une barre de defilement */
    .branches-card { overflow: visible; }
    .branches td { padding-block: 12px; }
    .b-name { display: flex; align-items: center; gap: 4px 8px; flex-wrap: wrap; font-weight: 600; }
    .b-name .bdot { margin-inline-end: 4px; }
    .b-sub { margin-top: 2px; padding-inline-start: 17px; }
    .pill.main { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    /* renommer : le nom devient un champ, depuis le menu ⋮ */
    .rename { display: none; gap: 6px; align-items: center; }
    .b-cell.editing .rename { display: flex; }
    .b-cell.editing .b-view { display: none; }
    .rename input { width: 180px; padding: 5px 8px; }
    .rename button { padding: 5px 10px; }
    .since { margin-top: 3px; }
    .sync { display: flex; align-items: center; gap: 4px 8px; flex-wrap: wrap; }
    .sync .last-sync { font-size: 14px; }
    .sync button { display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px; border: 0; border-radius: 6px; background: none; color: var(--accent); font-size: 13px; white-space: nowrap; }
    .sync button:hover { background: var(--accent-soft); }
    .sync button:disabled { opacity: .6; cursor: default; }
    .sync button svg { width: 14px; height: 14px; }
    .sync-msg { font-size: 12px; }
    .data-state { margin-top: 3px; }
    tr.off td { opacity: .55; }
    .row-menu summary { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 50%; color: var(--muted); }
    .row-menu summary:hover, .row-menu[open] summary { background: var(--row-hover); color: var(--text); }
    .row-menu summary svg { width: 18px; height: 18px; }
    .row-menu .drop-menu { min-width: 210px; }
    .row-menu form { margin: 0; }
    .row-menu .drop-menu button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border: 0; border-radius: 8px; background: none; color: var(--text); text-align: start; white-space: nowrap; }
    .row-menu .drop-menu button:hover, .row-menu .drop-menu button:focus-visible { background: var(--row-hover); }
    .row-menu .drop-menu button.danger, .row-menu .drop-menu button.danger svg { color: var(--bad); }
    .row-menu .drop-menu svg { width: 17px; height: 17px; flex: none; color: var(--muted); }
    .row-menu hr { border: 0; border-top: 1px solid var(--border); margin: 4px 0; }
    .help-more { margin-top: 12px; }
    .help-more summary { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; color: var(--muted); font-size: 13px; }
    .help-more summary:hover { color: var(--text); }
    .help-more ul { margin: 8px 0 0; padding-inline-start: 20px; display: grid; gap: 4px; }
    .access select { font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 6px 8px; }
    .access-card { margin-top: 16px; }
    .access-card h2 { font-size: 16px; margin: 0 0 4px; }
    .access-card td, .access-card th { text-align: center; }
    .access-card td:first-child, .access-card th:first-child { text-align: start; }
    .access-card input[type=checkbox] { width: 18px; height: 18px; }
    .access-card button { padding: 4px 10px; }
    .wait::before { content: ""; display: inline-block; width: 9px; height: 9px; margin-inline-end: 5px; border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; vertical-align: -1px; }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>
@endpush

@section('content')
<div class="top">
    <h1>{{ __('branches.title') }}</h1>
    {{-- chaque bouton seulement avec sa permission (App\Support\Permissions) --}}
    @can('agent.download')
        <a class="btn" href="{{ route('agent.download') }}">⬇ {{ __('branches.download_agent') }} <span class="ltr small">v{{ $agentVersion }}</span></a>
        <a class="btn" href="{{ route('agent.guide') }}" target="_blank" rel="noopener">{{ __('branches.guide') }}</a>
    @endcan
    @can('branches.manage')
        {{-- nouvelle branche ; la reinstallation d'une branche existante (meme branche, pas de doublon) est dans son menu ⋮ --}}
        <form method="post" action="{{ route('branches.install-code') }}">
            @csrf
            <button class="primary" type="submit" title="{{ __('branches.new_code_tip') }}">{{ __('branches.new_code') }}</button>
        </form>
    @endcan
</div>

@if (session('install_code'))
    <div class="code-box">
        @if (session('install_code_branch'))
            <div class="pill warn">{{ __('branches.reinstall_note', ['name' => session('install_code_branch')]) }}</div>
        @endif
        <div class="muted small">{{ __('branches.code_once') }}</div>
        <div class="code-row">
            <div class="code">{{ session('install_code') }}</div>
            <button type="button" class="copy" data-copy="{{ session('install_code') }}" title="{{ __('branches.copy.tip') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg>
                <span>{{ __('branches.copy.button') }}</span>
            </button>
            <span class="copy-msg small" aria-live="polite"></span>
        </div>
        <div class="small muted">
            {!! __('branches.code_help', ['script' => '<span class="ltr">GescomAgent-Setup.exe</span>']) !!}
            {!! __('branches.api_url', ['url' => '<span class="ltr">'.e(url('/api')).'</span>']) !!}
        </div>
    </div>
@endif

<div class="card branches-card">
    @if ($branches->isEmpty())
        <p class="muted">{{ __('branches.empty') }}</p>
    @else
        <table class="branches">
            <thead>
            <tr>
                <th>{{ __('branches.col_name') }}</th>
                <th>{{ __('branches.col_status') }}</th>
                <th>{{ __('branches.col_last_sync') }}</th>
                <th class="hide-sm">{{ __('branches.col_items') }}</th>
                <th class="hide-sm">{{ __('branches.col_db_write') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($branches as $b)
                @php($dbStale = $b->db_file_mtime && $b->db_file_mtime->lt(now()->subDay()))
                <tr class="{{ $b->active ? '' : 'off' }}">
                    <td class="b-cell">
                        <div class="b-view">
                            <div class="b-name">
                                {{-- sa couleur dans la page d'un article, l'apercu et les ventes (Branch::hue) --}}
                                <span><span class="bdot" style="--bh: {{ $b->hue() }}"></span><bdi>{{ $b->name }}</bdi></span>
                                @if ($b->is_main)
                                    <span class="pill main" title="{{ __('branches.main_pill_tip') }}">{{ __('branches.main') }}</span>
                                @endif
                            </div>
                            {{-- sur telephone, seulement la version de l'agent --}}
                            @if ($b->hostname || $b->agent_version)
                                <div class="b-sub muted small">
                                    @if ($b->hostname)<span class="ltr hide-sm">{{ $b->hostname }}</span>@endif
                                    @if ($b->hostname && $b->agent_version)<span class="hide-sm"> · </span>@endif
                                    @if ($b->agent_version)<span class="ltr">v{{ $b->agent_version }}</span>@endif
                                </div>
                            @endif
                        </div>
                        @can('branches.manage')
                            <form class="rename" method="post" action="{{ route('branches.update', $b) }}">
                                @csrf @method('patch')
                                <span class="bdot" style="--bh: {{ $b->hue() }}"></span>
                                <input type="text" name="name" value="{{ $b->name }}" maxlength="100" required aria-label="{{ __('branches.rename') }}">
                                <button type="submit" class="primary">{{ __('common.save') }}</button>
                                <button type="button" data-cancel>{{ __('common.cancel') }}</button>
                            </form>
                        @endcan
                    </td>
                    <td>
                        @if (! $b->active)
                            <span class="pill">{{ __('branches.disabled') }}</span>
                        @elseif ($b->isOnline())
                            <span class="pill ok">{{ __('branches.online') }}</span>
                        @else
                            <span class="pill warn">{{ __('branches.offline') }}</span>
                            <div class="since muted small">{{ $b->last_seen_at?->diffForHumans() ?? __('branches.never') }}</div>
                        @endif
                    </td>
                    <td>
                        <div class="sync">
                            <span class="last-sync">{{ $b->last_sync_at?->diffForHumans() ?? '—' }}</span>
                            @if ($b->active && auth()->user()->can('branches.sync'))
                                {{-- agent avant 1.0.4 : le bouton attend sa prochaine synchro automatique (RefreshJob "waiting") --}}
                                <button type="button" data-sync="{{ route('branches.sync', $b) }}"
                                        title="{{ $b->agentSupports('sync') ? __('branches.sync_tip') : __('branches.sync_tip_old', ['version' => \App\Models\Branch::AGENT_FEATURES['sync']]) }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/></svg>
                                    {{ __('branches.sync_now') }}
                                </button>
                            @endif
                            <span class="sync-msg muted"></span>
                        </div>
                        {{-- ventes, achats, clients... (agent 1.0.7) : une tache en echec se voit ici, les autres continuent --}}
                        @php($bt = $tasks->get($b->id, collect()))
                        <div class="data-state small">
                            @if (! $b->agentSupports('documents'))
                                <span class="muted">{{ __('branches.data_old', ['version' => \App\Models\Branch::AGENT_FEATURES['documents']]) }}</span>
                            @elseif ($bt->isEmpty())
                                <span class="muted">{{ __('branches.data_waiting') }}</span>
                            @elseif (($failing = $bt->filter(fn ($t) => $t->failing()))->isNotEmpty())
                                <span class="pill bad" title="{{ $failing->map(fn ($t) => __("branches.tasks.{$t->task}").' : '.$t->last_error.' ('.$t->last_error_at->format('d/m H:i').')')->join("\n") }}">{{ __('branches.data_error', ['tasks' => $failing->map(fn ($t) => __("branches.tasks.{$t->task}"))->join(__('stock.js.list_separator'))]) }}</span>
                            @else
                                <span class="muted" title="{{ $bt->map(fn ($t) => __("branches.tasks.{$t->task}").' : '.$t->last_ok_at?->diffForHumans())->join("\n") }}">{{ __('branches.data_ok', ['time' => $bt->min('last_ok_at')?->diffForHumans() ?? '—']) }}</span>
                            @endif
                        </div>
                    </td>
                    <td class="hide-sm"><span class="num items-count">{{ number_format($b->items_count, 0, ',', ' ') }}</span></td>
                    <td class="hide-sm small">
                        @if ($b->db_file_mtime)
                            <span class="{{ $dbStale ? 'pill warn' : '' }}" title="{{ $b->db_file_mtime->format('d/m/Y H:i') }}">{{ $b->db_file_mtime->diffForHumans() }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @can('branches.manage')
                        {{-- les actions de la branche dans un menu, facon Odoo : details.drop (ouverture, Echap, fleches : layouts/app) --}}
                        <details class="drop row-menu">
                            <summary title="{{ __('branches.actions', ['name' => $b->name]) }}" aria-label="{{ __('branches.actions', ['name' => $b->name]) }}">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                            </summary>
                            <div class="drop-menu">
                                <button type="button" data-rename>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/></svg>
                                    {{ __('branches.rename') }}
                                </button>
                                <form method="post" action="{{ route('branches.main', $b) }}">
                                    @csrf
                                    <button type="submit" title="{{ __('branches.main_tip') }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg>
                                        {{ $b->is_main ? __('branches.unset_main') : __('branches.set_main') }}
                                    </button>
                                </form>
                                {{-- reinstallation de son agent : meme branche, nouveau jeton (install_codes.for_branch_id) --}}
                                <form method="post" action="{{ route('branches.install-code') }}">
                                    @csrf
                                    <input type="hidden" name="branch_id" value="{{ $b->id }}">
                                    <button type="submit" title="{{ __('branches.reinstall_note', ['name' => $b->name]) }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/></svg>
                                        {{ __('branches.reinstall') }}
                                    </button>
                                </form>
                                <form method="post" action="{{ route('branches.update', $b) }}">
                                    @csrf @method('patch')
                                    <input type="hidden" name="active" value="{{ $b->active ? 0 : 1 }}">
                                    <button type="submit">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 3v8M6.6 6.6a8 8 0 1 0 10.8 0"/></svg>
                                        {{ $b->active ? __('branches.disable') : __('branches.enable') }}
                                    </button>
                                </form>
                                <hr>
                                {{-- @js : le nom vient de l'agent ; un ' ne casse pas (ni n'injecte) le JavaScript de l'attribut --}}
                                <form method="post" action="{{ route('branches.destroy', $b) }}"
                                      onsubmit="return confirm(@js(__('branches.confirm_delete', ['name' => $b->name])))">
                                    @csrf @method('delete')
                                    <button class="danger" type="submit">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                                        {{ __('common.delete') }}
                                    </button>
                                </form>
                            </div>
                        </details>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <details class="help-more">
            <summary>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                {{ __('branches.help_title') }}
            </summary>
            <ul class="muted small">
                @foreach (__('branches.help') as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</div>

{{-- qui voit le stock de qui (branch_access) : developpeur et directeur de la societe, parmi les branches qu'ils gerent --}}
@if ($managed->count() > 1)
<div class="card access-card">
    <h2>{{ __('branches.access_title') }}</h2>
    <p class="muted small">{{ __('branches.access_help') }}</p>
    <table>
        <thead>
        <tr>
            <th>{{ __('branches.access_row') }}</th>
            @foreach ($managed as $col)
                <th>{{ $col->name }}</th>
            @endforeach
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($managed as $row)
            <tr>
                <td><b>{{ $row->name }}</b></td>
                @foreach ($managed as $col)
                    <td>
                        @if ($col->id === $row->id)
                            <span class="muted small" title="{{ __('branches.access_self_tip') }}">✓</span>
                        @else
                            <input type="checkbox" form="access-{{ $row->id }}" name="visible[]" value="{{ $col->id }}"
                                   aria-label="{{ __('branches.access_cell', ['row' => $row->name, 'col' => $col->name]) }}"
                                   @checked(in_array($col->id, $access[$row->id] ?? [], true))>
                        @endif
                    </td>
                @endforeach
                <td>
                    <form id="access-{{ $row->id }}" method="post" action="{{ route('branches.access', $row) }}">
                        @csrf @method('put')
                        <button type="submit">{{ __('common.save') }}</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection

@push('scripts')
<script>
(() => {
    const COPY = @json(__('branches.copy'));
    document.querySelectorAll('button[data-copy]').forEach(btn => btn.addEventListener('click', async () => {
        const text = btn.dataset.copy;
        let ok = false;
        try {
            await navigator.clipboard.writeText(text);
            ok = true;
        } catch (e) {
            // presse-papiers refuse (ancien navigateur, page non https) : selection + execCommand
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { ok = document.execCommand('copy'); } catch (e2) { ok = false; }
            ta.remove();
        }
        const msg = btn.parentElement.querySelector('.copy-msg');
        msg.textContent = ok ? COPY.done : COPY.failed;
        msg.className = 'copy-msg small pill ' + (ok ? 'ok' : 'warn');
        clearTimeout(btn.copyTimer);
        btn.copyTimer = setTimeout(() => { msg.textContent = ''; msg.className = 'copy-msg small'; }, 4000);
    }));
})();

// renommer (menu ⋮) : le nom devient un champ ; Annuler ou Echap le rend tel qu'il etait
(() => {
    document.querySelectorAll('.b-cell .rename').forEach(form => {
        const cell = form.closest('.b-cell');
        const input = form.querySelector('input[name=name]');
        const close = () => { input.value = input.defaultValue; cell.classList.remove('editing'); };
        cell.closest('tr').querySelector('button[data-rename]').addEventListener('click', e => {
            e.target.closest('details').open = false;
            cell.classList.add('editing');
            input.focus();
            input.select();
        });
        form.querySelector('[data-cancel]').addEventListener('click', close);
        input.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); close(); } });
    });
})();

(() => {
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    const texts = @json(__('branches.sync'));
    const REQUEST_FAILED = @json(__('common.request_failed'));

    document.querySelectorAll('button[data-sync]').forEach(btn => btn.addEventListener('click', async () => {
        const msg = btn.parentElement.querySelector('.sync-msg');
        const show = (text, cls) => { msg.textContent = text; msg.className = 'sync-msg ' + (cls || 'muted'); };
        btn.disabled = true;
        show(texts.pending, 'wait muted');
        const row = btn.closest('tr');
        try {
            // batch choisi ici : rejouee apres une coupure, la requete ne cree pas une seconde demande
            const res = await fetchRetry(btn.dataset.sync, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF},
                body: JSON.stringify({batch: newId()}),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
            const statusUrl = btn.dataset.sync + '/' + data.job;
            const started = Date.now();
            // delai donne par le serveur : ~2 min, ou ~7 min pour un agent ancien qui attend sa synchro automatique
            const MAX_WAIT_MS = (Number(data.wait_seconds) || 120) * 1000 + 10000;
            show(texts[data.status] || texts.pending, 'wait muted');
            const tick = async () => {
                let s;
                try {
                    const r = await fetchRetry(statusUrl, {headers: {Accept: 'application/json'}});
                    s = await r.json();
                } catch (e) {
                    // coupure passagere : on reessaie au prochain tour, sans alarmer l'utilisateur
                    if (Date.now() - started < MAX_WAIT_MS) { setTimeout(tick, 3000); return; }
                    s = {status: 'timeout'};
                }
                if (s.status === 'done') {
                    // mise a jour de la ligne sur place, sans recharger la page
                    row.querySelector('.last-sync').textContent = s.last_sync || '—';
                    const count = row.querySelector('.items-count');
                    if (count) count.textContent = Number(s.items_count || 0).toLocaleString('fr-FR');
                    show(texts.done, 'pill ok');
                    btn.disabled = false;
                    return;
                }
                if (s.status === 'error' || s.status === 'timeout' || Date.now() - started > MAX_WAIT_MS) {
                    show(texts[s.status] || texts.timeout, 'pill warn');
                    btn.disabled = false;
                    return;
                }
                show(texts[s.status] || texts.pending, 'wait muted');
                setTimeout(tick, s.status === 'waiting' ? 5000 : 2000);
            };
            setTimeout(tick, 1500);
        } catch (e) {
            show(REQUEST_FAILED.replace(':error', e.message), 'pill warn');
            btn.disabled = false;
        }
    }));
})();
</script>
@endpush
