<?php

namespace App\Support;

use App\Models\BranchTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * La caisse de chaque branche (agent 1.0.8, cash_entries) : son solde = toutes les entrees - toutes les sorties, comme
 * MONTANTCAISSE de Gescomm (verifie le 05/11/2026 sur DESKTOP-9H7CGGI : 84 640 143,07 - 83 540 167,53 = 1 099 975,54,
 * le meme chiffre que son "Etat de la caisse"). Pour la periode de "لوحة القيادة" : le solde a la fin de son dernier
 * jour (maintenant, si c'est aujourd'hui ; comme MONLESOLDE de ETAT_CAISSE3 pour un autre jour), ce qui est entre et
 * sorti pendant la periode, le dernier mouvement, et quand l'agent a confirme la caisse pour la derniere fois.
 * Par jeu de branches et periode, garde 60 s comme SalesDashboard.
 */
final class CashBox
{
    private const CACHE_SECONDS = 60;

    /**
     * @param  list<int>  $ids  branches dont l'agent envoie la caisse (1.0.8+)
     * @return array<int, array{balance: float, in: float, out: float, last: ?string, checked_at: ?string}>
     */
    public static function figures(array $ids, string $from, string $to): array
    {
        sort($ids);

        return Cache::remember('cash-box:'.implode(',', $ids).':'.$from.':'.$to.':'.now()->toDateString(), self::CACHE_SECONDS,
            fn () => self::compute($ids, $from, $to));
    }

    /**
     * L'argent entre et sorti chaque jour de [from, to], pour le calendrier ; un jour sans mouvement n'y est pas.
     *
     * @param  list<int>  $ids
     * @return array<string, array{in: float, out: float}>
     */
    public static function days(array $ids, string $from, string $to): array
    {
        sort($ids);

        return Cache::remember('cash-days:'.implode(',', $ids).':'.$from.':'.$to, self::CACHE_SECONDS, function () use ($ids, $from, $to) {
            $days = [];
            $rows = DB::table('cash_entries')->whereIn('branch_id', $ids)->whereBetween('date', [$from, $to])
                ->groupBy('date', 'direction')->selectRaw('date as d, direction, SUM(amount) as total')->get();
            foreach ($rows as $r) {
                $day = substr((string) $r->d, 0, 10);
                $days[$day] ??= ['in' => 0.0, 'out' => 0.0];
                $days[$day][$r->direction === 'out' ? 'out' : 'in'] = round((float) $r->total, 2);
            }

            return $days;
        });
    }

    private static function compute(array $ids, string $from, string $to): array
    {
        // jusqu'a aujourd'hui : toute la caisse, comme MONTANTCAISSE (meme une ligne datee plus tard) ; un jour passe : jusqu'a lui
        $upTo = fn ($q) => $to >= now()->toDateString() ? $q : $q->where('date', '<=', $to);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['balance' => 0.0, 'in' => 0.0, 'out' => 0.0, 'last' => null, 'checked_at' => null];
        }

        $sums = $upTo(DB::table('cash_entries')->whereIn('branch_id', $ids))->groupBy('branch_id', 'direction')
            ->selectRaw('branch_id, direction, SUM(amount) as total, SUM(CASE WHEN date >= ? AND date <= ? THEN amount ELSE 0 END) as period', [$from, $to])
            ->get();
        foreach ($sums as $s) {
            $direction = $s->direction === 'out' ? 'out' : 'in';
            $out[$s->branch_id]['balance'] += ($direction === 'out' ? -1 : 1) * (float) $s->total;
            $out[$s->branch_id][$direction] += round((float) $s->period, 2);
        }

        foreach ($ids as $id) {
            $out[$id]['balance'] = round($out[$id]['balance'], 2);
            $last = $upTo(DB::table('cash_entries')->where('branch_id', $id))->orderByDesc('date')->orderByDesc('time')->first(['date', 'time']);
            $out[$id]['last'] = $last ? trim(Carbon::parse($last->date)->toDateString().' '.$last->time) : null;
        }

        // la caisse est a jour quand ses deux taches l'ont ete : la plus ancienne des deux
        $tasks = BranchTask::whereIn('branch_id', $ids)->whereIn('task', ['cash_in', 'cash_out'])->get()->groupBy('branch_id');
        foreach ($tasks as $id => $list) {
            $out[$id]['checked_at'] = $list->count() === 2 && $list->every(fn (BranchTask $t) => $t->last_ok_at !== null)
                ? $list->min('last_ok_at')->toIso8601String()
                : null;
        }

        return $out;
    }
}
