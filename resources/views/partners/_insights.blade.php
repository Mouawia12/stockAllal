{{--
    "علاقته معنا" sur la fiche (App\Support\PartnerInsights) : toujours les 12 derniers mois, quelle que soit la periode
    choisie plus haut. La regle de fidelite montre ou il se place parmi les autres de sa branche : des comptes, jamais un nom.
    Pas de jaune ni de rouge ici : ces couleurs veulent dire quelque chose pour le stock.
--}}
@php
    $I = __('partners.insights');
    $i = $insights;
    $iso = fn (string $s) => "\u{2066}{$s}\u{2069}";
    $fmtI = fn ($n) => $n === null ? '—' : number_format((float) $n, 2, ',', ' ');
    $intI = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $pctI = fn ($n) => $iso(number_format((float) $n, abs((float) $n) >= 10 || floor((float) $n) == (float) $n ? 0 : 1, ',', ' '));
    // 950k, 1,2M : comme les jours du calendrier du tableau de bord
    $shortI = fn ($n) => match (true) {
        abs($n) >= 1000000 => rtrim(rtrim(number_format($n / 1000000, 1, ',', ''), '0'), ',').'M',
        abs($n) >= 1000 => number_format($n / 1000, 0, ',', '').'k',
        default => number_format($n, 0, ',', ''),
    };
    $monthValue = fn (array $m) => $money ? (float) $m['amount'] : (float) $m['bons'];
    $monthMax = max(1, ...array_map(fn ($m) => abs($monthValue($m)), $i['months']));
    $weekMax = max(1, ...array_map(fn ($d) => $d['bons'], $i['week'] ?: [['bons' => 1]]));
    $bestDay = collect($i['week'])->firstWhere('best', true);
    $histMax = $i['loyalty'] ? max(1, ...$i['loyalty']['hist']) : 1;
@endphp

@push('head')
<style>
    .bond { margin-top: 22px; }
    .bond-head { display: flex; align-items: baseline; gap: 4px 12px; flex-wrap: wrap; margin-bottom: 10px; }
    .bond-head h2 { font-size: 18px; margin: 0; }
    .bond-head p { margin: 0; color: var(--muted); font-size: 13.5px; display: flex; gap: 4px 14px; flex-wrap: wrap; align-items: center; }
    .bond-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; }
    .bond-grid .card { overflow: visible; min-width: 0; }
    .bond-grid h3 { margin: 0; font-size: 13.5px; font-weight: 600; color: var(--muted); }
    .bond-grid .fig { font-family: var(--font-code); font-weight: 700; font-variant-numeric: tabular-nums; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }

    /* la fidelite : une regle d'acier graduee, le seul element fort de la section */
    .loyal { grid-column: span 7; display: flex; flex-direction: column; gap: 12px; }
    .loyal-top { display: flex; align-items: flex-end; gap: 6px 18px; flex-wrap: wrap; }
    .loyal-score { display: flex; align-items: baseline; gap: 4px; line-height: .86; margin: 0; direction: ltr; }
    .rule-fig { margin: 0; }
    .loyal-score .fig { font-size: 76px; letter-spacing: -.01em; color: var(--accent); }
    .loyal-score small { font: 600 20px/1 var(--font-code); color: var(--muted); }
    .loyal-say { flex: 1 1 220px; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
    .loyal-band { font-size: 17px; font-weight: 700; }
    .loyal-beats { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.5; }
    .rule { position: relative; padding-top: 2px; }
    .rule-hist { display: grid; grid-template-columns: repeat(10, 1fr); align-items: end; gap: 3px; height: 54px; }
    .rule-hist span { display: block; background: var(--bar); border-radius: 2px 2px 0 0; }
    .rule-hist span.mine { background: var(--bar-strong); }
    /* graduations : un trait tous les 2, un plus long tous les 10 ; memes positions dans les deux sens de lecture */
    .rule-ticks { height: 15px; border: 1px solid var(--border); border-top: 2px solid var(--muted); border-radius: 0 0 3px 3px;
        background-color: var(--row-hover);
        background-image:
            repeating-linear-gradient(to right, var(--muted) 0 1px, transparent 1px 10%),
            repeating-linear-gradient(to right, var(--border) 0 1px, transparent 1px 2%);
        background-size: 100% 9px, 100% 5px; background-repeat: no-repeat; }
    .rule-scale { display: flex; justify-content: space-between; margin-top: 3px; font: 600 11.5px/1 var(--font-code); color: var(--muted); }
    .rule-mark { position: absolute; top: -3px; bottom: 15px; inset-inline-start: var(--at); width: 2px; margin-inline-start: -1px; background: var(--text); pointer-events: none; }
    .rule-mark::before { content: ''; position: absolute; top: -2px; inset-inline-start: -4px; width: 10px; height: 10px; border-radius: 50%; background: var(--text); box-shadow: 0 0 0 2px var(--card); }
    .rule-note { margin: 7px 0 0; color: var(--muted); font-size: 12.5px; }
    .parts { display: grid; gap: 7px; margin: 0; }
    .parts > div { display: grid; grid-template-columns: 96px minmax(40px, 1fr) minmax(0, 150px); align-items: center; gap: 10px; }
    .parts dt { font-size: 13.5px; color: var(--muted); }
    .parts dd { display: contents; }
    .meter { display: block; height: 6px; border-radius: 99px; background: var(--row-hover); overflow: hidden; }
    .meter i { display: block; height: 100%; width: var(--w); background: var(--accent); border-radius: inherit; }
    .parts .val { font-size: 13px; text-align: end; }
    .how { font-size: 13px; color: var(--muted); }
    .how summary { cursor: pointer; width: fit-content; }
    .how p { margin: 6px 0 0; line-height: 1.6; }

    /* mois par mois */
    .months { grid-column: span 5; display: flex; flex-direction: column; gap: 10px; }
    .months-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 6px 12px; flex-wrap: wrap; }
    .months-trend { text-align: end; line-height: 1.3; }
    .months-trend b { font-weight: 700; }
    .months-trend b.up { color: var(--accent); }
    .months-trend b.down { color: var(--text); }
    .months-trend small { display: block; color: var(--muted); font-size: 12px; font-weight: 400; }
    .mbars { flex: 1; display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 3px; list-style: none; margin: 0; padding: 0; min-height: 150px; }
    .mbars li { display: flex; flex-direction: column; justify-content: flex-end; align-items: stretch; min-width: 0; gap: 3px; }
    .mbars .v { font: 600 10.5px/1 var(--font-code); color: var(--muted); text-align: center; white-space: nowrap; direction: ltr; overflow: hidden; }
    .mbars .b { display: block; min-height: 2px; background: var(--bar); border-radius: 3px 3px 0 0; }
    .mbars li.now .b { background: var(--bar-strong); }
    .mbars li.empty .b { background: var(--border); }
    .mbars li:hover .b { background: var(--accent); }
    .mbars .l { font-size: 10.5px; line-height: 1.2; color: var(--muted); text-align: center; border-top: 1px solid var(--border); padding-top: 3px; white-space: nowrap; overflow: hidden; }
    .months-foot { display: flex; justify-content: space-between; gap: 4px 12px; flex-wrap: wrap; margin: 0; color: var(--muted); font-size: 13px; }
    .months-foot b { color: var(--text); }

    /* les petits chiffres */
    .tiles { grid-column: 1 / -1; display: grid; grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)); gap: 12px; }
    .tile { display: flex; flex-direction: column; gap: 3px; }
    .tile p { margin: 0; }
    .tile .fig { font-size: 29px; line-height: 1.12; margin-top: 2px; align-self: flex-start; }
    .tile .fig small { font-size: 16px; font-weight: 600; color: var(--muted); }
    .tile .word { font-size: 18px; font-weight: 700; line-height: 1.3; margin-top: 3px; }
    .tile .sub { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.45; }
    .tile .sub.lead { color: var(--text); font-weight: 600; }
    .tile .meter { margin: 5px 0 3px; }

    /* ce qu'il prend, quand il vient */
    .favs { grid-column: span 7; }
    .week { grid-column: span 5; display: flex; flex-direction: column; gap: 10px; }
    .favs-head { display: flex; justify-content: space-between; align-items: center; gap: 6px 12px; flex-wrap: wrap; margin-bottom: 10px; }
    .fav-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 9px; }
    .fav-row { display: grid; grid-template-columns: minmax(0, auto) minmax(0, 1fr) auto; align-items: baseline; gap: 0 10px; }
    .fav-row a.code { color: var(--text); }
    .fav-row a.code:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    .fav-row .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13.5px; color: var(--muted); }
    .fav-row .share { font: 600 14px/1 var(--font-code); }
    .fav-row .meter { grid-column: 1 / -1; margin-top: 4px; height: 5px; }
    .favs-foot { margin: 10px 0 0; color: var(--muted); font-size: 13px; }
    .week-bars { flex: 1; display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 5px; list-style: none; margin: 0; padding: 0; min-height: 110px; }
    .week-bars li { display: flex; flex-direction: column; justify-content: flex-end; gap: 4px; min-width: 0; }
    .week-bars .b { display: block; min-height: 2px; background: var(--bar); border-radius: 3px 3px 0 0; }
    .week-bars li.best .b { background: var(--bar-strong); }
    .week-bars li.empty .b { background: var(--border); }
    .week-bars .l { font-size: 11.5px; line-height: 1.2; color: var(--muted); text-align: center; border-top: 1px solid var(--border); padding-top: 4px; white-space: nowrap; overflow: hidden; }
    .week-bars li.best .l { color: var(--text); font-weight: 700; }
    .week-say { margin: 0; font-size: 14px; font-weight: 600; }
    .bond-none { margin: 0; color: var(--muted); }
    @media (max-width: 860px) {
        .loyal, .months, .favs, .week { grid-column: 1 / -1; }
    }
    @media (max-width: 520px) {
        .loyal-score .fig { font-size: 64px; }
        .parts > div { grid-template-columns: 84px minmax(30px, 1fr) minmax(0, 118px); gap: 8px; }
        .mbars li:nth-child(even) .l { visibility: hidden; }
        .mbars .v { display: none; }
        .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .tile .fig { font-size: 25px; }
    }
    @media print { .bond-grid .card { break-inside: avoid; } .how { display: none; } }
</style>
@endpush

<section class="bond" aria-labelledby="bond-title">
    <div class="bond-head">
        <h2 id="bond-title">{{ $I['title'][$kind] }}</h2>
        <p>
            <span>{{ $I['window'] }}</span>
            @if ($i['first'])<span>{{ __("partners.insights.since.{$kind}", ['date' => $iso(\Illuminate\Support\Carbon::parse($i['first'])->format('d/m/Y'))]) }}</span>@endif
            @if ($i['is_new'])<span class="pill">{{ $I['is_new'][$kind] }}</span>@endif
        </p>
    </div>

    @if (! $i['active'])
        <div class="card">
            <p class="bond-none">
                @if ($i['last'])
                    {{ $I['none'][$kind] }} {{ __('partners.insights.last_ever', ['date' => $iso(\Illuminate\Support\Carbon::parse($i['last'])->format('d/m/Y'))]) }}
                @else
                    {{ $I['none_ever'] }}
                @endif
            </p>
        </div>
    @else
        @php($L = $i['loyalty'])
        <div class="bond-grid">
            <article class="card loyal" aria-labelledby="loyal-title">
                <h3 id="loyal-title">{{ $I['score'][$kind] }}</h3>
                <div class="loyal-top">
                    <p class="loyal-score"><span class="fig">{{ $L['score'] }}</span><small>/100</small></p>
                    <div class="loyal-say">
                        <span class="loyal-band">{{ $I['bands'][$kind][$L['band']] }}</span>
                        <p class="loyal-beats">
                            @if ($L['beats'] !== null)
                                {{ __("partners.insights.beats.{$kind}", ['pct' => $pctI($L['beats'])]) }}
                            @else
                                {{ $I['alone'][$kind] }}
                            @endif
                        </p>
                    </div>
                </div>

                <figure class="rule-fig">
                    <div class="rule">
                        <div class="rule-hist" aria-hidden="true">
                            @foreach ($L['hist'] as $b => $n)
                                <span @class(['mine' => $b === $L['bucket']]) style="height: {{ $n > 0 ? max(3, round($n / $histMax * 54)) : 0 }}px" title="{{ ($b * 10).'–'.($b === 9 ? 100 : $b * 10 + 9) }}: {{ $intI($n) }}"></span>
                            @endforeach
                        </div>
                        <div class="rule-ticks" aria-hidden="true"></div>
                        <div class="rule-scale" aria-hidden="true"><span>0</span><span>25</span><span>50</span><span>75</span><span>100</span></div>
                        <span class="rule-mark" style="--at: {{ $L['score'] }}%" title="{{ $I['marker'] }}" aria-hidden="true"></span>
                    </div>
                    <figcaption class="rule-note">{{ __("partners.insights.hist.{$kind}", ['n' => $iso($intI($L['compared'] + 1))]) }}</figcaption>
                </figure>

                <dl class="parts">
                    <div>
                        <dt>{{ $I['parts']['regular'] }}</dt>
                        <dd><span class="meter" aria-hidden="true"><i style="--w: {{ $L['regular'] }}%"></i></span><span class="val">{{ __('partners.insights.regular_value', ['n' => $iso((string) $i['active_months'])]) }}</span></dd>
                    </div>
                    <div>
                        <dt>{{ $I['parts']['frequent'] }}</dt>
                        <dd><span class="meter" aria-hidden="true"><i style="--w: {{ $L['frequent'] }}%"></i></span><span class="val">{{ __('partners.insights.frequent_value', ['pct' => $pctI($L['frequent'])]) }}</span></dd>
                    </div>
                    <div>
                        <dt>{{ $I['parts']['recent'] }}</dt>
                        <dd><span class="meter" aria-hidden="true"><i style="--w: {{ $L['recent'] }}%"></i></span><span class="val">{{ trans_choice('partners.insights.ago', $i['since_last'], ['count' => $i['since_last']]) }}</span></dd>
                    </div>
                </dl>

                <details class="how">
                    <summary>{{ $I['how_title'] }}</summary>
                    <p>{{ __('partners.insights.how', [
                        'regular' => $iso((string) \App\Support\PartnerInsights::WEIGHT_REGULAR),
                        'frequent' => $iso((string) \App\Support\PartnerInsights::WEIGHT_FREQUENT),
                        'recent' => $iso((string) \App\Support\PartnerInsights::WEIGHT_RECENT),
                        'fresh' => $iso((string) \App\Support\PartnerInsights::FRESH_DAYS),
                        'stale' => $iso((string) \App\Support\PartnerInsights::STALE_DAYS),
                    ]) }}</p>
                </details>
            </article>

            <article class="card months" aria-labelledby="months-title">
                <div class="months-head">
                    <div>
                        <h3 id="months-title">{{ $I['months'] }}</h3>
                        <span class="muted small">{{ $money ? $I['months_amount'] : $I['months_bons'] }}</span>
                    </div>
                    <div class="months-trend">
                        @php($t = $i['trend']['pct'])
                        @if ($t === null)
                            <small>{{ $I['trend_new'] }}</small>
                        @elseif ($t === 0)
                            <b>{{ $I['trend_same'] }}</b>
                        @elseif ($t > 0)
                            <b class="up">▲ {{ __('partners.insights.trend_up', ['pct' => $pctI($t)]) }}</b><small>{{ $I['trend_note'] }}</small>
                        @else
                            <b class="down">▼ {{ __('partners.insights.trend_down', ['pct' => $pctI(-$t)]) }}</b><small>{{ $I['trend_note'] }}</small>
                        @endif
                    </div>
                </div>
                <ol class="mbars">
                    @foreach ($i['months'] as $m)
                        @php($v = $monthValue($m))
                        <li @class(['now' => $m['now'], 'empty' => $m['bons'] === 0]) title="{{ $m['full'] }}: {{ $money ? "\u{2066}".$fmtI($m['amount'])."\u{2069} (".$m['bons'].')' : $m['bons'] }}">
                            <span class="v" aria-hidden="true">{{ $m['bons'] ? $shortI($v) : '' }}</span>
                            <span class="b" style="height: {{ $m['bons'] ? max(3, round(abs($v) / $monthMax * 112)) : 2 }}px"></span>
                            <span class="l">{{ $m['label'] }}</span>
                        </li>
                    @endforeach
                </ol>
                <p class="months-foot">
                    <span>{{ __('partners.insights.active_months', ['n' => $iso((string) $i['active_months'])]) }}</span>
                    <span>{{ $I['total'] }} <b class="num">{{ $money ? $fmtI($i['amount']) : $intI($i['bons']) }}</b></span>
                </p>
            </article>

            <div class="tiles">
                @php($r = $i['rank'])
                <article class="card tile" title="{{ $I['rank_by'][$r['by']] }}">
                    <h3>{{ $I['rank'] }}</h3>
                    <p class="fig">{{ $intI($r['place']) }} <small>/ {{ $intI($r['of']) }}</small></p>
                    <p class="sub lead">{{ __('partners.insights.rank_top', ['pct' => $iso((string) $r['top'])]) }}</p>
                    @if ($r['share'] !== null)
                        <p class="sub">
                            {{ $money ? __("partners.insights.share.{$kind}", ['pct' => $pctI($r['share'])]) : __('partners.insights.share_bons', ['pct' => $pctI($r['share'])]) }}
                        </p>
                    @endif
                </article>

                <article class="card tile">
                    <h3>{{ $I['rhythm'] }}</h3>
                    @if ($i['rhythm'])
                        <p class="word">{{ trans_choice('partners.insights.every', $i['rhythm']['gap'], ['count' => $i['rhythm']['gap']]) }}</p>
                        <p class="sub lead">{{ $I['states'][$i['rhythm']['state']] }}</p>
                    @else
                        <p class="word">{{ $I['rhythm_none'] }}</p>
                        <p class="sub">{{ $I['rhythm_none_sub'] }}</p>
                    @endif
                    <p class="sub">{{ __('partners.insights.last_ago', ['ago' => trans_choice('partners.insights.ago', $i['since_last'], ['count' => $i['since_last']])]) }}</p>
                </article>

                @if ($i['average'])
                    @php($a = $i['average'])
                    <article class="card tile">
                        <h3>{{ $I['average'] }}</h3>
                        <p class="fig">{{ $intI($a['amount']) }}</p>
                        <p class="sub">
                            @if ($a['pct'] === null || $a['pct'] === 0)
                                {{ $I['average_same'] }}
                            @elseif ($a['pct'] > 0)
                                {{ __('partners.insights.average_up', ['pct' => $pctI($a['pct'])]) }}
                            @else
                                {{ __('partners.insights.average_down', ['pct' => $pctI(-$a['pct'])]) }}
                            @endif
                        </p>
                    </article>
                @endif

                @if ($i['paid'] && $i['paid']['pct'] !== null)
                    @php($pd = $i['paid'])
                    <article class="card tile" title="{{ $I['paid_help'] }}">
                        <h3>{{ $I['paid'][$kind] }}</h3>
                        <p class="fig">{{ $intI($pd['pct']) }}<small>%</small></p>
                        <span class="meter" aria-hidden="true"><i style="--w: {{ max(0, min(100, $pd['pct'])) }}%"></i></span>
                        <p class="sub">{{ $I['paid_of'][$kind] }}</p>
                        <p class="sub">{{ $pd['last_date'] ? __('partners.insights.paid_last', ['date' => $iso($pd['last_date'])]) : $I['paid_none'] }}</p>
                    </article>
                @endif

                @if ($i['margin'])
                    @php($mg = $i['margin'])
                    <article class="card tile" title="{{ __('stock.activity.margin_help') }}">
                        <h3>{{ $I['margin'] }}@if ($mg['partial']) *@endif</h3>
                        <p class="fig">{{ $intI($mg['amount']) }}</p>
                        <p class="sub">{{ __('partners.insights.margin_pct', ['pct' => $pctI($mg['pct'])]) }}</p>
                        @if ($mg['partial'])<p class="sub">{{ $I['margin_partial'] }}</p>@endif
                    </article>
                @endif
            </div>

            <article class="card favs" aria-labelledby="favs-title">
                <div class="favs-head">
                    <h3 id="favs-title">{{ $I['favs'][$kind] }}</h3>
                    <button type="button" class="link rec-open-link" data-open="items">{{ $I['all_items'] }}</button>
                </div>
                <ol class="fav-list">
                    @foreach ($i['items'] as $f)
                        <li class="fav-row">
                            <a class="code ltr" href="{{ route('stock.item', ['code' => $f['code']]) }}">{{ $f['code'] }}</a>
                            <span class="name" title="{{ $f['designation'] }}"><bdi>{{ $f['designation'] ?? '' }}</bdi></span>
                            <span class="share" title="{{ $money ? $I['fav_share'] : $I['fav_bons'] }}">{{ number_format($f['share'], $f['share'] >= 10 ? 0 : 1, ',', ' ') }}%</span>
                            <span class="meter" aria-hidden="true"><i style="--w: {{ min(100, max(0, $f['share'])) }}%"></i></span>
                        </li>
                    @endforeach
                </ol>
                <p class="favs-foot">{{ __('partners.insights.breadth', ['n' => $iso($intI($i['breadth']))]) }}</p>
            </article>

            <article class="card week" aria-labelledby="week-title">
                <h3 id="week-title">{{ $I['week'][$kind] }}</h3>
                <ol class="week-bars">
                    @foreach ($i['week'] as $d)
                        <li @class(['best' => $d['best'], 'empty' => $d['bons'] === 0]) title="{{ __('partners.insights.day_tip', ['day' => $d['name'], 'n' => $d['bons']]) }}">
                            <span class="b" style="height: {{ $d['bons'] ? max(3, round($d['bons'] / $weekMax * 92)) : 2 }}px"></span>
                            <span class="l">{{ $d['short'] }}</span>
                        </li>
                    @endforeach
                </ol>
                @if ($bestDay)
                    <p class="week-say">{{ $i['hour'] !== null ? __('partners.insights.best_day_hour', ['day' => $bestDay['name'], 'hour' => $iso(sprintf('%02d:00', (int) $i['hour']))]) : __('partners.insights.best_day', ['day' => $bestDay['name']]) }}</p>
                @endif
            </article>
        </div>
    @endif
</section>
