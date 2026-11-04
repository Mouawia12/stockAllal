{{--
    Le "rayon" de la page d'un article : une ligne par branche affichee, une barre proportionnelle a son stock.
    Rendu dans la page, et seul (?partial=1&batch=...) pendant "تحديث الآن" : data-done dit quand s'arreter.
    $branches, $main_prices, $show_sale, $show_buy, $codes, $done : StockController::productData().
--}}
@php
    $T = __('stock.js');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $peak = max(0.01, (float) collect($branches)->flatMap(fn (array $b) => $b['rows'])->max('stock'));
    $differs = fn (bool $main, ?array $row, string $field) => ! $main && $row !== null && ($main_prices[$field] ?? null) !== null
        && ($row[$field] ?? null) !== null && abs($row[$field] - $main_prices[$field]) >= 0.005;
    $priceFields = array_values(array_filter([$show_sale ? 'price' : null, $show_sale ? 'price_wholesale' : null, $show_buy ? 'price_buy' : null]));
@endphp
<table class="shelf-table" data-done="{{ $done ? 1 : 0 }}">
    <thead>
    <tr>
        <th>{{ __('stock.col_branch') }}</th>
        <th class="meter-cell">{{ __('stock.col_quantity') }}</th>
        @foreach ($priceFields as $field)
            <th @class(['hide-sm' => $field !== 'price'])>{{ __(['price' => 'stock.col_retail', 'price_wholesale' => 'stock.col_wholesale', 'price_buy' => 'stock.col_buy'][$field]) }}</th>
        @endforeach
        <th class="hide-sm">{{ __('stock.col_status') }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($branches as $b)
        @php($rows = $b['rows']->isEmpty() ? collect([null]) : $b['rows'])
        @foreach ($rows as $r)
            <tr @class(['none' => $r === null])>
                <td>
                    <b>{{ $b['branch'] }}</b>
                    @if ($b['main'])<span class="pill ok">{{ $T['main'] }}</span>@endif
                    {{-- groupe de codes fusionnes : le code tel qu'il est dans cette branche --}}
                    @if ($r && count($codes) > 1)<div class="code ltr small muted">{{ $r['code'] }}</div>@endif
                </td>
                <td class="meter-cell">
                    @if ($r)
                        <span class="meter">
                            <span class="meter-track" aria-hidden="true"><span style="--w: {{ $r['stock'] > 0 ? round(100 * $r['stock'] / $peak, 1) : 0 }}%"></span></span>
                            <span class="qty {{ $r['state'] }}" @if ($r['state']) title="{{ __("stock.state.{$r['state']}") }}" @endif>{{ $fmt($r['stock']) }}</span>
                        </span>
                    @else
                        <span class="muted small">{{ $T['not_found'] }}</span>
                    @endif
                </td>
                @foreach ($priceFields as $field)
                    <td @class(['hide-sm' => $field !== 'price'])>
                        @if ($r === null)
                            <span class="muted">—</span>
                        @elseif ($differs($b['ref'], $r, $field))
                            <span class="pill warn num" title="{{ str_replace(':price', $fmt($main_prices[$field]), $T['main_price']) }}">{{ $fmt($r[$field]) }}</span>
                        @else
                            <span class="num {{ $field === 'price_buy' ? 'muted' : '' }}">{{ $fmt($r[$field] ?? null) }}</span>
                        @endif
                    </td>
                @endforeach
                <td class="hide-sm small">
                    @switch($b['status'])
                        @case('pending')
                        @case('taken')
                            <span class="wait muted">{{ $T['waiting'] }}</span>
                            @break
                        @case('done')
                            <span class="pill ok">{{ $T['now'] }}</span>
                            @break
                        @case('not_found')
                            <span class="pill">{{ $T['not_in_branch'] }}</span>
                            @break
                        @case('error')
                            <span class="pill bad">{{ $T['read_error'] }}</span>
                            @break
                        @case('timeout')
                            <span class="pill warn">{{ $T['no_answer'] }}@if ($r) — {{ str_replace(':time', $r['updated'], $T['last_value']) }}@endif</span>
                            @break
                        @default
                            @if (! $b['online'])
                                <span class="pill warn">{{ $T['offline'] }}@if ($r) — {{ $r['updated'] }}@endif</span>
                            @elseif ($r)
                                <span class="muted">{{ $r['updated'] }}</span>
                            @endif
                    @endswitch
                </td>
            </tr>
        @endforeach
    @empty
        <tr><td colspan="6" class="muted">{{ $T['no_branches'] }}</td></tr>
    @endforelse
    </tbody>
</table>
