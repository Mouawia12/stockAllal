{{--
    Le bloc sous la recherche d'un rapport (reports/index) : ses filtres et leurs nombres, la liste (un clic sur un titre
    de colonne la trie, un deuxieme inverse), ses totaux, ses pages. Seul avec ?partial=1 : la recherche pendant la frappe
    (partials/live-search) le remplace dans la page. Chaque colonne s'ecrit selon son type (App\Support\Reports::columns).
--}}
@use('App\Support\Highlight')
@use('App\Support\Reports')
@use('Illuminate\Support\Carbon')
@php
    $R = __('reports');
    $locale = app()->getLocale();
    $n0 = fn ($v) => $v === null ? '' : number_format(round($v), 0, ',', ' ');
    $n2 = fn ($v) => $v === null ? '' : number_format($v, 2, ',', ' ');
    $qty = fn ($v) => $v === null ? '' : number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2, ',', ' ');
    $pct = fn ($v) => $v === null ? '' : number_format($v, 1, ',', ' ').'%';
    $date = fn ($v) => $v === null ? '' : Carbon::parse($v)->format('d/m/Y');
    $url = fn (array $change = []) => route('dashboard.reports', ['report' => $report] + array_filter(array_merge($periodState, $listState, $change), fn ($v) => $v !== null && $v !== ''));
    $numeric = fn (array $col) => ! in_array($col['type'], ['item', 'partner', 'text', 'branch', 'period', 'class', 'elsewhere'], true);
    // un nombre dans une phrase : isole de gauche a droite, sinon "33 230" s'affiche "230 33" en arabe
    $iso = fn (string $s) => "\u{2066}{$s}\u{2069}";
    // "النواقص" : les branches affichees d'emblee dans la cellule "ailleurs" ; les autres sont dans sa fenetre
    $elseShown = 2;
    // les classes d'une cellule : n = un nombre (aligne a la fin), hide-sm = cachee sur telephone
    $cls = fn (array $col, array $more = []) => implode(' ', array_keys(array_filter(['n' => $numeric($col), 'hide-sm' => ! $col['sm']] + $more)));
    $partnerRoute = in_array($report, ['customers', 'receivable'], true) ? 'customers.show' : 'suppliers.show';
    $label = fn (string $f) => $R['filters_of'][$report][$f] ?? $R['filters'][$f];
    // une valeur de la ligne de total, ecrite comme sa colonne
    $total = fn (array $col) => match (true) {
        ! array_key_exists($col['key'], $totals) => '',
        in_array($col['type'], ['pct', 'share'], true) => $pct($totals[$col['key']]),
        $col['type'] === 'qty' => $qty($totals[$col['key']]),
        $col['type'] === 'money2' => $n2($totals[$col['key']]),
        default => $n0($totals[$col['key']]),
    };
@endphp
@if (count($filters) > 1)
    <div class="bar filters">
        <nav class="chips" aria-label="{{ $R['filter'] }}">
            @foreach ($filters as $f)
                <a href="{{ $url(['f' => $f === $filters[0] ? null : $f, 'page' => null]) }}"
                   @class(['chip', 'on' => $filter === $f]) @if ($filter === $f) aria-current="true" @endif>{{ $label($f) }} <span class="n">{{ number_format($counts[$f], 0, ',', ' ') }}</span></a>
            @endforeach
        </nav>
    </div>
@endif

<div class="card">
    @if ($branches === 0)
        <p class="empty">{{ $selected ? __('documents.no_branch_selected', ['branch' => $selected->name]) : __('documents.no_branch') }}</p>
    @elseif ($unseen)
        <p class="empty">{{ __('dashboard.stock_unseen') }}</p>
    @elseif ($rows->isEmpty())
        <p class="empty">{{ $q !== '' ? __('reports.none_search', ['q' => $q]) : $R['none'] }}</p>
    @else
        <p class="count muted small">
            {{ trans_choice('reports.rows', $matched, ['count' => number_format($matched, 0, ',', ' ')]) }}
            @if ($pages > 1) · {{ __('reports.page', ['page' => $current, 'pages' => $pages]) }}@endif
        </p>
        <table class="list rep">
            <thead>
            <tr>
                <th scope="col" class="rank">#</th>
                @foreach ($columns as $col)
                    @php
                        $on = $sort === $col['key'];
                        $next = $on ? ($dir === 'asc' ? 'desc' : 'asc') : $col['dir'];
                        $name = $R['cols'][$col['label']];
                    @endphp
                    <th scope="col" class="{{ $cls($col) }}" @if ($on) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                        <a class="sort" href="{{ $url(['sort' => $col['key'], 'dir' => $next, 'page' => null]) }}" title="{{ __('reports.sort_by', ['name' => $name]) }}">{{ $name }}@if ($on)<span class="arrow" aria-hidden="true">{{ $dir === 'asc' ? '▲' : '▼' }}</span>@endif</a>
                    </th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $r)
                <tr @if ($several && isset($r['branch_id'])) class="bt" style="--bh: {{ App\Models\Branch::hueFor($r['branch_id']) }}" @endif>
                    <td class="rank">{{ $offset + $loop->iteration }}</td>
                    @foreach ($columns as $col)
                        @php
                            $v = $r[$col['key']] ?? null;
                        @endphp
                        <td class="{{ $cls($col, ['item' => in_array($col['type'], ['item', 'partner', 'period'], true), 'strong' => $col['strong']]) }}">
                            @switch($col['type'])
                                @case('item')
                                    <a href="{{ route('stock.item', ['code' => $r['key']]) }}"><span class="name"><bdi>{{ Highlight::html($r['designation'] ?? $r['code'], $terms) }}</bdi></span>@if ($r['designation'])<span class="code ltr">{{ Highlight::html($r['code'], $terms) }}</span>@endif</a>
                                    @break
                                @case('partner')
                                    @if ($r['id'])<a href="{{ route($partnerRoute, $r['id']) }}"><span class="name"><bdi>{{ Highlight::html($r['name'] ?? $r['code'], $terms) }}</bdi></span>@if ($r['name'])<span class="code ltr">{{ Highlight::html($r['code'], $terms) }}</span>@endif</a>
                                    @else<span class="name"><bdi>{{ Highlight::html($r['code'], $terms) }}</bdi></span>@endif
                                    @break
                                @case('period')
                                    @if (strlen($v) === 7)
                                        <a href="{{ route('dashboard.index', ['from' => $v.'-01', 'to' => min(Carbon::parse($v.'-01')->endOfMonth()->toDateString(), $today)]) }}"><span class="name">{{ Carbon::parse($v.'-01')->locale($locale)->translatedFormat('F Y') }}</span></a>
                                    @else
                                        <a href="{{ route('dashboard.index', ['day' => $v]) }}"><span class="name">{{ Carbon::parse($v)->locale($locale)->translatedFormat('l d/m/Y') }}</span></a>
                                    @endif
                                    @break
                                @case('branch')
                                    <span class="where" style="--bh: {{ App\Models\Branch::hueFor($r['branch_id']) }}"><span class="bdot"></span><bdi>{{ $names[$r['branch_id']] ?? '' }}</bdi></span>
                                    @break
                                @case('text')
                                    <bdi>{{ $v ?? $R['unknown'] }}</bdi>
                                    @break
                                @case('class')
                                    @if ($v)<span class="abc {{ $v }}">{{ strtoupper($v) }}</span>@endif
                                    @break
                                @case('stock')
                                    @if ($v === null)<span class="muted">—</span>@else<span class="qty {{ $r['state'] ?? '' }}">{{ $qty($v) }}</span>@endif
                                    @break
                                @case('elsewhere')
                                    {{-- ou le trouver : les autres branches qui en ont, la plus fournie d'abord ; un clic = le detail --}}
                                    @php
                                        $else = $r['else'] ?? [];
                                        $soldThere = collect($else)->contains(fn (array $e) => $e['sold'] !== null);
                                    @endphp
                                    @if ($else === [])
                                        <span class="muted" title="{{ $R['else']['none'] }}">—</span>
                                    @else
                                        <button type="button" class="else" data-else aria-haspopup="dialog" title="{{ $R['else']['open'] }}">@foreach (array_slice($else, 0, $elseShown) as $e)<span class="where" style="--bh: {{ App\Models\Branch::hueFor($e['branch_id']) }}"><span class="bdot"></span><bdi>{{ $others->get($e['branch_id'])?->name }}</bdi><span class="qty {{ $e['state'] ?? '' }}">{{ $qty($e['stock']) }}</span></span>@endforeach @if (count($else) > $elseShown)<span class="more">+{{ count($else) - $elseShown }}</span>@endif</button>
                                        <template>
                                            <p class="else-what"><b><bdi>{{ $r['designation'] ?? $r['code'] }}</bdi></b>@if ($r['designation']) <span class="code ltr">{{ $r['code'] }}</span>@endif</p>
                                            <p class="else-here muted small">{{ __('reports.else.here', ['branch' => $names[$r['branch_id']] ?? '', 'stock' => $iso($qty($r['stock'])), 'sold' => $iso($qty($r['sold']))]) }}</p>
                                            <table class="list else-list">
                                                <thead>
                                                <tr>
                                                    <th scope="col">{{ $R['cols']['branch'] }}</th>
                                                    <th scope="col" class="n">{{ $R['else']['qty'] }}</th>
                                                    <th scope="col" class="n hide-sm">{{ $R['cols']['alert'] }}</th>
                                                    @if ($soldThere)<th scope="col" class="n">{{ $R['else']['sold'] }}</th>@endif
                                                    <th scope="col" class="hide-sm">{{ $R['else']['synced'] }}</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach ($else as $e)
                                                    <tr class="bt" style="--bh: {{ App\Models\Branch::hueFor($e['branch_id']) }}">
                                                        <td><span class="where"><span class="bdot"></span><bdi>{{ $others->get($e['branch_id'])?->name }}</bdi></span>@if ($e['code'])<span class="code ltr" title="{{ $R['else']['code'] }}">{{ $e['code'] }}</span>@endif</td>
                                                        <td class="n"><span class="qty {{ $e['state'] ?? '' }}">{{ $qty($e['stock']) }}</span></td>
                                                        <td class="n hide-sm"><span class="num muted">{{ $qty($e['alert']) }}</span></td>
                                                        @if ($soldThere)<td class="n">@if ($e['sold'] === null)<span class="muted" title="{{ $R['else']['sales_unseen'] }}">—</span>@else<span class="num">{{ $qty($e['sold']) }}</span>@endif</td>@endif
                                                        <td class="hide-sm"><span class="muted small">{{ $others->get($e['branch_id'])?->last_sync_at?->diffForHumans() ?? '—' }}</span></td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                                @if (count($else) > 1)
                                                    <tfoot><tr><td>{{ $R['total'] }}</td><td class="n"><span class="num">{{ $qty($v) }}</span></td><td class="hide-sm"></td>@if ($soldThere)<td></td>@endif<td class="hide-sm"></td></tr></tfoot>
                                                @endif
                                            </table>
                                            <p class="else-foot"><a class="btn" href="{{ route('stock.item', ['code' => $r['key']]) }}">{{ $R['else']['item'] }}</a></p>
                                        </template>
                                    @endif
                                    @break
                                @case('share')
                                    <span class="share-cell"><span class="num">{{ $pct($v) }}</span><span class="share" aria-hidden="true"><i style="--w: {{ min(100, max(0, (float) $v)) }}%"></i></span></span>
                                    @break
                                @case('idle')
                                    @if ($v === Reports::NEVER)<span class="muted">{{ $R['never'] }}</span>@else<span class="num">{{ $n0($v) }}</span>@endif
                                    @break
                                @case('date')
                                    <span class="num muted">{{ $date($v) }}</span>
                                    @break
                                @case('qty')
                                    <span class="num">{{ $qty($v) }}</span>
                                    @break
                                @case('pct')
                                    <span class="num">{{ $pct($v) }}</span>
                                    @break
                                @case('money2')
                                    <span class="num">{{ $n2($v) }}</span>
                                    @break
                                @default
                                    <span class="num">{{ $n0($v) }}</span>
                            @endswitch
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
            @if ($totals !== [])
                <tfoot>
                <tr>
                    <td class="rank"></td>
                    @foreach ($columns as $col)
                        <td class="{{ $cls($col) }}">@if ($loop->first){{ $R['total'] }}@else<span class="num">{{ $total($col) }}</span>@endif</td>
                    @endforeach
                </tr>
                </tfoot>
            @endif
        </table>
        @if ($pages > 1)
            <nav class="pager" aria-label="{{ $R['pages'] }}">
                @if ($current > 1)<a class="btn" href="{{ $url(['page' => $current - 1 > 1 ? $current - 1 : null]) }}">{{ $R['previous'] }}</a>@else<span></span>@endif
                <span class="muted small">{{ __('reports.page', ['page' => $current, 'pages' => $pages]) }}</span>
                @if ($current < $pages)<a class="btn" href="{{ $url(['page' => $current + 1]) }}">{{ $R['next'] }}</a>@else<span></span>@endif
            </nav>
        @endif
    @endif
</div>
