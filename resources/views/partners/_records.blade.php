{{--
    Une page de la fenetre de la fiche (PartnerController::records), chargee par partners/_records-modal : ses bons, ses
    versements, ses articles, ou un article bon par bon (lines). Les data-* du haut donnent au pied de la fenetre ou l'on
    en est. tr[data-bon] / tr[data-code] : la ligne s'ouvre dans la fenetre, ses liens restent de vrais liens (Ctrl+clic).
    Un retour : "مرتجع", jamais en jaune ni en rouge ; seul le stock garde les couleurs de Gescomm.
--}}
@php
    $P = __('partners');
    $R = $P['records'];
    $D = __('documents');
    $fmt = fn ($n) => $n === null ? '—' : number_format((float) $n, 2, ',', ' ');
    $int = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $qtyf = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 2, ',', ' '), '0'), ',');
    // une colonne triable : un bouton dans l'entete, et l'etat lu par les lecteurs d'ecran
    $th = function (string $key, string $label, string $class = '') use ($sort, $dir, $sorts) {
        if (! in_array($key, $sorts, true)) {
            return new \Illuminate\Support\HtmlString('<th scope="col" class="'.e($class).'">'.e($label).'</th>');
        }
        $on = $sort === $key;
        $aria = $on ? ' aria-sort="'.($dir === 'asc' ? 'ascending' : 'descending').'"' : '';
        $arrow = $on ? ($dir === 'asc' ? '↑' : '↓') : '';

        return new \Illuminate\Support\HtmlString('<th scope="col" class="'.e($class).'"'.$aria.'><button type="button" class="sort" data-sort="'.e($key).'">'.e($label).'<span class="arrow" aria-hidden="true">'.$arrow.'</span></button></th>');
    };
    $emptyText = match (true) {
        $q !== '' => __('partners.records.none_search', ['q' => $q]),
        $tab === 'payments' => $P['none_payments'],
        $tab === 'items' => $P['none_items'],
        $tab === 'lines' => $R['none_item'],
        default => $P['none_bons'],
    };
@endphp
<div class="rec-data" data-page="{{ $page->currentPage() }}" data-pages="{{ max(1, $page->lastPage()) }}" data-total="{{ $page->total() }}"
     data-range="{{ $page->total() ? __('partners.records.range', ['from' => $int($page->firstItem()), 'to' => $int($page->lastItem()), 'total' => $int($page->total())]) : '' }}"
     @isset($item) data-title="{{ $item['code'] }}" data-sub="{{ $item['designation'] }}" data-item-url="{{ route('stock.item', ['code' => $item['code']]) }}" @endisset>
    <div class="rec-sum">
        @if ($filters !== [])
            <div class="chips" role="group" aria-label="{{ $P['filter'] }}">
                @foreach ($filters as $value => $n)
                    @php($value = (string) $value)
                    <button type="button" data-filter="{{ $value }}" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" @class(['chip', 'on' => $filter === $value])>
                        @if ($value === ''){{ $R['all'] }}@elseif ($tab === 'bons'){{ $R['with_returns'] }}@else<bdi>{{ $value }}</bdi>@endif
                        @if ($n !== null) <span class="num muted">{{ $int($n) }}</span>@endif
                    </button>
                @endforeach
            </div>
        @endif
        <dl class="rec-totals">
            @if ($tab === 'lines')
                <div><dt>{{ $R['sum_sold'][$kind] }}</dt><dd class="num">{{ $qtyf($summary['sold']) }}</dd></div>
                @if ($summary['returned'] > 0)<div><dt>{{ $R['sum_back'] }}</dt><dd class="num">{{ $qtyf($summary['returned']) }}</dd></div>@endif
                <div><dt>{{ $R['sum_net'] }}</dt><dd class="num">{{ $qtyf($summary['qty']) }}</dd></div>
                @if ($money)
                    <div><dt>{{ $R['sum_amount'] }}</dt><dd class="num">{{ $fmt($summary['amount']) }}</dd></div>
                    @if ($summary['average'] !== null)<div class="hide-sm"><dt>{{ $R['sum_average'] }}</dt><dd class="num">{{ $fmt($summary['average']) }}</dd></div>@endif
                    @if ($summary['last_price'] !== null)<div class="strong"><dt>{{ $R['sum_last_price'] }}</dt><dd class="num">{{ $fmt($summary['last_price']) }}</dd></div>@endif
                @endif
            @else
                <div><dt>{{ $R['sum_count'][$tab] }}</dt><dd class="num">{{ $int($summary['n']) }}</dd></div>
                @if ($money)
                    @if ($summary['discount'] ?? null)<div class="hide-sm"><dt>{{ $R['sum_discount'] }}</dt><dd class="num">{{ $fmt($summary['discount']) }}</dd></div>@endif
                    <div class="strong"><dt>{{ $R['sum_amount'] }}</dt><dd class="num">{{ $fmt($summary['amount']) }}</dd></div>
                @endif
            @endif
        </dl>
    </div>

    <div class="rec-scroll">
        @if ($rows->isEmpty())
            <p class="muted rec-empty">{{ $emptyText }}</p>
        @elseif ($tab === 'bons')
            <table>
                <thead>
                <tr>
                    {{ $th('number', __('documents.col_bon')) }}
                    {{ $th('date', $P['col_date']) }}
                    {{ $th('lines', $D['col_lines'], 'n') }}
                    <th scope="col" class="hide-sm" title="Réglement">{{ $D['col_payment'] }}</th>
                    <th scope="col" class="hide-sm" title="Servi par">{{ $D['col_served_by'] }}</th>
                    <th scope="col" class="hide-sm">{{ $D['note'] }}</th>
                    @if ($money)
                        <th scope="col" class="n hide-sm">{{ $D['col_discount'] }}</th>
                        {{ $th('amount', $D['col_amount'], 'n') }}
                    @endif
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $b)
                    <tr data-row data-bon="{{ $b['url'] }}" data-number="{{ $b['number'] }}" tabindex="-1">
                        <td><a class="bon ltr" href="{{ $b['url'] }}" tabindex="-1">{{ $b['number'] }}</a></td>
                        <td class="num">{{ $b['date'] }} <span class="muted small">{{ $b['time'] }}</span></td>
                        <td class="n">@if ($b['returns'] > 0)<span class="pill">{{ $D['return'] }}</span> @endif<span class="num">{{ $b['lines'] }}</span></td>
                        <td class="hide-sm"><bdi>{{ $b['payment'] }}</bdi></td>
                        <td class="hide-sm"><bdi>{{ $b['served_by'] }}</bdi></td>
                        <td class="hide-sm muted cut" title="{{ $b['note'] }}"><bdi>{{ $b['note'] }}</bdi></td>
                        @if ($money)
                            <td class="n hide-sm muted"><span class="num">{{ $b['discount'] ? $fmt($b['discount']) : '' }}</span></td>
                            <td class="n"><span class="num strong">{{ $fmt($b['amount']) }}</span></td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        @elseif ($tab === 'payments')
            <table>
                <thead>
                <tr>
                    {{ $th('date', $P['col_date']) }}
                    <th scope="col" class="hide-sm">{{ $R['col_number'] }}</th>
                    <th scope="col" title="TYPEVER">{{ $P['col_type'] }}</th>
                    @if ($money){{ $th('amount', $P['col_paid'], 'n') }}@endif
                    <th scope="col" class="hide-sm">{{ $P['col_cheque'] }}</th>
                    <th scope="col">{{ __('documents.col_bon') }}</th>
                    <th scope="col" class="hide-sm">{{ $D['note'] }}</th>
                    <th scope="col" class="hide-sm" title="Saisi Par">{{ $D['entered_by'] }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $p)
                    <tr data-row @if ($p['bon_url']) data-bon="{{ $p['bon_url'] }}" data-number="{{ $p['bon'] }}" @endif tabindex="-1">
                        <td class="num">{{ $p['date'] }} <span class="muted small">{{ $p['time'] }}</span></td>
                        <td class="hide-sm muted"><span class="num">{{ $p['number'] }}</span></td>
                        <td><span class="pill"><bdi>{{ $p['type'] ?? '—' }}</bdi></span></td>
                        @if ($money)<td class="n"><span class="num strong">{{ $fmt($p['amount']) }}</span></td>@endif
                        <td class="hide-sm"><span class="num">{{ $p['cheque'] }}</span>@if ($p['paid_on']) <span class="muted small">{{ __('partners.paid_on', ['date' => $p['paid_on']]) }}</span>@endif</td>
                        <td>@if ($p['bon_url'])<a class="bon ltr" href="{{ $p['bon_url'] }}" tabindex="-1">{{ $p['bon'] }}</a>@else<span class="num muted">{{ $p['bon'] ?: '' }}</span>@endif</td>
                        <td class="hide-sm muted cut" title="{{ $p['note'] }}"><bdi>{{ $p['note'] }}</bdi></td>
                        <td class="hide-sm muted"><bdi>{{ $p['entered_by'] }}</bdi></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @elseif ($tab === 'items')
            <table>
                <thead>
                <tr>
                    <th scope="col">{{ $D['col_code'] }}</th>
                    <th scope="col">{{ $D['col_designation'] }}</th>
                    <th scope="col" class="n hide-sm">{{ $R['col_sold'][$kind] }}</th>
                    <th scope="col" class="n hide-sm">{{ $R['col_back'] }}</th>
                    {{ $th('qty', $P['col_net'], 'n') }}
                    @if ($money)
                        <th scope="col" class="n hide-sm">{{ $R['col_average'] }}</th>
                        {{ $th('amount', $D['col_amount'], 'n') }}
                    @endif
                    {{ $th('bons', $R['col_bons'], 'n hide-sm') }}
                    {{ $th('last', $R['col_last'], 'hide-sm') }}
                    @if ($seesStock)<th scope="col" class="n hide-sm" title="{{ $D['stock_help'] }}">{{ $D['col_stock'] }}</th>@endif
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $i)
                    <tr data-row data-code="{{ $i['key'] }}" tabindex="-1">
                        <td><a class="code ltr" href="{{ route('stock.item', ['code' => $i['code']]) }}" tabindex="-1">{{ $i['code'] }}</a></td>
                        <td class="cut wide" title="{{ $i['designation'] }}"><bdi>{{ $i['designation'] ?? '—' }}</bdi></td>
                        <td class="n hide-sm"><span class="num">{{ $qtyf($i['sold']) }}</span></td>
                        <td class="n hide-sm muted"><span class="num">{{ $i['returned'] > 0 ? $qtyf($i['returned']) : '' }}</span></td>
                        <td class="n"><span class="num strong">{{ $qtyf($i['qty']) }}</span></td>
                        @if ($money)
                            <td class="n hide-sm"><span class="num">{{ $fmt($i['average']) }}</span></td>
                            <td class="n"><span class="num strong">{{ $fmt($i['amount']) }}</span></td>
                        @endif
                        <td class="n hide-sm"><span class="num">{{ $i['bons'] }}</span></td>
                        <td class="hide-sm"><span class="num">{{ $i['last'] }}</span></td>
                        @if ($seesStock)
                            <td class="n hide-sm">@if ($i['stock'] !== null)<span class="qty {{ $i['state'] }}">{{ $qtyf($i['stock']) ?: '0' }}</span>@else<span class="muted">—</span>@endif</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <table>
                <thead>
                <tr>
                    {{ $th('date', $P['col_date']) }}
                    <th scope="col">{{ __('documents.col_bon') }}</th>
                    {{ $th('qty', $D['col_qty'], 'n') }}
                    @if ($money)
                        {{ $th('price', $D['col_price'], 'n') }}
                        <th scope="col" class="n hide-sm">{{ $D['col_discount'] }}</th>
                        {{ $th('total', $D['col_total'], 'n') }}
                    @endif
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $l)
                    <tr data-row data-bon="{{ $l['url'] }}" data-number="{{ $l['number'] }}" tabindex="-1" @class(['back' => $l['back']])>
                        <td class="num">{{ $l['date'] }} <span class="muted small">{{ $l['time'] }}</span></td>
                        <td><a class="bon ltr" href="{{ $l['url'] }}" tabindex="-1">{{ $l['number'] }}</a></td>
                        <td class="n">@if ($l['back'])<span class="pill">{{ $D['return'] }}</span> @endif<span class="num strong">{{ $qtyf($l['qty']) }}</span></td>
                        @if ($money)
                            <td class="n"><span class="num">{{ $fmt($l['price']) }}</span></td>
                            <td class="n hide-sm muted"><span class="num">{{ $l['discount'] ? $fmt($l['discount']) : '' }}</span></td>
                            <td class="n"><span class="num">{{ $fmt($l['total']) }}</span></td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
