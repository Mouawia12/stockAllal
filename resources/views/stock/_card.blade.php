{{--
    F9 de la recherche (StockController::card) : la "Fiche de Stock d'un Produit" de Gescomm dans une fenetre. En haut
    l'article, son stock, ses prix, ce qui s'en est achete et vendu ; puis un onglet a la fois (ventes ou achats), la
    recherche d'un client ou d'un fournisseur (ce qu'il en a pris : net, sorti, rendu), et les lignes, bon par bon,
    avec le jour et l'heure a la seconde. Un morceau de page : stock/index le charge et remplace .fc-list seul pendant
    la frappe d'un nom. Un retour est une ligne negative, marquee "مرتجع", jamais en jaune ni en rouge (couleurs du stock).
    Les montants de vente demandent prices.sale ; prix et montants d'achat prices.buy (App\Support\ProductActivity).
--}}
@php
    $A = __('stock.activity');
    $C = __('stock.card');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $qty = fn ($n) => $n === null ? '—' : number_format($n, abs($n - round($n)) < 0.005 ? 0 : 2, ',', ' ');
    $int = fn ($n) => number_format($n, 0, ',', ' ');
    $money = ['sale' => $activity['show_sale'], 'purchase' => $activity['show_buy']];
    $stat = $activity[$tab];
    $lines = $activity['lists'][$tab];
    // sans recherche : les chiffres de l'article entier
    $found = $activity['found'][$tab] ?? ['lines' => $stat['lines'], 'out' => $stat['out'], 'back' => $stat['back'], 'net' => $stat['net']];
    $others = array_values(array_diff($codes, [$code]));
    // les prix de la branche de reference ; elle n'a pas l'article : ceux de la branche qui en a le plus
    $prices = $main_prices ?? (collect($branches)->pluck('rows')->flatten(1)->first() ?? []);
    $facts = array_values(array_filter([
        ['stock', $qty($total), 'qty '.($total_state ?? '')],
        $show_buy ? ['price_buy', $fmt($prices['price_buy'] ?? null), ''] : null,
        $show_sale ? ['price_sale', $fmt($prices['price'] ?? null), ''] : null,
        ['bought', $qty($activity['purchase']['net']), ''],
        $show_buy ? ['bought_amount', $fmt($activity['purchase']['amount']), ''] : null,
        ['sold', $qty($activity['sale']['net']), ''],
        $show_sale ? ['sold_amount', $fmt($activity['sale']['amount']), ''] : null,
        $show_sale ? ['average', $fmt($activity['sale']['average']), ''] : null,
    ]));
@endphp
<div class="fc" data-code="{{ $code }}" data-tab="{{ $tab }}">
    <p class="fc-title"><span class="code ltr">{{ $code }}</span> <bdi>{{ $designation }}</bdi>@foreach ($others as $other) <span class="pill ltr">+ {{ $other }}</span>@endforeach</p>

    <dl class="fc-facts">
        @foreach ($facts as [$key, $value, $class])
            <div><dt>{{ $C['facts'][$key] }}</dt><dd><span class="num {{ $class }}">{{ $value }}</span></dd></div>
        @endforeach
    </dl>
    @if ($activity['outdated']->isNotEmpty())
        <p class="fc-note">{{ __('stock.activity.outdated', ['names' => $activity['outdated']->join(__('stock.js.list_separator'))]) }}</p>
    @endif

    @if ($activity['branches'] === 0)
        <p class="muted">{{ $A['no_branch'] }}</p>
    @elseif ($activity['ready'] > 0)
        <div class="fc-bar">
            <div class="fc-tabs" role="tablist" aria-label="{{ $C['title'] }}">
                @foreach (['sale', 'purchase'] as $kind)
                    <button type="button" role="tab" data-tab="{{ $kind }}" data-key="Alt+{{ $loop->iteration }}" aria-selected="{{ $tab === $kind ? 'true' : 'false' }}">
                        {{ $A[$kind] }} <span class="pill num">{{ $int($activity[$kind]['lines']) }}</span>
                    </button>
                @endforeach
            </div>
            <input type="search" name="who" value="{{ $activity['who'] }}" placeholder="{{ $C['who'][$tab] }}" aria-label="{{ $C['who'][$tab] }}" autocomplete="off" data-key="F3">
        </div>

        <div class="fc-list">
            {{-- Gescomm : "Qte Global Vendue pour le Client selectionne" (Global / Vente / Avoir) --}}
            <p class="fc-found">
                <span class="muted">{{ $activity['who'] === '' ? $C['everyone'][$tab] : __('stock.card.for', ['who' => $activity['who']]) }}</span>
                <span>{{ $C['net'] }} <b class="num">{{ $qty($found['net']) }}</b></span>
                <span>{{ $A["out_{$tab}"] }} <b class="num">{{ $qty($found['out']) }}</b></span>
                <span>{{ $A['return'] }} <b class="num">{{ $qty($found['back']) }}</b></span>
            </p>
            @if ($lines->isEmpty())
                <p class="muted fc-none">{{ $activity['who'] === '' ? $A["none_{$tab}"] : __('stock.card.none', ['who' => $activity['who']]) }}</p>
            @else
                <div class="fc-scroll">
                    <table class="fc-lines">
                        <thead>
                        <tr>
                            <th scope="col">{{ $A['col_date'] }}</th>
                            <th scope="col">{{ $C['col_time'] }}</th>
                            <th scope="col" class="n">{{ $A['col_qty'] }}</th>
                            @if ($money[$tab])
                                <th scope="col" class="n">{{ $A['col_price'] }}</th>
                                <th scope="col" class="n hide-sm">{{ $A['col_discount'] }}</th>
                                <th scope="col" class="n hide-sm">{{ $C['col_net_price'] }}</th>
                            @endif
                            <th scope="col">{{ $A["col_partner_{$tab}"] }}</th>
                            <th scope="col" class="n">{{ $A['col_bon'] }}</th>
                            @if ($activity['several'])<th scope="col" class="hide-sm">{{ __('stock.col_branch') }}</th>@endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($lines as $l)
                            <tr @class(['bt', 'back' => $l['back']]) style="--bh: {{ $l['hue'] }}">
                                <td class="num">{{ $l['date'] }}</td>
                                <td class="num muted">{{ $l['clock'] }}</td>
                                <td class="n">@if ($l['back'])<span class="pill">{{ $A['return'] }}</span> @endif<b class="num">{{ $qty($l['qty']) }}</b></td>
                                @if ($money[$tab])
                                    <td class="n"><span class="num">{{ $fmt($l['price']) }}</span></td>
                                    <td class="n hide-sm muted"><span class="num">{{ $l['discount'] ? $fmt($l['discount']) : '' }}</span></td>
                                    <td class="n hide-sm"><span class="num">{{ $fmt($l['net_price']) }}</span></td>
                                @endif
                                <td>@if ($l['partner_url'])<a class="bon-link" href="{{ $l['partner_url'] }}"><bdi>{{ $l['partner'] }}</bdi></a>@else<bdi>{{ $l['partner'] ?? '—' }}</bdi>@endif @if ($l['partner_code'])<span class="muted small ltr">{{ $l['partner_code'] }}</span>@endif</td>
                                <td class="n num"><a class="bon-link" href="{{ $l['url'] }}">{{ $l['number'] }}</a>@if (count($codes) > 1) <span class="code ltr small muted">{{ $l['code'] }}</span>@endif</td>
                                @if ($activity['several'])<td class="hide-sm">{{ $l['branch'] }}</td>@endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($found['lines'] > $lines->count())
                    <p class="muted small fc-more">
                        {{ __('stock.activity.latest', ['shown' => $int($lines->count()), 'total' => $int($found['lines'])]) }}
                        @unless ($all)<button type="button" class="link" data-all>{{ $C['all'] }}</button>@endunless
                    </p>
                @endif
            @endif
        </div>
    @endif
</div>
