{{--
    Le bloc sous la recherche des bons (documents/index) : periode, filtres et leurs nombres, total, bons jour par jour,
    pages. Seul avec ?partial=1 : la recherche pendant la frappe (partials/live-search) le remplace dans la page.
    Les mots cherches sont mis en evidence (numero, client / fournisseur, code).
--}}
@use('App\Support\Highlight')
@php
    $D = __('documents');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $cols = 4 + ($several ? 1 : 0) + ($money ? 1 : 0) + 1;
    $terms = Highlight::terms($q);
@endphp
<div class="bar">
    <nav class="chips" aria-label="{{ $D['period'] }}">
        @foreach (\App\Http\Controllers\DocumentController::PERIODS as $key => $days)
            <a href="{{ route("{$route}.index", array_filter(['q' => $q, 'period' => (string) $key === '30' ? null : $key, 'f' => $filter === 'all' ? null : $filter])) }}"
               data-key="Alt+{{ $loop->iteration }}" @class(['chip', 'on' => $period === (string) $key]) @if ($period === (string) $key) aria-current="true" @endif>{{ $D['periods'][$key] }}</a>
        @endforeach
    </nav>
    <nav class="chips" aria-label="{{ $D['filter'] }}">
        @foreach (\App\Http\Controllers\DocumentController::FILTERS as $f)
            <a href="{{ route("{$route}.index", array_filter(['q' => $q, 'period' => $period === '30' ? null : $period, 'f' => $f === 'all' ? null : $f])) }}"
               @class(['chip', 'on' => $filter === $f]) @if ($filter === $f) aria-current="true" @endif>{{ $D['filters'][$f] }} <span class="n">{{ number_format($counts[$f], 0, ',', ' ') }}</span></a>
        @endforeach
    </nav>
    <div class="bar-end">
        @if ($money && $counts[$filter] > 0)
            <p class="sum">{{ $D['stat_amount'] }} <b class="num">{{ $fmt($total) }}</b></p>
        @endif
        @include('partials.print-button', ['href' => route("{$route}.print", array_filter(['q' => $q, 'period' => $period, 'f' => $filter === 'all' ? null : $filter]))])
    </div>
</div>

<div class="card">
    @if ($branches === 0)
        <p class="empty">{{ $selected ? __('documents.no_branch_selected', ['branch' => $selected->name]) : $D['no_branch'] }}</p>
    @elseif ($groups->isEmpty())
        <p class="empty">{{ $q !== '' ? __('documents.none_search', ['q' => $q]) : $D['none'][$kind] }}</p>
    @else
        <table class="bons">
            <thead>
            <tr>
                <th scope="col">{{ $D['col_bon'] }}</th>
                <th scope="col" class="hide-sm">{{ $D['col_time'] }}</th>
                @if ($several)<th scope="col">{{ $D['col_branch'] }}</th>@endif
                <th scope="col">{{ $D['col_partner'][$kind] }}</th>
                <th scope="col" class="n">{{ $D['col_lines'] }}</th>
                @if ($money)<th scope="col" class="n">{{ $D['col_amount'] }}</th>@endif
                <th scope="col" class="hide-sm">{{ $kind === 'sale' ? $D['col_served_by'] : $D['col_payment'] }}</th>
            </tr>
            </thead>
            @foreach ($groups as $day)
                <tbody>
                <tr class="day">
                    <th scope="rowgroup" colspan="{{ $cols }}">
                        <span class="when">
                            <span>{{ $day['label']['name'] }}</span>
                            <span class="date num">{{ $day['label']['date'] }}</span>
                            <span class="grow"></span>
                            <span class="muted small">{{ trans_choice('documents.bons', $day['bons'], ['count' => $day['bons']]) }}</span>
                            @if ($money)<span class="day-sum num">{{ $fmt($day['amount']) }}</span>@endif
                        </span>
                    </th>
                </tr>
                @foreach ($day['rows'] as $r)
                    <tr @class(['bt' => $several]) @if ($several) style="--bh: {{ $r['hue'] }}" @endif>
                        <td><a class="bon ltr" href="{{ $r['url'] }}">{{ Highlight::html((string) $r['number'], $terms) }}</a></td>
                        <td class="num muted hide-sm">{{ $r['time'] }}</td>
                        @if ($several)<td>{{ $r['branch'] }}</td>@endif
                        <td>@if ($r['partner_url'])<a class="who" href="{{ $r['partner_url'] }}"><bdi>{{ Highlight::html($r['partner'], $terms) }}</bdi></a>@else<bdi>{{ $r['partner'] !== null ? Highlight::html($r['partner'], $terms) : '—' }}</bdi>@endif @if ($r['partner_code']) <span class="muted small ltr hide-sm">{{ Highlight::html($r['partner_code'], $terms) }}</span>@endif</td>
                        <td class="n">
                            @if ($r['returns'] > 0)<span class="pill" title="{{ trans_choice('documents.return_lines', $r['returns'], ['count' => $r['returns']]) }}">{{ $D['return'] }}</span>@endif
                            <span class="num">{{ $r['lines'] }}</span>
                        </td>
                        @if ($money)<td class="n"><span class="num">{{ $fmt($r['amount']) }}</span></td>@endif
                        <td class="hide-sm muted"><bdi>{{ $kind === 'sale' ? $r['served_by'] : $r['payment'] }}</bdi></td>
                    </tr>
                @endforeach
                </tbody>
            @endforeach
        </table>
        @if ($documents->hasPages())
            <nav class="pager" aria-label="{{ $D['pages'] }}">
                @if ($documents->previousPageUrl())<a class="btn" href="{{ $documents->previousPageUrl() }}">{{ $D['newer'] }}</a>@else<span></span>@endif
                @if ($documents->nextPageUrl())<a class="btn" href="{{ $documents->nextPageUrl() }}">{{ $D['older'] }}</a>@endif
            </nav>
        @endif
    @endif
</div>
