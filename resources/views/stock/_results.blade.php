{{-- Bloc des resultats : rendu dans la page, et seul (?partial=1) pour la recherche pendant la frappe. --}}
@use('App\Support\Highlight')
@if ($q === '')
    <div class="empty">
        @if ($merge)
            {!! __('stock.merge.empty') !!}
        @else
            {{ __('stock.empty') }}
        @endif
    </div>
@elseif ($results->isEmpty())
    <div class="empty">{{ __('stock.no_match', ['q' => $q]) }}</div>
@else
    @if ($merge)
        <div class="merge-bar">
            <button class="primary" type="submit" form="merge-form" id="merge-btn" disabled>{{ __('stock.merge.submit') }}</button>
            <span class="muted small" id="merge-count">{{ __('stock.merge.pick_hint') }}</span>
        </div>
    @endif
    <table>
        <thead>
        <tr>
            @if ($merge)<th></th>@endif
            <th>{{ __('stock.col_code') }}</th>
            <th>{{ __('stock.col_designation') }}</th>
            <th>{{ __('stock.col_total') }}</th>
            @if ($mainBranch)
                {{-- prix de la branche principale, comme dans la liste des articles de Gescomm (noms de colonnes Gescomm en francais) ;
                     chaque colonne seulement avec sa permission (prices.sale, prices.buy) --}}
                @if ($showSale)
                    <th title="Pu Detail — {{ __('stock.main_branch_price', ['name' => $mainBranch->name]) }}">{{ __('stock.col_retail') }}</th>
                    <th class="hide-sm" title="Prix Vente Gros — {{ __('stock.main_branch_price', ['name' => $mainBranch->name]) }}">{{ __('stock.col_wholesale') }}</th>
                @endif
                @if ($showBuy)
                    <th class="hide-sm" title="Pu Achat — {{ __('stock.admin_only') }}">{{ __('stock.col_buy') }}</th>
                @endif
            @endif
            <th class="hide-sm">{{ __('stock.col_branches') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($results as $r)
            <tr>
                @if ($merge)
                    <td class="pick"><input type="checkbox" name="codes[]" value="{{ $r->code }}" form="merge-form" aria-label="{{ __('stock.merge.pick', ['code' => $r->code]) }}"></td>
                @endif
                <td>
                    <span class="codes">
                        {{-- le code ouvre la page de l'article ; "معاينة" garde l'apercu rapide --}}
                        <a class="code ltr" href="{{ route('stock.item', ['code' => $r->code]) }}">{{ Highlight::html($r->code, $terms) }}</a>
                        @if ($merge && $r->merged)
                            <button type="submit" class="link" form="unmerge-form" name="code" value="{{ $r->code }}" title="{{ __('stock.merge.remove', ['code' => $r->code]) }}">✕</button>
                        @endif
                        @foreach ($r->merged as $other)
                            <span class="pill ltr" title="{{ __('stock.merged_with_code', ['code' => $r->code]) }}">+ {{ Highlight::html($other, $terms) }}@if ($merge)<button type="submit" class="link" form="unmerge-form" name="code" value="{{ $other }}" title="{{ __('stock.merge.remove', ['code' => $other]) }}">✕</button>@endif</span>
                        @endforeach
                    </span>
                </td>
                <td><bdi>{{ Highlight::html($r->designation, $terms) }}</bdi></td>
                @php($state = App\Support\StockState::of($r->total))
                <td><span class="qty total {{ $state }}" @if ($state) title="{{ __("stock.state.{$state}") }}" @endif>{{ number_format($r->total, 2, ',', ' ') }}</span></td>
                @if ($mainBranch)
                    @if ($showSale)
                        <td>
                            <span class="num">{{ $r->price === null ? '—' : number_format($r->price, 2, ',', ' ') }}</span>
                            @if ($r->price_differs)
                                <span class="pill warn" title="{{ __('stock.price_differs_tip') }}">{{ __('stock.price_differs') }}</span>
                            @endif
                        </td>
                        <td class="hide-sm"><span class="num">{{ $r->wholesale === null ? '—' : number_format($r->wholesale, 2, ',', ' ') }}</span></td>
                    @endif
                    @if ($showBuy)
                        <td class="hide-sm"><span class="num muted">{{ $r->buy === null ? '—' : number_format($r->buy, 2, ',', ' ') }}</span></td>
                    @endif
                @endif
                <td class="hide-sm"><span class="pill">{{ $r->branches }} / {{ $branchesTotal }}</span></td>
                <td><button type="button" data-code="{{ $r->code }}" data-designation="{{ $r->designation }}">{{ __('stock.preview') }}</button></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{-- memes couleurs que la liste des articles de Gescomm (App\Support\StockState) --}}
    <p class="legend muted small">
        <span><span class="qty zero">0,00</span> {{ __('stock.state.zero') }}</span>
        <span><span class="qty negative">-3,00</span> {{ __('stock.state.negative') }}</span>
    </p>
    @if ($results->count() >= 100)
        <p class="muted small">{{ __('stock.too_many', ['count' => 100]) }}</p>
    @endif
    @if ($noMain && auth()->user()->can('branches.manage'))
        <p class="muted small">{!! __('stock.no_main', ['link' => '<a href="'.route('branches.index').'">'.e(__('common.nav.branches')).'</a>']) !!}</p>
    @endif
@endif
