<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Un exercice de Gescomm dans une branche. A la cloture, Gescomm copie sa base sous LaBaseN<nom>.ib, vide les bons et
 * les versements (procedure CLOTURE1 ; clients, fournisseurs, articles et caisse restent) et reporte le solde de chacun
 * en un versement "Autre" du jour : "Solde Initial Client de l'exercice <nom>" (INITIALISERSOLDENEW). Ce jour et ce nom
 * sont la marque d'une base (opened_on, opening_label) : l'agent (1.0.11) l'envoie avec ce qu'il lit.
 *
 * Les bons, lignes et versements de l'exercice en cours ont exercise_id = 0 ; ceux d'un exercice clos, son id.
 * Des "Solde Initial" plus recents : Gescomm a cloture, l'exercice en cours se ferme (ses lignes prennent son id) et un
 * nouveau commence, sans rien effacer. L'agent envoie aussi chaque base archivee (forArchive) : un exercice clos par base.
 */
#[Fillable(['branch_id', 'current', 'name', 'opened_on', 'opening_label', 'first_day', 'last_day', 'file', 'counts', 'closed_at', 'synced_at'])]
class Exercise extends Model
{
    /** Les jeux de l'agent que la cloture vide : ils portent leur exercice. La caisse, les clients et le stock restent. */
    public const SETS = ['sales', 'purchases', 'customer_payments', 'supplier_payments'];

    /** Les tables de ces jeux. */
    public const TABLES = ['documents', 'document_lines', 'payments'];

    /**
     * Premiere marque d'une branche (agent mis a jour) : plus de lignes que cela datees d'avant ses "Solde Initial"
     * (moins une semaine), c'est une cloture faite quand l'agent ne savait pas encore la voir.
     */
    private const MISSED_ROWS = 50;

    protected function casts(): array
    {
        return [
            'current' => 'boolean',
            'opened_on' => 'date',
            'first_day' => 'date',
            'last_day' => 'date',
            'counts' => 'array',
            'closed_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function currentFor(Branch $branch): self
    {
        return self::firstOrCreate(['branch_id' => $branch->id, 'current' => true]);
    }

    /**
     * La base que l'agent lit, d'apres sa marque (null : agent d'avant 1.0.11, ou base sans "Solde Initial").
     *
     * @param  array{opened_on: ?string, label: ?string, first_day: ?string}|null  $mark
     */
    public static function forLive(Branch $branch, ?array $mark): self
    {
        $current = self::currentFor($branch);
        if ($mark === null || $mark['opened_on'] === null) {
            if ($mark !== null && $current->opened_on === null && $mark['first_day'] !== null) {
                $current->update(['first_day' => $mark['first_day']]);
            }

            return $current;
        }
        if ($current->opened_on !== null && $mark['opened_on'] <= $current->opened_on->toDateString()) {
            return $current;
        }
        if ($current->opened_on === null && self::rowsBefore($branch, $mark['opened_on']) <= self::MISSED_ROWS) {
            $current->update(['opened_on' => $mark['opened_on'], 'opening_label' => $mark['label'], 'first_day' => $mark['first_day']]);

            return $current;
        }

        return DB::transaction(function () use ($branch, $mark) {
            // deux envois en meme temps : un seul ferme
            $current = self::where('branch_id', $branch->id)->where('current', true)->lockForUpdate()->first();
            if ($current === null || ($current->opened_on !== null && $mark['opened_on'] <= $current->opened_on->toDateString())) {
                return $current ?? self::currentFor($branch);
            }

            return self::close($branch, $current, $mark);
        });
    }

    /**
     * Une base archivee trouvee par l'agent (LaBaseN<nom>.ib), sa marque et ce qu'elle contient. Rien a lire (null +
     * raison) si elle est vide (LABASENV.IB), si c'est une copie de la base en cours (meme marque), ou tant que la base
     * en cours n'a pas encore donne sa marque. Sinon l'exercice clos de meme marque (celui que le serveur a vu se fermer),
     * ou du meme fichier si la base n'a pas de "Solde Initial", ou celui sans marque que cette base recouvre, ou un nouveau.
     *
     * @param  array{file: string, opened_on: ?string, label: ?string, first_day: ?string, last_day: ?string, counts: array<string, int>}  $archive
     * @return array{0: ?self, 1: ?string}
     */
    public static function forArchive(Branch $branch, array $archive): array
    {
        if (array_sum($archive['counts']) === 0 || $archive['first_day'] === null) {
            return [null, 'empty'];
        }
        $current = self::currentFor($branch);
        if ($current->opened_on === null && $current->first_day === null) {
            return [null, 'wait'];
        }
        $same = $archive['opened_on'] !== null
            ? $current->opened_on?->toDateString() === $archive['opened_on'] && $current->opening_label === $archive['label']
            : $current->opened_on === null && $current->first_day?->toDateString() === $archive['first_day'];
        if ($same) {
            return [null, 'current'];
        }

        $closed = fn () => self::where('branch_id', $branch->id)->where('current', false);
        $found = $archive['opened_on'] !== null
            ? $closed()->whereDate('opened_on', $archive['opened_on'])->where(fn ($q) => $archive['label'] === null
                ? $q->whereNull('opening_label') : $q->where('opening_label', $archive['label']))->first()
            : $closed()->whereNull('opened_on')->where('file', $archive['file'])->first();
        $found ??= $closed()->whereNull('opened_on')->whereNull('file')
            ->whereDate('first_day', '<=', $archive['last_day'])->whereDate('last_day', '>=', $archive['first_day'])->first();
        $found ??= new self(['branch_id' => $branch->id, 'current' => false, 'closed_at' => now()]);

        $found->fill([
            'name' => self::nameOf($archive['file']),
            'opened_on' => $archive['opened_on'],
            'opening_label' => $archive['label'],
            'first_day' => $archive['first_day'],
            'last_day' => $archive['last_day'],
            'file' => $archive['file'],
            'counts' => $archive['counts'],
        ])->save();

        return [$found, null];
    }

    /** LaBaseN20263.ib -> 20263 : le nom tape a la cloture. */
    public static function nameOf(string $file): string
    {
        $name = preg_replace('/\.[^.]*$/', '', basename($file));

        return mb_substr(preg_match('/^labasen(.+)$/i', $name, $m) ? $m[1] : $name, 0, 40);
    }

    /** Les exercices clos de ces branches, du plus recent au plus ancien (le menu "السنة المالية"). */
    public static function closedOf(?array $branchIds)
    {
        return self::with('branch:id,name')->where('current', false)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderByDesc('last_day')->orderByDesc('id')->get();
    }

    /** Ce que le menu montre : "20263 (08/02/2026 → 22/06/2026)". */
    public function label(): string
    {
        $span = $this->first_day && $this->last_day ? ' ('.$this->first_day->format('d/m/Y').' → '.$this->last_day->format('d/m/Y').')' : '';

        return ($this->name ?? '#'.$this->id).$span;
    }

    /** Ferme l'exercice en cours : ses lignes prennent son id, un nouveau commence avec la marque de la nouvelle base. */
    private static function close(Branch $branch, self $current, array $mark): self
    {
        $span = self::span($branch, 0);
        $current->update([
            'current' => false,
            // la nouvelle base reporte "l'exercice <label>" : c'est le nom de celui qui se ferme
            'name' => $current->name ?? $mark['label'],
            'first_day' => $current->first_day ?? $span[0],
            'last_day' => $span[1],
            'closed_at' => now(),
        ]);
        foreach (self::TABLES as $table) {
            DB::table($table)->where('branch_id', $branch->id)->where('exercise_id', 0)->update(['exercise_id' => $current->id]);
        }

        return self::create(['branch_id' => $branch->id, 'current' => true,
            'opened_on' => $mark['opened_on'], 'opening_label' => $mark['label'], 'first_day' => $mark['first_day']]);
    }

    /** Lignes de l'exercice en cours datees d'avant ce jour moins une semaine. */
    private static function rowsBefore(Branch $branch, string $day): int
    {
        $before = Carbon::parse($day)->subDays(7)->toDateString();

        return DB::table('documents')->where('branch_id', $branch->id)->where('exercise_id', 0)->where('date', '<', $before)->count()
            + DB::table('payments')->where('branch_id', $branch->id)->where('exercise_id', 0)->where('date', '<', $before)->count();
    }

    /** @return array{0: ?string, 1: ?string} le premier et le dernier jour des bons et versements de cet exercice */
    private static function span(Branch $branch, int $exercise): array
    {
        $first = $last = null;
        foreach (['documents', 'payments'] as $table) {
            $row = DB::table($table)->where('branch_id', $branch->id)->where('exercise_id', $exercise)->selectRaw('MIN(date) as a, MAX(date) as b')->first();
            $a = $row?->a ? substr((string) $row->a, 0, 10) : null;
            $b = $row?->b ? substr((string) $row->b, 0, 10) : null;
            $first = $first === null || ($a !== null && $a < $first) ? ($a ?? $first) : $first;
            $last = $last === null || ($b !== null && $b > $last) ? ($b ?? $last) : $last;
        }

        return [$first, $last];
    }
}
