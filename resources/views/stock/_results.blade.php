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
                {{-- prix de la branche principale, comme dans la liste des articles de Gescomm (noms de colonnes Gescomm en francais) --}}
                <th title="Pu Detail — {{ __('stock.main_branch_price', ['name' => $mainBranch->name]) }}">{{ __('stock.col_retail') }}</th>
                <th class="hide-sm" title="Prix Vente Gros — {{ __('stock.main_branch_price', ['name' => $mainBranch->name]) }}">{{ __('stock.col_wholesale') }}</th>
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
                        <span class="code ltr">{{ Highlight::html($r->code, $terms) }}</span>
                        @if ($merge && $r->merged)
                            <button type="submit" class="link" form="unmerge-form" name="code" value="{{ $r->code }}" title="{{ __('stock.merge.remove', ['code' => $r->code]) }}">✕</button>
                        @endif
                        @foreach ($r->merged as $other)
                            <span class="pill ltr" title="{{ __('stock.merged_with_code', ['code' => $r->code]) }}">+ {{ Highlight::html($other, $terms) }}@if ($merge)<button type="submit" class="link" form="unmerge-form" name="code" value="{{ $other }}" title="{{ __('stock.merge.remove', ['code' => $other]) }}">✕</button>@endif</span>
                        @endforeach
                    </span>
                </td>
                <td><bdi>{{ Highlight::html($r->designation, $terms) }}</bdi></td>
                <td><span class="num total {{ $r->total < 0 ? 'neg' : '' }}">{{ number_format($r->total, 2, ',', ' ') }}</span></td>
                @if ($mainBranch)
                    <td>
                        <span class="num">{{ $r->price === null ? '—' : number_format($r->price, 2, ',', ' ') }}</span>
                        @if ($r->price_differs)
                            <span class="pill warn" title="{{ __('stock.price_differs_tip') }}">{{ __('stock.price_differs') }}</span>
                        @endif
                    </td>
                    <td class="hide-sm"><span class="num">{{ $r->wholesale === null ? '—' : number_format($r->wholesale, 2, ',', ' ') }}</span></td>
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
    @if ($results->count() >= 100)
        <p class="muted small">{{ __('stock.too_many', ['count' => 100]) }}</p>
    @endif
    @if (! $mainBranch && auth()->user()->is_admin)
        <p class="muted small">{!! __('stock.no_main', ['link' => '<a href="'.route('branches.index').'">'.e(__('common.nav.branches')).'</a>']) !!}</p>
    @endif
@endif
