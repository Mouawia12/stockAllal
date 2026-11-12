<?php

namespace App\Support;

use App\Http\Controllers\PartnerController;
use App\Models\BranchStock;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Scopes\ExerciseScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "علاقته معنا" sur la fiche d'un client ou d'un fournisseur : les 12 derniers mois, compares aux autres de SA branche.
 *
 * La note de fidelite (0 a 100) ne compte que le comportement, jamais l'argent : un compte sans prices.* peut la voir.
 *   50 % regularite (mois avec au moins un bon, sur 12)
 *   30 % frequence (rang de son nombre de bons parmi ceux qui ont un bon sur la periode)
 *   20 % fraicheur (plein jusqu'a 30 jours apres le dernier bon, zero a partir de 180)
 *
 * On ne compare qu'a ceux qui ont un bon sur la periode (les dormants gonfleraient le pourcentage). Les autres n'apparaissent
 * jamais par leur nom : seulement des comptes (histogramme) et un rang. Sans prices.* : ni montant, ni rang en montant,
 * ni versements ; avec, les montants viennent des memes bons que le reste de la fiche. Ce n'est pas le solde : il attend
 * agent/tools/check-balances.ps1.
 */
final class PartnerInsights
{
    public const MONTHS = 12;

    public const WEIGHT_REGULAR = 50;

    public const WEIGHT_FREQUENT = 30;

    public const WEIGHT_RECENT = 20;

    /** Dernier bon : fraicheur pleine jusqu'a FRESH_DAYS, nulle a partir de STALE_DAYS. */
    public const FRESH_DAYS = 30;

    public const STALE_DAYS = 180;

    /** Note minimale de chaque niveau, du plus haut au plus bas. */
    public const BANDS = ['high' => 75, 'regular' => 50, 'occasional' => 25, 'rare' => 0];

    public const TOP_ITEMS = 5;

    /** Premier bon depuis moins de ce nombre de jours : "nouveau", sa note ne dit encore rien. */
    private const NEW_DAYS = 60;

    /** Au moins ce nombre de jours d'achat pour parler d'une habitude. */
    private const MIN_DAYS_FOR_RHYTHM = 3;

    /** Tolerance avant "en retard" (les fins de semaine, les jours feries) : jamais moins d'une semaine. */
    private const MIN_LATE_DAYS = 7;

    /** Une branche recalculee au plus toutes les dix minutes : la comparaison bouge lentement. */
    private const CACHE_SECONDS = 600;

    /**
     * @param  bool  $money  montants de cette sorte (prices.sale pour un client, prices.buy pour un fournisseur)
     * @param  bool  $margin  la marge, pour un client et un compte qui a aussi prices.buy
     * @return array<string, mixed>
     */
    public static function for(Partner $partner, bool $money, bool $margin, ?Carbon $now = null): array
    {
        $now ??= ExerciseScope::now();
        $locale = app()->getLocale();
        $today = $now->toDateString();
        $from = $now->copy()->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1)->toDateString();
        $yearBefore = Carbon::parse($from)->subYearNoOverflow()->toDateString();
        $yearBeforeTo = $now->copy()->subYearNoOverflow()->toDateString();
        $docKind = PartnerController::DOCUMENT_KIND[$partner->kind];
        $docs = fn (): Builder => Document::query()->where('branch_id', $partner->branch_id)->where('kind', $docKind)->where('partner_code', $partner->code);

        // un jour par ligne : au plus deux ans de lignes, quel que soit le nombre de bons
        $days = $docs()->whereNotNull('date')->where('date', '>=', $yearBefore)
            ->groupBy('date')->orderBy('date')->selectRaw('date as d, COUNT(*) as bons, SUM(amount) as amount')->toBase()->get()
            ->map(fn ($r) => ['d' => substr((string) $r->d, 0, 10), 'bons' => (int) $r->bons, 'amount' => (float) $r->amount]);
        $window = $days->filter(fn (array $r) => $r['d'] >= $from)->values();
        $before = $days->filter(fn (array $r) => $r['d'] <= $yearBeforeTo);

        $bons = (int) $window->sum('bons');
        $amount = (float) $window->sum('amount');
        $firstRaw = $docs()->min('date');
        $lastRaw = $docs()->max('date');
        $first = $firstRaw ? substr((string) $firstRaw, 0, 10) : null;
        $last = $lastRaw ? substr((string) $lastRaw, 0, 10) : null;
        $sinceLast = $last === null ? null : max(0, (int) Carbon::parse($last)->startOfDay()->diffInDays($now->copy()->startOfDay()));

        $months = self::months($window, $now, $money, $locale);
        $activeMonths = count(array_filter($months, fn (array $m) => $m['bons'] > 0));
        $standing = self::standing($partner->branch_id, $docKind, $from, $today, $now);

        $out = [
            'from' => $from,
            'first' => $first,
            'last' => $last,
            'since_last' => $sinceLast,
            'active' => $bons > 0,
            'is_new' => $first !== null && Carbon::parse($first)->diffInDays($now) < self::NEW_DAYS,
            'bons' => $bons,
            'amount' => $money ? round($amount, 2) : null,
            'months' => $months,
            'active_months' => $activeMonths,
            'trend' => self::trend($money ? $amount : $bons, $money ? (float) $before->sum('amount') : (float) $before->sum('bons')),
            'loyalty' => null,
            'rank' => null,
            'rhythm' => null,
            'average' => null,
            'paid' => null,
            'margin' => null,
            'items' => [],
            'breadth' => 0,
            'week' => [],
            'hour' => null,
        ];
        if ($bons === 0) {
            return $out;
        }

        $out['loyalty'] = self::loyalty($partner->code, $standing, $activeMonths, $bons, (int) $sinceLast);
        $out['rank'] = self::rank($partner->code, $standing, $money ? $amount : $bons, $money);
        $out['rhythm'] = self::rhythm($window->pluck('d')->all(), (int) $sinceLast);
        $out['week'] = self::week($window, $locale);
        $hour = $docs()->where('date', '>=', $from)->whereNotNull('time')
            ->groupByRaw('SUBSTR(time, 1, 2)')->selectRaw('SUBSTR(time, 1, 2) as h, COUNT(*) as n')
            ->orderByDesc('n')->orderBy('h')->toBase()->first();
        $out['hour'] = $hour ? (string) $hour->h : null;

        if ($money && $standing['total_bons'] > 0) {
            $mine = $amount / $bons;
            $theirs = $standing['total_amount'] / $standing['total_bons'];
            $out['average'] = [
                'amount' => round($mine, 2),
                'branch' => round($theirs, 2),
                'pct' => $theirs > 0 ? (int) round(($mine - $theirs) / $theirs * 100) : null,
            ];
            $out['paid'] = self::paid($partner, $from, $amount);
        }

        $ids = fn () => $docs()->where('date', '>=', $from)->select('id');
        if ($margin) {
            $row = DocumentLine::whereIn('document_id', $ids())->selectRaw('
                SUM(CASE WHEN cost IS NOT NULL THEN total - qty * cost ELSE 0 END) as margin,
                SUM(CASE WHEN cost IS NOT NULL THEN total ELSE 0 END) as costed,
                SUM(CASE WHEN cost IS NULL THEN 1 ELSE 0 END) as without_cost')->toBase()->first();
            if ($row && (float) $row->costed != 0) {
                $out['margin'] = [
                    'amount' => round((float) $row->margin, 2),
                    'pct' => round((float) $row->margin / (float) $row->costed * 100, 1),
                    'partial' => (int) $row->without_cost > 0,
                ];
            }
        }

        // ce qu'il prend le plus : part de son montant, ou sans prices.* la part de ses bons ou l'article revient
        $rows = DocumentLine::whereIn('document_id', $ids())->groupBy('code_key')
            ->selectRaw('code_key, MIN(code_raw) as code, SUM(qty) as qty, SUM(total) as amount, COUNT(DISTINCT document_id) as bons')
            ->orderByDesc($money ? 'amount' : 'bons')->orderBy('code_key')->limit(self::TOP_ITEMS)->get();
        $names = BranchStock::where('branch_id', $partner->branch_id)->whereIn('code_key', $rows->pluck('code_key'))->pluck('designation', 'code_key');
        $out['items'] = $rows->map(fn ($i) => [
            'code' => $i->code,
            'designation' => $names[$i->code_key] ?? null,
            'qty' => round((float) $i->qty, 2),
            'amount' => $money ? round((float) $i->amount, 2) : null,
            'bons' => (int) $i->bons,
            'share' => $money
                ? ($amount > 0 ? round((float) $i->amount / $amount * 100, 1) : 0.0)
                : round((int) $i->bons / $bons * 100, 1),
        ])->all();
        $out['breadth'] = DocumentLine::whereIn('document_id', $ids())->distinct()->count('code_key');

        return $out;
    }

    /** Ce que la comparaison a besoin de savoir sur tous ceux de la branche qui ont un bon depuis $from. */
    private static function standing(int $branchId, string $docKind, string $from, string $today, Carbon $now): array
    {
        return Cache::remember("partner-standing:{$branchId}:{$docKind}:{$from}:{$today}".ExerciseScope::cacheKey(), self::CACHE_SECONDS, function () use ($branchId, $docKind, $from, $now) {
            $rows = Document::query()->where('branch_id', $branchId)->where('kind', $docKind)->where('date', '>=', $from)
                ->whereNotNull('partner_code')->where('partner_code', '!=', '')->groupBy('partner_code')
                ->selectRaw('partner_code as code, COUNT(*) as bons, SUM(amount) as amount, MAX(date) as last_date, COUNT(DISTINCT SUBSTR(date, 1, 7)) as months')
                ->toBase()->get();
            $bons = [];
            $amounts = [];
            foreach ($rows as $row) {
                $bons[(string) $row->code] = (int) $row->bons;
                $amounts[(string) $row->code] = (float) $row->amount;
            }
            $ranks = self::percentiles(array_values($bons));
            $today = $now->copy()->startOfDay();
            $scores = [];
            $hist = array_fill(0, 10, 0);
            foreach ($rows as $row) {
                $since = max(0, (int) Carbon::parse(substr((string) $row->last_date, 0, 10))->diffInDays($today));
                $score = self::score((int) $row->months, $ranks[(int) $row->bons], $since);
                $scores[(string) $row->code] = $score;
                $hist[self::bucket($score)]++;
            }

            return [
                'bons' => $bons,
                'amounts' => $amounts,
                'scores' => $scores,
                'hist' => $hist,
                'total_bons' => array_sum($bons),
                'total_amount' => array_sum($amounts),
            ];
        });
    }

    /**
     * Rang centile de chaque valeur : (ceux en dessous + la moitie des egaux) / tous. Une table par valeur, pas par personne :
     * une branche peut avoir des milliers de clients.
     *
     * @param  list<int>  $values
     * @return array<int, float>
     */
    private static function percentiles(array $values): array
    {
        $counts = array_count_values($values);
        ksort($counts);
        $total = max(1, count($values));
        $below = 0;
        $out = [];
        foreach ($counts as $value => $count) {
            $out[$value] = ($below + $count / 2) / $total * 100;
            $below += $count;
        }

        return $out;
    }

    private static function bucket(int $score): int
    {
        return min(9, intdiv($score, 10));
    }

    private static function freshness(int $sinceLast): float
    {
        return max(0.0, min(100.0, (self::STALE_DAYS - $sinceLast) / (self::STALE_DAYS - self::FRESH_DAYS) * 100));
    }

    private static function regularity(int $months): float
    {
        return min(100.0, $months / self::MONTHS * 100);
    }

    private static function score(int $months, float $frequent, int $sinceLast): int
    {
        return (int) round((self::WEIGHT_REGULAR * self::regularity($months) + self::WEIGHT_FREQUENT * $frequent + self::WEIGHT_RECENT * self::freshness($sinceLast)) / 100);
    }

    /** @return array<string, mixed> */
    private static function loyalty(string $code, array $standing, int $months, int $bons, int $sinceLast): array
    {
        // ses propres chiffres sont ceux d'a l'instant ; la branche, ceux des dix dernieres minutes
        $list = $standing['bons'];
        $list[$code] = $bons;
        $below = 0;
        $equal = 0;
        foreach ($list as $n) {
            $below += $n < $bons ? 1 : 0;
            $equal += $n === $bons ? 1 : 0;
        }
        $frequent = ($below + $equal / 2) / count($list) * 100;
        $score = self::score($months, $frequent, $sinceLast);

        $others = $standing['scores'];
        unset($others[$code]);
        $lower = 0;
        foreach ($others as $other) {
            $lower += $other < $score ? 1 : 0;
        }
        $band = array_key_first(array_filter(self::BANDS, fn (int $min) => $score >= $min));

        return [
            'score' => $score,
            'band' => $band,
            'beats' => $others === [] ? null : (int) round($lower / count($others) * 100),
            'compared' => count($others),
            'hist' => $standing['hist'],
            'bucket' => self::bucket($score),
            'regular' => (int) round(self::regularity($months)),
            'frequent' => (int) round($frequent),
            'recent' => (int) round(self::freshness($sinceLast)),
        ];
    }

    /** @return array<string, mixed> */
    private static function rank(string $code, array $standing, float $mine, bool $money): array
    {
        $list = $money ? $standing['amounts'] : $standing['bons'];
        $list[$code] = $mine;
        $above = 0;
        foreach ($list as $value) {
            $above += $value > $mine ? 1 : 0;
        }
        $total = $money ? $standing['total_amount'] : $standing['total_bons'];
        $place = $above + 1;

        return [
            'by' => $money ? 'amount' : 'bons',
            'place' => $place,
            'of' => count($list),
            'top' => (int) max(1, ceil($place / count($list) * 100)),
            'share' => $total > 0 ? round($mine / $total * 100, 1) : null,
        ];
    }

    /**
     * Combien de jours separent d'ordinaire deux achats (mediane : quelques longues absences ne la deforment pas), et ou
     * il en est par rapport a cette habitude. Null tant qu'il n'y a pas assez de jours d'achat pour parler d'habitude.
     *
     * @param  list<string>  $dates  jours d'achat, en ordre
     * @return array{gap: int, state: string}|null
     */
    private static function rhythm(array $dates, int $sinceLast): ?array
    {
        if (count($dates) < self::MIN_DAYS_FOR_RHYTHM) {
            return null;
        }
        $gaps = [];
        foreach ($dates as $i => $date) {
            if ($i > 0) {
                $gaps[] = (int) Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($date));
            }
        }
        sort($gaps);
        $middle = intdiv(count($gaps), 2);
        $gap = count($gaps) % 2 ? $gaps[$middle] : ($gaps[$middle - 1] + $gaps[$middle]) / 2;
        $gap = max(1, (int) round($gap));
        $limit = max(self::MIN_LATE_DAYS, 2 * $gap);

        return ['gap' => $gap, 'state' => match (true) {
            $sinceLast <= $limit => 'on_time',
            $sinceLast <= 2 * $limit => 'late',
            default => 'away',
        }];
    }

    /** @return array<string, mixed>|null */
    private static function paid(Partner $partner, string $from, float $amount): ?array
    {
        $payments = fn (): Builder => Payment::query()->where('branch_id', $partner->branch_id)->where('partner_kind', $partner->kind)
            ->where('partner_code', $partner->code)->where('date', '>=', $from);
        $sum = $payments()->selectRaw('COUNT(*) as n, SUM(amount) as amount')->toBase()->first();
        $last = $payments()->orderByDesc('date')->orderByDesc('time')->orderByDesc('number')->first(['date', 'amount']);

        return [
            'amount' => round((float) ($sum->amount ?? 0), 2),
            'count' => (int) ($sum->n ?? 0),
            'pct' => $amount > 0 ? (int) round((float) ($sum->amount ?? 0) / $amount * 100) : null,
            'last_date' => $last?->date?->format('d/m/Y'),
            'last_amount' => $last ? round((float) $last->amount, 2) : null,
        ];
    }

    /** @return list<array<string, mixed>> les 12 mois, du plus ancien au mois en cours */
    private static function months($window, Carbon $now, bool $money, string $locale): array
    {
        $byMonth = [];
        foreach ($window as $row) {
            $key = substr($row['d'], 0, 7);
            $byMonth[$key]['bons'] = ($byMonth[$key]['bons'] ?? 0) + $row['bons'];
            $byMonth[$key]['amount'] = ($byMonth[$key]['amount'] ?? 0) + $row['amount'];
        }
        $out = [];
        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $month = $now->copy()->startOfMonth()->subMonthsNoOverflow($i)->locale($locale);
            $key = $month->format('Y-m');
            $out[] = [
                'key' => $key,
                'label' => $month->translatedFormat('M'),
                'full' => $month->translatedFormat('F Y'),
                'bons' => (int) ($byMonth[$key]['bons'] ?? 0),
                'amount' => $money ? round((float) ($byMonth[$key]['amount'] ?? 0), 2) : null,
                'now' => $i === 0,
            ];
        }

        return $out;
    }

    /** @return array{pct: int|null, before: float} les 12 mois contre les memes dates un an plus tot */
    private static function trend(float $now, float $before): array
    {
        return ['pct' => $before > 0 ? (int) round(($now - $before) / $before * 100) : null, 'before' => $before];
    }

    /** @return list<array{name: string, short: string, bons: int, best: bool}> du samedi au vendredi, comme le calendrier */
    private static function week($window, string $locale): array
    {
        $counts = array_fill(0, 7, 0);
        foreach ($window as $row) {
            $counts[(Carbon::parse($row['d'])->dayOfWeek + 1) % 7] += $row['bons'];
        }
        $max = max($counts);
        $saturday = Carbon::parse('2026-11-07')->locale($locale);

        return array_map(fn (int $i) => [
            'name' => $saturday->copy()->addDays($i)->translatedFormat('l'),
            'short' => $saturday->copy()->addDays($i)->translatedFormat('D'),
            'bons' => $counts[$i],
            'best' => $max > 0 && $counts[$i] === $max,
        ], array_keys($counts));
    }
}
