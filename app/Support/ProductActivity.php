<?php

namespace App\Support;

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PartnerController;
use App\Models\Branch;
use App\Models\DocumentLine;
use App\Models\Scopes\ExerciseScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ventes et achats d'un article, facon "Fiche de Stock" de Gescomm (docs/roadmap.md, section 5) : vendu, retourne,
 * net, montant et prix moyen, puis les lignes, bon par bon. Seulement avec sales.view, et seulement les branches
 * dont le compte voit les ventes (User::salesBranchIds) parmi celles affichees. Un retour = une ligne negative.
 * Les montants de vente demandent prices.sale ; prix et montants d'achat, et la marge, prices.buy.
 */
final class ProductActivity
{
    /** ?period= : nombre de jours (null = tout l'historique, comme la Fiche de Stock). */
    public const PERIODS = ['all' => null, '30' => 30, '90' => 90, '365' => 365];

    public const LIST_LIMIT = 50;

    /** La fenetre F9 de la recherche (stock/_card) : les dernieres lignes d'abord, toutes (?all=1) jusqu'a CARD_ALL. */
    public const CARD_LIMIT = 100;

    public const CARD_ALL = 1000;

    private const PARTNER_KIND = ['sale' => 'customer', 'purchase' => 'supplier'];

    /**
     * @param  list<string>  $codes  codes de l'article (ProductGroup::codesOf)
     * @param  Collection<int, Branch>  $shown  branches affichees (BranchScope)
     * @param  int  $limit  lignes de chaque liste (plus sur une impression)
     * @param  array{from: ?string, to: ?string}|null  $window  une periode choisie, a la place de $period (impression)
     * @param  string|null  $only  "sale" ou "purchase" : cette liste seulement (la fenetre F9 n'en montre qu'une)
     * @param  string  $who  les lignes d'un client ou d'un fournisseur : chaque mot dans son nom, ou au debut de son code
     * @return array<string, mixed>|null null : le compte ne voit pas les ventes
     */
    public static function for(User $user, array $codes, Collection $shown, string $period, int $limit = self::LIST_LIMIT, ?array $window = null,
        ?string $only = null, string $who = ''): ?array
    {
        if (! $user->can('sales.view')) {
            return null;
        }
        $allowed = $user->salesBranchIds();
        $branches = ($allowed === null ? $shown : $shown->whereIn('id', $allowed))->values();
        // un agent d'avant 1.0.7 n'envoie pas les ventes : "mettre a jour l'agent", jamais un zero
        $ready = $branches->filter(fn (Branch $b) => $b->agentSupports('documents'))->values();
        $period = array_key_exists($period, self::PERIODS) ? $period : 'all';
        $days = self::PERIODS[$period];
        $since = $window === null ? ($days === null ? null : ExerciseScope::now()->subDays($days - 1)->toDateString()) : $window['from'];
        $until = $window['to'] ?? null;
        $sale = $user->can('prices.sale');
        $buy = $user->can('prices.buy');
        $ids = $ready->pluck('id')->all();
        $names = $ready->pluck('name', 'id');
        $words = Highlight::terms(trim(mb_substr($who, 0, 60)));

        $stats = DocumentLine::query()
            ->whereIn('code_key', $codes)->whereIn('branch_id', $ids)
            ->when($since, fn ($q) => $q->where('date', '>=', $since))
            ->when($until, fn ($q) => $q->where('date', '<=', $until))
            ->groupBy('branch_id', 'kind')
            ->selectRaw('branch_id, kind, COUNT(*) as line_count,
                SUM(CASE WHEN qty > 0 THEN qty ELSE 0 END) as out_qty,
                SUM(CASE WHEN qty < 0 THEN -qty ELSE 0 END) as back_qty,
                SUM(CASE WHEN qty > 0 THEN total ELSE 0 END) as out_amount,
                SUM(CASE WHEN qty < 0 THEN -total ELSE 0 END) as back_amount,
                SUM(CASE WHEN cost IS NOT NULL THEN total - qty * cost ELSE 0 END) as margin,
                SUM(CASE WHEN cost IS NULL THEN 1 ELSE 0 END) as without_cost')
            ->get();

        $sum = function (Collection $rows, string $kind) use ($sale, $buy) {
            $rows = $rows->where('kind', $kind);
            $f = fn (string $field) => round((float) $rows->sum($field), 2);
            $net = round($f('out_qty') - $f('back_qty'), 2);
            $amount = round($f('out_amount') - $f('back_amount'), 2);
            $money = $kind === 'sale' ? $sale : $buy;

            return [
                'lines' => (int) $rows->sum('line_count'),
                'out' => $f('out_qty'),
                'back' => $f('back_qty'),
                'net' => $net,
                'out_amount' => $money ? $f('out_amount') : null,
                'back_amount' => $money ? $f('back_amount') : null,
                'amount' => $money ? $amount : null,
                // prix moyen = montant / quantite nette, comme PRIXVENTEMOYEN de Gescomm
                'average' => $money && abs($net) >= 0.005 ? round($amount / $net, 2) : null,
                'margin' => $kind === 'sale' && $buy && $rows->isNotEmpty() ? $f('margin') : null,
                'margin_partial' => $kind === 'sale' && $rows->sum('without_cost') > 0,
            ];
        };

        $perBranch = $ready->count() > 1
            ? $ready->map(fn (Branch $b) => [
                'branch' => $b->name,
                'hue' => $b->hue(),
                'sale' => $sum($stats->where('branch_id', $b->id), 'sale'),
                'purchase' => $sum($stats->where('branch_id', $b->id), 'purchase'),
            ])->sortByDesc(fn (array $row) => $row['sale']['net'])->values()
            : collect();

        return [
            'period' => $period,
            'branches' => $branches->count(),
            'ready' => $ready->count(),
            'outdated' => $branches->reject(fn (Branch $b) => $b->agentSupports('documents'))->pluck('name')->values(),
            'sale' => $sum($stats, 'sale'),
            'purchase' => $sum($stats, 'purchase'),
            'per_branch' => $perBranch,
            'lists' => [
                'sale' => $only === 'purchase' ? collect() : self::lines('sale', $codes, $ids, $since, $until, $names, $sale, $limit, $words),
                'purchase' => $only === 'sale' ? collect() : self::lines('purchase', $codes, $ids, $since, $until, $names, $buy, $limit, $words),
            ],
            // ce que la recherche d'un client ou d'un fournisseur trouve (Gescomm : "Qte Global Vendue pour le Client
            // selectionne") : ses lignes, sorti, rendu, net. Sans recherche : les chiffres de l'article entier.
            'found' => [
                'sale' => $only === 'purchase' ? null : ($words === [] ? null : self::found('sale', $codes, $ids, $since, $until, $words)),
                'purchase' => $only === 'sale' ? null : ($words === [] ? null : self::found('purchase', $codes, $ids, $since, $until, $words)),
            ],
            'who' => $words === [] ? '' : $who,
            'show_sale' => $sale,
            'show_buy' => $buy,
            'several' => $ready->count() > 1,
        ];
    }

    /** Les lignes d'un article dans ces branches, avec leur bon et leur client ou fournisseur ; $words : son nom ou son code. */
    private static function ledger(string $kind, array $codes, array $ids, ?string $since, ?string $until, array $words): Builder
    {
        return DocumentLine::query()
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->leftJoin('partners', fn ($join) => $join->on('partners.branch_id', '=', 'documents.branch_id')
                ->on('partners.code', '=', 'documents.partner_code')
                ->where('partners.kind', '=', self::PARTNER_KIND[$kind]))
            ->whereIn('document_lines.code_key', $codes)
            ->whereIn('document_lines.branch_id', $ids)
            ->where('document_lines.kind', $kind)
            ->when($since, fn ($q) => $q->where('document_lines.date', '>=', $since))
            ->when($until, fn ($q) => $q->where('document_lines.date', '<=', $until))
            ->when($words !== [], function (Builder $q) use ($words) {
                foreach ($words as $word) {
                    $like = Highlight::like($word);
                    $q->where(fn (Builder $w) => $w->whereRaw("UPPER(documents.partner_code) LIKE ? ESCAPE '!'", [$like.'%'])
                        ->orWhereRaw("UPPER(partners.name) LIKE ? ESCAPE '!'", ['%'.$like.'%']));
                }
            });
    }

    /** @return array{lines: int, out: float, back: float, net: float} */
    private static function found(string $kind, array $codes, array $ids, ?string $since, ?string $until, array $words): array
    {
        $sum = self::ledger($kind, $codes, $ids, $since, $until, $words)->toBase()
            ->selectRaw('COUNT(*) as line_count,
                SUM(CASE WHEN document_lines.qty > 0 THEN document_lines.qty ELSE 0 END) as out_qty,
                SUM(CASE WHEN document_lines.qty < 0 THEN -document_lines.qty ELSE 0 END) as back_qty')->first();
        $out = round((float) ($sum->out_qty ?? 0), 2);
        $back = round((float) ($sum->back_qty ?? 0), 2);

        return ['lines' => (int) ($sum->line_count ?? 0), 'out' => $out, 'back' => $back, 'net' => round($out - $back, 2)];
    }

    /** Les dernieres lignes, du plus recent au plus ancien, avec le bon et le nom du client ou du fournisseur. */
    private static function lines(string $kind, array $codes, array $ids, ?string $since, ?string $until, Collection $names, bool $money, int $limit, array $words = []): Collection
    {
        return self::ledger($kind, $codes, $ids, $since, $until, $words)
            ->orderByDesc('document_lines.date')->orderByDesc('documents.time')->orderByDesc('documents.number')
            ->limit($limit)
            ->get(['document_lines.*', 'documents.number', 'documents.time', 'documents.partner_code', 'partners.name as partner_name', 'partners.id as partner_id'])
            ->map(fn (DocumentLine $line) => [
                'date' => $line->date?->format('d/m/Y'),
                'time' => $line->time ? substr($line->time, 0, 5) : null,
                // l'heure a la seconde, comme la Fiche de Stock de Gescomm (la fenetre F9)
                'clock' => $line->time ? substr($line->time, 0, 8) : null,
                'number' => $line->number,
                'url' => route(DocumentController::ROUTES[$kind].'.show', $line->document_id),
                'branch' => $names[$line->branch_id] ?? null,
                'hue' => Branch::hueFor($line->branch_id),
                'code' => $line->code_raw,
                'partner' => $line->partner_name ?? $line->partner_code,
                'partner_code' => $line->partner_name ? $line->partner_code : null,
                'partner_url' => $line->partner_id ? route(PartnerController::ROUTES[self::PARTNER_KIND[$kind]].'.show', $line->partner_id) : null,
                'qty' => $line->qty,
                'back' => $line->qty !== null && $line->qty < 0,
                'price' => $money ? $line->price : null,
                'discount' => $money && $line->discount ? $line->discount : null,
                // le prix une fois la remise faite ("Prix avec RM") : ce que la ligne a vraiment coute a l'unite
                'net_price' => $money && $line->qty !== null && abs($line->qty) >= 0.005 && $line->total !== null ? round($line->total / $line->qty, 2) : null,
                'total' => $money ? $line->total : null,
            ]);
    }
}
