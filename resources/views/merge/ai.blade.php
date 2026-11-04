@extends('layouts.app')

{{--
    Propositions de fusion de l'IA (MergeAiController) : lancer une analyse, la suivre, puis decider avec toute liberte :
    tout cocher et fusionner, cocher quelques groupes, decocher un code dans un groupe, fusionner un groupe a la fois,
    ou "ليسا نفس الصنف". Les cases et les boutons visent des formulaires caches (attribut form) : pas de formulaire imbrique.
--}}
@section('title', __('merge_ai.title').' — '.__('common.app_name'))

@push('head')
<style>
    .crumbs { margin: 0 0 6px; font-size: 14px; }
    .ai-title { font-size: 20px; margin: 0 0 4px; }
    .ai-lead { margin: 0 0 16px; max-width: 80ch; }
    .panel { margin-bottom: 16px; }
    .panel h2 { font-size: 16px; margin: 0 0 8px; }
    .panel p { margin: 0 0 8px; }
    .panel .row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; margin-top: 12px; }
    .panel select { font: inherit; color: var(--text); background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; }
    .panel label.check { display: flex; gap: 8px; align-items: flex-start; cursor: pointer; }
    .panel label.check input { margin-top: 5px; }
    details.panel > summary { cursor: pointer; font-weight: 600; }
    details.panel[open] > summary { margin-bottom: 8px; }
    .bar { height: 10px; border-radius: 99px; background: var(--row-hover); overflow: hidden; margin: 10px 0 8px; }
    .bar > span { display: block; height: 100%; background: var(--accent); border-radius: 99px; transition: width .4s; }
    .stats { display: flex; flex-wrap: wrap; gap: 4px 18px; }
    .filters { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 10px; }
    .chip { display: inline-flex; align-items: center; gap: 8px; padding: 4px 0; padding-inline: 12px 6px; border: 1px solid var(--border); border-radius: 99px; color: var(--text); font-size: 14px; background: var(--card); }
    .chip:hover { background: var(--row-hover); }
    .chip .n { font-variant-numeric: tabular-nums; font-size: 12.5px; color: var(--muted); background: var(--row-hover); border-radius: 99px; padding: 0 8px; }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .chip.on .n { background: var(--card); color: var(--accent); }
    .toolbar { position: sticky; top: 56px; z-index: 3; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 12px; margin: 0 0 12px;
        background: var(--card); border: 1px solid var(--border); border-radius: 10px; }
    .toolbar label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .toolbar .grow { flex: 1; }
    .toolbar button:disabled { opacity: .45; cursor: not-allowed; }
    .sugs { display: grid; gap: 12px; }
    .sug { padding: 14px 16px; }
    .sug.off { opacity: .55; }
    .sug-head { display: flex; align-items: flex-start; gap: 10px; }
    .sug-head input[type=checkbox] { width: 18px; height: 18px; margin-top: 3px; flex: none; }
    .sug-head .grow { flex: 1; min-width: 0; }
    .sug-name { font-weight: 600; margin: 0; overflow-wrap: anywhere; }
    .sug-reason { margin: 2px 0 0; }
    .tags { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
    /* confiance : jamais en jaune ni en rouge, ce sont les couleurs du stock (Gescomm) */
    .pill.conf-high { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .pill.conf-low { background: none; border: 1px dashed var(--border); }
    .units { margin: 10px 0 0; }
    .units td { padding: 6px 8px; }
    .units td.pick { width: 1%; }
    .units td.pick input { width: 17px; height: 17px; }
    .units .who { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
    .units .code { white-space: nowrap; }
    .sug-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 10px; }
    .sug-actions .end { margin-inline-start: auto; }
    .sug-actions button:disabled { opacity: .45; cursor: not-allowed; }
    .empty { padding: 30px; text-align: center; color: var(--muted); }
    .warn-line { color: var(--bad); }
    /* telephone : la designation passe sous le code ; la barre de selection ne colle plus (l'en-tete y prend deux lignes) */
    .units .desig-sm { display: none; }
    @media (max-width: 700px) { .toolbar { position: static; } .units .desig-sm { display: block; white-space: normal; } }
</style>
@endpush

@push('keys')
    @if ($scan && $suggestions->isNotEmpty() && $status === 'open')
        @include('partials.key', ['keys' => ['Alt', 'A'], 'run' => 'Alt+A', 'label' => __('merge_ai.select_all')])
        @include('partials.key', ['keys' => ['Alt', 'M'], 'run' => 'Alt+M', 'label' => __('merge_ai.merge_selected_short')])
    @endif
    @include('partials.key', ['keys' => [__('common.keys.esc')], 'label' => __('common.keys.back')])
@endpush

@section('content')
@php
    $C = __('merge_ai');
    $num = fn ($n) => number_format($n, 0, ',', ' ');
    $providerName = fn (?string $p) => $p ? (App\Support\AiProviders::PROVIDERS[$p]['name'] ?? $p) : '';
@endphp

<p class="crumbs"><a href="{{ route('merge.index') }}" data-back>{{ __('common.nav.merge') }}</a></p>
<h1 class="ai-title">{{ $C['title'] }}</h1>
<p class="ai-lead muted">{{ $C['lead'] }}</p>

{{-- lancer une analyse : ouvert s'il n'y en a aucune, replie au-dessus d'un rapport --}}
@if (! $scan || ! $scan->running())
    <details class="card panel" @if (! $scan) open @endif>
        <summary>{{ $scan ? $C['new_scan'] : $C['start_title'] }}</summary>
        <p>{{ $C['how'] }}</p>
        <p class="muted small">{{ $C['privacy'] }}</p>
        @if ($branchCount < 2)
            <p class="warn-line">{{ $C['need_two'] }}</p>
        @elseif (! $provider)
            <p class="warn-line">{{ $C['no_provider'] }}
                @can('manage-ai')<a href="{{ route('ai.index') }}">{{ __('ai.title') }}</a>@endcan
            </p>
        @else
            <form method="post" action="{{ route('merge.ai.start') }}">
                @csrf
                <label class="check"><input type="checkbox" name="deep" value="1" checked>
                    <span>{{ $C['deep'] }}<br><span class="muted small">{{ $C['deep_help'] }}</span></span></label>
                <div class="row">
                    @php($ready = App\Models\AiProvider::where('enabled', true)->get()->filter->connected())
                    @if ($ready->count() > 1)
                        <label>{{ $C['with'] }}
                            <select name="provider">
                                @foreach ($ready as $p)
                                    <option value="{{ $p->provider }}" @selected($p->provider === $provider->provider)>{{ $providerName($p->provider) }} ({{ $p->model }})</option>
                                @endforeach
                            </select>
                        </label>
                    @else
                        <span class="muted small">{{ $C['with'] }} {{ $providerName($provider->provider) }} <span class="ltr">({{ $provider->model }})</span></span>
                        <input type="hidden" name="provider" value="{{ $provider->provider }}">
                    @endif
                    <button class="primary" type="submit">{{ $C['start'] }}</button>
                </div>
            </form>
        @endif
    </details>
@endif

@if ($scan)
    @if ($scan->running())
        <section class="card panel" id="progress" data-step="{{ route('merge.ai.step', $scan) }}">
            <h2>{{ $C['running'] }}</h2>
            <p class="muted small">{{ $C['running_help'] }}</p>
            <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $scan->progress() }}"><span id="p-bar" style="width: {{ $scan->progress() }}%"></span></div>
            <div class="stats small">
                <span id="p-done">{{ __('merge_ai.batches', ['done' => $scan->batches_done + $scan->batches_failed, 'total' => $scan->batches]) }}</span>
                <span id="p-found">{{ trans_choice('merge_ai.found', $counts['status']['open'] ?? 0, ['count' => $counts['status']['open'] ?? 0]) }}</span>
                <span class="muted">{{ $providerName($scan->provider) }} <span class="ltr">({{ $scan->model }})</span></span>
                <span class="muted" id="p-note" hidden></span>
            </div>
            <form class="row" method="post" action="{{ route('merge.ai.cancel', $scan) }}">@csrf<button class="link danger" type="submit">{{ $C['cancel'] }}</button></form>
        </section>
    @else
        <section class="card panel">
            <p class="stats small">
                <span>{{ __('merge_ai.scanned', ['units' => $num($scan->units)]) }}</span>
                <span>{{ __('merge_ai.branches_count', ['branches' => count($scan->branch_ids)]) }}</span>
                <span class="muted" title="{{ $scan->created_at->format('d/m/Y H:i') }}">{{ $scan->created_at->diffForHumans() }}</span>
                <span class="muted">{{ $providerName($scan->provider) }} <span class="ltr">({{ $scan->model }})</span></span>
                @if ($scan->batches_failed)<span class="warn-line">{{ trans_choice('merge_ai.batches_failed', $scan->batches_failed, ['count' => $scan->batches_failed]) }}</span>@endif
            </p>
            @if ($scan->status === 'failed' || $scan->status === 'cancelled')
                <p class="{{ $scan->status === 'failed' ? 'warn-line' : 'muted' }}">
                    {{ $scan->status === 'failed' ? __("ai.errors.{$scan->error}", ['name' => $providerName($scan->provider)]) : $C['cancelled'] }}
                    @if ($scan->error_detail)<br><span class="small ltr">{{ $scan->error_detail }}</span>@endif
                </p>
                @if ($scan->batches_done + $scan->batches_failed < $scan->batches)
                    <form method="post" action="{{ route('merge.ai.resume', $scan) }}">@csrf
                        <button type="submit">{{ __('merge_ai.resume', ['done' => $scan->batches_done + $scan->batches_failed, 'total' => $scan->batches]) }}</button>
                        @if ($scan->status === 'failed')@can('manage-ai')<a class="small" href="{{ route('ai.index') }}">{{ __('ai.title') }}</a>@endcan @endif
                    </form>
                @endif
            @endif
        </section>
    @endif

    {{-- le rapport : propositions a decider, deja fusionnees, rangees --}}
    <nav class="filters" aria-label="{{ $C['status_label'] }}">
        @foreach (App\Http\Controllers\MergeAiController::STATUS_FILTERS as $s)
            <a href="{{ route('merge.ai', $s === 'open' ? [] : ['s' => $s]) }}" @class(['chip', 'on' => $status === $s]) @if ($status === $s) aria-current="true" @endif>
                {{ $C['status'][$s] }}<span class="n">{{ $num($counts['status'][$s] ?? 0) }}</span></a>
        @endforeach
    </nav>
    @if (array_sum($counts['confidence']) > 0)
        <nav class="filters" aria-label="{{ $C['confidence_label'] }}">
            @foreach (App\Http\Controllers\MergeAiController::CONFIDENCE_FILTERS as $c)
                <a href="{{ route('merge.ai', array_filter(['s' => $status === 'open' ? null : $status, 'c' => $c === 'all' ? null : $c])) }}" @class(['chip', 'on' => $confidence === $c]) @if ($confidence === $c) aria-current="true" @endif>
                    {{ $C['confidence'][$c] }}<span class="n">{{ $num($c === 'all' ? array_sum($counts['confidence']) : ($counts['confidence'][$c] ?? 0)) }}</span></a>
            @endforeach
        </nav>
    @endif

    @if ($suggestions->isEmpty())
        <p class="card empty">{{ $scan->running() ? $C['empty_running'] : $C['empty'][$status] }}</p>
    @else
        <form id="merge-ai-form" method="post" action="{{ route('merge.ai.merge', $scan) }}">@csrf</form>
        <form id="act-form" method="post">@csrf</form>
        @if ($status === 'open')
            <div class="toolbar">
                <label><input type="checkbox" id="all" data-key="Alt+A"> {{ $C['select_all'] }}</label>
                <span class="grow"></span>
                <span class="muted small" id="picked"></span>
                <button class="primary" type="submit" form="merge-ai-form" id="merge-selected" data-key="Alt+M" disabled>{{ $C['merge_selected_short'] }}</button>
            </div>
        @endif

        <div class="sugs">
            @foreach ($suggestions as $s)
                <section class="card sug" data-sug="{{ $s->id }}" aria-labelledby="sug-{{ $s->id }}">
                    <div class="sug-head">
                        @if ($status === 'open')
                            <input type="checkbox" name="groups[]" value="{{ $s->id }}" form="merge-ai-form" class="pick-group" aria-labelledby="sug-{{ $s->id }}">
                        @endif
                        <div class="grow">
                            <p class="sug-name" id="sug-{{ $s->id }}">{{ $s->name ?? implode(' + ', $s->codes()) }}</p>
                            @if ($s->reason)<p class="sug-reason muted small">{{ $s->reason }}</p>@endif
                        </div>
                        <div class="tags">
                            <span class="pill conf-{{ $s->confidence }}">{{ $C['confidence'][$s->confidence] }}</span>
                            <span class="pill">{{ $C['source'][$s->source] }}</span>
                        </div>
                    </div>

                    <table class="units">
                        <tbody>
                            @foreach ($s->units as $unit)
                                @php($rows = collect($unit)->flatMap(fn ($k) => $items[$k] ?? [])->values())
                                @foreach ($rows->isEmpty() ? [null] : $rows as $i => $row)
                                    <tr>
                                        @if ($i === 0 && $status === 'open')
                                            <td class="pick" rowspan="{{ max(1, $rows->count()) }}">
                                                <input type="checkbox" name="codes[{{ $s->id }}][]" value="{{ $unit[0] }}" form="merge-ai-form" class="pick-unit" checked
                                                       aria-label="{{ __('merge_ai.keep_code', ['code' => implode(' + ', $unit)]) }}">
                                            </td>
                                        @endif
                                        @if ($row)
                                            <td><a class="code ltr" href="{{ route('stock.item', ['code' => $row['code']]) }}">{{ $row['code'] }}</a>
                                                <span class="desig-sm muted small">{{ $row['designation'] }}</span></td>
                                            <td>@php($b = $branches[$row['branch_id']] ?? null)<span class="who" @if ($b) style="--bh: {{ $b->hue() }}" @endif><span class="bdot"></span>{{ $b?->name }}</span></td>
                                            <td class="hide-sm">{{ $row['designation'] }}</td>
                                            <td><span class="qty {{ $row['state'] }}">{{ number_format($row['stock'], 2, ',', ' ') }}</span></td>
                                        @else
                                            <td class="code ltr">{{ implode(' + ', $unit) }}</td>
                                            <td colspan="3" class="muted small">{{ $C['gone'] }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>

                    <div class="sug-actions">
                        @if ($status === 'open')
                            <button type="submit" form="merge-ai-form" name="only" value="{{ $s->id }}" class="merge-one">{{ $C['merge_one'] }}</button>
                            <button type="submit" form="act-form" formaction="{{ route('merge.ai.dismiss', [$scan, $s]) }}" class="link end">{{ $C['dismiss'] }}</button>
                        @elseif ($status === 'merged')
                            <span class="muted small">{{ __('merge_ai.decided', ['who' => $s->decided_by ? (App\Models\User::find($s->decided_by)?->name ?? '') : '', 'time' => $s->decided_at?->diffForHumans()]) }}</span>
                            <a class="end small" href="{{ route('stock.item', ['code' => $s->units[0][0]]) }}">{{ $C['open_item'] }}</a>
                        @else
                            <span class="muted small">{{ __('merge_ai.decided', ['who' => $s->decided_by ? (App\Models\User::find($s->decided_by)?->name ?? '') : '', 'time' => $s->decided_at?->diffForHumans()]) }}</span>
                            <button type="submit" form="act-form" formaction="{{ route('merge.ai.reopen', [$scan, $s]) }}" class="link end">{{ $C['reopen'] }}</button>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    @endif
@endif
@endsection

@push('scripts')
@php($jsTexts = ['picked' => __('merge_ai.picked'), 'confirm' => __('merge_ai.confirm_merge'), 'batches' => __('merge_ai.batches'), 'found' => __('merge_ai.found_js'), 'retrying' => __('merge_ai.retrying')])
<script>
(() => {
    const T = @json($jsTexts);
    const t = (s, vars) => s.replace(/:(\w+)/g, (m, k) => vars[k] ?? m);

    // pendant l'analyse : un lot apres l'autre, tant que la page est ouverte (la tache planifiee prend le relais sinon)
    const progress = document.getElementById('progress');
    if (progress) {
        const token = document.querySelector('meta[name=csrf-token]').content;
        const note = document.getElementById('p-note');
        const sleep = ms => new Promise(r => setTimeout(r, ms));
        (async () => {
            for (;;) {
                let data;
                try {
                    const res = await fetchRetry(progress.dataset.step, {method: 'POST', headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}}, 2, 180000);
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    data = await res.json();
                    note.hidden = true;
                } catch (e) {
                    if (e.name === 'AbortError') return;
                    note.textContent = T.retrying;
                    note.hidden = false;
                    await sleep(5000);
                    continue;
                }
                document.getElementById('p-bar').style.width = data.progress + '%';
                progress.querySelector('[role=progressbar]').setAttribute('aria-valuenow', data.progress);
                document.getElementById('p-done').textContent = t(T.batches, {done: data.done, total: data.batches});
                document.getElementById('p-found').textContent = t(T.found, {count: data.found});
                if (data.status !== 'running') { location.reload(); return; }
                if (data.busy) await sleep(4000);
            }
        })();
    }

    // le choix : tout, quelques groupes, ou des codes dans un groupe ; un groupe a moins de deux codes ne se fusionne pas
    const all = document.getElementById('all');
    const mergeBtn = document.getElementById('merge-selected');
    const picked = document.getElementById('picked');
    if (!mergeBtn) return;
    const groups = [...document.querySelectorAll('.pick-group')];
    const refresh = () => {
        let n = 0;
        document.querySelectorAll('.sug').forEach(card => {
            const kept = card.querySelectorAll('.pick-unit:checked').length;
            const box = card.querySelector('.pick-group');
            const one = card.querySelector('.merge-one');
            if (box) {
                box.disabled = kept < 2;
                if (kept < 2) box.checked = false;
                if (box.checked) n++;
            }
            if (one) one.disabled = kept < 2;
            card.classList.toggle('off', kept < 2);
        });
        mergeBtn.disabled = n === 0;
        picked.textContent = n ? t(T.picked, {count: n}) : '';
        const usable = groups.filter(g => !g.disabled);
        all.checked = usable.length > 0 && usable.every(g => g.checked);
        all.indeterminate = !all.checked && usable.some(g => g.checked);
    };
    all.addEventListener('change', () => { groups.forEach(g => { if (!g.disabled) g.checked = all.checked; }); refresh(); });
    document.addEventListener('change', e => { if (e.target.matches('.pick-group, .pick-unit')) refresh(); });
    // fusionner la selection : une confirmation (fusionner un seul groupe n'en demande pas)
    document.getElementById('merge-ai-form').addEventListener('submit', e => {
        if (e.submitter === mergeBtn && !confirm(t(T.confirm, {count: groups.filter(g => g.checked).length}))) e.preventDefault();
    });
    refresh();
})();
</script>
@endpush
