<?php

namespace App\Support;

use App\Models\BranchTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * La caisse de chaque branche (agent 1.0.8, cash_entries) : son solde = toutes les entrees - toutes les sorties, comme
 * MONTANTCAISSE de Gescomm (verifie le 05/11/2026 sur DESKTOP-9H7CGGI : 84 640 143,07 - 83 540 167,53 = 1 099 975,54,
 * le meme chiffre que son "Etat de la caisse"). Puis ce qui est entre et sorti aujourd'hui, le dernier mouvement, et
 * quand l'agent a confirme la caisse pour la derniere fois. Par jeu de branches, garde 60 s comme SalesDashboard.
 */
final class CashBox
{
    private const CACHE_SECONDS = 60;

    /**
     * @param  list<int>  $ids  branches dont l'agent envoie la caisse (1.0.8+)
     * @return array<int, array{balance: float, in_today: float, out_today: float, last: ?string, checked_at: ?string}>
     */
    public static function figures(array $ids): array
    {
        sort($ids);
        $today = now()->toDateString();

        return Cache::remember('cash-box:'.implode(',', $ids).':'.$today, self::CACHE_SECONDS, fn () => self::compute($ids, $today));
    }

    private static function compute(array $ids, string $today): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['balance' => 0.0, 'in_today' => 0.0, 'out_today' => 0.0, 'last' => null, 'checked_at' => null];
        }

        $sums = DB::table('cash_entries')->whereIn('branch_id', $ids)->groupBy('branch_id', 'direction')
            ->selectRaw('branch_id, direction, SUM(amount) as total, SUM(CASE WHEN date = ? THEN amount ELSE 0 END) as today', [$today])
            ->get();
        foreach ($sums as $s) {
            $sign = $s->direction === 'out' ? -1 : 1;
            $out[$s->branch_id]['balance'] += $sign * (float) $s->total;
            $out[$s->branch_id][$s->direction === 'out' ? 'out_today' : 'in_today'] += (float) $s->today;
        }

        foreach ($ids as $id) {
            $out[$id]['balance'] = round($out[$id]['balance'], 2);
            $last = DB::table('cash_entries')->where('branch_id', $id)->orderByDesc('date')->orderByDesc('time')->first(['date', 'time']);
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
