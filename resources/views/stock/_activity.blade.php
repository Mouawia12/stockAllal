{{--
    Ventes et achats d'un article (App\Support\ProductActivity), comme la "Fiche de Stock" de Gescomm : deux registres
    (vendu / retourne / net, achete / retourne / net, quantite et montant), puis les lignes, bon par bon.
    Un retour est une ligne negative dans un bon : il est marque "مرتجع", jamais en jaune (le jaune = stock negatif).
    $activity, $code : StockController::item().
--}}
@php
    $A = __('stock.activity');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $kinds = ['sale', 'purchase'];
    $money = ['sale' => $activity['show_sale'], 'purchase' => $activity['show_buy']];
@endphp
<section class="card activity" id="activity" aria-labelledby="activity-title">
    <div class="act-head">
        <h2 id="activity-title">{{ $A['title'] }}</h2>
        <nav class="periods" aria-label="{{ $A['period'] }}">
            @foreach (\App\Support\ProductActivity::PERIODS as $key => $days)
                <a href="{{ route('stock.item', ['code' => $code, 'period' => $key]) }}#activity" data-key="Alt+{{ $loop->iteration }}"
                   @class(['chip', 'on' => $activity['period'] === (string) $key]) @if ($activity['period'] === (string) $key) aria-current="true" @endif>{{ $A['periods'][$key] }}</a>
            @endforeach
        </nav>
    </div>

    @if ($activity['outdated']->isNotEmpty())
        <p class="act-note">{{ __('stock.activity.outdated', ['names' => $activity['outdated']->join(__('stock.js.list_separator'))]) }}</p>
    @endif

    @if ($activity['branches'] === 0)
        <p class="muted">{{ $A['no_branch'] }}</p>
    @elseif ($activity['ready'] > 0)
        <div class="ledgers">
            @foreach ($kinds as $kind)
                @php($s = $activity[$kind])
                <table class="ledger">
                    <caption>{{ $A[$kind] }}</caption>
                    <thead>
                    <tr>
                        <td></td>
                        <th scope="col" class="n">{{ $A['col_qty'] }}</th>
                        @if ($money[$kind])<th scope="col" class="n">{{ $A['col_amount'] }}</th>@endif
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <th scope="row">{{ $A["out_{$kind}"] }}</th>
                        <td class="n"><span class="num">{{ $fmt($s['out']) }}</span></td>
                        @if ($money[$kind])<td class="n"><span class="num">{{ $fmt($s['out_amount']) }}</span></td>@endif
                    </tr>
                    <tr class="back">
                        <th scope="row">{{ $A["back_{$kind}"] }}</th>
                        <td class="n"><span class="num">{{ $s['back'] > 0 ? '− '.$fmt($s['back']) : $fmt(0) }}</span></td>
                        @if ($money[$kind])<td class="n"><span class="num">{{ $s['back_amount'] > 0 ? '− '.$fmt($s['back_amount']) : $fmt(0) }}</span></td>@endif
                    </tr>
                    <tr class="net">
                        <th scope="row">{{ $A['net'] }}</th>
                        <td class="n"><span class="num">{{ $fmt($s['net']) }}</span></td>
                        @if ($money[$kind])<td class="n"><span class="num">{{ $fmt($s['amount']) }}</span></td>@endif
                    </tr>
                    @if ($money[$kind])
                        <tr class="avg">
                            <th scope="row">{{ $A["avg_{$kind}"] }}</th>
                            <td></td>
                            <td class="n"><span class="num">{{ $fmt($s['average']) }}</span></td>
                        </tr>
                    @endif
                    @if ($kind === 'sale' && $s['margin'] !== null)
                        <tr class="avg">
                            <th scope="row" title="{{ $A['margin_help'] }}">{{ $A['margin'] }}@if ($s['margin_partial'])<span class="muted small" title="{{ $A['margin_partial'] }}"> *</span>@endif</th>
                            <td></td>
                            <td class="n"><span class="num">{{ $fmt($s['margin']) }}</span></td>
                        </tr>
                    @endif
                    </tbody>
                </table>
            @endforeach
        </div>
        @if ($activity['sale']['margin_partial'] && $activity['sale']['margin'] !== null)
            <p class="muted small">* {{ $A['margin_partial'] }}</p>
        @endif

        @if ($activity['several'])
            <table class="by-branch">
                <caption>{{ $A['per_branch'] }}</caption>
                <thead>
                <tr>
                    <th scope="col">{{ __('stock.col_branch') }}</th>
                    <th scope="col" class="n">{{ $A['sold'] }}</th>
                    <th scope="col" class="n hide-sm">{{ $A['returned'] }}</th>
                    @if ($money['sale'])<th scope="col" class="n">{{ $A['col_amount'] }}</th>@endif
                    <th scope="col" class="n hide-sm">{{ $A['bought'] }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($activity['per_branch'] as $row)
                    <tr class="bt" style="--bh: {{ $row['hue'] }}">
                        <td><b>{{ $row['branch'] }}</b></td>
                        <td class="n"><span class="num">{{ $fmt($row['sale']['net']) }}</span></td>
                        <td class="n hide-sm"><span class="num">{{ $fmt($row['sale']['back']) }}</span></td>
                        @if ($money['sale'])<td class="n"><span class="num">{{ $fmt($row['sale']['amount']) }}</span></td>@endif
                        <td class="n hide-sm"><span class="num">{{ $fmt($row['purchase']['net']) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        {{-- facon Odoo : un bouton par liste, avec son nombre de lignes --}}
        <div class="act-tabs" role="tablist" aria-label="{{ $A['title'] }}">
            @foreach ($kinds as $kind)
                <button type="button" role="tab" id="tab-{{ $kind }}" aria-controls="panel-{{ $kind }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" @if (! $loop->first) tabindex="-1" @endif>
                    {{ $A["list_{$kind}"] }} <span class="pill num">{{ number_format($activity[$kind]['lines'], 0, ',', ' ') }}</span>
                </button>
            @endforeach
        </div>
        @foreach ($kinds as $kind)
            @php($lines = $activity['lists'][$kind])
            <div class="act-panel" role="tabpanel" id="panel-{{ $kind }}" aria-labelledby="tab-{{ $kind }}" @if (! $loop->first) hidden @endif>
                @if ($lines->isEmpty())
                    <p class="muted">{{ $A["none_{$kind}"] }}</p>
                @else
                    <table class="act-lines">
                        <thead>
                        <tr>
                            <th scope="col">{{ $A['col_date'] }}</th>
                            <th scope="col">{{ $A['col_bon'] }}</th>
                            @if ($activity['several'])<th scope="col" class="hide-sm">{{ __('stock.col_branch') }}</th>@endif
                            <th scope="col">{{ $A["col_partner_{$kind}"] }}</th>
                            <th scope="col" class="n">{{ $A['col_qty'] }}</th>
                            @if ($money[$kind])
                                <th scope="col" class="n hide-sm">{{ $A['col_price'] }}</th>
                                <th scope="col" class="n hide-sm">{{ $A['col_discount'] }}</th>
                                <th scope="col" class="n">{{ $A['col_total'] }}</th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($lines as $l)
                            <tr @class(['bt', 'back' => $l['back']]) style="--bh: {{ $l['hue'] }}">
                                <td class="num">{{ $l['date'] }} <span class="muted small">{{ $l['time'] }}</span></td>
                                <td class="num"><a class="bon-link" href="{{ $l['url'] }}">{{ $l['number'] }}</a>@if (count($codes) > 1) <span class="code ltr small muted">{{ $l['code'] }}</span>@endif</td>
                                @if ($activity['several'])<td class="hide-sm">{{ $l['branch'] }}</td>@endif
                                <td>@if ($l['partner_url'])<a class="bon-link" href="{{ $l['partner_url'] }}"><bdi>{{ $l['partner'] }}</bdi></a>@else<bdi>{{ $l['partner'] ?? '—' }}</bdi>@endif @if ($l['partner_code']) <span class="muted small ltr">{{ $l['partner_code'] }}</span>@endif</td>
                                <td class="n">
                                    @if ($l['back'])<span class="pill">{{ $A['return'] }}</span>@endif
                                    <span class="num">{{ $fmt($l['qty']) }}</span>
                                </td>
                                @if ($money[$kind])
                                    <td class="n hide-sm"><span class="num">{{ $fmt($l['price']) }}</span></td>
                                    <td class="n hide-sm muted"><span class="num">{{ $l['discount'] ? $fmt($l['discount']) : '' }}</span></td>
                                    <td class="n"><span class="num">{{ $fmt($l['total']) }}</span></td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @if ($activity[$kind]['lines'] > $lines->count())
                        <p class="muted small">{{ __('stock.activity.latest', ['shown' => $lines->count(), 'total' => number_format($activity[$kind]['lines'], 0, ',', ' ')]) }}</p>
                    @endif
                @endif
            </div>
        @endforeach
    @endif
</section>
