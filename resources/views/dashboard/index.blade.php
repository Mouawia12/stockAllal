@extends('layouts.app')

{{--
    "لوحة القيادة" (DashboardController) : en haut le chiffre de la periode en grand, avec sa tendance et sa courbe ;
    puis les cartes (bons, marge, retours, achats), les plus vendus, les branches, les clients, ce qui est vendu
    et epuise, le stock dormant et les heures de pointe. Les chiffres sont en Barlow Condensed (les nombres de
    l'etiquette de rayon) ; les barres en bleu-vert, jamais en rouge ou jaune (les couleurs du stock).
--}}
@php
    $D = __('dashboard');
    $n0 = fn ($v) => $v === null ? '—' : number_format(round($v), 0, ',', ' ');
    // l'argent de la caisse au centime, comme l'ecrit Gescomm
    $n2 = fn ($v) => number_format($v, 2, ',', ' ');
    // un nombre dans une phrase : isole de gauche a droite, sinon "33 230" s'affiche "230 33" en arabe
    $iso = fn (string $s) => "\u{2066}{$s}\u{2069}";
    // une quantite : sans decimales quand elle est entiere (12 boulons), avec sinon (12,50 m)
    $qty = fn ($v) => $v === null ? '—' : number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2, ',', ' ');
    $trend = function (?float $pct) use ($D) {
        if ($pct === null) {
            return ['class' => 'flat', 'arrow' => '', 'text' => $D['no_compare']];
        }

        return match (true) {
            $pct > 0 => ['class' => 'up', 'arrow' => '▲', 'text' => str_replace(':pct', number_format($pct, $pct >= 10 ? 0 : 1, ',', ' '), $D['up'])],
            $pct < 0 => ['class' => 'down', 'arrow' => '▼', 'text' => str_replace(':pct', number_format(-$pct, -$pct >= 10 ? 0 : 1, ',', ' '), $D['down'])],
            default => ['class' => 'flat', 'arrow' => '=', 'text' => $D['same']],
        };
    };
    $metric = $money ? 'amount' : 'bons';
@endphp
@section('title', $D['title'].' — '.__('common.app_name'))

@push('head')
<style>
    /* sur le papier (Alt+P) : une carte du tableau de bord ne se coupe pas entre deux pages */
    @media print { .card { break-inside: avoid; } }
    .dash-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px 20px; flex-wrap: wrap; margin-bottom: 16px; }
    .dash-head h1 { margin: 0; font-size: 24px; line-height: 1.2; }
    .dash-head .sub { margin: 4px 0 0; color: var(--muted); font-size: 13.5px; display: flex; gap: 6px 14px; flex-wrap: wrap; }
    .chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .chip { display: inline-flex; align-items: center; padding: 4px 13px; border: 1px solid var(--border); border-radius: 99px; color: var(--text); font-size: 13.5px; background: var(--card); }
    .chip:hover { background: var(--row-hover); }
    .chip.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .note { margin: 0 0 14px; }
    .dash-head .sub .when { color: var(--text); font-weight: 600; }

    /* les periodes : les boutons, puis le calendrier (un jour, des jours) et, pour un jour, la veille et le lendemain */
    .periods { display: flex; align-items: center; gap: 6px 10px; flex-wrap: wrap; }
    .date-pick { display: flex; align-items: center; gap: 4px; }
    .chip svg { width: 16px; height: 16px; display: block; }
    .chip.step { padding: 4px 6px; color: var(--muted); }
    .cal-btn { gap: 7px; }
    .ico-back, .ico-fwd { display: inline-flex; }
    /* le passe vers le debut de la ligne : a droite en arabe */
    [dir="rtl"] .ico-back, [dir="ltr"] .ico-fwd { transform: scaleX(-1); }

    .cal-pop { position: absolute; inset-inline-end: 0; top: calc(100% + 8px); z-index: 30; width: 356px; padding: 14px;
        background: var(--card); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow), 0 18px 44px rgba(0, 0, 0, .18); }
    .cal-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
    .cal-modes { display: inline-flex; padding: 3px; border-radius: 99px; background: var(--row-hover); }
    .cal-modes button { border: 0; background: none; border-radius: 99px; padding: 4px 15px; font-size: 13.5px; color: var(--muted); }
    .cal-modes button[aria-pressed="true"] { background: var(--card); color: var(--text); font-weight: 600; box-shadow: 0 1px 3px rgba(0, 0, 0, .14); }
    .cal-link { border: 0; background: none; color: var(--accent); font-size: 13.5px; font-weight: 600; padding: 4px 8px; }
    .cal-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
    .cal-month { font-size: 15.5px; }
    .cal-arrow { display: grid; place-items: center; width: 32px; height: 32px; padding: 0; border: 0; border-radius: 50%; background: none; color: var(--muted); }
    .cal-arrow:hover:not(:disabled) { background: var(--row-hover); color: var(--text); }
    .cal-arrow:disabled { opacity: .3; cursor: default; }
    .cal-arrow svg { width: 18px; height: 18px; }
    .cal-week, .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 3px; }
    .cal-week span { text-align: center; color: var(--muted); font-size: 12px; font-weight: 600; padding: 2px 0 6px; }
    .cal-week .fri { color: var(--accent); }
    .cal-grid { min-height: 282px; align-content: start; }
    /* un jour : son numero, ce qu'il a vendu en court (1,1M, 950k), et un fond d'autant plus fort qu'il a vendu (--heat) */
    .cal-day { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; height: 44px; padding: 0;
        border: 0; border-radius: 9px; background: color-mix(in srgb, var(--accent) var(--heat, 0%), transparent); color: var(--text); }
    .cal-day:hover:not(:disabled) { background: color-mix(in srgb, var(--accent) var(--heat, 0%), transparent); box-shadow: inset 0 0 0 1.5px var(--accent); }
    .cal-day:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
    .cal-day .n { font: 600 15px/1 var(--font-code); font-variant-numeric: tabular-nums; }
    .cal-day .v { font: 600 10.5px/1 var(--font-code); letter-spacing: .02em; color: var(--muted); direction: ltr; min-height: 10px; }
    .cal-day.today { box-shadow: inset 0 0 0 1.5px var(--accent); }
    .cal-day.today .n { color: var(--accent); }
    .cal-day.best .v { color: var(--text); }
    /* entre le premier et le dernier jour : un anneau, le fond garde ce que le jour a vendu */
    .cal-day.in, .cal-day.in:hover:not(:disabled) { box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--accent) 70%, transparent); }
    .cal-day.sel, .cal-day.start, .cal-day.end, .cal-day.sel:hover, .cal-day.start:hover, .cal-day.end:hover { background: var(--accent); color: var(--on-accent); }
    .cal-day.sel .n, .cal-day.start .n, .cal-day.end .n, .cal-day.sel .v, .cal-day.start .v, .cal-day.end .v { color: inherit; }
    .cal-day:disabled { opacity: .3; cursor: default; background: none; box-shadow: none; }
    .cal-foot { display: flex; align-items: center; justify-content: space-between; gap: 6px 10px; flex-wrap: wrap; margin-top: 10px; padding-top: 10px;
        border-top: 1px solid var(--border); color: var(--muted); font-size: 12.5px; }
    .cal-sum { margin: 0; }
    .cal-sum b { color: var(--text); font-weight: 600; direction: ltr; unicode-bidi: isolate; }
    .cal-legend { display: inline-flex; align-items: center; gap: 3px; }
    .cal-legend i { width: 12px; height: 12px; border-radius: 3px; border: 1px solid var(--border); background: color-mix(in srgb, var(--accent) var(--heat), transparent); }
    .cal-legend i:first-of-type { margin-inline-start: 3px; }
    .cal-legend i:last-of-type { margin-inline-end: 3px; }
    .cal-picks { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
    .cal-picks .chip { padding: 3px 11px; font-size: 13px; }
    /* la bulle d'un jour : en couleurs inversees, au-dessus de la case (sous elle en haut du calendrier) */
    .cal-tip { position: absolute; z-index: 2; min-width: 196px; max-width: 260px; padding: 10px 12px; border-radius: 10px; background: var(--text); color: var(--card);
        box-shadow: 0 10px 28px rgba(0, 0, 0, .28); pointer-events: none; font-size: 13px; }
    .cal-tip strong { display: block; margin-bottom: 6px; font-size: 13.5px; }
    .cal-tip dl { display: grid; gap: 3px; margin: 0; }
    .cal-tip dl div { display: flex; justify-content: space-between; gap: 16px; }
    .cal-tip dt { opacity: .72; }
    .cal-tip dd { margin: 0; font: 600 14px/1.3 var(--font-code); direction: ltr; unicode-bidi: isolate; }
    .cal-tip .note { margin: 6px 0 0; opacity: .8; }
    @supports not (background: color-mix(in srgb, red 50%, blue)) {
        .cal-day.has { background: var(--accent-soft); }
        .cal-legend { display: none; }
    }

    .dash { display: grid; gap: 14px; }
    .dash h2 { margin: 0; font-size: 16px; }
    .dash .help { margin: 2px 0 0; color: var(--muted); font-size: 13px; }
    .card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .fig { font-family: var(--font-code); font-weight: 700; letter-spacing: .01em; font-variant-numeric: tabular-nums; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }

    /* le chiffre de la periode et sa courbe : le seul element fort de la page */
    .hero { display: grid; grid-template-columns: minmax(220px, 280px) minmax(0, 1fr); gap: 18px 28px; align-items: stretch; overflow: visible; }
    .hero .what { display: flex; flex-direction: column; gap: 4px; }
    .hero .label { margin: 0; color: var(--muted); font-weight: 600; }
    .hero .big { margin: 2px 0 0; font-size: clamp(40px, 6vw, 66px); line-height: .95; color: var(--text); }
    /* le chiffre tient toujours dans sa colonne : plus il a de caracteres (--chars), plus il est petit */
    .hero .what { container-type: inline-size; }
    @supports (font-size: 1cqi) { .hero .big { font-size: clamp(34px, calc(210cqi / var(--chars)), 66px); } }
    .hero .compare { margin: 6px 0 0; font-size: 13.5px; }
    .hero .peak { margin: auto 0 0; padding-top: 10px; color: var(--muted); font-size: 13px; }
    .trend { font-weight: 600; }
    .trend.up { color: var(--accent); }
    .trend.down, .trend.flat { color: var(--muted); }
    .trend .ctx { font-weight: 400; color: var(--muted); }

    .chart { display: flex; flex-direction: column; min-width: 0; }
    .chart h2 { font-size: 14px; color: var(--muted); font-weight: 600; margin-bottom: 8px; }
    .bars { flex: 1; min-height: 150px; display: flex; align-items: flex-end; gap: 3px; border-bottom: 1px solid var(--border); }
    .bars span { flex: 1 1 0; min-width: 2px; height: var(--h); min-height: 2px; background: var(--bar); border-radius: 3px 3px 0 0; }
    .bars span:hover { background: var(--accent); }
    .bars span.now { background: var(--bar-strong); }
    .bars span.empty { background: var(--border); }
    .axis { display: flex; gap: 3px; margin-top: 5px; color: var(--muted); font-size: 11.5px; }
    /* chaque date centree sous sa barre ; celles des deux bords debordent vers l'interieur de la carte */
    .axis span { flex: 1 1 0; min-width: 2px; display: flex; justify-content: center; white-space: nowrap; }
    .axis span.first { justify-content: flex-start; }
    .axis span.last { justify-content: flex-end; }
    .axis span:not(.grid) { visibility: hidden; }

    /* la caisse : l'argent qui y est maintenant, puis chaque branche */
    .cash { display: grid; grid-template-columns: minmax(220px, 280px) minmax(0, 1fr); gap: 14px 28px; align-items: start; }
    .cash .label { margin: 0; color: var(--muted); font-weight: 600; }
    .cash .value { margin: 2px 0 0; font-size: 34px; line-height: 1.05; }
    .cash .flows { display: flex; flex-wrap: wrap; gap: 4px 18px; margin: 8px 0 0; color: var(--muted); font-size: 13.5px; }
    .cash .flows b { color: var(--text); font-weight: 600; }
    .cash .sub { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
    /* la pastille toujours avant le nom, meme latin dans une page arabe */
    .cash td .name { display: flex; align-items: center; gap: 7px; }

    .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; }
    .tile { display: flex; flex-direction: column; gap: 2px; }
    .tile .label { margin: 0; color: var(--muted); font-size: 13.5px; font-weight: 600; }
    .tile .value { margin: 0; font-size: 32px; line-height: 1.1; }
    .tile .sub { margin: 0; color: var(--muted); font-size: 13px; }
    .tile .trend { font-size: 13px; margin: 0; }

    .cols { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr); gap: 14px; align-items: start; }
    .stack { display: grid; gap: 14px; }
    .pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; align-items: start; }

    table.list th.n, table.list td.n { text-align: end; }
    table.list td { padding-top: 7px; padding-bottom: 7px; }
    table.list tr:last-child td { border-bottom: 0; }
    .rank { color: var(--muted); font: 600 15px/1 var(--font-code); width: 1%; }
    .item a { color: var(--text); }
    .item a:hover { color: var(--accent); }
    .item .name { display: block; font-weight: 600; line-height: 1.35; }
    .item .code { color: var(--muted); font-size: 14px; }
    /* la part de l'article par rapport au premier de la liste */
    .share { display: block; height: 4px; margin-top: 5px; border-radius: 99px; background: var(--row-hover); overflow: hidden; }
    .share i { display: block; height: 100%; width: var(--w); background: var(--bar-strong); border-radius: 99px; }
    .where { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-size: 13px; }

    .branch-row { display: grid; gap: 4px; padding: 8px 0; border-bottom: 1px solid var(--border); }
    .branch-row:last-child { border-bottom: 0; }
    .branch-row .top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .branch-row .name { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; }
    .branch-row .meter { height: 8px; border-radius: 99px; background: var(--row-hover); overflow: hidden; }
    .branch-row .meter i { display: block; height: 100%; width: var(--w); border-radius: 99px; background: hsl(var(--bh) 55% var(--tint-dot-l)); }
    .branch-row .line { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; color: var(--muted); }

    .dormant-sum { display: flex; align-items: baseline; gap: 8px 14px; flex-wrap: wrap; margin: 0 0 10px; }
    .dormant-sum strong { font-size: 19px; }
    .empty { margin: 4px 0; color: var(--muted); }

    .hours { display: flex; align-items: flex-end; gap: 4px; height: 90px; border-bottom: 1px solid var(--border); }
    .hours span { flex: 1 1 0; height: var(--h); min-height: 2px; background: var(--bar); border-radius: 3px 3px 0 0; }
    .hours span.top { background: var(--bar-strong); }
    .hours-axis { display: flex; gap: 4px; margin-top: 5px; color: var(--muted); font: 600 12px/1 var(--font-code); }
    .hours-axis span { flex: 1 1 0; text-align: center; }

    @media (max-width: 900px) {
        .cols, .pair, .cash { grid-template-columns: minmax(0, 1fr); }
    }
    @media (max-width: 700px) {
        .hero { grid-template-columns: minmax(0, 1fr); }
        .hero .peak { margin-top: 0; padding-top: 0; }
        .bars { min-height: 120px; gap: 2px; }
        .axis { gap: 2px; }
        .axis span:not(.keep) { visibility: hidden; }
        .axis span.keep { visibility: visible; }
        .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .tile .value { font-size: 26px; }
        .dash-head .chips, .periods { width: 100%; }
        /* sur telephone : le calendrier en bas de l'ecran, sur toute la largeur */
        .cal-pop { position: fixed; inset-inline: 8px; top: auto; bottom: 8px; width: auto; max-height: calc(100vh - 16px); overflow-y: auto; }
    }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => ['Alt', '1…4'], 'label' => $D['period']])
    @include('partials.key', ['keys' => ['Alt', '5'], 'run' => 'Alt+5', 'label' => $D['calendar']['open']])
@endpush

@php
    $calendarPage = [
        'base' => route('dashboard.index'),
        'json' => route('dashboard.calendar'),
        'rank' => $rank === 'qty' && $money ? 'qty' : null,
        'month' => $date['month'],
        // un exercice clos ouvert : son dernier jour (App\Models\Scopes\ExerciseScope::now)
        'today' => App\Models\Scopes\ExerciseScope::now()->toDateString(),
        // ce qui est choisi : un jour (from = to) ou des jours ; rien pour 7, 30 jours ou 12 mois
        'chosen' => in_array($kind, ['today', 'day', 'range'], true) ? ['from' => $window['from'], 'to' => $window['to']] : null,
        'mode' => $kind === 'range' ? 'range' : 'day',
    ];
@endphp
@push('scripts')
<script>
// Le calendrier de "لوحة القيادة" : un mois (DashboardController::calendar), chaque jour avec ce qu'il a vendu (en court
// dans la case, un fond d'autant plus fort qu'il a vendu), et sa bulle au survol ou au clavier : ventes, bons, caisse.
// "يوم" : un clic ouvre ce jour ; "مدة" : le premier jour puis le dernier. Fleches = jour / semaine (dans le sens de la
// langue), Page precedente / suivante = mois, Entree = choisir, Echap = annuler le debut d'une periode, puis fermer.
(() => {
    const cal = document.getElementById('cal');
    if (!cal) return;
    const P = @json($calendarPage);
    const T = @json($D['calendar']);
    const pop = cal.querySelector('.cal-pop');
    const grid = pop.querySelector('.cal-grid');
    const monthEl = pop.querySelector('.cal-month');
    const sum = pop.querySelector('.cal-sum');
    const tip = pop.querySelector('.cal-tip');
    const RTL = document.documentElement.dir === 'rtl';
    const months = new Map();
    let month = P.month, mode = P.mode, start = null, data = null;

    const fmt = v => Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    // dans la case : 1,1M, 18M, 950k, 75
    const compact = v => {
        const a = Math.abs(v);
        if (a >= 1e6) return (v / 1e6).toFixed(a >= 1e7 ? 0 : 1).replace('.', ',') + 'M';
        if (a >= 1e3) return Math.round(v / 1e3) + 'k';
        return String(Math.round(v));
    };
    const esc = s => Object.assign(document.createElement('span'), {textContent: s}).innerHTML;
    const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const short = date => date.slice(8, 10) + '/' + date.slice(5, 7);
    const metric = () => data.money ? 'amount' : 'bons';

    function urlFor(from, to) {
        const q = new URLSearchParams();
        if (from !== to) { q.set('from', from); q.set('to', to); }
        else if (from === P.today) q.set('period', 'today');
        else q.set('day', from);
        if (P.rank) q.set('rank', P.rank);
        return P.base + '?' + q;
    }

    async function load(m) {
        if (!months.has(m)) {
            months.set(m, fetchRetry(P.json + '?month=' + m, {headers: {Accept: 'application/json'}}).then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            }));
        }
        try { return await months.get(m); } catch (e) { months.delete(m); throw e; }
    }

    // focus : le jour qui prend le clavier (navigation au clavier) ; sans, le clavier reste ou il est (souris)
    async function show(m, focus) {
        grid.setAttribute('aria-busy', 'true');
        sum.textContent = T.loading;
        tip.hidden = true;
        try { data = await load(m); }
        catch (e) {
            if (e.name !== 'AbortError') sum.textContent = T.error;
            grid.setAttribute('aria-busy', 'false');
            return;
        }
        month = data.month;
        render(focus);
    }

    function render(focus) {
        monthEl.textContent = data.label;
        pop.querySelector('[data-go="prev"]').disabled = !data.prev;
        pop.querySelector('[data-go="next"]').disabled = !data.next;
        const key = metric();
        const max = Math.max(0, ...data.days.map(d => d[key]));
        const best = max > 0 ? data.days.find(d => d[key] === max).date : null;
        const cells = [];
        for (let i = 0; i < data.offset; i++) cells.push(Object.assign(document.createElement('span'), {className: 'cal-blank'}));
        for (const d of data.days) {
            const b = document.createElement('button');
            const v = d[key];
            b.type = 'button';
            b.className = 'cal-day';
            b.dataset.date = d.date;
            b.tabIndex = -1;
            b.disabled = d.future;
            if (v > 0) { b.classList.add('has'); b.style.setProperty('--heat', (8 + 40 * v / max).toFixed(1) + '%'); }
            if (d.date === data.today) b.classList.add('today');
            if (d.date === best) b.classList.add('best');
            b.innerHTML = '<span class="n">' + Number(d.date.slice(8)) + '</span><span class="v">' + (v > 0 ? compact(v) : '') + '</span>';
            b.setAttribute('aria-label', d.label + (v > 0 ? ' — ' + (data.money ? fmt(v) : T.bons + ' ' + v) : ''));
            cells.push(b);
        }
        grid.replaceChildren(...cells);
        grid.setAttribute('aria-busy', 'false');
        paint();
        hint();
        const days = [...grid.querySelectorAll('.cal-day:not(:disabled)')];
        const target = (focus && grid.querySelector('[data-date="' + focus + '"]:not(:disabled)'))
            || grid.querySelector('.sel, .end') || grid.querySelector('.today') || days[days.length - 1];
        if (target) {
            target.tabIndex = 0;
            if (focus) target.focus();
        }
    }

    // le jour ou les jours choisis ; pendant un choix "مدة" : du premier jour jusqu'au jour survole
    function paint(hover) {
        let from = null, to = null;
        if (start) [from, to] = [start, hover || start].sort();
        else if (P.chosen) [from, to] = [P.chosen.from, P.chosen.to];
        for (const b of grid.querySelectorAll('.cal-day')) {
            const d = b.dataset.date;
            const range = from !== null && from !== to;
            b.classList.toggle('sel', from !== null && !range && d === from);
            b.classList.toggle('start', range && d === from);
            b.classList.toggle('end', range && d === to);
            b.classList.toggle('in', range && d > from && d < to);
            b.setAttribute('aria-selected', String(from !== null && d >= from && d <= to));
        }
    }

    // sous la grille : le total du mois, ou ce qu'il faut faire pendant un choix "مدة"
    function hint() {
        if (mode === 'range') {
            sum.textContent = start ? T.range_from.replace(':date', '⁦' + short(start) + '⁩') : T.range_hint;
            return;
        }
        const total = data.days.reduce((t, d) => ({amount: t.amount + (d.amount || 0), bons: t.bons + d.bons}), {amount: 0, bons: 0});
        // un nombre dans une phrase : isole de gauche a droite, sinon "2 483 475" s'affiche "475 483 2" en arabe
        const bons = esc(T.month_bons.replace(':count', '⁦' + fmt(total.bons) + '⁩'));
        sum.innerHTML = data.money ? esc(T.month_total).replace(':amount', '<b>' + fmt(total.amount) + '</b>') + ' · ' + bons : '<b>' + bons + '</b>';
    }

    function tipFor(b) {
        const d = data.days.find(x => x.date === b.dataset.date);
        if (!d) return;
        let html = '<strong>' + esc(d.label) + '</strong>';
        const row = (label, value) => '<div><dt>' + esc(label) + '</dt><dd>' + value + '</dd></div>';
        if (d.future) {
            html += '<p class="note">' + esc(T.future) + '</p>';
        } else {
            let rows = '';
            if (d.bons > 0) {
                if (data.money) rows += row(T.sales, fmt(d.amount));
                rows += row(T.bons, fmt(d.bons));
            }
            if (data.cash && (d.cash_in || d.cash_out)) rows += row(T.cash_in, '+' + fmt(d.cash_in)) + row(T.cash_out, '−' + fmt(d.cash_out));
            if (rows) html += '<dl>' + rows + '</dl>';
            if (d.bons === 0) html += '<p class="note">' + esc(T.no_sales) + '</p>';
            if (b.classList.contains('best')) html += '<p class="note">★ ' + esc(T.best) + '</p>';
        }
        tip.innerHTML = html;
        tip.hidden = false;
        // au-dessus de la case, dans le calendrier ; sous elle s'il n'y a pas la place
        const p = pop.getBoundingClientRect(), r = b.getBoundingClientRect();
        const left = Math.max(6, Math.min(r.left - p.left + r.width / 2 - tip.offsetWidth / 2, p.width - tip.offsetWidth - 6));
        let top = r.top - p.top - tip.offsetHeight - 8;
        if (top < 6) top = r.bottom - p.top + 8;
        tip.style.left = left + 'px';
        tip.style.top = top + pop.scrollTop + 'px';
    }

    function pick(date) {
        if (mode === 'day') { location.href = urlFor(date, date); return; }
        if (!start) { start = date; paint(); hint(); return; }
        const [from, to] = [start, date].sort();
        location.href = urlFor(from, to);
    }

    function move(from, step) {
        const d = new Date(from + 'T12:00:00');
        d.setDate(d.getDate() + step);
        const day = iso(d);
        if (day > data.today) return;
        const m = day.slice(0, 7);
        if (m === month) {
            const t = grid.querySelector('[data-date="' + day + '"]');
            if (!t) return;
            grid.querySelectorAll('[tabindex="0"]').forEach(x => { x.tabIndex = -1; });
            t.tabIndex = 0;
            t.focus();
        } else if ((m < month && data.prev) || (m > month && data.next)) {
            show(m, day);
        }
    }

    grid.addEventListener('click', e => {
        const b = e.target.closest('.cal-day');
        if (b && !b.disabled) pick(b.dataset.date);
    });
    grid.addEventListener('pointerover', e => {
        const b = e.target.closest('.cal-day');
        if (!b || e.pointerType === 'touch') return;
        if (start) paint(b.dataset.date);
        tipFor(b);
    });
    grid.addEventListener('pointerleave', () => { tip.hidden = true; if (start) paint(); });
    grid.addEventListener('focusin', e => {
        const b = e.target.closest('.cal-day');
        if (!b || !b.matches(':focus-visible')) return;
        if (start) paint(b.dataset.date);
        tipFor(b);
    });
    grid.addEventListener('focusout', e => { if (!grid.contains(e.relatedTarget)) tip.hidden = true; });
    grid.addEventListener('keydown', e => {
        const b = e.target.closest('.cal-day');
        if (!b) return;
        const step = {ArrowRight: RTL ? -1 : 1, ArrowLeft: RTL ? 1 : -1, ArrowDown: 7, ArrowUp: -7}[e.key];
        if (step) { e.preventDefault(); move(b.dataset.date, step); return; }
        if (e.key === 'PageUp' || e.key === 'PageDown') {
            e.preventDefault();
            const m = e.key === 'PageUp' ? data.prev : data.next;
            if (m) show(m, (m + b.dataset.date.slice(7)) > data.today ? data.today : m + b.dataset.date.slice(7));
        }
    });

    pop.querySelectorAll('[data-mode]').forEach(btn => btn.addEventListener('click', () => {
        mode = btn.dataset.mode;
        start = null;
        pop.querySelectorAll('[data-mode]').forEach(x => x.setAttribute('aria-pressed', String(x === btn)));
        if (data) { paint(); hint(); }
    }));
    pop.querySelector('[data-go="prev"]').addEventListener('click', () => { if (data?.prev) show(data.prev); });
    pop.querySelector('[data-go="next"]').addEventListener('click', () => { if (data?.next) show(data.next); });
    pop.querySelector('[data-go="today"]').addEventListener('click', () => show(P.today.slice(0, 7), P.today));

    // Echap pendant un choix "مدة" : annuler le premier jour, sans fermer (avant le Echap qui ferme les menus, layouts/app)
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape' || !cal.open || !start) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        start = null;
        paint();
        hint();
    }, true);

    // le mois se charge des que la souris approche, il est pret a l'ouverture
    cal.querySelector('summary').addEventListener('pointerenter', () => { load(month).catch(() => {}); }, {once: true});
    cal.addEventListener('toggle', () => {
        if (!cal.open) { start = null; tip.hidden = true; return; }
        const focus = P.chosen ? P.chosen.to : P.today;
        if (data && data.month === month) render(focus);
        else show(month, focus);
    });
})();
</script>
@endpush

@section('content')
@php
    $Cal = $D['calendar'];
    // fleche vers le debut de la ligne (le passe) : a gauche en francais, a droite en arabe (.ico-back / .ico-fwd)
    $chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>';
@endphp
<div class="dash-head">
    <div>
        <h1>{{ $D['title'] }}</h1>
        <p class="sub">
            <span>{{ $selected ? $selected->name : __('common.scope.all').' ('.trans_choice('dashboard.scope_all', $branches, ['count' => $branches]).')' }}</span>
            @if ($date['label'])<span class="when">{{ $date['label'] }}</span>@endif
            @if (! empty($syncedAt))<span title="{{ $syncedAt->format('d/m/Y H:i') }}">{{ __('dashboard.synced', ['time' => $syncedAt->diffForHumans()]) }}</span>@endif
        </p>
    </div>
    <div class="periods">
        <button type="button" class="btn print-btn" data-key="Alt+P" title="{{ __('print.print') }} (Alt+P)" onclick="window.print()">@include('partials.app-icon', ['name' => 'print']){{ __('print.print') }}</button>
        <nav class="chips" aria-label="{{ $D['period'] }}">
            @foreach (App\Support\SalesDashboard::PERIODS as $p)
                <a href="{{ route('dashboard.index', array_filter(['period' => $p === '30' ? null : $p, 'rank' => $rank === 'qty' && $money ? 'qty' : null])) }}" data-key="Alt+{{ $loop->iteration }}"
                   @class(['chip', 'on' => $period === $p]) @if ($period === $p) aria-current="true" @endif>{{ $D['periods'][$p] }}</a>
            @endforeach
        </nav>
        {{-- un jour ou des jours choisis : le calendrier (details.drop : un clic ailleurs ou Echap le ferme, layouts/app) --}}
        <div class="date-pick">
            @if ($date['prev'])<a class="chip step" href="{{ $date['prev'] }}" title="{{ $Cal['prev_day'] }}" aria-label="{{ $Cal['prev_day'] }}"><span class="ico-back">{!! $chevron !!}</span></a>@endif
            <details class="drop cal" id="cal">
                <summary @class(['chip', 'cal-btn', 'on' => in_array($kind, ['day', 'range'], true)]) data-key="Alt+5" title="{{ $Cal['open'] }}" aria-label="{{ $Cal['open'] }}{{ $date['label'] ? ': '.$date['label'] : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4M8 13.5h2M14 13.5h2M8 17h2"/></svg>
                    <span>{{ $date['label'] ?? $Cal['pick'] }}</span>
                </summary>
                <div class="cal-pop" role="dialog" aria-label="{{ $Cal['title'] }}">
                    <div class="cal-top">
                        <div class="cal-modes" role="group" aria-label="{{ $Cal['title'] }}">
                            <button type="button" data-mode="day" aria-pressed="{{ $kind === 'range' ? 'false' : 'true' }}">{{ $Cal['modes']['day'] }}</button>
                            <button type="button" data-mode="range" aria-pressed="{{ $kind === 'range' ? 'true' : 'false' }}">{{ $Cal['modes']['range'] }}</button>
                        </div>
                        <button type="button" class="cal-link" data-go="today">{{ $Cal['today'] }}</button>
                    </div>
                    <div class="cal-nav">
                        <button type="button" class="cal-arrow" data-go="prev" title="{{ $Cal['prev_month'] }}" aria-label="{{ $Cal['prev_month'] }}"><span class="ico-back">{!! $chevron !!}</span></button>
                        <strong class="cal-month" aria-live="polite"></strong>
                        <button type="button" class="cal-arrow" data-go="next" title="{{ $Cal['next_month'] }}" aria-label="{{ $Cal['next_month'] }}"><span class="ico-fwd">{!! $chevron !!}</span></button>
                    </div>
                    <div class="cal-week" aria-hidden="true">
                        @foreach ($Cal['weekdays'] as $w)<span @class(['fri' => $loop->last]) title="{{ $Cal['weekdays_long'][$loop->index] }}">{{ $w }}</span>@endforeach
                    </div>
                    <div class="cal-grid" role="grid" aria-busy="true"></div>
                    <div class="cal-foot">
                        <p class="cal-sum" aria-live="polite"></p>
                        <span class="cal-legend" aria-hidden="true">{{ $Cal['less'] }}<i style="--heat: 10%"></i><i style="--heat: 22%"></i><i style="--heat: 34%"></i><i style="--heat: 46%"></i>{{ $Cal['more'] }}</span>
                    </div>
                    <nav class="cal-picks" aria-label="{{ $D['period'] }}">
                        @foreach ($date['picks'] as $pick)
                            <a href="{{ $pick['url'] }}" @class(['chip', 'on' => $pick['on']]) @if ($pick['on']) aria-current="true" @endif>{{ $pick['label'] }}</a>
                        @endforeach
                    </nav>
                    <div class="cal-tip" role="tooltip" hidden></div>
                </div>
            </details>
            @if ($date['next'])<a class="chip step" href="{{ $date['next'] }}" title="{{ $Cal['next_day'] }}" aria-label="{{ $Cal['next_day'] }}"><span class="ico-fwd">{!! $chevron !!}</span></a>@endif
        </div>
    </div>
</div>

@if ($outdated->isNotEmpty())
    <p class="note muted small">{{ __('documents.outdated', ['names' => $outdated->join(__('stock.js.list_separator'))]) }}</p>
@endif

@if ($f === null)
    <div class="card"><p class="empty">{{ $selected ? __('documents.no_branch_selected', ['branch' => $selected->name]) : __('documents.no_branch') }}</p></div>
@else
<div class="dash">
    {{-- le chiffre de la periode, sa tendance, et sa courbe --}}
    <section class="card hero" aria-labelledby="hero-label">
        @php($t = $trend($trends[$metric]))
        <div class="what">
            <p class="label" id="hero-label">{{ $money ? $D['net_sales'] : $D['bons'] }}</p>
            @php($big = $n0($money ? $totals['amount'] : $totals['bons']))
            <p class="big fig" style="--chars: {{ max(5, mb_strlen($big)) }}">{{ $big }}</p>
            <p class="compare trend {{ $t['class'] }}">@if ($t['arrow'])<span aria-hidden="true">{{ $t['arrow'] }}</span> @endif{{ $t['text'] }}@if ($trends[$metric] !== null) <span class="ctx">{{ $D['compare'][$kind] }}</span>@endif</p>
            @if ($money)<p class="help">{{ $D['net_sales_help'] }}</p>@endif
            @if ($peak && count($series) > 1)<p class="peak">{{ __('dashboard.peak', ['when' => $peak['tip']]) }}</p>@endif
        </div>
        <div class="chart">
            <h2>{{ $D['chart'][$metric][$f['window']['unit']] }}</h2>
            @php($every = max(1, (int) ceil(count($series) / ($f['window']['unit'] === 'month' ? 12 : 10))))
            <div class="bars" role="img" aria-label="{{ $D['chart'][$metric][$f['window']['unit']] }}{{ $peak ? '. '.__('dashboard.peak', ['when' => $peak['tip']]) : '' }}">
                @foreach ($series as $p)
                    <span @class(['now' => $p['now'], 'empty' => $p['value'] <= 0]) style="--h: {{ max(0, $p['pct']) }}%" title="{{ $p['tip'] }}"></span>
                @endforeach
            </div>
            {{-- une date toutes les $every cases en partant de maintenant (.grid) ; sur telephone seulement le debut,
                 le milieu et la fin (.keep), sinon les dates se chevauchent --}}
            @php($lastIndex = count($series) - 1)
            @php($middle = intdiv($lastIndex, 2))
            <div class="axis" aria-hidden="true">
                @foreach ($series as $i => $p)
                    @php($grid = ($lastIndex - $i) % $every === 0)
                    @php($keep = $i === 0 || $i === $middle || $i === $lastIndex)
                    <span @class(['grid' => $grid, 'keep' => $keep, 'first' => $i === 0, 'last' => $i === $lastIndex])>{{ $grid || $keep ? $p['label'] : '' }}</span>
                @endforeach
            </div>
            @if ($totals['bons'] === 0)<p class="empty">{{ $D['no_sales'] }}</p>@endif
        </div>
    </section>

    {{-- la caisse (cash.view) : son solde maintenant, ce qui est entre et sorti aujourd'hui, puis chaque branche --}}
    @if ($cash !== null)
        @php($C = $D['cash'])
        @php($agentCash = App\Models\Branch::AGENT_FEATURES['cash'])
        <section class="card" aria-labelledby="cash-title">
            <div class="card-head">
                <div><h2 id="cash-title">{{ $C['title'] }}</h2><p class="help">{{ $C['help'] }}</p></div>
                @if ($cash['branches']->isNotEmpty())
                    <a class="btn print-btn" href="{{ route('cash.print', ['from' => $window['from'], 'to' => $window['to']]) }}" target="_blank" title="{{ __('print.cash.button') }}">@include('partials.app-icon', ['name' => 'print']){{ __('print.cash.button') }}</a>
                @endif
                @if ($cash['checked_at'])<p class="muted small" title="{{ $cash['checked_at']->format('d/m/Y H:i') }}">{{ __('dashboard.cash.checked', ['time' => $cash['checked_at']->diffForHumans()]) }}</p>@endif
            </div>
            @if ($cash['branches']->isEmpty())
                <p class="empty">{{ __('dashboard.cash.none', ['names' => $cash['outdated']->join(__('stock.js.list_separator')), 'version' => $agentCash]) }}</p>
            @else
                <div class="cash">
                    <div>
                        <p class="label">{{ $cash['now'] ? $C['now'] : __('dashboard.cash.at_end', ['date' => $iso($cash['end'])]) }}</p>
                        {{-- le chiffre isole (.fig) dans un paragraphe de la langue de la page : du meme cote que son titre --}}
                        <p class="value"><span class="fig">{{ $n2($cash['total']) }}</span></p>
                        <p class="flows">
                            <span>{{ $C['in_'.$cash['flow']] }} <b class="fig">{{ $n2($cash['in']) }}</b></span>
                            <span>{{ $C['out_'.$cash['flow']] }} <b class="fig">{{ $n2($cash['out']) }}</b></span>
                        </p>
                        @if ($cash['branches']->count() === 1 && $cash['branches'][0]['last'])
                            <p class="sub">{{ __('dashboard.cash.last', ['when' => $iso($cash['branches'][0]['last'])]) }}</p>
                        @endif
                    </div>
                    @if ($cash['branches']->count() > 1)
                        <table class="list">
                            <thead><tr>
                                <th>{{ $D['branches_title'] }}</th>
                                <th class="n">{{ $C['col_balance'] }}</th>
                                <th class="n hide-sm">{{ $C['in_'.$cash['flow']] }}</th>
                                <th class="n hide-sm">{{ $C['out_'.$cash['flow']] }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($cash['branches'] as $b)
                                <tr>
                                    <td class="item" style="--bh: {{ $b['hue'] }}">
                                        <span class="name"><span class="bdot"></span>{{ $b['branch'] }}</span>
                                        @if ($b['last'])<span class="muted small">{{ __('dashboard.cash.last', ['when' => $iso($b['last'])]) }}</span>@endif
                                    </td>
                                    <td class="n"><span class="fig">{{ $n2($b['balance']) }}</span></td>
                                    <td class="n hide-sm"><span class="num">{{ $n2($b['in']) }}</span></td>
                                    <td class="n hide-sm"><span class="num">{{ $n2($b['out']) }}</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                @if ($cash['outdated']->isNotEmpty())
                    <p class="sub muted small">{{ __('dashboard.cash.outdated', ['version' => $agentCash, 'names' => $cash['outdated']->join(__('stock.js.list_separator'))]) }}</p>
                @endif
            @endif
        </section>
    @endif

    {{-- les cartes de la periode --}}
    <div class="tiles">
        @if ($money)
            @php($t = $trend($trends['bons']))
            <section class="card tile">
                <h2 class="label">{{ $D['bons'] }}</h2>
                <p class="value fig">{{ $n0($totals['bons']) }}</p>
                <p class="trend {{ $t['class'] }}">@if ($t['arrow'])<span aria-hidden="true">{{ $t['arrow'] }}</span> @endif{{ $t['text'] }}</p>
                @if ($totals['average'] !== null)<p class="sub">{{ __('dashboard.average', ['amount' => $iso($n0($totals['average']))]) }}</p>@endif
            </section>
        @endif
        @if ($totals['margin'] !== null)
            @php($t = $trend($trends['margin']))
            <section class="card tile">
                <h2 class="label">{{ $D['margin'] }}</h2>
                <p class="value fig">{{ $n0($totals['margin']) }}</p>
                <p class="trend {{ $t['class'] }}">@if ($t['arrow'])<span aria-hidden="true">{{ $t['arrow'] }}</span> @endif{{ $t['text'] }}</p>
                @if ($totals['margin_pct'] !== null)<p class="sub">{{ __('dashboard.margin_pct', ['pct' => $iso(number_format($totals['margin_pct'], 1, ',', ' '))]) }}</p>@endif
                @if ($totals['margin_partial'])<p class="sub">{{ $D['margin_partial'] }}</p>@endif
            </section>
        @endif
        <section class="card tile">
            <h2 class="label">{{ $D['returns'] }}</h2>
            <p class="value fig">{{ $money ? $n0($totals['back_amount']) : $qty($totals['back_qty']) }}</p>
            <p class="sub">{{ $totals['back_lines'] ? trans_choice('documents.return_lines', $totals['back_lines'], ['count' => $totals['back_lines']]) : '—' }}</p>
            @if ($money && $totals['back_lines'])<p class="sub">{{ __('dashboard.returns_qty', ['qty' => $iso($qty($totals['back_qty']))]) }}</p>@endif
        </section>
        @if ($totals['purchase_amount'] !== null)
            <section class="card tile">
                <h2 class="label">{{ $D['purchases'] }}</h2>
                <p class="value fig">{{ $n0($totals['purchase_amount']) }}</p>
                <p class="sub">{{ trans_choice('dashboard.bons_count', $totals['purchase_bons'], ['count' => $totals['purchase_bons']]) }}</p>
            </section>
        @else
            <section class="card tile">
                <h2 class="label">{{ $D['products_sold'] }}</h2>
                <p class="value fig">{{ $n0($totals['products']) }}</p>
                <p class="sub">{{ $D['products_sold_help'] }}</p>
            </section>
        @endif
    </div>

    <div class="cols">
        {{-- les plus vendus --}}
        <section class="card">
            <div class="card-head">
                <h2>{{ $D['top_title'] }}</h2>
                @if ($money)
                    <nav class="chips" aria-label="{{ $D['rank'] }}">
                        @foreach (App\Http\Controllers\DashboardController::RANKS as $r)
                            <a href="{{ route('dashboard.index', array_filter($here + ['rank' => $r === 'amount' ? null : $r])) }}"
                               @class(['chip', 'on' => $rank === $r]) @if ($rank === $r) aria-current="true" @endif>{{ $D['ranks'][$r] }}</a>
                        @endforeach
                    </nav>
                @endif
            </div>
            @if ($products->isEmpty())
                <p class="empty">{{ $D['no_sales'] }}</p>
            @else
                <table class="list">
                    <thead><tr>
                        <th class="rank">#</th>
                        <th>{{ $D['col_product'] }}</th>
                        <th @class(['n', 'hide-sm' => $rank === 'amount'])>{{ $D['col_qty'] }}</th>
                        @if ($money)<th @class(['n', 'hide-sm' => $rank === 'qty'])>{{ $D['col_amount'] }}</th>@endif
                        @if ($buy)<th class="n hide-sm">{{ $D['col_margin'] }}</th>@endif
                        <th class="n">{{ $D['col_stock'] }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($products as $p)
                        <tr>
                            <td class="rank">{{ $loop->iteration }}</td>
                            <td class="item">
                                <a href="{{ $p['url'] }}"><span class="name">{{ $p['designation'] ?? $p['code'] }}</span>@if ($p['designation'])<span class="code ltr">{{ $p['code'] }}</span>@endif</a>
                                <span class="share" aria-hidden="true"><i style="--w: {{ $p['bar'] }}%"></i></span>
                            </td>
                            <td @class(['n', 'hide-sm' => $rank === 'amount'])><span class="num">{{ $qty($p['net']) }}</span></td>
                            @if ($money)<td @class(['n', 'hide-sm' => $rank === 'qty'])><span class="num">{{ $n0($p['amount']) }}</span></td>@endif
                            @if ($buy)<td class="n hide-sm"><span class="num" @if ($p['margin_partial']) title="{{ $D['margin_partial_row'] }}" @endif>{{ $p['margin'] === null ? '—' : number_format($p['margin'], 1, ',', ' ').'%' }}{{ $p['margin_partial'] ? '*' : '' }}</span></td>@endif
                            <td class="n">@if ($p['stock'] === null)<span class="muted">—</span>@else<span class="qty {{ $p['state'] }}">{{ $qty($p['stock']) }}</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <div class="stack">
            @if ($several)
                {{-- la part de chaque branche --}}
                <section class="card">
                    <div class="card-head"><div><h2>{{ $D['branches_title'] }}</h2><p class="help">{{ $D['branches_help'][$metric] }}</p></div></div>
                    @foreach ($perBranch as $b)
                        @php($t = $trend($b['trend']))
                        <div class="branch-row" style="--bh: {{ $b['hue'] }}">
                            <div class="top">
                                <span class="name"><span class="bdot"></span>{{ $b['branch'] }}</span>
                                <span class="fig">{{ $n0($b['value']) }}</span>
                            </div>
                            <div class="meter" aria-hidden="true"><i style="--w: {{ $b['share'] }}%"></i></div>
                            <div class="line"><span class="num">{{ number_format($b['share'], 0, ',', ' ') }}%</span><span class="trend {{ $t['class'] }}">@if ($t['arrow'])<span aria-hidden="true">{{ $t['arrow'] }}</span> @endif{{ $t['text'] }}</span></div>
                        </div>
                    @endforeach
                </section>
            @endif

            {{-- les meilleurs clients --}}
            <section class="card">
                <div class="card-head"><h2>{{ $D['customers_title'] }}</h2></div>
                @if ($customers->isEmpty())
                    <p class="empty">{{ $D['no_sales'] }}</p>
                @else
                    <table class="list">
                        <tbody>
                        @foreach ($customers as $c)
                            <tr>
                                <td class="item">
                                    @if ($c['url'])<a href="{{ $c['url'] }}"><span class="name">{{ $c['name'] }}</span></a>@else<span class="name">{{ $c['name'] }}</span>@endif
                                    @if ($several)<span class="where" style="--bh: {{ $c['hue'] }}"><span class="bdot"></span>{{ $c['branch'] }}</span>@endif
                                </td>
                                <td class="n muted small">{{ trans_choice('dashboard.bons_count', $c['bons'], ['count' => $c['bons']]) }}</td>
                                @if ($money)<td class="n"><span class="fig">{{ $n0($c['amount']) }}</span></td>@endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
    </div>

    <div class="pair">
        {{-- vendus puis epuises : les couleurs du stock de Gescomm (0 rouge, negatif jaune) --}}
        <section class="card">
            <div class="card-head"><div><h2>{{ $D['sold_out_title'] }}</h2><p class="help">{{ $D['sold_out_help'] }}</p></div></div>
            @if (! $dormant['seen'])
                <p class="empty">{{ $D['stock_unseen'] }}</p>
            @elseif ($soldOut->isEmpty())
                <p class="empty">{{ $D['sold_out_none'] }}</p>
            @else
                <table class="list">
                    <thead><tr><th>{{ $D['col_product'] }}</th><th class="n">{{ $D['col_sold'] }}</th><th class="n">{{ $D['col_stock'] }}</th></tr></thead>
                    <tbody>
                    @foreach ($soldOut as $r)
                        <tr>
                            <td class="item">
                                <a href="{{ $r['url'] }}"><span class="name">{{ $r['designation'] ?? $r['code'] }}</span>@if ($r['designation'])<span class="code ltr">{{ $r['code'] }}</span>@endif</a>
                                @if ($several)<span class="where" style="--bh: {{ $r['hue'] }}"><span class="bdot"></span>{{ $r['branch'] }}</span>@endif
                            </td>
                            <td class="n"><span class="num">{{ $qty($r['sold']) }}</span></td>
                            <td class="n"><span class="qty {{ $r['state'] }}">{{ $qty($r['stock']) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        {{-- le stock dormant --}}
        <section class="card">
            <div class="card-head"><div><h2>{{ $D['dormant_title'] }}</h2><p class="help">{{ __('dashboard.dormant_help', ['days' => App\Support\SalesDashboard::DORMANT_DAYS]) }}</p></div></div>
            @if (! $dormant['seen'])
                <p class="empty">{{ $D['stock_unseen'] }}</p>
            @elseif ($dormant['count'] === 0)
                <p class="empty">{{ __('dashboard.dormant_none', ['days' => App\Support\SalesDashboard::DORMANT_DAYS]) }}</p>
            @else
                <p class="dormant-sum">
                    <strong>{{ trans_choice('dashboard.dormant_count', $dormant['count'], ['count' => $iso($n0($dormant['count']))]) }}</strong>
                    @if ($dormant['value'] !== null)<span class="muted">{{ __('dashboard.dormant_value', ['value' => $iso($n0($dormant['value']))]) }}</span>@endif
                </p>
                <table class="list">
                    <thead><tr><th>{{ $D['col_product'] }}</th><th class="n">{{ $D['col_stock'] }}</th>@if ($buy)<th class="n">{{ $D['col_value'] }}</th>@endif</tr></thead>
                    <tbody>
                    @foreach ($dormant['items'] as $r)
                        <tr>
                            <td class="item">
                                <a href="{{ $r['url'] }}"><span class="name">{{ $r['designation'] ?? $r['code'] }}</span>@if ($r['designation'])<span class="code ltr">{{ $r['code'] }}</span>@endif</a>
                                @if ($several)<span class="where" style="--bh: {{ $r['hue'] }}"><span class="bdot"></span>{{ $r['branch'] }}</span>@endif
                            </td>
                            <td class="n"><span class="num">{{ $qty($r['stock']) }}</span></td>
                            @if ($buy)<td class="n"><span class="num">{{ $n0($r['value']) }}</span></td>@endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    {{-- les heures de pointe --}}
    @if ($hours->isNotEmpty())
        <section class="card">
            <div class="card-head">
                <div><h2>{{ $D['hours_title'] }}</h2><p class="help">{{ $D['hours_help'] }}</p></div>
                <p class="muted small">{{ __('dashboard.busiest', ['hour' => sprintf('%02d:00', $busiest)]) }}</p>
            </div>
            <div class="hours" role="img" aria-label="{{ $D['hours_title'] }}. {{ __('dashboard.busiest', ['hour' => sprintf('%02d:00', $busiest)]) }}">
                @foreach ($hours as $h)
                    <span @class(['top' => $h['hour'] === $busiest]) style="--h: {{ $h['pct'] }}%" title="{{ sprintf('%02d:00', $h['hour']) }} — {{ trans_choice('dashboard.bons_count', $h['bons'], ['count' => $h['bons']]) }}"></span>
                @endforeach
            </div>
            <div class="hours-axis" aria-hidden="true">
                @foreach ($hours as $h)<span>{{ sprintf('%02d', $h['hour']) }}</span>@endforeach
            </div>
        </section>
    @endif
</div>
@endif
@endsection
