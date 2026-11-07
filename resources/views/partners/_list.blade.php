{{--
    Le bloc sous la recherche des clients / fournisseurs (partners/index) : periode, filtres et leurs nombres,
    impression, liste, pages. Seul avec ?partial=1 : la recherche pendant la frappe (partials/live-search) le remplace
    dans la page. Les mots cherches sont mis en evidence (nom, code, telephone).
--}}
@use('App\Support\Highlight')
@php
    $P = __('partners');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $keep = fn (array $change) => array_filter(array_merge(['q' => $q, 'period' => $period === '365' ? null : $period, 'f' => $filter === 'all' ? null : $filter], $change));
    $terms = Highlight::terms($q);
@endphp
<div class="bar">
    <nav class="chips" aria-label="{{ $P['period'] }}">
        @foreach (\App\Http\Controllers\PartnerController::LIST_PERIODS as $key => $days)
            <a href="{{ route("{$route}.index", $keep(['period' => (string) $key === '365' ? null : $key])) }}" data-key="Alt+{{ $loop->iteration }}"
               @class(['chip', 'on' => $period === (string) $key]) @if ($period === (string) $key) aria-current="true" @endif>{{ $P['periods'][$key] }}</a>
        @endforeach
    </nav>
    <nav class="chips" aria-label="{{ $P['filter'] }}">
        @foreach (\App\Http\Controllers\PartnerController::FILTERS as $f)
            <a href="{{ route("{$route}.index", $keep(['f' => $f === 'all' ? null : $f])) }}"
               @class(['chip', 'on' => $filter === $f]) @if ($filter === $f) aria-current="true" @endif>{{ $P['filters'][$kind][$f] }} <span class="n">{{ number_format($counts[$f], 0, ',', ' ') }}</span></a>
        @endforeach
    </nav>
    <details class="drop print-drop">
        <summary class="btn print-btn" data-key="Alt+P" title="{{ __('print.print') }} (Alt+P)">@include('partials.app-icon', ['name' => 'print']){{ __('print.print') }}</summary>
        <div class="drop-menu">
            <a href="{{ route("{$route}.print", array_filter(['q' => $q, 'period' => $period, 'f' => $filter === 'all' ? null : $filter])) }}" target="_blank">{{ __("print.partners.menu_list.{$kind}") }}</a>
            <a href="{{ route("{$route}.print-payments") }}" target="_blank">{{ __("print.partners.menu_payments.{$kind}") }}</a>
        </div>
    </details>
</div>

<div class="card">
    @if ($branches === 0)
        <p class="empty">{{ $selected ? __('documents.no_branch_selected', ['branch' => $selected->name]) : __('documents.no_branch') }}</p>
    @elseif ($rows->isEmpty())
        <p class="empty">{{ $q !== '' ? __('partners.none_search', ['q' => $q]) : $P['none'][$kind] }}</p>
    @else
        <table class="people">
            <thead>
            <tr>
                <th scope="col">{{ $P['col_name'][$kind] }}</th>
                @if ($several)<th scope="col">{{ __('documents.col_branch') }}</th>@endif
                <th scope="col" class="hide-sm">{{ $P['col_phone'] }}</th>
                <th scope="col" class="hide-sm">{{ $P['col_wilaya'] }}</th>
                <th scope="col" class="n">{{ $P['col_bons'] }}</th>
                @if ($money)<th scope="col" class="n">{{ $P['col_amount'][$kind] }}</th>@endif
                <th scope="col" class="n hide-sm">{{ $P['col_last'] }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $r)
                <tr @class(['bt' => $several]) @if ($several) style="--bh: {{ $r['hue'] }}" @endif>
                    <td>
                        <a class="who" href="{{ $r['url'] }}"><bdi>{{ Highlight::html($r['name'] ?? $r['code'], $terms) }}</bdi></a><span class="code ltr">{{ Highlight::html($r['code'], $terms) }}</span>
                        @if ($r['blocked'])<span class="pill" title="{{ $P['blocked_help'] }}">{{ $P['blocked'] }}</span>@endif
                    </td>
                    @if ($several)<td>{{ $r['branch'] }}</td>@endif
                    <td class="hide-sm num muted">{{ Highlight::html($r['phone'], $terms) }}</td>
                    <td class="hide-sm muted"><bdi>{{ $r['wilaya'] }}</bdi></td>
                    <td class="n"><span class="num">{{ $r['bons'] ?: '' }}</span></td>
                    @if ($money)<td class="n"><span class="num">{{ $r['bons'] ? $fmt($r['amount']) : '' }}</span></td>@endif
                    <td class="n hide-sm muted"><span class="num">{{ $r['last'] }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($partners->hasPages())
            <nav class="pager" aria-label="{{ __('documents.pages') }}">
                @if ($partners->previousPageUrl())<a class="btn" href="{{ $partners->previousPageUrl() }}">{{ $P['previous'] }}</a>@else<span></span>@endif
                @if ($partners->nextPageUrl())<a class="btn" href="{{ $partners->nextPageUrl() }}">{{ $P['next'] }}</a>@endif
            </nav>
        @endif
    @endif
</div>
