{{--
    Le contenu d'un bon (DocumentController::bon) : sa page (documents/show) et l'apercu de la liste (F2, ?partial=1,
    $inModal : sans cartes, l'apercu en est deja une). Le numero, le jour, le client et qui l'a servi, puis les lignes
    avec le stock actuel de chaque article dans cette branche (si le compte voit ce stock), puis les totaux.
    Une ligne negative est un retour : "مرتجع", jamais en jaune. Styles : documents/_bon-style.
--}}
@php
    $D = __('documents');
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 2, ',', ' ');
    $partnerName = $partner?->name;
    $phones = collect([$partner?->phone, $partner?->mobile])->filter()->join(' / ');
    $inModal ??= false;
@endphp
<div class="bon-head">
    <section @class(['bon-id', 'card' => ! $inModal]) aria-labelledby="bon-number">
        <p class="bon-kind">{{ $D['bon'][$kind] }}</p>
        <h1 class="bon-number" id="bon-number">{{ $document->number }}</h1>
        <p class="bon-when">{{ $day['name'] }} <span class="num">{{ $day['date'] }}@if ($document->time) — {{ substr($document->time, 0, 5) }}@endif</span></p>
        <p class="bon-branch"><span class="bdot" style="--bh: {{ $branch->hue() }}"></span> {{ $branch->name }}</p>
        @if ($returns > 0)
            <div class="bon-flags"><span class="pill">{{ trans_choice('documents.return_lines', $returns, ['count' => $returns]) }}</span></div>
        @endif
    </section>
    <section @class(['card' => ! $inModal]) aria-label="{{ $D['details'] }}">
        <dl class="facts">
            <div>
                <dt>{{ $D['col_partner'][$kind] }}</dt>
                <dd>@if ($partnerUrl)<a class="who" href="{{ $partnerUrl }}"><bdi>{{ $partnerName ?? $document->partner_code }}</bdi></a>@else<bdi>{{ $partnerName ?? $document->partner_code ?? '—' }}</bdi>@endif @if ($partnerName && $document->partner_code) <span class="muted small ltr">{{ $document->partner_code }}</span>@endif</dd>
            </div>
            @if ($phones !== '')<div><dt>{{ $D['phone'] }}</dt><dd class="num">{{ $phones }}</dd></div>@endif
            @if ($partner?->wilaya)<div><dt>{{ $D['wilaya'] }}</dt><dd><bdi>{{ $partner->wilaya }}</bdi></dd></div>@endif
            @if ($document->served_by)<div><dt title="Servi par">{{ $D['col_served_by'] }}</dt><dd><bdi>{{ $document->served_by }}</bdi></dd></div>@endif
            @if ($document->payment)<div><dt title="Réglement">{{ $D['col_payment'] }}</dt><dd><bdi>{{ $document->payment }}</bdi></dd></div>@endif
            @if ($document->credit_days)<div><dt title="Délais du Crédit">{{ $D['credit_days'] }}</dt><dd>{{ trans_choice('documents.days', $document->credit_days, ['count' => $document->credit_days]) }}</dd></div>@endif
            @if ($document->entered_by)<div><dt title="Saisi Par">{{ $D['entered_by'] }}</dt><dd><bdi>{{ $document->entered_by }}</bdi></dd></div>@endif
            @if ($document->note)<div><dt title="Observation">{{ $D['note'] }}</dt><dd><bdi>{{ $document->note }}</bdi></dd></div>@endif
        </dl>
    </section>
</div>

<section @class(['card' => ! $inModal]) aria-label="{{ $D['col_lines'] }}">
    @if ($lines->isEmpty())
        <p class="muted">{{ $D['no_lines'] }}</p>
    @else
        <table class="lines">
            <thead>
            <tr>
                <th scope="col" class="hide-sm">#</th>
                <th scope="col">{{ $D['col_code'] }}</th>
                <th scope="col">{{ $D['col_designation'] }}</th>
                <th scope="col" class="n">{{ $D['col_qty'] }}</th>
                @if ($money)
                    <th scope="col" class="n hide-sm">{{ $D['col_price'] }}</th>
                    <th scope="col" class="n hide-sm">{{ $D['col_discount'] }}</th>
                    <th scope="col" class="n">{{ $D['col_total'] }}</th>
                @endif
                @if ($seesStock)<th scope="col" class="n hide-sm" title="{{ $D['stock_help'] }}">{{ $D['col_stock'] }}</th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach ($lines as $l)
                <tr @class(['back' => $l['back']])>
                    <td class="hide-sm muted num">{{ $l['position'] }}</td>
                    <td><a class="code ltr" href="{{ route('stock.item', ['code' => $l['code']]) }}">{{ $l['code'] }}</a></td>
                    <td><bdi>{{ $l['designation'] ?? '—' }}</bdi></td>
                    <td class="n">
                        @if ($l['back'])<span class="pill">{{ $D['return'] }}</span>@endif
                        <span class="num">{{ $fmt($l['qty']) }}</span>
                    </td>
                    @if ($money)
                        <td class="n hide-sm"><span class="num">{{ $fmt($l['price']) }}</span></td>
                        <td class="n hide-sm muted"><span class="num">{{ $l['discount'] ? $fmt($l['discount']) : '' }}</span></td>
                        <td class="n"><span class="num">{{ $fmt($l['total']) }}</span></td>
                    @endif
                    @if ($seesStock)
                        <td class="n hide-sm">@if ($l['stock'] !== null)<span class="qty {{ $l['state'] }}">{{ $fmt($l['stock']) }}</span>@else<span class="muted">—</span>@endif</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($money)
            <dl class="totals">
                @if ($document->discount)
                    @if ($document->gross !== null)<dt>{{ $D['gross'] }}</dt><dd class="num">{{ $fmt($document->gross) }}</dd>@endif
                    <dt title="Total Remise">{{ $D['discount'] }}</dt><dd class="num">{{ $fmt($document->discount) }}</dd>
                @endif
                <dt>{{ $D['amount'] }}</dt><dd class="num grand">{{ $fmt($document->amount) }}</dd>
                @if ($margin !== null)
                    <dt title="{{ __('stock.activity.margin_help') }}">{{ __('stock.activity.margin') }}@if ($marginPartial) *@endif</dt><dd class="num">{{ $fmt($margin) }}</dd>
                @endif
            </dl>
            @if ($margin !== null && $marginPartial)
                <p class="muted small">* {{ __('stock.activity.margin_partial') }}</p>
            @endif
        @endif
    @endif
</section>
