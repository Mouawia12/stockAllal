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

    /* le rapport prend toute la largeur ; le chat ouvert (Alt+Q) : une colonne a cote */
    .work.chat-on { display: grid; grid-template-columns: minmax(0, 1fr) 400px; gap: 16px; align-items: start; }
    .main > * + * { margin-top: 14px; }
    .chat h2 { font-size: 16px; margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }
    .chat h2 .grow { flex: 1; }
    .chat-btn svg { width: 16px; height: 16px; color: var(--accent); vertical-align: -3px; margin-inline-end: 6px; }
    .chat-btn[aria-expanded=true] { border-color: var(--accent); background: var(--accent-soft); }
    .diffs { font-size: 13.5px; }
    .diffs td, .diffs th { padding: 5px 6px; }
    details.more { margin-top: 10px; }
    details.more > summary { cursor: pointer; font-weight: 600; }

    /* d'ou vient l'ecart */
    .where h2, .extra-card h2 { font-size: 16px; margin: 0 0 8px; }
    .wt th[scope=row] { color: var(--text); font-weight: 500; font-size: 14.5px; white-space: normal; }
    .wt a.jump { color: var(--text); }
    .wt a.jump:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    .wt td.cnt { width: 56px; text-align: center; }
    .wt td.barc { width: 26%; }
    .wb { height: 8px; border-radius: 99px; background: var(--accent); opacity: .55; min-width: 2px; }
    .wt td.eff, .pt td.eff { text-align: end; font-weight: 700; white-space: nowrap; }
    .wt tfoot th, .wt tfoot td { border-bottom: 0; }
    .wt tr.tot th, .wt tr.tot td { border-top: 2px solid var(--border); font-weight: 800; font-size: 16px; color: var(--text); }

    /* la recherche dans le rapport, qui reste en haut */
    .pf { position: sticky; top: 64px; z-index: 3; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 10px 12px;
        background: var(--card); border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow); }
    .pf input[type=search] { flex: 1 1 260px; min-width: 0; font: inherit; color: var(--text); background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; }
    .chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .chip { display: inline-flex; align-items: center; gap: 8px; padding: 4px 12px; border-radius: 99px; font-size: 14px; }
    .chip .n { font-variant-numeric: tabular-nums; font-size: 12.5px; color: var(--muted); background: var(--row-hover); border-radius: 99px; padding: 0 8px; margin-inline-end: -6px; }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .chip.on .n { background: var(--card); color: var(--accent); }
    .pf-none { margin: 0; }

    /* un groupe : son titre, ce qu'il faut faire, ses problemes en tableau */
    .grp { padding: 0; }
    .grp > details > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 12px 16px; }
    .grp > details > summary::-webkit-details-marker { display: none; }
    .grp > details > summary::before { content: ''; width: 7px; height: 7px; border: solid var(--muted); border-width: 0 2px 2px 0; transform: rotate(-45deg); transition: transform .15s; flex: none; }
    [dir=rtl] .grp > details > summary::before { transform: rotate(135deg); }
    .grp > details[open] > summary::before { transform: rotate(45deg); }
    .grp > details[open] > summary { border-bottom: 1px solid var(--border); }
    .grp .gt { font-weight: 700; font-size: 15.5px; }
    .grp .grow { flex: 1; }
    .grp .ge { font-weight: 800; font-size: 16px; white-space: nowrap; }
    .gdo { margin: 0; padding: 10px 16px; color: var(--muted); background: var(--row-hover); }
    .tw { overflow-x: auto; }
    .pt { font-size: 14px; }
    .pt th:first-child, .pt td:first-child { padding-inline-start: 16px; }
    .pt tbody tr:last-of-type > td { border-bottom: 0; }
    .pt tr.pr { cursor: pointer; }
    .pt tr.pr:hover > td { background: var(--row-hover); }
    .pt tr.pr:focus { outline: 2px solid var(--accent); outline-offset: -2px; }
    .pt tr.pr:focus > td { background: var(--accent-soft); }
    .pt td.refs > div { white-space: nowrap; }
    .pt td.refs .num.muted { font-size: 13px; margin-inline-start: 4px; }
    .pt td.what { min-width: 140px; }
    a.bref { color: var(--text); font-weight: 600; }
    a.bref:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    .nowrap { white-space: nowrap; }

    /* la fenetre rapide d'un probleme : ses bons cote a cote ; le bon lui-meme : documents/_bon-style */
    .modal-bg { position: fixed; inset: 0; background: rgba(0, 0, 0, .45); display: none; align-items: flex-start; justify-content: center; padding: 4vh 12px; z-index: 10; overflow: auto; }
    .modal-bg.open { display: flex; }
    .modal { width: 100%; max-width: 1400px; padding: 12px 18px 12px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow), 0 18px 44px rgba(0, 0, 0, .2); }
    .modal:focus { outline: none; }
    .modal.single { max-width: 920px; }
    .modal-head { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
    .modal-head .grow { flex: 1; }
    .rm .step { display: grid; place-items: center; width: 34px; height: 34px; padding: 0; border-radius: 50%; color: var(--muted); }
    .rm .step:hover:not(:disabled) { color: var(--text); }
    .rm .step:disabled { opacity: .35; cursor: default; }
    .rm .step svg { width: 18px; height: 18px; }
    #rm-body { transition: opacity .12s; }
    #rm-body.loading { opacity: .45; }
    .rm-info { margin-bottom: 14px; }
    .rm-info p { margin: 4px 0 0; }
    .rm-top { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; }
    .rm-title { font-size: 18px; margin: 0; flex: 1; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .rm-eff b { font-size: 20px; }
    .rm-info .tw { margin-top: 8px; border: 1px solid var(--border); border-radius: 8px; }
    .rm-info .diffs tbody tr:last-child td { border-bottom: 0; }
    .rm-cols { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; align-items: start; }
    .rm-cols.one { grid-template-columns: minmax(0, 1fr) minmax(200px, .35fr); }
    .rm-cols.single { grid-template-columns: minmax(0, 1fr); }
    .rm-col { display: grid; gap: 14px; min-width: 0; }
    .rm-item { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; min-width: 0; overflow-x: auto; }
    .rm-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
    .rm-bar .grow { flex: 1; }
    .rm-bar a.open-bon { padding: 4px 12px; font-size: 13.5px; background: var(--accent); border-color: var(--accent); color: var(--on-accent); font-weight: 600; }
    .rm-none { margin: 4px 0; padding: 22px 10px; text-align: center; color: var(--muted); border: 2px dashed var(--border); border-radius: 8px; }
    .rm-pay { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; }
    .rm-pay dt { color: var(--muted); }
    .rm-pay dd { margin: 0; }
    .rm-bon .bon-head { grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr); gap: 12px; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
    .rm-bon .bon-number { font-size: 32px; }
    .rm-bon .lines { font-size: 13.5px; }
    .rm-bon .lines td, .rm-bon .lines th { padding: 6px 7px; }
    /* les lignes qui different entre les deux cotes */
    .rm-bon .lines tr.hit > td { background: var(--accent-soft); }
    .rm-bon .lines tr.hit > td:first-child { box-shadow: inset 3px 0 0 var(--accent); }
    [dir=rtl] .rm-bon .lines tr.hit > td:first-child { box-shadow: inset -3px 0 0 var(--accent); }
    .rm-hint { margin: 10px 0 0; text-align: center; }
    @media (max-width: 1000px) { .rm-cols, .rm-cols.one { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 700px), (hover: none) { .rm-hint { display: none; } }

    .chat { position: sticky; top: 64px; display: flex; flex-direction: column; max-height: calc(100vh - 84px); }
    .chat[hidden] { display: none; }
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

    /* l'analyse, etape par etape : chaque ligne dit ce que l'etape a trouve */
    .analysis h2 { font-size: 17px; margin: 0 0 4px; }
    .analysis .bar { height: 10px; border-radius: 99px; background: var(--row-hover); overflow: hidden; margin: 14px 0; }
    .analysis .bar > span { display: block; height: 100%; width: 0; background: var(--accent); border-radius: 99px; transition: width .5s; }
    .steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
    .steps li { display: grid; grid-template-columns: 22px 1fr auto; gap: 2px 10px; align-items: start; padding: 8px 10px; border-radius: 8px; }
    .steps .ic { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--border); margin-top: 3px; }
    .steps li.running { background: var(--accent-soft); }
    .steps li.running .ic { border-color: var(--accent); border-top-color: transparent; animation: spin .8s linear infinite; }
    .steps li.ok .ic { background: var(--accent); border-color: var(--accent); }
    .steps li.err .ic, .steps li.warn .ic { border-color: var(--bad); }
    .steps .lbl { font-weight: 600; }
    .steps .note { grid-column: 2 / 4; color: var(--muted); font-size: 13.5px; }
    .steps li.err .note { color: var(--bad); }
    .steps .t { color: var(--muted); font-size: 12.5px; }
    .analysis .end { margin: 12px 0 0; }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .steps li.running .ic { animation: none; } .analysis .bar > span { transition: none; } }

    .ran { display: flex; gap: 10px; align-items: center; justify-content: flex-end; flex-wrap: wrap; margin: -6px 0 12px; }
    .ran .history .drop-menu { min-width: 320px; max-height: 340px; overflow-y: auto; }
    .ran .history .drop-menu a { display: flex; gap: 8px; align-items: baseline; padding: 7px 10px; border-radius: 8px; color: var(--text); }
    .ran .history .drop-menu a:hover, .ran .history .drop-menu a:focus-visible { background: var(--row-hover); }
    .ran .history .drop-menu a.on { background: var(--accent-soft); font-weight: 600; }
    .ran .history .grow { flex: 1; }
    .btn.primary-soft { border-color: var(--accent); color: var(--accent); font-weight: 600; }
    .old-run { margin: 0 0 12px; padding: 8px 12px; border-radius: 8px; background: var(--accent-soft); }
    .recent { margin-bottom: 18px; }
    .recent h2, .since h2 { font-size: 16px; margin: 0 0 10px; }
    .since { margin-bottom: 16px; }
    .since-row { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center; }
    /* de l'ancien ecart au nouveau : la fleche suit le sens de lecture */
    .flip { display: inline-block; }
    [dir=rtl] .flip { transform: scaleX(-1); }
    /* "nouveau" : la couleur de l'accent, jamais le rouge ni le jaune (couleurs du stock) */
    .pill.new { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .prob-head .pill.st { margin-inline-start: 6px; font-weight: 500; }
    details.fixed ul { margin: 6px 0 0; padding-inline-start: 18px; display: grid; gap: 4px; }
    .ai-sum { margin-bottom: 16px; }
    .ai-sum h2 { font-size: 16px; margin: 0 0 8px; display: flex; gap: 8px; align-items: center; }
    .ai-sum h2 svg { width: 18px; height: 18px; color: var(--accent); }
    .ai-sum .body p { margin: 0 0 8px; }
    .ai-sum .body ul, .ai-sum .body ol { margin: 0 0 8px; padding-inline-start: 22px; }
    .ai-sum .body li, .msg li { margin-bottom: 3px; }
    .ai-sum .body li > ul, .ai-sum .body li > ol, .msg li > ul, .msg li > ol { margin: 4px 0 2px; }
    /* une longue conclusion : le debut, puis "اقرأ الكل" */
    .ai-sum .body.clamp { max-height: 330px; overflow: hidden; -webkit-mask-image: linear-gradient(to bottom, #000 70%, transparent); mask-image: linear-gradient(to bottom, #000 70%, transparent); }
    .ai-sum .body h3, .msg h3 { font-size: 15px; margin: 14px 0 6px; }
    .ai-sum .body h3:first-child, .msg h3:first-child { margin-top: 0; }
    .ai-sum .body hr, .msg hr { border: 0; border-top: 1px solid var(--border); margin: 12px 0; }
    .ai-sum .body table, .msg table { margin: 4px 0 10px; font-size: 13.5px; }
    .ai-sum .body td, .ai-sum .body th, .msg td, .msg th { padding: 5px 8px; }
    .more-btn { margin-top: 6px; font-weight: 600; }
    .extra { margin: 6px 0 0; font-size: 13.5px; }
    .tl td.barc { width: 40%; }
    .tl .tb { height: 8px; border-radius: 99px; background: var(--accent); opacity: .55; min-width: 2px; }

    @media (max-width: 1100px) {
        .work.chat-on { display: block; }
        /* le chat en bas de l'ecran, par-dessus le rapport */
        .chat { position: fixed; inset: auto 0 0 0; max-height: 72vh; z-index: 9; border-radius: 12px 12px 0 0; box-shadow: var(--shadow), 0 -10px 30px rgba(0, 0, 0, .18); }
    }
    @media (max-width: 700px) {
        .sides, .summary, .rm-bon .bon-head { grid-template-columns: 1fr; }
        .arrow { display: none; }
    }
</style>
@include('documents._bon-style')
@endpush

@push('keys')
    @include('partials.key', ['keys' => ['Alt', 'C'], 'run' => 'Alt+C', 'label' => __('reconcile.keys.compare')])
    @if ($report)
        @if ($report['problems'] !== [])
            @include('partials.key', ['keys' => ['F3'], 'run' => 'F3', 'label' => __('reconcile.keys.filter')])
            @include('partials.key', ['keys' => ['↑', '↓'], 'label' => __('reconcile.keys.move')])
        @endif
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
            // le bon trouve sous un autre partenaire est de l'autre cote ; le jumeau d'un doublon, du meme cote
            if (isset($p['other'])) { $add($p['other'], $p['type'] === 'bon_elsewhere_b' ? 'B' : 'A'); }
            if (isset($p['twin'])) { $add($p['twin'], $p['type'] === 'bon_duplicate_a' ? 'A' : 'B'); }
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

{{-- les dernieres paires analysees (gardees) : on y revient en un clic --}}
@if ($recent->isNotEmpty())
    <section class="card recent">
        <h2>{{ $T['recent'] }}</h2>
        <table>
            <thead><tr><th>{{ $T['seller'] }}</th><th>{{ $T['buyer'] }}</th><th>{{ $T['gap'] }}</th><th class="hide-sm">{{ $T['count'] }}</th><th>{{ $T['checked_on'] }}</th></tr></thead>
            <tbody>
                @foreach ($recent as $r)
                    @php
                        $open = route('reconcile.index', ['a' => $r->customer->branch_id, 'ca' => $r->customer_id, 'b' => $r->supplier->branch_id, 'sb' => $r->supplier_id]);
                    @endphp
                    <tr>
                        <td><a href="{{ $open }}"><bdi>{{ $r->customer->name ?? $r->customer->code }}</bdi></a> <span class="muted small">— {{ $r->customer->branch->name }}</span></td>
                        <td><bdi>{{ $r->supplier->name ?? $r->supplier->code }}</bdi> <span class="muted small">— {{ $r->supplier->branch->name }}</span></td>
                        <td class="num">{{ $signed($r->gap) }}</td>
                        <td class="num hide-sm">{{ $r->problems }}</td>
                        <td class="small muted">{{ $r->created_at->diffForHumans() }}@if ($r->user) · {{ $r->user->name }}@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif

@if ($analyze)
    <section class="card analysis" id="analysis">
        <h2>{{ $T['analysis']['title'] }}</h2>
        <p class="muted small" style="margin:0">{{ $T['analysis']['lead'] }}</p>
        <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
        <ol class="steps">
            @foreach ($steps as $s)
                <li data-step="{{ $s }}"><span class="ic" aria-hidden="true"></span><span class="lbl">{{ $T['steps'][$s] }}</span><span class="t num"></span><span class="note"></span></li>
            @endforeach
        </ol>
        <p class="end muted" id="analysis-end" hidden></p>
        <p class="end" id="analysis-retry" hidden><button type="button" class="primary">{{ $T['analysis']['retry'] }}</button></p>
    </section>
@endif

@if ($report)
    @php
        $a = $report['a'];
        $b = $report['b'];
        $hueA = $customer->branch->hue();
        $hueB = $supplier->branch->hue();
        $side = fn (string $s) => $s === 'a' ? ['name' => $nameA, 'hue' => $hueA] : ['name' => $nameB, 'hue' => $hueB];
        $ai = $report['ai'] ?? null;
    @endphp
    @php
        $pairUrl = fn (array $more = []) => route('reconcile.index', ['a' => $customer->branch_id, 'ca' => $customer->id, 'b' => $supplier->branch_id, 'sb' => $supplier->id] + $more);
    @endphp
    @if ($run->id !== $latest)
        <p class="old-run">{{ __('reconcile.history.old', ['time' => $run->created_at->format('d/m/Y H:i')]) }}
            <a href="{{ $pairUrl() }}">{{ $T['history']['latest'] }}</a></p>
    @endif
    <div class="ran small">
        <span class="muted">{{ __('reconcile.analysis.ago', ['time' => $run->created_at->diffForHumans()]) }}@if ($run->user) · {{ $run->user->name }}@endif
            @if ($run->synced) · <span title="{{ $T['history']['synced_help'] }}">{{ $T['history']['synced'] }}</span>@endif</span>
        {{-- les analyses gardees de la paire (les 30 dernieres) --}}
        @if ($runs->count() > 1)
            <details class="drop history">
                <summary class="btn">{{ __('reconcile.history.title', ['count' => $runs->count()]) }}</summary>
                <div class="drop-menu">
                    @foreach ($runs as $h)
                        <a href="{{ $pairUrl(['r' => $h->id]) }}" @class(['on' => $h->id === $run->id])>
                            <span class="num">{{ $h->created_at->format('d/m/Y H:i') }}</span>
                            <span class="grow"></span>
                            <span class="num">{{ $signed($h->gap) }}</span>
                            <span class="muted">· {{ trans_choice('reconcile.history.problems', $h->problems, ['count' => $h->problems]) }}</span>
                            @if ($h->synced)<span class="muted" title="{{ $T['history']['synced_help'] }}">⟳</span>@endif
                        </a>
                    @endforeach
                </div>
            </details>
        @endif
        <button type="button" class="btn chat-btn" id="chat-toggle" aria-controls="chat" aria-expanded="false" data-key="Alt+Q" data-key-fallback>@include('partials.app-icon', ['name' => 'spark']){{ $T['chat_open'] }}</button>
        <a class="btn primary-soft" href="{{ $pairUrl(['again' => 1, 'sync' => 1]) }}" data-key="Alt+R" title="{{ $T['analysis']['recheck_help'] }}">⟳ {{ $T['analysis']['recheck'] }}</a>
    </div>
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

    {{-- depuis le fichier precedent de la paire : ce qui a ete corrige, ce qui reste, ce qui est nouveau --}}
    @if ($since = $report['since'] ?? null)
        <section class="card since">
            <h2>{{ __('reconcile.since.title', ['time' => \Illuminate\Support\Carbon::parse($since['previous_at'])->format('d/m/Y H:i')]) }}</h2>
            <div class="since-row">
                <span>{{ $T['since']['gap'] }} <span class="num">{{ $signed($since['gap']) }}</span> <span class="flip" aria-hidden="true">→</span> <b class="num">{{ $signed($report['gap']) }}</b></span>
                <span class="pill ok">{{ trans_choice('reconcile.since.fixed', $since['fixed'], ['count' => $since['fixed']]) }}</span>
                <span class="pill">{{ trans_choice('reconcile.since.still', $since['still'], ['count' => $since['still']]) }}</span>
                @if ($since['changed'])<span class="pill">{{ trans_choice('reconcile.since.changed', $since['changed'], ['count' => $since['changed']]) }}</span>@endif
                @if ($since['new'])<span class="pill new">{{ trans_choice('reconcile.since.new', $since['new'], ['count' => $since['new']]) }}</span>@endif
                @if (! empty($since['previous_id']))
                    <a class="small" href="{{ $pairUrl(['r' => $since['previous_id']]) }}">{{ $T['since']['open_previous'] }}</a>
                @endif
            </div>
        </section>
    @endif

    @php
        $groups = App\Support\Reconciliation::groups($report);
        $listed = round(array_sum(array_column($groups, 'effect')), 2);
        $peakG = max([1, ...array_map(fn ($g) => abs($g['effect']), $groups)]);
        $url = fn (string $s, array $x) => route($s === 'a' ? 'sales.show' : 'purchases.show', $x['id']);
        // un code de A -> ses codes chez B (appris par l'analyse) : les lignes a colorer dans le bon de B
        $codesB = [];
        foreach ($report['codes'] ?? [] as $c) {
            $codesB[(string) $c['a']][] = $c['b'];
        }
        $statusCount = array_count_values(array_filter(array_column($report['problems'], 'status')));
        $hasStatus = $statusCount !== [];
        $nProblems = count($report['problems']) + $report['more_problems'];
    @endphp

    <div class="work" id="work">
    <div class="main">
    {{-- d'ou vient l'ecart : chaque groupe, son nombre et son effet ; le total est l'ecart --}}
    @if ($groups !== [])
        <section class="card where">
            <h2>{{ $T['where'] }}</h2>
            <table class="wt">
                <tbody>
                    @foreach ($groups as $g)
                        <tr>
                            <th scope="row"><a href="#g-{{ $g['key'] }}" class="jump">{{ __("reconcile.groups.{$g['key']}.title", ['a' => $nameA, 'b' => $nameB]) }}</a></th>
                            <td class="num cnt">{{ $g['count'] }}</td>
                            <td class="barc hide-sm"><div class="wb" style="width: {{ round(100 * abs($g['effect']) / $peakG) }}%"></div></td>
                            <td class="num eff">@if (abs($g['effect']) < 0.005)<span class="muted small">{{ $T['no_effect'] }}</span>@else{{ $signed($g['effect']) }}@endif</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    @if (abs($report['explained'] - $listed) >= 0.005)
                        <tr><th scope="row">{{ $T['where_more'] }}</th><td class="num cnt">{{ $report['more_problems'] }}</td><td class="hide-sm"></td><td class="num eff">{{ $signed($report['explained'] - $listed) }}</td></tr>
                    @endif
                    @if (abs($report['gap'] - $report['explained']) >= 0.005)
                        <tr><th scope="row">{{ $T['where_left'] }}</th><td></td><td class="hide-sm"></td><td class="num eff">{{ $signed($report['gap'] - $report['explained']) }}</td></tr>
                    @endif
                    <tr class="tot"><th scope="row">{{ $T['where_total'] }}</th><td class="num cnt">{{ $nProblems }}</td><td class="hide-sm"></td><td class="num eff">{{ $signed($report['gap']) }}</td></tr>
                </tfoot>
            </table>
        </section>
    @endif

    @if (! empty($ai['text']))
        <section class="card ai-sum">
            <h2>@include('partials.app-icon', ['name' => 'spark']){{ $T['summary'] }}
                <span class="muted small">{{ App\Support\AiProviders::name($ai['provider']) }}</span></h2>
            {{-- le texte de l'IA : mis en forme par le script (Markdown simple, {A} et {A:101} en noms et liens) --}}
            <div class="body" id="ai-summary" data-text="{{ $ai['text'] }}">{{ $ai['text'] }}</div>
            <button type="button" class="link more-btn" id="ai-more" hidden data-less="{{ $T['read_less'] }}">{{ $T['read_all'] }}</button>
            @if (! empty($ai['truncated']))<p class="muted small">{{ $T['chat']['truncated'] }}</p>@endif
        </section>
    @elseif (! empty($ai['error']))
        <p class="note muted">{{ __('reconcile.summary_failed', ['error' => $ai['error']]) }}</p>
    @endif

    {{-- les problemes par groupe ; un clic sur une ligne (ou un bon) : la fenetre rapide, les bons des deux cotes --}}
    @if ($report['problems'] !== [])
        <div class="pf" id="pf">
            <input type="search" id="pf-q" placeholder="{{ $T['filter'] }}" aria-label="{{ $T['filter'] }}" autocomplete="off" data-key="F3">
            @if ($hasStatus)
                <div class="chips" role="group">
                    <button type="button" class="chip on" data-status="" aria-pressed="true">{{ $T['filter_all'] }} <span class="n">{{ count($report['problems']) }}</span></button>
                    @foreach (['new', 'changed', 'still'] as $st)
                        @if (! empty($statusCount[$st]))
                            <button type="button" class="chip" data-status="{{ $st }}" aria-pressed="false">{{ $T['status'][$st] }} <span class="n">{{ $statusCount[$st] }}</span></button>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
        <p class="muted pf-none" id="pf-none" hidden>{{ $T['no_match'] }}</p>
        @foreach ($groups as $g)
            <section class="card grp" id="g-{{ $g['key'] }}">
                <details @if ($g['key'] !== 'lines') open @endif>
                    <summary>
                        <span class="gt">{{ __("reconcile.groups.{$g['key']}.title", ['a' => $nameA, 'b' => $nameB]) }}</span>
                        <span class="pill gc">{{ $g['count'] }}</span>
                        <span class="grow"></span>
                        <span class="num ge">@if (abs($g['effect']) < 0.005)<span class="muted small">{{ $T['no_effect'] }}</span>@else{{ $signed($g['effect']) }}@endif</span>
                    </summary>
                    @if ($do = __("reconcile.groups.{$g['key']}.do", ['a' => $nameA, 'b' => $nameB]))
                        <p class="gdo small">{{ $do }}</p>
                    @endif
                    <div class="tw">
                        <table class="pt">
                            <thead><tr>
                                <th>{{ $T['col_effect'] }}</th>
                                <th>{{ $T['date'] }}</th>
                                <th><span class="bdot" style="--bh: {{ $hueA }}"></span> {{ $nameA }}</th>
                                <th><span class="bdot" style="--bh: {{ $hueB }}"></span> {{ $nameB }}</th>
                                <th class="hide-sm">{{ $T['col_what'] }}</th>
                                @if ($hasStatus)<th></th>@endif
                            </tr></thead>
                            <tbody>
                                @foreach ($g['problems'] as $i => $p)
                                    @include('reconcile._problem')
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </section>
        @endforeach
        @if ($report['more_problems'])
            <p class="muted small">{{ __('reconcile.more', ['count' => $report['more_problems']]) }}</p>
        @endif
    @else
        <section class="card"><p class="muted" style="margin:0">{{ $T['no_problems'] }}</p></section>
    @endif

    {{-- le reste de l'analyse : ce qu'elle a fait, quand l'ecart est apparu, les codes, ce qui est corrige, ce qui est identique --}}
    <section class="card extra-card">
        <h2>{{ $T['details'] }}</h2>
        @if ($report['steps'] ?? [])
            <details class="more">
                <summary>{{ $T['checked'] }}</summary>
                <ul class="small">
                    @foreach ($report['steps'] as $step => $found)
                        <li><b>{{ $T['steps'][$step] ?? $step }}</b> — <span class="muted">{{ __("reconcile.found.{$step}", $found) }}</span></li>
                    @endforeach
                    @if (! empty($ai['text']))
                        <li><b>{{ $T['steps']['ai'] }}</b> — <span class="muted">{{ __('reconcile.found.ai', ['name' => App\Support\AiProviders::name($ai['provider'])]) }}</span></li>
                    @endif
                </ul>
            </details>
        @endif
        @if ($report['timeline'] ?? [])
            @php
                $peak = max(array_map(fn ($m) => abs($m['effect']), $report['timeline'])) ?: 1;
            @endphp
            <details class="more tl">
                <summary>{{ $T['timeline'] }}</summary>
                <table>
                    <thead><tr><th>{{ $T['month'] }}</th><th>{{ $T['count'] }}</th><th>{{ $T['month_effect'] }}</th><th class="hide-sm"></th><th>{{ $T['running'] }}</th></tr></thead>
                    <tbody>
                        @foreach ($report['timeline'] as $m)
                            <tr>
                                <td class="num">{{ $m['month'] === '?' ? '—' : \Illuminate\Support\Carbon::parse($m['month'].'-01')->format('m/Y') }}</td>
                                <td class="num">{{ $m['count'] }}</td>
                                <td class="num">{{ $signed($m['effect']) }}</td>
                                <td class="barc hide-sm"><div class="tb" style="width: {{ round(100 * abs($m['effect']) / $peak) }}%"></div></td>
                                <td class="num">{{ $signed($m['running']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif
        @if ($report['codes'] ?? [])
            <details class="more">
                <summary>{{ __('reconcile.codes', ['count' => count($report['codes'])]) }}</summary>
                <p class="muted small">{{ $T['codes_help'] }}
                    @can('merge.manage')<a href="{{ route('merge.index') }}">{{ __('common.nav.merge') }}</a>@endcan</p>
                <table>
                    <thead><tr><th>{{ $nameA }}</th><th>{{ $nameB }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($report['codes'] as $c)
                            <tr>
                                <td><span class="code ltr">{{ $c['a'] }}</span> <span class="muted small">{{ $c['name_a'] }}</span></td>
                                <td><span class="code ltr">{{ $c['b'] }}</span> <span class="muted small">{{ $c['name_b'] }}</span></td>
                                <td class="small muted">{{ $T['how'][$c['how']] ?? $c['how'] }} · {{ __('reconcile.used_in', ['count' => $c['bons']]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif
        @if ($report['fixed'] ?? [])
            <details class="more fixed">
                <summary>{{ __('reconcile.since.fixed_list', ['count' => count($report['fixed'])]) }}</summary>
                <ul class="small">
                    @foreach ($report['fixed'] as $p)
                        <li>
                            <b>{{ __('reconcile.types.'.$p['type'], ['a' => $nameA, 'b' => $nameB]) }}</b>
                            <span class="num muted">({{ $signed($p['effect']) }})</span> —
                            @foreach (['a', 'b'] as $s)
                                @foreach ($p[$s] as $x)
                                    @if (empty($x['payment']))
                                        <a class="bref" href="{{ $url($s, $x) }}">{{ $side($s)['name'] }} <bdi class="num">#{{ $x['number'] }}</bdi></a>
                                    @else
                                        {{ $side($s)['name'] }} {{ $T['payment'] }} <bdi class="num">#{{ $x['number'] }}</bdi>
                                    @endif
                                    <span class="muted">{{ $date($x['date']) }}</span>
                                @endforeach
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
        @if ($report['same'] !== [])
            <details class="more">
                <summary>{{ __('reconcile.same_bons', ['count' => $report['counts']['same']]) }}</summary>
                @if ($report['counts']['late'] ?? 0)
                    <p class="muted small">{{ __('reconcile.late_count', ['count' => $report['counts']['late']]) }}</p>
                @endif
                <table>
                    <thead><tr><th>{{ $T['date'] }}</th><th>{{ $nameA }}</th><th>{{ $nameB }}</th><th>{{ $T['amount'] }}</th><th>{{ $T['days'] }}</th></tr></thead>
                    <tbody>
                        @foreach ($report['same'] as $s)
                            <tr>
                                <td class="num">{{ $date($s['a']['date']) }}</td>
                                <td><a class="bref" href="{{ route('sales.show', $s['a']['id']) }}"><bdi class="num">#{{ $s['a']['number'] }}</bdi></a></td>
                                <td><a class="bref" href="{{ route('purchases.show', $s['b']['id']) }}"><bdi class="num">#{{ $s['b']['number'] }}</bdi></a></td>
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
    </div>

    {{-- le chat : une colonne qui s'ouvre a la demande (Alt+Q), le rapport garde toute la largeur sinon --}}
    <aside class="card chat" id="chat" hidden>
        <h2>@include('partials.app-icon', ['name' => 'spark']){{ $T['chat']['title'] }}
            @if ($provider)<span class="muted small">{{ __('reconcile.chat.with', ['name' => App\Support\AiProviders::name($provider->provider)]) }}</span>@endif
            <span class="grow"></span>
            <button type="button" class="link chat-x" id="chat-x" title="{{ $T['chat_close'] }}" aria-label="{{ $T['chat_close'] }}">✕</button>
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

    {{-- la fenetre rapide : le probleme et ses bons des deux cotes (?partial=1) ; ↑ ↓ le probleme precedent / suivant --}}
    <div class="modal-bg" id="rm-bg">
        <div class="modal rm" role="dialog" aria-modal="true" aria-label="{{ $T['modal']['title'] }}" tabindex="-1">
            <div class="modal-head">
                <span class="muted small" id="rm-pos"></span>
                <span class="grow"></span>
                <button type="button" class="step" id="rm-prev" title="{{ $T['modal']['prev'] }} (↑)" aria-label="{{ $T['modal']['prev'] }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg></button>
                <button type="button" class="step" id="rm-next" title="{{ $T['modal']['next'] }} (↓)" aria-label="{{ $T['modal']['next'] }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
                <button type="button" class="link" id="rm-close" title="{{ $T['modal']['close'] }} (Échap)" aria-label="{{ $T['modal']['close'] }}">✕</button>
            </div>
            <div id="rm-body" aria-live="polite"></div>
            <p class="muted small rm-hint">{{ $T['modal']['hint'] }}</p>
        </div>
    </div>
@endif
@endsection

@push('scripts')
@php
    // @json coupe son argument aux virgules : les tableaux sont prepares ici
    $jsTexts = ['no_hits' => $T['no_hits'], 'bons' => $T['bons_count'], 'chat' => $T['chat'], 'analysis' => $T['analysis'], 'sync' => $T['sync'], 'modal' => $T['modal']];
    $slot = '00000000-0000-4000-8000-000000000000';
    $jsRun = ['ca' => $customer?->id, 'sb' => $supplier?->id, 'url' => route('reconcile.analyze'),
        'sync_url' => route('reconcile.sync'), 'status_url' => route('reconcile.sync-status', ['batch' => $slot]), 'batch_slot' => $slot];
    $jsChat = ['ca' => $customer?->id, 'sb' => $supplier?->id, 'run' => $run?->id, 'url' => route('reconcile.chat'),
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

    const token = document.querySelector('meta[name=csrf-token]').content;

    // ---------- l'analyse, etape par etape (ReconcileController::analyze) ; "إعادة الفحص" : d'abord la synchro ----------
    const panel = document.getElementById('analysis');
    if (panel) {
        const RUN = @json($jsRun);
        const bar = panel.querySelector('.bar');
        const end = document.getElementById('analysis-end');
        const retry = document.getElementById('analysis-retry');
        const item = step => panel.querySelector('[data-step="' + step + '"]');
        let restarted = false, synced = false, runId = null;
        const reset = () => panel.querySelectorAll('[data-step]:not([data-step=sync])').forEach(li => {
            li.className = '';
            li.querySelector('.note').textContent = '';
            li.querySelector('.t').textContent = '';
        });
        // fetch simple : une etape refaite par erreur referait l'appel a l'IA (paye deux fois) ou une demande aux branches
        const post = (url, body) => fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token},
            body: JSON.stringify(body),
        });
        const clock = li => {
            const t0 = Date.now();
            return setInterval(() => {
                li.querySelector('.t').textContent = T.analysis.seconds.replace(':s', Math.round((Date.now() - t0) / 1000));
            }, 500);
        };
        const wait = ms => new Promise(r => setTimeout(r, ms));

        // les deux branches renvoient leurs bons et versements (agent 1.0.10) ; on attend leur reponse, 11 minutes au
        // plus (le serveur dit "لم يرد" apres 10) ; un agent plus ancien ou hors ligne : la note le dit, on continue
        const runSync = async () => {
            const li = item('sync');
            li.className = 'running';
            li.querySelector('.note').textContent = T.analysis.running;
            const tick = clock(li);
            try {
                const res = await post(RUN.sync_url, {ca: RUN.ca, sb: RUN.sb});
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || T.chat.failed);
                const notes = data.branches.filter(b => !b.job).map(b => b.note);
                const show = states => { li.querySelector('.note').textContent = [...states, ...notes].join(' — '); };
                let ok = data.branches.some(b => b.job);
                if (ok) {
                    const deadline = Date.now() + 11 * 60 * 1000;
                    for (;;) {
                        await wait(2000);
                        let jobs = [];
                        try {
                            const st = await fetchRetry(RUN.status_url.replace(RUN.batch_slot, data.batch), {headers: {Accept: 'application/json'}});
                            if (st.ok) jobs = (await st.json()).jobs;
                        } catch (e) { /* ligne faible : on redemande */ }
                        show(jobs.map(j => j.branch + ' : ' + (T.sync[j.status] || j.status)));
                        const over = jobs.length && jobs.every(j => ['done', 'error', 'timeout', 'not_found'].includes(j.status));
                        if (over || Date.now() > deadline) {
                            synced = jobs.some(j => j.status === 'done');
                            ok = jobs.length > 0 && jobs.every(j => j.status === 'done') && !notes.length;
                            break;
                        }
                    }
                } else {
                    show([]);
                }
                li.className = ok ? 'ok' : 'warn';
            } catch (e) {
                li.className = 'warn';
                li.querySelector('.note').textContent = T.analysis.failed + ' ' + (e.message && e.message !== 'Failed to fetch' ? e.message : T.chat.failed);
            } finally {
                clearInterval(tick);
            }
            return run('load');
        };

        const run = async step => {
            if (step === 'sync') return runSync();
            const li = item(step);
            li.className = 'running';
            li.querySelector('.note').textContent = T.analysis.running;
            retry.hidden = true;
            const tick = clock(li);
            try {
                const res = await post(RUN.url, {ca: RUN.ca, sb: RUN.sb, step, synced: synced ? 1 : 0, run: runId});
                const data = await res.json().catch(() => ({}));
                if (res.status === 409 && data.restart && !restarted) {
                    // etat perdu (trop long entre deux etapes) : on recommence une fois depuis le debut
                    restarted = true;
                    clearInterval(tick);
                    reset();
                    return run('load');
                }
                if (!res.ok) throw new Error(data.message || T.chat.failed);
                clearInterval(tick);
                if (data.run) runId = data.run;
                li.className = data.failed ? 'warn' : 'ok';
                li.querySelector('.note').textContent = data.note || '';
                bar.firstElementChild.style.width = data.percent + '%';
                bar.setAttribute('aria-valuenow', data.percent);
                if (data.next) return run(data.next);
                end.textContent = T.analysis.done;
                end.hidden = false;
                // le rapport garde ; un instant pour lire la liste complete (elle reste dans "ما فحصه التحليل")
                const url = new URL(location.href);
                url.searchParams.delete('again');
                url.searchParams.delete('sync');
                url.searchParams.set('r', runId);
                setTimeout(() => location.replace(url.toString()), 1200);
            } catch (e) {
                clearInterval(tick);
                li.className = 'err';
                li.querySelector('.note').textContent = T.analysis.failed + ' ' + (e.message && e.message !== 'Failed to fetch' ? e.message : T.chat.failed);
                retry.hidden = false;
                retry.querySelector('button').onclick = () => run(step);
            }
        };
        run(panel.querySelector('[data-step]').dataset.step);
    }

    // ---------- textes de l'IA : Markdown simple, {A} / {A:101} remis en noms et en liens ----------
    const R = @json($jsChat);

    // le Markdown simple que l'IA ecrit (paragraphes, listes, gras), et ses {A} / {A:101} remis en noms et en liens
    const bon = (side, n) => {
        const label = '<bdi>' + esc(R.names[side]) + ' #' + esc(n) + '</bdi>';
        const href = (R.links[side] || {})[n];
        // data-ref : le clic ouvre le probleme de ce bon dans la fenetre rapide
        return href ? '<a class="bref" data-ref="' + side + esc(n) + '" href="' + esc(href) + '">' + label + '</a>' : label;
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
    // titres (# ou une ligne toute en gras), listes et sous-listes (par l'indentation), tableaux | a | b |, traits ---
    const md = text => {
        const out = [], stack = [];
        let table = null;
        const closeTo = indent => {
            while (stack.length && stack[stack.length - 1].indent > indent) out.push('</li></' + stack.pop().kind + '>');
        };
        const flush = () => {
            if (!table) return;
            const [head, ...rows] = table;
            out.push('<div class="tw"><table><thead><tr>' + head.map(c => '<th>' + inline(c) + '</th>').join('') + '</tr></thead><tbody>'
                + rows.map(r => '<tr>' + r.map(c => '<td>' + inline(c) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>');
            table = null;
        };
        text.split(/\n/).forEach(line => {
            const cells = line.match(/^\s*\|(.+)\|\s*$/);
            if (cells) {
                closeTo(-1);
                const row = cells[1].split('|').map(c => c.trim());
                if (!row.every(c => /^:?-{2,}:?$/.test(c))) (table ??= []).push(row);
                return;
            }
            flush();
            const item = line.match(/^(\s*)(?:([-*•])|(\d+)[.)])\s+(.*)$/);
            if (item) {
                const indent = item[1].replace(/\t/g, '    ').length;
                const kind = item[2] ? 'ul' : 'ol';
                closeTo(indent);
                const top = stack[stack.length - 1];
                if (top && top.indent === indent && top.kind === kind) {
                    out.push('</li><li>');
                } else {
                    // une autre sorte de liste au meme niveau : la precedente se ferme ; plus a droite : une sous-liste
                    if (top && top.indent === indent) out.push('</li></' + stack.pop().kind + '>');
                    out.push('<' + kind + '><li>');
                    stack.push({kind, indent});
                }
                out.push(inline(item[4]));
                return;
            }
            closeTo(-1);
            if (line.trim() === '') return;
            if (/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) { out.push('<hr>'); return; }
            const head = line.match(/^\s*#{1,6}\s+(.*)$/) || line.match(/^\s*\*\*([^*]+)\*\*\s*:?\s*$/);
            out.push(head ? '<h3>' + inline(head[1]) + '</h3>' : '<p>' + inline(line) + '</p>');
        });
        closeTo(-1);
        flush();
        return out.join('');
    };
    const summary = document.getElementById('ai-summary');
    if (summary) {
        summary.innerHTML = md(summary.dataset.text || '');
        // une longue conclusion : son debut, et "اقرأ الكل"
        const more = document.getElementById('ai-more');
        if (summary.scrollHeight > 460) {
            const label = more.textContent;
            summary.classList.add('clamp');
            more.hidden = false;
            more.addEventListener('click', () => {
                const clamped = summary.classList.toggle('clamp');
                more.textContent = clamped ? label : more.dataset.less;
                if (clamped) summary.closest('section').scrollIntoView({block: 'nearest'});
            });
        }
    }

    // ---------- le rapport : recherche, lignes au clavier, fenetre rapide d'un probleme ----------
    const M = T.modal;
    const bg = document.getElementById('rm-bg');
    // les problemes visibles (ni filtres, ni dans un groupe ferme), dans l'ordre de la page
    const rows = () => [...document.querySelectorAll('tr.pr')].filter(r => !r.hidden && r.getClientRects().length > 0);

    const q = document.getElementById('pf-q');
    let status = '';
    const filter = () => {
        const words = (q?.value || '').toLowerCase().split(/\s+/).filter(Boolean);
        const on = words.length > 0 || status !== '';
        let any = false;
        document.querySelectorAll('section.grp').forEach(g => {
            let n = 0;
            g.querySelectorAll('tr.pr').forEach(r => {
                const ok = (!status || r.dataset.status === status) && words.every(w => r.dataset.q.includes(w));
                r.hidden = !ok;
                if (ok) n++;
            });
            const pill = g.querySelector('.gc');
            pill.dataset.total ??= pill.textContent;
            pill.textContent = on ? n + ' / ' + pill.dataset.total : pill.dataset.total;
            g.hidden = n === 0;
            // la recherche ouvre les groupes ou elle trouve
            if (n && on) g.querySelector('details').open = true;
            any ||= n > 0;
        });
        document.getElementById('pf-none').hidden = any;
    };
    q?.addEventListener('input', filter);
    q?.addEventListener('keydown', e => {
        if (e.key === 'Escape' && q.value) { e.preventDefault(); q.value = ''; filter(); }
        if (e.key === 'ArrowDown' && rows()[0]) { e.preventDefault(); rows()[0].focus(); }
    });
    document.querySelectorAll('#pf .chip').forEach(c => c.addEventListener('click', () => {
        status = c.dataset.status;
        document.querySelectorAll('#pf .chip').forEach(o => {
            o.classList.toggle('on', o === c);
            o.setAttribute('aria-pressed', o === c ? 'true' : 'false');
        });
        filter();
    }));
    // "من أين يأتي الفرق" : le groupe s'ouvre
    document.querySelectorAll('.wt a.jump').forEach(a => a.addEventListener('click', () => {
        const g = document.querySelector(a.getAttribute('href'));
        if (g) g.querySelector('details').open = true;
    }));

    // la fenetre : le <template> du probleme (reconcile/_problem), ses bons charges par ?partial=1 (comme l'apercu F2
    // de la liste des bons), les lignes differentes colorees ; ↑ ↓ le probleme precedent / suivant, Echap ferme
    if (bg) {
        const box = bg.querySelector('.modal');
        const body = document.getElementById('rm-body');
        const pos = document.getElementById('rm-pos');
        const prevBtn = document.getElementById('rm-prev');
        const nextBtn = document.getElementById('rm-next');
        const cache = new Map();
        let shown = null, back = null, token = 0;

        const load = url => {
            if (!cache.has(url)) {
                const page = fetchRetry(url + (url.includes('?') ? '&' : '?') + 'partial=1', {headers: {Accept: 'text/html'}})
                    .then(res => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); });
                cache.set(url, page);
                page.catch(() => cache.delete(url));
            }
            return cache.get(url);
        };
        const urlsOf = row => row ? [...row.nextElementSibling.content.querySelectorAll('.rm-bon[data-url]')].map(b => b.dataset.url) : [];
        const fill = mine => body.querySelectorAll('.rm-bon[data-url]').forEach(async el => {
            el.textContent = M.loading;
            try {
                const html = await load(el.dataset.url);
                if (mine !== token) return;
                el.innerHTML = html;
                const codes = new Set((el.dataset.codes || '').split('|').filter(Boolean));
                if (codes.size) el.querySelectorAll('.lines tbody tr').forEach(tr => {
                    if (codes.has(tr.querySelector('.code')?.textContent.trim())) tr.classList.add('hit');
                });
            } catch (e) {
                if (mine === token) el.textContent = M.error;
            }
        });
        const show = content => {
            if (!bg.classList.contains('open')) {
                back = document.activeElement;
                bg.classList.add('open');
            }
            body.replaceChildren(content);
            bg.scrollTop = 0;
            box.focus({preventScroll: true});
            return ++token;
        };

        const open = row => {
            const list = rows();
            const i = list.indexOf(row);
            shown = row;
            box.classList.remove('single');
            pos.textContent = i < 0 ? '' : M.position.replace(':n', i + 1).replace(':total', list.length);
            prevBtn.hidden = nextBtn.hidden = false;
            prevBtn.disabled = i <= 0;
            nextBtn.disabled = i < 0 || i >= list.length - 1;
            fill(show(row.nextElementSibling.content.cloneNode(true)));
            // les voisins, pour que ↑ ↓ soient immediats
            [list[i - 1], list[i + 1]].forEach(n => urlsOf(n).forEach(u => load(u).catch(() => {})));
        };
        // un bon hors des problemes (bons identiques, ce qui est corrige, un lien de l'IA) : seul
        const openBon = url => {
            shown = null;
            box.classList.add('single');
            pos.textContent = '';
            prevBtn.hidden = nextBtn.hidden = true;
            const cols = document.createElement('div');
            cols.className = 'rm-cols single';
            cols.innerHTML = '<div class="rm-col"><div class="rm-item"><div class="rm-bar"><span class="grow"></span><a class="btn open-bon"></a></div><div class="rm-bon"></div></div></div>';
            const a = cols.querySelector('a.open-bon');
            a.href = url;
            a.textContent = M.open;
            cols.querySelector('.rm-bon').dataset.url = url;
            fill(show(cols));
        };
        const step = by => {
            if (!shown) return;
            const list = rows();
            const next = list[list.indexOf(shown) + by];
            if (next) { open(next); next.scrollIntoView({block: 'nearest'}); }
        };
        const close = () => {
            bg.classList.remove('open');
            token++;
            const to = shown || back;
            shown = null;
            if (to?.isConnected) { to.focus({preventScroll: true}); to.scrollIntoView?.({block: 'nearest'}); }
        };

        // une ligne (ou un de ses bons) : la fenetre ; ctrl / cmd / maj : la page du bon, comme d'habitude
        const plain = e => !(e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) && e.button === 0;
        document.addEventListener('click', e => {
            if (!plain(e) || e.target.closest('#rm-bg')) return;
            const row = e.target.closest('tr.pr');
            const link = e.target.closest('a.bref');
            if (row) {
                if (e.target.closest('a') && !link) return;
                e.preventDefault();
                open(row);
            } else if (link) {
                e.preventDefault();
                const own = link.dataset.ref && document.querySelector('tr.pr[data-refs~="' + CSS.escape(link.dataset.ref) + '"]');
                if (own) open(own); else openBon(link.href);
            }
        });
        document.addEventListener('keydown', e => {
            if (bg.classList.contains('open')) {
                if (e.key === 'Escape') { e.preventDefault(); close(); return; }
                if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && shown && !e.altKey) { e.preventDefault(); step(e.key === 'ArrowDown' ? 1 : -1); }
                return;
            }
            const row = e.target.closest?.('tr.pr');
            if (!row || e.target !== row) return;
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(row); return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const list = rows();
                const i = list.indexOf(row) + (e.key === 'ArrowDown' ? 1 : -1);
                if (i < 0) q?.focus(); else list[Math.min(i, list.length - 1)].focus();
            }
        });
        prevBtn.addEventListener('click', () => step(-1));
        nextBtn.addEventListener('click', () => step(1));
        document.getElementById('rm-close').addEventListener('click', close);
        bg.addEventListener('click', e => { if (e.target === bg) close(); });
    }

    // ---------- le chat : une colonne a la demande (Alt+Q), retenue d'une visite a l'autre ----------
    const work = document.getElementById('work');
    const chatBox = document.getElementById('chat');
    const toggle = document.getElementById('chat-toggle');
    const wide = matchMedia('(min-width: 1101px)').matches;
    const setChat = (on, focus) => {
        if (!chatBox) return;
        chatBox.hidden = !on;
        work.classList.toggle('chat-on', on);
        toggle?.setAttribute('aria-expanded', on ? 'true' : 'false');
        try { localStorage.setItem('reconcile:chat', on ? '1' : '0'); } catch (e) {}
        if (on && focus) document.getElementById('question')?.focus();
    };
    toggle?.addEventListener('click', () => setChat(chatBox.hidden, true));
    document.getElementById('chat-x')?.addEventListener('click', () => { setChat(false); toggle?.focus(); });
    chatBox?.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !bg?.classList.contains('open')) { e.preventDefault(); setChat(false); toggle?.focus(); }
    });
    let chatPref = null;
    try { chatPref = localStorage.getItem('reconcile:chat'); } catch (e) {}
    if (wide && chatPref === '1') setChat(true);

    // ---------- chat ----------
    const chat = document.getElementById('ask');
    if (!chat) return;
    // une conversation par rapport garde : revenir a un ancien rapport retrouve la sienne
    const KEY = 'reconcile:' + R.ca + ':' + R.sb + ':' + R.run;
    const msgs = document.getElementById('msgs');
    const quick = document.getElementById('quick');
    const box = document.getElementById('question');
    const send = document.getElementById('send');
    let history = [];
    try { history = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { history = []; }
    // une conversation deja commencee sur ce rapport : la colonne du chat revient ouverte
    if (history.length && wide) setChat(true);
    const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(history)); } catch (e) {} };

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
                body: JSON.stringify({ca: R.ca, sb: R.sb, run: R.run, messages: history.slice(-R.max)}),
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
