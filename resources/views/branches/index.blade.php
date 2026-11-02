@extends('layouts.app')

@section('title', __('branches.title').' — '.__('common.app_name'))

@push('head')
<style>
    .top { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .top h1 { font-size: 20px; margin: 0; flex: 1; }
    .code-box { border: 2px dashed var(--accent); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; background: var(--card); }
    .code-box .code { font: 600 26px/1.3 Consolas, "Courier New", monospace; letter-spacing: 2px; direction: ltr; unicode-bidi: isolate; user-select: all; }
    .code-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 4px 0 6px; }
    .copy { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; }
    .copy svg { width: 17px; height: 17px; flex: none; }
    .rename { display: flex; gap: 6px; }
    .rename input { width: 170px; padding: 5px 8px; }
    .rename button { padding: 5px 10px; }
    .actions { display: flex; flex-wrap: wrap; gap: 6px; }
    .actions button { padding: 5px 10px; white-space: nowrap; }
    .sync { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .sync button { padding: 3px 10px; font-size: 13px; white-space: nowrap; }
    .sync-msg { font-size: 12px; }
    tr.off td { opacity: .55; }
    .wait::before { content: ""; display: inline-block; width: 9px; height: 9px; margin-inline-end: 5px; border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: spin .8s linear infinite; vertical-align: -1px; }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>
@endpush

@section('content')
<div class="top">
    <h1>{{ __('branches.title') }}</h1>
    <a class="btn" href="{{ route('agent.download') }}">⬇ {{ __('branches.download_agent') }} <span class="ltr small">v{{ $agentVersion }}</span></a>
    <a class="btn" href="{{ route('agent.guide') }}" target="_blank" rel="noopener">{{ __('branches.guide') }}</a>
    <form method="post" action="{{ route('branches.install-code') }}">
        @csrf
        <button class="primary" type="submit">{{ __('branches.new_code') }}</button>
    </form>
</div>

@if (session('install_code'))
    <div class="code-box">
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

<div class="card">
    @if ($branches->isEmpty())
        <p class="muted">{{ __('branches.empty') }}</p>
    @else
        <table>
            <thead>
            <tr>
                <th>{{ __('branches.col_name') }}</th>
                <th>{{ __('branches.col_status') }}</th>
                <th>{{ __('branches.col_last_sync') }}</th>
                <th class="hide-sm">{{ __('branches.col_items') }}</th>
                <th class="hide-sm">{{ __('branches.col_db_write') }}</th>
                <th class="hide-sm">{{ __('branches.col_host') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($branches as $b)
                @php($dbStale = $b->db_file_mtime && $b->db_file_mtime->lt(now()->subDay()))
                <tr class="{{ $b->active ? '' : 'off' }}">
                    <td>
                        <form class="rename" method="post" action="{{ route('branches.update', $b) }}">
                            @csrf @method('patch')
                            <input type="text" name="name" value="{{ $b->name }}" maxlength="100" required>
                            <button type="submit">{{ __('common.save') }}</button>
                        </form>
                    </td>
                    <td>
                        @if ($b->is_main)
                            <span class="pill ok" title="{{ __('branches.main_pill_tip') }}">{{ __('branches.main') }}</span>
                        @endif
                        @if (! $b->active)
                            <span class="pill">{{ __('branches.disabled') }}</span>
                        @elseif ($b->isOnline())
                            <span class="pill ok">{{ __('branches.online') }}</span>
                        @else
                            <span class="pill warn">{{ __('branches.offline') }}</span>
                            <div class="muted small">{{ $b->last_seen_at?->diffForHumans() ?? __('branches.never') }}</div>
                        @endif
                    </td>
                    <td>
                        <div class="sync">
                            <span class="small last-sync">{{ $b->last_sync_at?->diffForHumans() ?? '—' }}</span>
                            @if ($b->active)
                                <button type="button" data-sync="{{ route('branches.sync', $b) }}" title="{{ __('branches.sync_tip') }}">⟳ {{ __('branches.sync_now') }}</button>
                            @endif
                            <span class="sync-msg muted"></span>
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
                    <td class="hide-sm small muted"><span class="ltr">{{ $b->hostname }}</span>@if ($b->agent_version)<br><span class="ltr">v{{ $b->agent_version }}</span>@endif</td>
                    <td>
                        <div class="actions">
                            <form method="post" action="{{ route('branches.main', $b) }}">
                                @csrf
                                <button type="submit" title="{{ __('branches.main_tip') }}">
                                    {{ $b->is_main ? __('branches.unset_main') : __('branches.set_main') }}
                                </button>
                            </form>
                            <form method="post" action="{{ route('branches.update', $b) }}">
                                @csrf @method('patch')
                                <input type="hidden" name="active" value="{{ $b->active ? 0 : 1 }}">
                                <button type="submit">{{ $b->active ? __('branches.disable') : __('branches.enable') }}</button>
                            </form>
                            {{-- @js : le nom vient de l'agent ; un ' ne casse pas (ni n'injecte) le JavaScript de l'attribut --}}
                            <form method="post" action="{{ route('branches.destroy', $b) }}"
                                  onsubmit="return confirm(@js(__('branches.confirm_delete', ['name' => $b->name])))">
                                @csrf @method('delete')
                                <button class="danger" type="submit">{{ __('common.delete') }}</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="muted small">
            @foreach (__('branches.help') as $line)
                {{ $line }}
            @endforeach
        </p>
    @endif
</div>
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

(() => {
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    const MAX_WAIT_MS = 130000;
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
                setTimeout(tick, 2000);
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
