@extends('layouts.app')

{{--
    "المطابقة" (ReconcileController) : choisir le client chez la branche qui vend (?ca=) et le fournisseur chez celle qui
    achete (?sb=), puis le rapport de App\Support\Reconciliation (soldes, ecart, problemes du plus gros au plus petit,
    bons et versements identiques) et le chat : l'IA connectee explique ce rapport (ReconciliationChat). Elle ecrit
    {A} / {B} et {A:101} / {B:7} : le script remet ici les noms des branches et les liens vers les bons.
    L'historique du chat reste dans l'onglet (sessionStorage), jamais sur le serveur.
--}}
@section('title', __('reconcile.title').' — '.__('common.app_name'))

@push('head')
<style>
    .rc-title { font-size: 20px; margin: 0 0 4px; }
    .rc-lead { margin: 0 0 16px; max-width: 85ch; }
    .pick { margin-bottom: 18px; }
    .sides { display: grid; grid-template-columns: 1fr auto 1fr; gap: 14px; align-items: start; }
    .side { border: 0; margin: 0; padding: 0; min-width: 0; }
    .side legend { font-weight: 700; padding: 0; margin-bottom: 2px; }
    .side .help { margin: 0 0 8px; }
    .side select { font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; width: 100%; margin-bottom: 8px; }
    .arrow { align-self: center; font-size: 22px; color: var(--muted); padding-top: 24px; }
    [dir=rtl] .arrow { transform: scaleX(-1); }
    .pp input[type=search] { width: 100%; }
    .pp .chosen { display: none; align-items: center; gap: 8px; padding: 7px 10px; border: 1px solid var(--accent); background: var(--accent-soft); border-radius: 8px; }
    .pp .chosen .name { flex: 1; min-width: 0; font-weight: 600; overflow-wrap: anywhere; }
    .pp.has .chosen { display: flex; }
    .pp.has input[type=search], .pp.has .hits { display: none; }
    .hits { list-style: none; margin: 6px 0 0; padding: 0; display: grid; gap: 4px; max-height: 280px; overflow-y: auto; }
    .hits button { width: 100%; text-align: start; display: flex; gap: 8px; align-items: baseline; padding: 6px 10px; }
    .hits .nm { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .hits .none { padding: 6px 10px; }
    .pick-row { display: flex; align-items: center; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
    .pick-msg { color: var(--bad); }
    .note { margin: 0 0 14px; }

    .summary { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 16px; }
    .sum h2 { font-size: 15px; margin: 0 0 8px; display: flex; gap: 8px; align-items: center; }
    .sum dl { margin: 0; display: grid; grid-template-columns: 1fr auto; gap: 2px 12px; }
    .sum dt { color: var(--muted); }
    .sum dd { margin: 0; text-align: end; }
    .sum .bal { font-weight: 700; font-size: 17px; }
    .gapc { text-align: center; display: flex; flex-direction: column; justify-content: center; }
    .gapc .big { font-size: 28px; font-weight: 800; margin: 2px 0; }
    .gapc.ok .big { color: var(--ok); }
    .gapc p { margin: 4px 0 0; }

    .work { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
    .probs h2, .chat h2 { font-size: 16px; margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }
    .prob { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; }
    .prob-head { display: flex; gap: 10px; align-items: baseline; justify-content: space-between; }
    .prob-head .ptype { font-weight: 600; }
    .prob-head .eff { font-weight: 700; }
    .prob-sides { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 14px; margin-top: 6px; }
    .ps { min-width: 0; }
    .ps .who { display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 13px; }
    .ps ul { list-style: none; margin: 2px 0 0; padding: 0; }
    .ps li { overflow-wrap: anywhere; }
    .diffs { margin-top: 8px; font-size: 13.5px; }
    .diffs td, .diffs th { padding: 5px 6px; }
    .when { margin: 6px 0 0; }
    details.more { margin-top: 12px; }
    details.more > summary { cursor: pointer; font-weight: 600; }

    .chat { position: sticky; top: 64px; display: flex; flex-direction: column; max-height: calc(100vh - 84px); }
    .chat h2 svg { width: 18px; height: 18px; color: var(--accent); }
    .msgs { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; min-height: 120px; margin: 8px 0; }
    .msg { padding: 8px 11px; border-radius: 10px; max-width: 95%; overflow-wrap: anywhere; }
    .msg.user { align-self: flex-end; background: var(--accent-soft); }
    .msg.assistant { align-self: flex-start; background: var(--row-hover); }
    .msg.wait { color: var(--muted); font-style: italic; }
    .msg.err { color: var(--bad); }
    .msg p { margin: 0 0 6px; }
    .msg p:last-child { margin-bottom: 0; }
    .msg ul, .msg ol { margin: 0 0 6px; padding-inline-start: 20px; }
    .quick { display: flex; flex-direction: column; gap: 6px; margin-bottom: 8px; }
    .quick[hidden] { display: none; }
    .quick button { text-align: start; font-size: 14px; }
    .chat textarea { font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; width: 100%; resize: vertical; unicode-bidi: plaintext; }
    .ask-row { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; }
    .ask-row button:disabled { opacity: .5; cursor: wait; }

    @media (max-width: 900px) {
        .work { grid-template-columns: 1fr; }
        .chat { position: static; max-height: none; }
        .msgs { max-height: 60vh; }
    }
    @media (max-width: 700px) {
        .sides, .summary, .prob-sides { grid-template-columns: 1fr; }
        .arrow { display: none; }
    }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => ['Alt', 'C'], 'run' => 'Alt+C', 'label' => __('reconcile.keys.compare')])
    @if ($report && $provider)
        @include('partials.key', ['keys' => ['Alt', 'Q'], 'run' => 'Alt+Q', 'label' => __('reconcile.keys.ask')])
    @endif
@endpush

@section('content')
@php
    $T = __('reconcile');
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $signed = fn ($v) => ($v > 0 ? '+' : '').number_format((float) $v, 2, ',', ' ');
    $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '';
    $aBranch = $customer?->branch ?? $branches->firstWhere('id', (int) request('a')) ?? $branches->first();
    $bBranch = $supplier?->branch ?? $branches->firstWhere('id', (int) request('b')) ?? $branches->firstWhere('id', '!=', $aBranch?->id);
    $nameA = $customer?->branch?->name;
    $nameB = $supplier?->branch?->name;
    // les bons du rapport : numero -> lien, pour les {A:101} / {B:7} ecrits par l'IA
    $links = ['A' => [], 'B' => []];
    if ($report) {
        $add = function (array $x, string $side) use (&$links) {
            if (empty($x['payment']) && $x['number'] !== null) {
                $links[$side][$x['number']] ??= route($side === 'A' ? 'sales.show' : 'purchases.show', $x['id']);
            }
        };
        foreach ($report['problems'] as $p) {
            foreach ($p['a'] as $x) { $add($x, 'A'); }
            foreach ($p['b'] as $x) { $add($x, 'B'); }
        }
        foreach ($report['same'] as $s) { $add($s['a'], 'A'); $add($s['b'], 'B'); }
    }
@endphp

<h1 class="rc-title">{{ $T['title'] }}</h1>
<p class="rc-lead muted">{{ $T['lead'] }}</p>

@if ($outdated->isNotEmpty())
    <p class="note muted small">{{ __('reconcile.outdated', ['names' => $outdated->implode('، ')]) }}</p>
@endif

@if ($branches->count() < 2)
    <p class="card">{{ $T['need_two'] }}</p>
@else
<form class="card pick" method="get" action="{{ route('reconcile.index') }}" id="pick">
    <div class="sides">
        @foreach ([['a', 'customer', 'ca', $aBranch, $customer, 'b'], ['b', 'supplier', 'sb', $bBranch, $supplier, 'a']] as [$s, $kind, $field, $branch, $partner, $other])
            @if ($s === 'b')<div class="arrow" aria-hidden="true">→</div>@endif
            <fieldset class="side">
                <legend>{{ $T[$s === 'a' ? 'seller' : 'buyer'] }}</legend>
                <p class="help muted small">{{ $T[$s === 'a' ? 'seller_help' : 'buyer_help'] }}</p>
                <select name="{{ $s }}" id="branch-{{ $s }}" aria-label="{{ $T['branch'] }}">
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}" @selected($branch?->id === $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
                <div class="pp {{ $partner ? 'has' : '' }}" data-kind="{{ $kind }}" data-branch="branch-{{ $s }}" data-other="branch-{{ $other }}">
                    <input type="hidden" name="{{ $field }}" value="{{ $partner?->id }}">
                    <input type="search" autocomplete="off" placeholder="{{ $T[$kind === 'customer' ? 'search_customer' : 'search_supplier'] }}" aria-label="{{ $T[$kind] }}">
                    <div class="chosen"><span class="name">{{ $partner ? ($partner->name ?? $partner->code).' ('.trim($partner->code).')' : '' }}</span>
                        <button type="button" class="link change">{{ $T['change'] }}</button></div>
                    <ul class="hits"></ul>
                </div>
            </fieldset>
        @endforeach
    </div>
    <div class="pick-row">
        <button class="primary" type="submit" data-key="Alt+C">{{ $T['compare'] }}</button>
        <span class="pick-msg small" id="pick-msg" hidden>{{ $T['pick_both'] }}</span>
    </div>
</form>
@endif

@if ($report)
    @php
        $a = $report['a'];
        $b = $report['b'];
        $hueA = $customer->branch->hue();
        $hueB = $supplier->branch->hue();
        $side = fn (string $s) => $s === 'a' ? ['name' => $nameA, 'hue' => $hueA] : ['name' => $nameB, 'hue' => $hueB];
    @endphp
    <section class="summary">
        <div class="card sum">
            <h2><span class="bdot" style="--bh: {{ $hueA }}"></span>{{ __('reconcile.side_a', ['partner' => $a['partner'], 'branch' => $nameA]) }}</h2>
            <dl>
                <dt>{{ $T['sales'] }} ({{ __('reconcile.bons_count', ['count' => $a['bons']]) }})</dt><dd class="num">{{ $money($a['amount']) }}</dd>
                <dt>{{ $T['payments_in'] }}</dt><dd class="num">{{ $money($a['payments']) }}</dd>
                @if ($a['opening'] != 0)<dt class="small">{{ $T['opening'] }}</dt><dd class="num small">{{ $money($a['opening']) }}</dd>@endif
                <dt>{{ $T['balance_a'] }}</dt><dd class="num bal">{{ $money($a['balance']) }}</dd>
            </dl>
            @if ($a['first'])<p class="muted small" style="margin:6px 0 0">{{ __('reconcile.period', ['from' => $date($a['first']), 'to' => $date($a['last'])]) }}</p>@endif
        </div>
        <div class="card gapc {{ abs($report['gap']) < 0.005 && $report['problems'] === [] ? 'ok' : '' }}">
            <div class="muted">{{ $T['gap'] }}</div>
            <div class="big num">{{ $signed($report['gap']) }}</div>
            @if (abs($report['gap']) < 0.005 && $report['problems'] === [])
                <p>{{ $T['agree'] }}</p>
            @else
                <p class="muted small">{{ __('reconcile.gap_help', ['a' => $nameA, 'b' => $nameB]) }}</p>
            @endif
        </div>
        <div class="card sum">
            <h2><span class="bdot" style="--bh: {{ $hueB }}"></span>{{ __('reconcile.side_b', ['partner' => $b['partner'], 'branch' => $nameB]) }}</h2>
            <dl>
                <dt>{{ $T['purchases'] }} ({{ __('reconcile.bons_count', ['count' => $b['bons']]) }})</dt><dd class="num">{{ $money($b['amount']) }}</dd>
                <dt>{{ $T['payments_out'] }}</dt><dd class="num">{{ $money($b['payments']) }}</dd>
                @if ($b['opening'] != 0)<dt class="small">{{ $T['opening'] }}</dt><dd class="num small">{{ $money($b['opening']) }}</dd>@endif
                <dt>{{ $T['balance_b'] }}</dt><dd class="num bal">{{ $money($b['balance']) }}</dd>
            </dl>
            @if ($b['first'])<p class="muted small" style="margin:6px 0 0">{{ __('reconcile.period', ['from' => $date($b['first']), 'to' => $date($b['last'])]) }}</p>@endif
        </div>
    </section>

    <div class="work">
        <section class="card probs">
            <h2>{{ $T['problems'] }}</h2>
            @forelse ($report['problems'] as $p)
                <article class="prob" data-type="{{ $p['type'] }}">
                    <div class="prob-head">
                        <span class="ptype">{{ __('reconcile.types.'.$p['type'], ['a' => $nameA, 'b' => $nameB]) }}</span>
                        <span class="eff num" title="{{ $T['effect'] }}">{{ $signed($p['effect']) }}</span>
                    </div>
                    <div class="prob-sides">
                        @foreach (['a', 'b'] as $s)
                            <div class="ps">
                                <div class="who"><span class="bdot" style="--bh: {{ $side($s)['hue'] }}"></span>{{ $side($s)['name'] }}</div>
                                <ul>
                                    @forelse ($p[$s] as $x)
                                        <li>
                                            @if (! empty($x['payment']))
                                                {{ $x['opening'] ? $T['opening_payment'] : $T['payment'] }} <bdi class="num">#{{ $x['number'] }}</bdi>
                                                · {{ $date($x['date']) }} · <span class="num">{{ $money($x['amount']) }}</span>
                                                @if ($x['type'])<span class="muted small">({{ $x['type'] }})</span>@endif
                                            @else
                                                <a href="{{ route($s === 'a' ? 'sales.show' : 'purchases.show', $x['id']) }}">{{ $T['bon'] }} <bdi class="num">#{{ $x['number'] }}</bdi></a>
                                                · {{ $date($x['date']) }} · <span class="num">{{ $money($x['amount']) }}</span>
                                            @endif
                                        </li>
                                    @empty
                                        <li class="muted">—</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endforeach
                    </div>
                    @if (! empty($p['lines']))
                        <table class="diffs">
                            <thead><tr><th>{{ $T['item'] }}</th><th></th><th>{{ $nameA }}</th><th>{{ $nameB }}</th></tr></thead>
                            <tbody>
                                @foreach ($p['lines'] as $l)
                                    @php
                                        $cell = fn (string $s) => array_key_exists('qty_'.$s, $l)
                                            ? $money($l['qty_'.$s]).(isset($l['price_'.$s]) ? ' × '.$money($l['price_'.$s]) : '').' = '.$money($l['total_'.$s])
                                            : '—';
                                    @endphp
                                    <tr>
                                        <td><span class="code ltr">{{ $l['code'] }}</span> <span class="muted small">{{ $report['names'][$l['code']] ?? '' }}</span></td>
                                        <td class="small">{{ __('reconcile.line.'.$l['kind'], ['a' => $nameA, 'b' => $nameB]) }}</td>
                                        <td class="num small">{{ $cell('a') }}</td>
                                        <td class="num small">{{ $cell('b') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                    @if (! empty($p['days']))
                        <p class="when muted small">{{ __($p['days'] > 0 ? 'reconcile.later' : 'reconcile.earlier', ['b' => $nameB, 'days' => abs($p['days'])]) }}</p>
                    @endif
                </article>
            @empty
                <p class="muted">{{ $T['no_problems'] }}</p>
            @endforelse
            @if ($report['more_problems'])
                <p class="muted small">{{ __('reconcile.more', ['count' => $report['more_problems']]) }}</p>
            @endif

            @if ($report['same'] !== [])
                <details class="more">
                    <summary>{{ __('reconcile.same_bons', ['count' => $report['counts']['same']]) }}</summary>
                    <table>
                        <thead><tr><th>{{ $T['date'] }}</th><th>{{ $nameA }}</th><th>{{ $nameB }}</th><th>{{ $T['amount'] }}</th><th>{{ $T['days'] }}</th></tr></thead>
                        <tbody>
                            @foreach ($report['same'] as $s)
                                <tr>
                                    <td class="num">{{ $date($s['a']['date']) }}</td>
                                    <td><a href="{{ route('sales.show', $s['a']['id']) }}"><bdi class="num">#{{ $s['a']['number'] }}</bdi></a></td>
                                    <td><a href="{{ route('purchases.show', $s['b']['id']) }}"><bdi class="num">#{{ $s['b']['number'] }}</bdi></a></td>
                                    <td class="num">{{ $money($s['amount']) }}</td>
                                    <td class="num">{{ $s['days'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif
            @if ($report['pay_same'] !== [])
                <details class="more">
                    <summary>{{ __('reconcile.same_payments', ['count' => $report['counts']['pay_same']]) }}</summary>
                    <table>
                        <thead><tr><th>{{ $T['date'] }}</th><th>{{ $nameA }}</th><th>{{ $nameB }}</th><th>{{ $T['amount'] }}</th><th>{{ $T['days'] }}</th></tr></thead>
                        <tbody>
                            @foreach ($report['pay_same'] as $s)
                                <tr>
                                    <td class="num">{{ $date($s['a']['date']) }}</td>
                                    <td><bdi class="num">#{{ $s['a']['number'] }}</bdi></td>
                                    <td><bdi class="num">#{{ $s['b']['number'] }}</bdi></td>
                                    <td class="num">{{ $money($s['a']['amount']) }}</td>
                                    <td class="num">{{ $s['days'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif
        </section>

        <aside class="card chat" id="chat">
            <h2>@include('partials.app-icon', ['name' => 'spark']){{ $T['chat']['title'] }}
                @if ($provider)<span class="muted small">{{ __('reconcile.chat.with', ['name' => App\Support\AiProviders::name($provider->provider)]) }}</span>@endif
            </h2>
            @if (! $provider)
                <p class="muted">{{ $T['chat']['no_provider'] }}
                    @can('manage-ai')<a href="{{ route('ai.index') }}">{{ __('ai.title') }}</a>@endcan</p>
            @else
                <p class="muted small" style="margin:0">{{ $T['chat']['privacy'] }}</p>
                <div class="msgs" id="msgs" aria-live="polite"></div>
                <div class="quick" id="quick">
                    @foreach ($T['chat']['quick'] as $q)
                        <button type="button">{{ $q }}</button>
                    @endforeach
                </div>
                <form id="ask">
                    <textarea id="question" rows="2" maxlength="{{ App\Support\ReconciliationChat::MAX_CHARS }}" placeholder="{{ $T['chat']['placeholder'] }}" data-key="Alt+Q" aria-label="{{ $T['chat']['title'] }}"></textarea>
                    <div class="ask-row">
                        <button type="button" class="link" id="clear">{{ $T['chat']['clear'] }}</button>
                        <button class="primary" type="submit" id="send">{{ $T['chat']['send'] }}</button>
                    </div>
                </form>
            @endif
        </aside>
    </div>
@endif
@endsection

@push('scripts')
@php
    // @json coupe son argument aux virgules : les tableaux sont prepares ici
    $jsTexts = ['no_hits' => $T['no_hits'], 'bons' => $T['bons_count'], 'chat' => $T['chat']];
    $jsChat = ['ca' => $customer?->id, 'sb' => $supplier?->id, 'url' => route('reconcile.chat'),
        'names' => ['A' => $nameA, 'B' => $nameB], 'links' => $links, 'max' => App\Support\ReconciliationChat::MAX_MESSAGES];
@endphp
<script>
(() => {
    const T = @json($jsTexts);
    const partnersUrl = @json(route('reconcile.partners'));
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    // ---------- choix du client et du fournisseur ----------
    const form = document.getElementById('pick');
    form && form.querySelectorAll('.pp').forEach(pp => {
        const hidden = pp.querySelector('input[type=hidden]');
        const input = pp.querySelector('input[type=search]');
        const hits = pp.querySelector('.hits');
        const branch = document.getElementById(pp.dataset.branch);
        const other = document.getElementById(pp.dataset.other);
        let ctrl = null, timer = null;

        const pick = p => {
            hidden.value = p.id;
            pp.querySelector('.chosen .name').textContent = p.name + ' (' + p.code + ')';
            pp.classList.add('has');
            hits.innerHTML = '';
        };
        const render = list => {
            hits.innerHTML = '';
            if (!list.length) {
                hits.innerHTML = '<li class="none muted small">' + esc(T.no_hits) + '</li>';
                return;
            }
            list.forEach(p => {
                const li = document.createElement('li');
                const b = document.createElement('button');
                b.type = 'button';
                b.innerHTML = '<span class="nm">' + esc(p.name) + '</span><span class="code ltr small">' + esc(p.code) + '</span>'
                    + '<span class="muted small">' + esc(T.bons.replace(':count', p.bons)) + '</span>';
                b.addEventListener('click', () => pick(p));
                li.append(b);
                hits.append(li);
            });
        };
        const load = async () => {
            ctrl?.abort();
            ctrl = new AbortController();
            const url = new URL(partnersUrl, location.href);
            url.searchParams.set('branch', branch.value);
            url.searchParams.set('kind', pp.dataset.kind);
            url.searchParams.set('near', other.value);
            if (input.value.trim()) url.searchParams.set('q', input.value.trim());
            try {
                const res = await fetchRetry(url, {signal: ctrl.signal, headers: {Accept: 'application/json'}});
                if (res.ok) render(await res.json());
            } catch (e) { /* annule ou hors ligne : la liste reste */ }
        };
        pp.reload = () => { if (!hidden.value) load(); };
        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 250); });
        input.addEventListener('keydown', e => {
            if (e.key === 'ArrowDown' && hits.querySelector('button')) { e.preventDefault(); hits.querySelector('button').focus(); }
        });
        hits.addEventListener('keydown', e => {
            const all = [...hits.querySelectorAll('button')];
            const i = all.indexOf(document.activeElement);
            if (e.key === 'ArrowDown' && i < all.length - 1) { e.preventDefault(); all[i + 1].focus(); }
            if (e.key === 'ArrowUp') { e.preventDefault(); (i > 0 ? all[i - 1] : input).focus(); }
        });
        pp.querySelector('.change').addEventListener('click', () => {
            hidden.value = '';
            pp.classList.remove('has');
            input.value = '';
            input.focus();
            load();
        });
        branch.addEventListener('change', () => {
            hidden.value = '';
            pp.classList.remove('has');
            load();
            // l'autre cote propose les noms qui ressemblent a cette branche
            form.querySelectorAll('.pp').forEach(o => { if (o !== pp) o.reload?.(); });
        });
        if (!hidden.value) load();
    });
    form?.addEventListener('submit', e => {
        const empty = [...form.querySelectorAll('.pp input[type=hidden]')].some(h => !h.value);
        document.getElementById('pick-msg').hidden = !empty;
        if (empty) e.preventDefault();
    });

    // ---------- chat ----------
    const chat = document.getElementById('ask');
    if (!chat) return;
    const R = @json($jsChat);
    const KEY = 'reconcile:' + R.ca + ':' + R.sb;
    const msgs = document.getElementById('msgs');
    const quick = document.getElementById('quick');
    const box = document.getElementById('question');
    const send = document.getElementById('send');
    const token = document.querySelector('meta[name=csrf-token]').content;
    let history = [];
    try { history = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { history = []; }
    const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(history)); } catch (e) {} };

    // le Markdown simple que l'IA ecrit (paragraphes, listes, gras), et ses {A} / {A:101} remis en noms et en liens
    const bon = (side, n) => {
        const label = '<bdi>' + esc(R.names[side]) + ' #' + esc(n) + '</bdi>';
        const href = (R.links[side] || {})[n];
        return href ? '<a href="' + esc(href) + '">' + label + '</a>' : label;
    };
    // les noms, liens et codes sont mis de cote pendant qu'on isole les montants ("46 600,00" se lirait "600,00 46" en arabe)
    const inline = s => {
        const kept = [];
        const hold = html => '\u0000' + (kept.push(html) - 1) + '\u0000';
        return esc(s)
            .replace(/\{([AB]):(\d+)\}/g, (m, side, n) => hold(bon(side, n)))
            .replace(/\{([AB])\}/g, (m, side) => hold('<b>' + esc(R.names[side]) + '</b>'))
            .replace(/`([^`]+)`/g, (m, code) => hold('<code class="ltr">' + code + '</code>'))
            .replace(/[-+−]?\d{1,3}(?:[   ]\d{3})+(?:[.,]\d+)?|[-+−]?\d+[.,]\d+/g, m => '<span class="num">' + m + '</span>')
            .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
            .replace(/\u0000(\d+)\u0000/g, (m, i) => kept[i]);
    };
    const md = text => {
        let html = '', list = null;
        const close = () => { if (list) { html += '</' + list + '>'; list = null; } };
        text.split(/\n/).forEach(line => {
            const item = line.match(/^\s*(?:([-*•])|(\d+)[.)])\s+(.*)$/);
            if (item) {
                const kind = item[1] ? 'ul' : 'ol';
                if (list !== kind) { close(); html += '<' + kind + '>'; list = kind; }
                html += '<li>' + inline(item[3]) + '</li>';
            } else if (line.trim() === '') {
                close();
            } else {
                close();
                html += '<p>' + inline(line.replace(/^#+\s*/, '')) + '</p>';
            }
        });
        close();
        return html;
    };
    const bubble = (role, text, extra) => {
        const d = document.createElement('div');
        d.className = 'msg ' + role + (extra ? ' ' + extra : '');
        if (role === 'assistant' && !extra) d.innerHTML = md(text);
        else d.textContent = text;
        return d;
    };
    const render = () => {
        msgs.innerHTML = '';
        history.forEach(m => msgs.append(bubble(m.role, m.content)));
        quick.hidden = history.length > 0;
        msgs.scrollTop = msgs.scrollHeight;
    };

    let busy = false;
    const ask = async text => {
        text = text.trim();
        if (busy || !text) return;
        busy = true;
        send.disabled = true;
        history.push({role: 'user', content: text});
        save();
        render();
        box.value = '';
        const wait = bubble('assistant', T.chat.thinking, 'wait');
        msgs.append(wait);
        msgs.scrollTop = msgs.scrollHeight;
        try {
            // fetch simple, sans fetchRetry : une question refaite serait payee deux fois
            const res = await fetch(R.url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token},
                body: JSON.stringify({ca: R.ca, sb: R.sb, messages: history.slice(-R.max)}),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.text) throw new Error(data.error || data.message || T.chat.failed);
            history.push({role: 'assistant', content: data.text + (data.truncated ? '\n\n' + T.chat.truncated : '')});
            save();
            render();
        } catch (e) {
            // la question revient dans la case : rien n'est garde d'un echange rate
            history.pop();
            save();
            render();
            box.value = text;
            msgs.append(bubble('assistant', e.message && e.message !== 'Failed to fetch' ? e.message : T.chat.failed, 'err'));
        } finally {
            busy = false;
            send.disabled = false;
        }
    };

    chat.addEventListener('submit', e => { e.preventDefault(); ask(box.value); });
    box.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(box.value); }
    });
    quick.querySelectorAll('button').forEach(b => b.addEventListener('click', () => ask(b.textContent)));
    document.getElementById('clear').addEventListener('click', () => { history = []; save(); render(); box.focus(); });
    render();
})();
</script>
@endpush
