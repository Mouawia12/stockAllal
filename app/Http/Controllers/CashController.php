<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\BranchScope;
use App\Support\PrintPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Les mouvements de la caisse sur papier (agent 1.0.8, cash_entries), comme l'etat de la caisse et les "Sorties de Caisse"
 * de Gescomm : le solde au debut, chaque jour ce qui est entre et sorti et le solde du soir, le solde a la fin.
 * cash.view seulement, dans les branches dont le compte voit les ventes. Solde = entrees - sorties, comme CashBox.
 */
class CashController extends Controller
{
    /** ?period= quand la page n'est pas ouverte avec des dates. */
    private const PERIODS = ['1' => 1, '7' => 7, '30' => 30, 'all' => null];

    /** ?dir= : tout, les entrees seulement, les sorties seulement. */
    public const DIRECTIONS = ['all', 'in', 'out'];

    private const PRINT_LIMIT = 5000;

    public function print(Request $request): View
    {
        $branches = Branch::where('active', true)->among(BranchScope::salesIds($request))->orderBy('name')->get();
        // un agent d'avant 1.0.8 n'envoie pas la caisse : sa branche est nommee, jamais comptee comme une caisse vide
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('cash'))->values();
        $ids = $ready->pluck('id')->all();
        $window = PrintPeriod::read($request, self::PERIODS, '1');
        $dir = in_array($request->query('dir'), self::DIRECTIONS, true) ? $request->query('dir') : 'all';
        // jusqu'a aujourd'hui : toute la caisse, comme CashBox (meme une ligne datee plus tard) ; un jour passe : jusqu'a lui
        $until = $window['to'] !== null && $window['to'] < now()->toDateString() ? $window['to'] : null;
        $inPeriod = fn () => DB::table('cash_entries')->whereIn('branch_id', $ids)
            ->when($window['from'], fn ($q) => $q->where('date', '>=', $window['from']))
            ->when($until, fn ($q) => $q->where('date', '<=', $until));

        $opening = $window['from'] === null ? 0.0 : round((float) DB::table('cash_entries')->whereIn('branch_id', $ids)->where('date', '<', $window['from'])
            ->selectRaw("SUM(CASE WHEN direction = 'out' THEN -amount ELSE amount END) as balance")->value('balance'), 2);

        // chaque jour : ce qui est entre, ce qui est sorti, et le solde du soir (les deux sens, meme si on n'en imprime qu'un)
        $perDay = $inPeriod()->groupBy('date', 'direction')->orderBy('date')
            ->selectRaw('date as d, direction, SUM(amount) as total, COUNT(*) as n')->get();
        $days = [];
        $running = $opening;
        foreach ($perDay->groupBy(fn ($r) => substr((string) $r->d, 0, 10)) as $day => $list) {
            $in = round((float) $list->where('direction', 'in')->sum('total'), 2);
            $out = round((float) $list->where('direction', 'out')->sum('total'), 2);
            $running = round($running + $in - $out, 2);
            $days[$day] = ['in' => $in, 'out' => $out, 'balance' => $running];
        }

        $rows = $inPeriod()->when($dir !== 'all', fn ($q) => $q->where('direction', $dir))
            ->orderBy('date')->orderBy('time')->orderBy('number')->orderBy('direction')
            ->limit(self::PRINT_LIMIT)->get();
        $names = $ready->pluck('name', 'id');
        $count = $dir === 'all' ? (int) $perDay->sum('n') : (int) $perDay->where('direction', $dir)->sum('n');

        return view('print.cash', [
            'window' => $window,
            'dir' => $dir,
            'opening' => $opening,
            'in' => round((float) $perDay->where('direction', 'in')->sum('total'), 2),
            'out' => round((float) $perDay->where('direction', 'out')->sum('total'), 2),
            'closing' => $running,
            'groups' => $rows->groupBy(fn ($r) => substr((string) $r->date, 0, 10))->map(fn ($list, string $day) => [
                'date' => $day === '' ? '—' : Carbon::parse($day)->format('d/m/Y'),
                'name' => $day === '' ? '' : Carbon::parse($day)->locale(app()->getLocale())->translatedFormat('l'),
                'in' => $days[$day]['in'] ?? 0.0,
                'out' => $days[$day]['out'] ?? 0.0,
                'balance' => $days[$day]['balance'] ?? null,
                'rows' => $list->map(fn ($r) => [
                    'time' => $r->time ? substr($r->time, 0, 5) : null,
                    'number' => $r->number,
                    'branch' => $names[$r->branch_id] ?? null,
                    'out' => $r->direction === 'out',
                    'label' => $r->label ?: ($r->category ?: $r->person),
                    'detail' => collect([$r->type, $r->cheque, $r->note])->filter()->join(' — '),
                    'entered_by' => $r->entered_by,
                    'amount' => (float) $r->amount,
                ]),
            ]),
            'shown' => $rows->count(),
            'count' => $count,
            'letter' => $ready->count() === 1 ? $ready->first() : $ready->firstWhere('is_main', true),
            'branchNames' => $ready->pluck('name'),
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('cash'))->pluck('name')->values(),
            'several' => $ready->count() > 1,
        ]);
    }
}
