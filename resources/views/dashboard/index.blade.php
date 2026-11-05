@extends('layouts.app')

{{--
    "لوحة القيادة" (DashboardController) : en haut le chiffre de la periode en grand, avec sa tendance et sa courbe ;
    puis les cartes (bons, marge, retours, achats), les plus vendus, les branches, les clients, ce qui est vendu
    et epuise, le stock dormant et les heures de pointe. Les chiffres sont en Barlow Condensed (les nombres de
    l'etiquette de rayon) ; les barres en bleu-vert, jamais en rouge ou jaune (les couleurs du stock).
--}}
@php
    $D = __('dashboard');
    $n0 = fn ($v) => $v === null ? '—' : number_format(round($v), 0, ',', ' ');
    // l'argent de la caisse au centime, comme l'ecrit Gescomm
    $n2 = fn ($v) => number_format($v, 2, ',', ' ');
    // un nombre dans une phrase : isole de gauche a droite, sinon "33 230" s'affiche "230 33" en arabe
    $iso = fn (string $s) => "\u{2066}{$s}\u{2069}";
    // une quantite : sans decimales quand elle est entiere (12 boulons), avec sinon (12,50 m)
    $qty = fn ($v) => $v === null ? '—' : number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2, ',', ' ');
    $trend = function (?float $pct) use ($D) {
        if ($pct === null) {
            return ['class' => 'flat', 'arrow' => '', 'text' => $D['no_compare']];
        }

        return match (true) {
            $pct > 0 => ['class' => 'up', 'arrow' => '▲', 'text' => str_replace(':pct', number_format($pct, $pct >= 10 ? 0 : 1, ',', ' '), $D['up'])],
            $pct < 0 => ['class' => 'down', 'arrow' => '▼', 'text' => str_replace(':pct', number_format(-$pct, -$pct >= 10 ? 0 : 1, ',', ' '), $D['down'])],
            default => ['class' => 'flat', 'arrow' => '=', 'text' => $D['same']],
        };
    };
    $metric = $money ? 'amount' : 'bons';
@endphp
@section('title', $D['title'].' — '.__('common.app_name'))

@push('head')
<style>
    .dash-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px 20px; flex-wrap: wrap; margin-bottom: 16px; }
    .dash-head h1 { margin: 0; font-size: 24px; line-height: 1.2; }
    .dash-head .sub { margin: 4px 0 0; color: var(--muted); font-size: 13.5px; display: flex; gap: 6px 14px; flex-wrap: wrap; }
    .chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .chip { display: inline-flex; align-items: center; padding: 4px 13px; border: 1px solid var(--border); border-radius: 99px; color: var(--text); font-size: 13.5px; background: var(--card); }
    .chip:hover { background: var(--row-hover); }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .note { margin: 0 0 14px; }

    .dash { display: grid; gap: 14px; }
    .dash h2 { margin: 0; font-size: 16px; }
    .dash .help { margin: 2px 0 0; color: var(--muted); font-size: 13px; }
    .card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .fig { font-family: var(--font-code); font-weight: 700; letter-spacing: .01em; font-variant-numeric: tabular-nums; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }

    /* le chiffre de la periode et sa courbe : le seul element fort de la page */
    .hero { display: grid; grid-template-columns: minmax(220px, 280px) minmax(0, 1fr); gap: 18px 28px; align-items: stretch; overflow: visible; }
    .hero .what { display: flex; flex-direction: column; gap: 4px; }
    .hero .label { margin: 0; color: var(--muted); font-weight: 600; }
    .hero .big { margin: 2px 0 0; font-size: clamp(40px, 6vw, 66px); line-height: .95; color: var(--text); }
    /* le chiffre tient toujours dans sa colonne : plus il a de caracteres (--chars), plus il est petit */
    .hero .what { container-type: inline-size; }
    @supports (font-size: 1cqi) { .hero .big { font-size: clamp(34px, calc(210cqi / var(--chars)), 66px); } }
    .hero .compare { margin: 6px 0 0; font-size: 13.5px; }
    .hero .peak { margin: auto 0 0; padding-top: 10px; color: var(--muted); font-size: 13px; }
    .trend { font-weight: 600; }
    .trend.up { color: var(--accent); }
    .trend.down, .trend.flat { color: var(--muted); }
    .trend .ctx { font-weight: 400; color: var(--muted); }

    .chart { display: flex; flex-direction: column; min-width: 0; }
    .chart h2 { font-size: 14px; color: var(--muted); font-weight: 600; margin-bottom: 8px; }
    .bars { flex: 1; min-height: 150px; display: flex; align-items: flex-end; gap: 3px; border-bottom: 1px solid var(--border); }
    .bars span { flex: 1 1 0; min-width: 2px; height: var(--h); min-height: 2px; background: var(--bar); border-radius: 3px 3px 0 0; }
    .bars span:hover { background: var(--accent); }
    .bars span.now { background: var(--bar-strong); }
    .bars span.empty { background: var(--border); }
    .axis { display: flex; gap: 3px; margin-top: 5px; color: var(--muted); font-size: 11.5px; }
    /* chaque date centree sous sa barre ; celles des deux bords debordent vers l'interieur de la carte */
    .axis span { flex: 1 1 0; min-width: 2px; display: flex; justify-content: center; white-space: nowrap; }
    .axis span.first { justify-content: flex-start; }
    .axis span.last { justify-content: flex-end; }
    .axis span:not(.grid) { visibility: hidden; }

    /* la caisse : l'argent qui y est maintenant, puis chaque branche */
    .cash { display: grid; grid-template-columns: minmax(220px, 280px) minmax(0, 1fr); gap: 14px 28px; align-items: start; }
    .cash .label { margin: 0; color: var(--muted); font-weight: 600; }
    .cash .value { margin: 2px 0 0; font-size: 34px; line-height: 1.05; }
    .cash .flows { display: flex; flex-wrap: wrap; gap: 4px 18px; margin: 8px 0 0; color: var(--muted); font-size: 13.5px; }
    .cash .flows b { color: var(--text); font-weight: 600; }
    .cash .sub { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
    /* la pastille toujours avant le nom, meme latin dans une page arabe */
    .cash td .name { display: flex; align-items: center; gap: 7px; }

    .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; }
    .tile { display: flex; flex-direction: column; gap: 2px; }
    .tile .label { margin: 0; color: var(--muted); font-size: 13.5px; font-weight: 600; }
    .tile .value { margin: 0; font-size: 32px; line-height: 1.1; }
    .tile .sub { margin: 0; color: var(--muted); font-size: 13px; }
    .tile .trend { font-size: 13px; margin: 0; }

    .cols { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr); gap: 14px; align-items: start; }
    .stack { display: grid; gap: 14px; }
    .pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; align-items: start; }

    table.list th.n, table.list td.n { text-align: end; }
    table.list td { padding-top: 7px; padding-bottom: 7px; }
    table.list tr:last-child td { border-bottom: 0; }
    .rank { color: var(--muted); font: 600 15px/1 var(--font-code); width: 1%; }
    .item a { color: var(--text); }
    .item a:hover { color: var(--accent); }
    .item .name { display: block; font-weight: 600; line-height: 1.35; }
    .item .code { color: var(--muted); font-size: 14px; }
    /* la part de l'article par rapport au premier de la liste */
    .share { display: block; height: 4px; margin-top: 5px; border-radius: 99px; background: var(--row-hover); overflow: hidden; }
    .share i { display: block; height: 100%; width: var(--w); background: var(--bar-strong); border-radius: 99px; }
    .where { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-size: 13px; }

    .branch-row { display: grid; gap: 4px; padding: 8px 0; border-bottom: 1px solid var(--border); }
    .branch-row:last-child { border-bottom: 0; }
    .branch-row .top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .branch-row .name { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; }
    .branch-row .meter { height: 8px; border-radius: 99px; background: var(--row-hover); overflow: hidden; }
    .branch-row .meter i { display: block; height: 100%; width: var(--w); border-radius: 99px; background: hsl(var(--bh) 55% var(--tint-dot-l)); }
    .branch-row .line { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; color: var(--muted); }

    .dormant-sum { display: flex; align-items: baseline; gap: 8px 14px; flex-wrap: wrap; margin: 0 0 10px; }
    .dormant-sum strong { font-size: 19px; }
    .empty { margin: 4px 0; color: var(--muted); }

    .hours { display: flex; align-items: flex-end; gap: 4px; height: 90px; border-bottom: 1px solid var(--border); }
    .hours span { flex: 1 1 0; height: var(--h); min-height: 2px; background: var(--bar); border-radius: 3px 3px 0 0; }
    .hours span.top { background: var(--bar-strong); }
    .hours-axis { display: flex; gap: 4px; margin-top: 5px; color: var(--muted); font: 600 12px/1 var(--font-code); }
    .hours-axis span { flex: 1 1 0; text-align: center; }

    @media (max-width: 900px) {
        .cols, .pair, .cash { grid-template-columns: minmax(0, 1fr); }
    }
    @media (max-width: 700px) {
        .hero { grid-template-columns: minmax(0, 1fr); }
        .hero .peak { margin-top: 0; padding-top: 0; }
        .bars { min-height: 120px; gap: 2px; }
        .axis { gap: 2px; }
        .axis span:not(.keep) { visibility: hidden; }
        .axis span.keep { visibility: visible; }
        .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .tile .value { font-size: 26px; }
        .dash-head .chips { width: 100%; }
    }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => ['Alt', '1…4'], 'label' => $D['period']])
@endpush

@section('content')
<div class="dash-head">
    <div>
        <h1>{{ $D['title'] }}</h1>
        <p class="sub">
            <span>{{ $selected ? $selected->name : __('common.scope.all').' ('.trans_choice('dashboard.scope_all', $branches, ['count' => $branches]).')' }}</span>
            @if (! empty($syncedAt))<span title="{{ $syncedAt->format('d/m/Y H:i') }}">{{ __('dashboard.synced', ['time' => $syncedAt->diffForHumans()]) }}</span>@endif
        </p>
    </div>
    <nav class="chips" aria-label="{{ $D['period'] }}">
        @foreach (App\Support\SalesDashboard::PERIODS as $p)
            <a href="{{ route('dashboard.index', array_filter(['period' => $p === '30' ? null : $p, 'rank' => $rank === 'qty' && $money ? 'qty' : null])) }}" data-key="Alt+{{ $loop->iteration }}"
               @class(['chip', 'on' => $period === $p]) @if ($period === $p) aria-current="true" @endif>{{ $D['periods'][$p] }}</a>
        @endforeach
    </nav>
</div>

@if ($outdated->isNotEmpty())
    <p class="note muted small">{{ __('documents.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>
@endif

@if ($f === null)
    <div class="card"><p class="empty">{{ $selected ? __('documents.no_branch_selected', ['branch' => $selected->name]) : __('documents.no_branch') }}</p></div>
@else
<div class="dash">
    {{-- le chiffre de la periode, sa tendance, et sa courbe --}}
    <section class="card hero" aria-labelledby="hero-label">
        @php($t = $trend($trends[$metric]))
        <div class="what">
            <p class="label" id="hero-label">{{ $money ? $D['net_sales'] : $D['bons'] }}</p>
            @php($big = $n0($money ? $totals['amount'] : $totals['bons']))
            <p class="big fig" style="--chars: {{ max(5, mb_strlen($big)) }}">{{ $big }}</p>
            <p class="compare trend {{ $t['class'] }}">@if ($t['arrow'])<span aria-hidden="true">{{ $t['arrow'] }}</span> @endif{{ $t['text'] }}@if ($trends[$metric] !== null) <span class="ctx">{{ $D['compare'][$period] }}</span>@endif</p>
            @if ($money)<p class="help">{{ $D['net_sales_help'] }}</p>@endif
            @if ($peak && count($series) > 1)<p class="peak">{{ __('dashboard.peak', ['when' => $peak['tip']]) }}</p>@endif
        </div>
        <div class="chart">
            <h2>{{ $D['chart'][$metric][$f['window']['unit']] }}</h2>
            @php($every = max(1, (int) ceil(count($series) / ($f['window']['unit'] === 'month' ? 12 : 10))))
            <div class="bars" role="img" aria-label="{{ $D['chart'][$metric][$f['window']['unit']] }}{{ $peak ? '. '.__('dashboard.peak', ['when' => $peak['tip']]) : '' }}">
                @foreach ($series as $p)
                    <span @class(['now' => $p['now'], 'empty' => $p['value'] <= 0]) style="--h: {{ max(0, $p['pct']) }}%" title="{{ $p['tip'] }}"></span>
                @endforeach
            </div>
            {{-- une date toutes les $every cases en partant de maintenant (.grid) ; sur telephone seulement le debut,
                 le milieu et la fin (.keep), sinon les dates se chevauchent --}}
            @php($lastIndex = count($series) - 1)
            @php($middle = intdiv($lastIndex, 2))
            <div class="axis" aria-hidden="true">
                @foreach ($series as $i => $p)
                    @php($grid = ($lastIndex - $i) % $every === 0)
                    @php($keep = $i === 0 || $i === $middle || $i === $lastIndex)
                    <span @class(['grid' => $grid, 'keep' => $keep, 'first' => $i === 0, 'last' => $i === $lastIndex])>{{ $grid || $keep ? $p['label'] : '' }}</span>
                @endforeach
            </div>
            @if ($totals['bons'] === 0)<p class="empty">{{ $D['no_sales'] }}</p>@endif
        </div>
    </section>

    {{-- la caisse (cash.view) : son solde maintenant, ce qui est entre et sorti aujourd'hui, puis chaque branche --}}
    @if ($cash !== null)
        @php($C = $D['cash'])
        @php($agentCash = App\Models\Branch::AGENT_FEATURES['cash'])
        <section class="card" aria-labelledby="cash-title">
            <div class="card-head">
                <div><h2 id="cash-title">{{ $C['title'] }}</h2><p class="help">{{ $C['help'] }}</p></div>
                @if ($cash['checked_at'])<p class="muted small" title="{{ $cash['checked_at']->format('d/m/Y H:i') }}">{{ __('dashboard.cash.checked', ['time' => $cash['checked_at']->diffForHumans()]) }}</p>@endif
            </div>
            @if ($cash['branches']->isEmpty())
                <p class="empty">{{ __('dashboard.cash.none', ['names' => $cash['outdated']->join(__('stock.js.list_separator')), 'version' => $agentCash]) }}</p>
            @else
                <div class="cash">
                    <div>
                        <p class="label">{{ $C['now'] }}</p>
                        {{-- le chiffre isole (.fig) dans un paragraphe de la langue de la page : du meme cote que son titre --}}
                        <p class="value"><span class="fig">{{ $n2($cash['total']) }}</span></p>
                        <p class="flows">
                            <span>{{ $C['in_today'] }} <b class="fig">{{ $n2($cash['in_today']) }}</b></span>
                            <span>{{ $C['out_today'] }} <b class="fig">{{ $n2($cash['out_today']) }}</b></span>
                        </p>
                        @if ($cash['branches']->count() === 1 && $cash['branches'][0]['last'])
                            <p class="sub">{{ __('dashboard.cash.last', ['when' => $iso($cash['branches'][0]['last'])]) }}</p>
                        @endif
                    </div>
                    @if ($cash['branches']->count() > 1)
                        <table class="list">
                            <thead><tr>
                                <th>{{ $D['branches_title'] }}</th>
                                <th class="n">{{ $C['col_balance'] }}</th>
                                <th class="n hide-sm">{{ $C['in_today'] }}</th>
                                <th class="n hide-sm">{{ $C['out_today'] }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($cash['branches'] as $b)
                                <tr>
                                    <td class="item" style="--bh: {{ $b['hue'] }}">
                                        <span class="name"><span class="bdot"></span>{{ $b['branch'] }}</span>
                                        @if ($b['last'])<span class="muted small">{{ __('dashboard.cash.last', ['when' => $iso($b['last'])]) }}</span>@endif
                                    </td>
                                    <td class="n"><span class="fig">{{ $n2($b['balance']) }}</span></td>
                                    <td class="n hide-sm"><span class="num">{{ $n2($b['in_today']) }}</span></td>
                                    <td class="n hide-sm"><span class="num">{{ $n2($b['out_today']) }}</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                @if ($cash['outdated']->isNotEmpty())
                    <p class="sub muted small">{{ __('dashboard.cash.outdated', ['version' => $agentCash, 'names' => $cash['outdated']->join(__('stock.js.list_separator'))]) }}</p>
                @endif
            @endif
        </section>
    @endif

    {{-- les cartes de la periode --}}
    <div class="tiles">
        @if ($money)
            @php($t = $trend($trends['bons']))
            <section class="card tile">
                <h2 class="label">{{ $D['bons'] }}</h2>
                <p class="value fig">{{ $n0($totals['bons']) }}</p>
                <p class="trend {{ $t['class'] }}">@if ($t['arrow'])<span aria-hidden="true">{{ $t['arrow'] }}</span> @endif{{ $t['text'] }}</p>
                @if ($totals['average'] !== null)<p class="sub">{{ __('dashboard.average', ['amount' => $iso($n0($totals['average']))]) }}</p>@endif
            </section>
        @endif
        @if ($totals['margin'] !== null)
            @php($t = $trend($trends['margin']))
            <section class="card tile">
                <h2 class="label">{{ $D['margin'] }}</h2>
                <p class="value fig">{{ $n0($totals['margin']) }}</p>
                <p class="trend {{ $t['class'] }}">@if ($t['arrow'])<span aria-hidden="true">{{ $t['arrow'] }}</span> @endif{{ $t['text'] }}</p>
                @if ($totals['margin_pct'] !== null)<p class="sub">{{ __('dashboard.margin_pct', ['pct' => $iso(number_format($totals['margin_pct'], 1, ',', ' '))]) }}</p>@endif
                @if ($totals['margin_partial'])<p class="sub">{{ $D['margin_partial'] }}</p>@endif
            </section>
        @endif
        <section class="card tile">
            <h2 class="label">{{ $D['returns'] }}</h2>
            <p class="value fig">{{ $money ? $n0($totals['back_amount']) : $qty($totals['back_qty']) }}</p>
            <p class="sub">{{ $totals['back_lines'] ? trans_choice('documents.return_lines', $totals['back_lines'], ['count' => $totals['back_lines']]) : '—' }}</p>
            @if ($money && $totals['back_lines'])<p class="sub">{{ __('dashboard.returns_qty', ['qty' => $iso($qty($totals['back_qty']))]) }}</p>@endif
        </section>
        @if ($totals['purchase_amount'] !== null)
            <section class="card tile">
                <h2 class="label">{{ $D['purchases'] }}</h2>
                <p class="value fig">{{ $n0($totals['purchase_amount']) }}</p>
                <p class="sub">{{ trans_choice('dashboard.bons_count', $totals['purchase_bons'], ['count' => $totals['purchase_bons']]) }}</p>
            </section>
        @else
            <section class="card tile">
                <h2 class="label">{{ $D['products_sold'] }}</h2>
                <p class="value fig">{{ $n0($totals['products']) }}</p>
                <p class="sub">{{ $D['products_sold_help'] }}</p>
            </section>
        @endif
    </div>

    <div class="cols">
        {{-- les plus vendus --}}
        <section class="card">
            <div class="card-head">
                <h2>{{ $D['top_title'] }}</h2>
                @if ($money)
                    <nav class="chips" aria-label="{{ $D['rank'] }}">
                        @foreach (App\Http\Controllers\DashboardController::RANKS as $r)
                            <a href="{{ route('dashboard.index', array_filter(['period' => $period === '30' ? null : $period, 'rank' => $r === 'amount' ? null : $r])) }}"
                               @class(['chip', 'on' => $rank === $r]) @if ($rank === $r) aria-current="true" @endif>{{ $D['ranks'][$r] }}</a>
                        @endforeach
                    </nav>
                @endif
            </div>
            @if ($products->isEmpty())
                <p class="empty">{{ $D['no_sales'] }}</p>
            @else
                <table class="list">
                    <thead><tr>
                        <th class="rank">#</th>
                        <th>{{ $D['col_product'] }}</th>
                        <th @class(['n', 'hide-sm' => $rank === 'amount'])>{{ $D['col_qty'] }}</th>
                        @if ($money)<th @class(['n', 'hide-sm' => $rank === 'qty'])>{{ $D['col_amount'] }}</th>@endif
                        @if ($buy)<th class="n hide-sm">{{ $D['col_margin'] }}</th>@endif
                        <th class="n">{{ $D['col_stock'] }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($products as $p)
                        <tr>
                            <td class="rank">{{ $loop->iteration }}</td>
                            <td class="item">
                                <a href="{{ $p['url'] }}"><span class="name">{{ $p['designation'] ?? $p['code'] }}</span>@if ($p['designation'])<span class="code ltr">{{ $p['code'] }}</span>@endif</a>
                                <span class="share" aria-hidden="true"><i style="--w: {{ $p['bar'] }}%"></i></span>
                            </td>
                            <td @class(['n', 'hide-sm' => $rank === 'amount'])><span class="num">{{ $qty($p['net']) }}</span></td>
                            @if ($money)<td @class(['n', 'hide-sm' => $rank === 'qty'])><span class="num">{{ $n0($p['amount']) }}</span></td>@endif
                            @if ($buy)<td class="n hide-sm"><span class="num" @if ($p['margin_partial']) title="{{ $D['margin_partial_row'] }}" @endif>{{ $p['margin'] === null ? '—' : number_format($p['margin'], 1, ',', ' ').'%' }}{{ $p['margin_partial'] ? '*' : '' }}</span></td>@endif
                            <td class="n">@if ($p['stock'] === null)<span class="muted">—</span>@else<span class="qty {{ $p['state'] }}">{{ $qty($p['stock']) }}</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <div class="stack">
            @if ($several)
                {{-- la part de chaque branche --}}
                <section class="card">
                    <div class="card-head"><div><h2>{{ $D['branches_title'] }}</h2><p class="help">{{ $D['branches_help'][$metric] }}</p></div></div>
                    @foreach ($perBranch as $b)
                        @php($t = $trend($b['trend']))
                        <div class="branch-row" style="--bh: {{ $b['hue'] }}">
                            <div class="top">
                                <span class="name"><span class="bdot"></span>{{ $b['branch'] }}</span>
                                <span class="fig">{{ $n0($b['value']) }}</span>
                            </div>
                            <div class="meter" aria-hidden="true"><i style="--w: {{ $b['share'] }}%"></i></div>
                            <div class="line"><span class="num">{{ number_format($b['share'], 0, ',', ' ') }}%</span><span class="trend {{ $t['class'] }}">@if ($t['arrow'])<span aria-hidden="true">{{ $t['arrow'] }}</span> @endif{{ $t['text'] }}</span></div>
                        </div>
                    @endforeach
                </section>
            @endif

            {{-- les meilleurs clients --}}
            <section class="card">
                <div class="card-head"><h2>{{ $D['customers_title'] }}</h2></div>
                @if ($customers->isEmpty())
                    <p class="empty">{{ $D['no_sales'] }}</p>
                @else
                    <table class="list">
                        <tbody>
                        @foreach ($customers as $c)
                            <tr>
                                <td class="item">
                                    @if ($c['url'])<a href="{{ $c['url'] }}"><span class="name">{{ $c['name'] }}</span></a>@else<span class="name">{{ $c['name'] }}</span>@endif
                                    @if ($several)<span class="where" style="--bh: {{ $c['hue'] }}"><span class="bdot"></span>{{ $c['branch'] }}</span>@endif
                                </td>
                                <td class="n muted small">{{ trans_choice('dashboard.bons_count', $c['bons'], ['count' => $c['bons']]) }}</td>
                                @if ($money)<td class="n"><span class="fig">{{ $n0($c['amount']) }}</span></td>@endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
    </div>

    <div class="pair">
        {{-- vendus puis epuises : les couleurs du stock de Gescomm (0 rouge, negatif jaune) --}}
        <section class="card">
            <div class="card-head"><div><h2>{{ $D['sold_out_title'] }}</h2><p class="help">{{ $D['sold_out_help'] }}</p></div></div>
            @if (! $dormant['seen'])
                <p class="empty">{{ $D['stock_unseen'] }}</p>
            @elseif ($soldOut->isEmpty())
                <p class="empty">{{ $D['sold_out_none'] }}</p>
            @else
                <table class="list">
                    <thead><tr><th>{{ $D['col_product'] }}</th><th class="n">{{ $D['col_sold'] }}</th><th class="n">{{ $D['col_stock'] }}</th></tr></thead>
                    <tbody>
                    @foreach ($soldOut as $r)
                        <tr>
                            <td class="item">
                                <a href="{{ $r['url'] }}"><span class="name">{{ $r['designation'] ?? $r['code'] }}</span>@if ($r['designation'])<span class="code ltr">{{ $r['code'] }}</span>@endif</a>
                                @if ($several)<span class="where" style="--bh: {{ $r['hue'] }}"><span class="bdot"></span>{{ $r['branch'] }}</span>@endif
                            </td>
                            <td class="n"><span class="num">{{ $qty($r['sold']) }}</span></td>
                            <td class="n"><span class="qty {{ $r['state'] }}">{{ $qty($r['stock']) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        {{-- le stock dormant --}}
        <section class="card">
            <div class="card-head"><div><h2>{{ $D['dormant_title'] }}</h2><p class="help">{{ __('dashboard.dormant_help', ['days' => App\Support\SalesDashboard::DORMANT_DAYS]) }}</p></div></div>
            @if (! $dormant['seen'])
                <p class="empty">{{ $D['stock_unseen'] }}</p>
            @elseif ($dormant['count'] === 0)
                <p class="empty">{{ __('dashboard.dormant_none', ['days' => App\Support\SalesDashboard::DORMANT_DAYS]) }}</p>
            @else
                <p class="dormant-sum">
                    <strong>{{ trans_choice('dashboard.dormant_count', $dormant['count'], ['count' => $iso($n0($dormant['count']))]) }}</strong>
                    @if ($dormant['value'] !== null)<span class="muted">{{ __('dashboard.dormant_value', ['value' => $iso($n0($dormant['value']))]) }}</span>@endif
                </p>
                <table class="list">
                    <thead><tr><th>{{ $D['col_product'] }}</th><th class="n">{{ $D['col_stock'] }}</th>@if ($buy)<th class="n">{{ $D['col_value'] }}</th>@endif</tr></thead>
                    <tbody>
                    @foreach ($dormant['items'] as $r)
                        <tr>
                            <td class="item">
                                <a href="{{ $r['url'] }}"><span class="name">{{ $r['designation'] ?? $r['code'] }}</span>@if ($r['designation'])<span class="code ltr">{{ $r['code'] }}</span>@endif</a>
                                @if ($several)<span class="where" style="--bh: {{ $r['hue'] }}"><span class="bdot"></span>{{ $r['branch'] }}</span>@endif
                            </td>
                            <td class="n"><span class="num">{{ $qty($r['stock']) }}</span></td>
                            @if ($buy)<td class="n"><span class="num">{{ $n0($r['value']) }}</span></td>@endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    {{-- les heures de pointe --}}
    @if ($hours->isNotEmpty())
        <section class="card">
            <div class="card-head">
                <div><h2>{{ $D['hours_title'] }}</h2><p class="help">{{ $D['hours_help'] }}</p></div>
                <p class="muted small">{{ __('dashboard.busiest', ['hour' => sprintf('%02d:00', $busiest)]) }}</p>
            </div>
            <div class="hours" role="img" aria-label="{{ $D['hours_title'] }}. {{ __('dashboard.busiest', ['hour' => sprintf('%02d:00', $busiest)]) }}">
                @foreach ($hours as $h)
                    <span @class(['top' => $h['hour'] === $busiest]) style="--h: {{ $h['pct'] }}%" title="{{ sprintf('%02d:00', $h['hour']) }} — {{ trans_choice('dashboard.bons_count', $h['bons'], ['count' => $h['bons']]) }}"></span>
                @endforeach
            </div>
            <div class="hours-axis" aria-hidden="true">
                @foreach ($hours as $h)<span>{{ sprintf('%02d', $h['hour']) }}</span>@endforeach
            </div>
        </section>
    @endif
</div>
@endif
@endsection
