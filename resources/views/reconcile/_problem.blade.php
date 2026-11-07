{{--
    Un probleme du rapport (reconcile/index) : une ligne du tableau de son groupe, et son <template> : ce que montre la
    fenetre rapide (les bons des deux cotes cote a cote, charges par ?partial=1, les lignes differentes colorees).
    Variables de la page : $p, $i, $T, $money, $signed, $date, $side, $url, $nameA, $nameB, $codesB, $hasStatus, $report.
--}}
@php
    $oSide = isset($p['other']) ? (in_array($p['type'], ['bon_elsewhere_b', 'pay_elsewhere_b'], true) ? 'b' : 'a') : null;
    $tSide = isset($p['twin']) ? ($p['type'] === 'bon_duplicate_a' ? 'a' : 'b') : null;
    $label = fn (array $x) => empty($x['payment']) ? $T['bon'] : (! empty($x['opening']) ? $T['opening_payment'] : $T['payment']);

    // les bons de la ligne (A455 B202) : un lien {A:455} de l'IA ouvre ce probleme
    $refs = [];
    foreach (['a', 'b'] as $s) {
        foreach ($p[$s] as $x) {
            if (empty($x['payment'])) { $refs[] = strtoupper($s).$x['number']; }
        }
    }
    if ($oSide && empty($p['other']['payment'])) { $refs[] = strtoupper($oSide).$p['other']['number']; }
    if ($tSide) { $refs[] = strtoupper($tSide).$p['twin']['number']; }

    // les lignes a colorer dans chaque bon : chez B, aussi les codes appris (77-X chez A = 77X chez B)
    $codesA = array_values(array_unique(array_map('strval', array_column($p['lines'] ?? [], 'code'))));
    $codesForB = $codesA;
    foreach ($codesA as $c) {
        foreach ($codesB[$c] ?? [] as $cb) { $codesForB[] = (string) $cb; }
    }

    $otherName = isset($p['other']) ? trim(($p['other']['partner'] ?? '').' ('.$p['other']['partner_code'].')') : null;
    $nItems = fn (array $x) => isset($x['lines']) ? trans_choice('reconcile.n_items', count($x['lines']), ['count' => count($x['lines'])]) : '';
    $what = match (true) {
        ! empty($p['lines']) => trans_choice('reconcile.n_diffs', count($p['lines']), ['count' => count($p['lines'])]),
        $otherName !== null => __('reconcile.elsewhere_at', ['partner' => $otherName]),
        $tSide !== null => $T['twin'].' #'.$p['twin']['number'],
        default => implode(' · ', array_filter(array_map(
            fn (array $x) => empty($x['payment']) ? $nItems($x) : trim(($x['type'] ?? '').' '.($x['note'] ?? '')),
            [...$p['a'], ...$p['b']]))),
    };
    if (! empty($p['late'])) {
        $what .= ($what !== '' ? ' · ' : '').__('reconcile.late_pair', ['days' => abs($p['days'])]);
    }

    // ce que la recherche du rapport trouve : numeros, codes, montants (12300.00 et 12 300,00), partenaire
    $words = [];
    foreach ([...$p['a'], ...$p['b'], ...(isset($p['other']) ? [$p['other']] : []), ...(isset($p['twin']) ? [$p['twin']] : [])] as $x) {
        $words[] = '#'.$x['number'];
        if (isset($x['amount'])) { $words[] = number_format((float) $x['amount'], 2, '.', ''); $words[] = $money($x['amount']); }
        foreach ($x['lines'] ?? [] as $l) { $words[] = $l['code']; }
    }
    $words[] = number_format(abs((float) $p['effect']), 2, '.', '');
    $words[] = $otherName;
    $search = mb_strtolower(implode(' ', array_filter($words)));

    // la fenetre : un doublon montre la copie et l'original ; sinon un cote par colonne
    $cols = [];
    if ($tSide) {
        // les deux sont identiques : on dit seulement lequel a son pendant chez l'autre branche
        $otherBranch = $side($tSide === 'a' ? 'b' : 'a')['name'];
        $cols[] = ['side' => $tSide, 'items' => [['x' => $p[$tSide][0], 'role' => __('reconcile.copy', ['branch' => $otherBranch])]]];
        $cols[] = ['side' => $tSide, 'items' => [['x' => $p['twin'], 'role' => __('reconcile.original', ['branch' => $otherBranch])]]];
    } else {
        foreach (['a', 'b'] as $s) {
            $items = array_map(fn (array $x) => ['x' => $x, 'role' => null], $p[$s]);
            if ($oSide === $s) { $items[] = ['x' => $p['other'], 'role' => __('reconcile.elsewhere_at', ['partner' => $otherName])]; }
            $cols[] = ['side' => $s, 'items' => $items];
        }
    }
@endphp
<tr class="pr" tabindex="0" data-refs="{{ implode(' ', $refs) }}" data-q="{{ $search }}" data-status="{{ $p['status'] ?? '' }}">
    <td class="num eff">{{ abs($p['effect']) < 0.005 ? '—' : $signed($p['effect']) }}</td>
    <td class="num nowrap">{{ $date($p['date']) }}</td>
    @foreach (['a', 'b'] as $s)
        <td class="refs">
            @foreach ($p[$s] as $x)
                <div>
                    @if (empty($x['payment']))
                        <a class="bref" href="{{ $url($s, $x) }}">{{ $T['bon'] }} <bdi class="num">#{{ $x['number'] }}</bdi></a>
                    @else
                        {{ $label($x) }} <bdi class="num">#{{ $x['number'] }}</bdi>
                    @endif
                    <span class="num muted">{{ $money($x['amount']) }}</span>
                </div>
            @endforeach
            @if ($oSide === $s)
                <div class="muted">↳ @if (empty($p['other']['payment']))<a class="bref" href="{{ $url($s, $p['other']) }}">#{{ $p['other']['number'] }}</a>@else #{{ $p['other']['number'] }}@endif
                    <span class="small">({{ $p['other']['sure'] ? $T['other_sure'] : $T['other_maybe'] }})</span></div>
            @endif
            @if ($tSide === $s)
                <div class="muted small">{{ $T['twin'] }} <a class="bref" href="{{ $url($s, $p['twin']) }}"><bdi class="num">#{{ $p['twin']['number'] }}</bdi></a></div>
            @endif
            @if ($p[$s] === [] && $oSide !== $s && $tSide !== $s)
                <span class="muted">—</span>
            @endif
        </td>
    @endforeach
    <td class="what small muted hide-sm">{{ $what }}</td>
    @if ($hasStatus)
        <td>@if (isset($p['status']))<span @class(['pill', 'new' => $p['status'] === 'new'])>{{ $T['status'][$p['status']] }}</span>@endif</td>
    @endif
</tr>
<template>
    <div class="rm-info">
        <div class="rm-top">
            <h2 class="rm-title" id="rm-title">{{ __('reconcile.types.'.$p['type'], ['a' => $nameA, 'b' => $nameB]) }}
                @if (isset($p['status']))<span @class(['pill', 'new' => $p['status'] === 'new'])>{{ $T['status'][$p['status']] }}</span>@endif</h2>
            <span class="rm-eff"><span class="muted small">{{ $T['effect'] }}</span> <b class="num">{{ abs($p['effect']) < 0.005 ? '—' : $signed($p['effect']) }}</b></span>
        </div>
        @if (! empty($p['late']))
            <p class="muted small">{{ __('reconcile.late_pair', ['days' => abs($p['days'])]) }}</p>
        @elseif (! empty($p['days']))
            <p class="muted small">{{ __($p['days'] > 0 ? 'reconcile.later' : 'reconcile.earlier', ['b' => $nameB, 'days' => abs($p['days'])]) }}</p>
        @endif
        @if (isset($p['other']))
            <p class="small">{{ __('reconcile.other', ['branch' => $side($oSide)['name'], 'partner' => $otherName]) }}
                <span class="muted">({{ $p['other']['sure'] ? $T['other_sure'] : $T['other_maybe'] }})</span></p>
        @endif
        @if (! empty($p['lines']))
            <div class="tw">
                <table class="diffs">
                    <thead><tr><th>{{ $T['item'] }}</th><th></th><th>{{ $nameA }}</th><th>{{ $nameB }}</th></tr></thead>
                    <tbody>
                        @foreach ($p['lines'] as $l)
                            @php
                                // quantite × prix = total ; une ligne d'un seul cote n'a pas de prix dans le rapport : total / quantite
                                $cell = function (string $s) use ($l, $money) {
                                    if (! array_key_exists('qty_'.$s, $l)) {
                                        return '—';
                                    }
                                    $price = $l['price_'.$s] ?? (abs($l['qty_'.$s]) > 0 ? $l['total_'.$s] / $l['qty_'.$s] : null);

                                    return $money($l['qty_'.$s]).($price !== null ? ' × '.$money($price) : '').' = '.$money($l['total_'.$s]);
                                };
                            @endphp
                            <tr>
                                <td><span class="code ltr">{{ $l['code'] }}</span> <span class="muted small">{{ $report['names'][$l['code']] ?? '' }}</span></td>
                                <td class="small">{{ __('reconcile.line.'.$l['kind'], ['a' => $nameA, 'b' => $nameB]) }}</td>
                                <td class="num small">{{ $cell('a') }}</td>
                                <td class="num small">{{ $cell('b') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted small">{{ $T['modal']['marked'] }}</p>
        @endif
    </div>
    <div @class(['rm-cols', 'one' => count(array_filter($cols, fn ($c) => $c['items'] !== [])) < 2])>
        @foreach ($cols as $c)
            @php
                $who = $side($c['side']);
            @endphp
            <div @class(['rm-col', 'empty' => $c['items'] === []])>
                @forelse ($c['items'] as $it)
                    @php
                        $x = $it['x'];
                    @endphp
                    <div class="rm-item">
                        <div class="rm-bar">
                            <span class="bdot" style="--bh: {{ $who['hue'] }}"></span><b>{{ $who['name'] }}</b>
                            @if ($it['role'])<span class="muted small">· {{ $it['role'] }}</span>@endif
                            <span class="grow"></span>
                            @if (empty($x['payment']))
                                <a class="btn open-bon" href="{{ $url($c['side'], $x) }}">{{ $T['modal']['open'] }}</a>
                            @endif
                        </div>
                        @if (empty($x['payment']))
                            <div class="rm-bon" data-url="{{ $url($c['side'], $x) }}" data-codes="{{ implode('|', $c['side'] === 'a' ? $codesA : $codesForB) }}"></div>
                        @else
                            <dl class="rm-pay">
                                <dt>{{ $label($x) }}</dt><dd class="num">#{{ $x['number'] }}</dd>
                                <dt>{{ $T['date'] }}</dt><dd class="num">{{ $date($x['date']) }}</dd>
                                <dt>{{ $T['amount'] }}</dt><dd class="num"><b>{{ $money($x['amount']) }}</b></dd>
                                @if (! empty($x['type']))<dt>{{ $T['payment'] }}</dt><dd>{{ $x['type'] }}</dd>@endif
                                @if (! empty($x['note']))<dt></dt><dd class="muted"><bdi>{{ $x['note'] }}</bdi></dd>@endif
                            </dl>
                        @endif
                    </div>
                @empty
                    <div class="rm-item">
                        <div class="rm-bar"><span class="bdot" style="--bh: {{ $who['hue'] }}"></span><b>{{ $who['name'] }}</b></div>
                        <p class="rm-none">{{ __('reconcile.missing_at', ['branch' => $who['name']]) }}</p>
                    </div>
                @endforelse
            </div>
        @endforeach
    </div>
</template>
