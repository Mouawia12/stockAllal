<?php

namespace App\Support;

use App\Models\AiProvider;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\MergeDismissal;
use App\Models\MergeScan;
use App\Models\MergeScanBatch;
use App\Models\MergeSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "دمج الأصناف" par l'IA : MergeCandidates trouve les candidats ici, puis chaque lot part a l'IA, un par un.
 * Un lot = un appel (10 s a 3 min) : la page en cours les demande l'un apres l'autre (step), et la tache planifiee
 * reprend ce qui reste si la page est fermee (runPending, chaque minute). Un verrou par analyse : jamais deux
 * appels pour le meme lot. L'IA ne recoit que des codes et des designations (jamais un prix ni une quantite),
 * et les branches sous forme de lettres. Rien n'est fusionne ici : ce sont des propositions.
 */
final class MergeScanner
{
    /** Langue des raisons ecrites par l'IA (celle du compte qui lance l'analyse). */
    private const LANGUAGES = ['ar' => 'Arabic', 'fr' => 'French', 'en' => 'English'];

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'groups' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'confidence' => ['type' => 'string', 'enum' => MergeSuggestion::CONFIDENCES],
                        'name' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['items', 'confidence', 'name', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['groups'],
        'additionalProperties' => false,
    ];

    /** Le premier fournisseur pret (cle lisible, dernier test reussi, modele choisi, active), dans l'ordre de la page. */
    public static function provider(?string $wanted = null): ?AiProvider
    {
        $ready = AiProvider::where('enabled', true)->get()->filter(fn (AiProvider $p) => $p->connected())->keyBy('provider');

        return $ready[$wanted] ?? collect(array_keys(AiProviders::PROVIDERS))->map(fn ($id) => $ready[$id] ?? null)->filter()->first();
    }

    /** Les branches qu'une analyse lancee par ce compte couvre : les actives qu'il voit. */
    public static function branchIds(User $user): array
    {
        return Branch::where('active', true)->among($user->stockBranchIds())->orderBy('id')->pluck('id')->all();
    }

    public static function start(User $user, AiProvider $ai, bool $deep): MergeScan
    {
        $ids = self::branchIds($user);
        $found = MergeCandidates::find($ids, $deep);
        $batches = [
            ...array_map(fn (array $chunk) => ['kind' => 'verify', 'clusters' => $chunk], array_chunk($found['verify'], MergeCandidates::VERIFY_BATCH)),
            ...array_map(fn (array $units) => ['kind' => 'discover', 'clusters' => [['source' => 'discover', 'units' => $units]]], $found['discover']),
        ];

        return DB::transaction(function () use ($user, $ai, $ids, $found, $batches) {
            $scan = MergeScan::create([
                'user_id' => $user->id, 'provider' => $ai->provider, 'model' => $ai->model, 'locale' => app()->getLocale(),
                'status' => $batches ? 'running' : 'done', 'branch_ids' => $ids, 'units' => $found['units'], 'batches' => count($batches),
            ]);
            if (! $batches) {
                $scan->forceFill(['finished_at' => now()])->save();
            }
            foreach ($batches as $batch) {
                $scan->batchRows()->create($batch);
            }

            return $scan;
        });
    }

    /** Toutes les analyses en cours, un lot apres l'autre, pendant au plus $seconds (la tache planifiee). */
    public static function runPending(int $seconds = 50): void
    {
        $until = microtime(true) + $seconds;
        foreach (MergeScan::where('status', 'running')->orderBy('id')->get() as $scan) {
            while (microtime(true) < $until && self::step($scan)) {
                // un lot de plus
            }
        }
    }

    /**
     * Envoie le prochain lot de cette analyse a l'IA et garde ses propositions.
     * false : rien a faire (analyse finie ou arretee), ou un autre appel s'en occupe deja.
     */
    public static function step(MergeScan $scan): bool
    {
        // le verrou tient plus longtemps que l'appel le plus long : jamais deux appels pour le meme lot
        $lock = Cache::lock("merge-scan-{$scan->id}", AiProviders::ASK_TIMEOUT + 60);
        if (! $lock->get()) {
            return false;
        }

        try {
            $scan->refresh();
            if (! $scan->running()) {
                return false;
            }
            $batch = $scan->batchRows()->where('status', 'pending')->orderBy('id')->first();
            if (! $batch) {
                $scan->forceFill(['status' => 'done', 'finished_at' => now()])->save();

                return false;
            }
            $ai = AiProvider::for($scan->provider);
            if ($ai->api_key === null) {
                $scan->forceFill(['status' => 'failed', 'error' => 'no_key', 'error_detail' => null])->save();

                return false;
            }

            $batch->increment('attempts');
            $lookup = self::lookup($scan, $batch);
            try {
                $answer = AiProviders::ask($scan->provider, $ai->api_key, $scan->model, self::system($scan), $lookup['prompt'], self::SCHEMA);
                self::keep($scan, $batch, $answer, $lookup);
                $batch->forceFill(['status' => 'done', 'error' => null])->save();
                $scan->increment('batches_done');
            } catch (AiProviderError $e) {
                $batch->forceFill(['error' => mb_substr($e->reason.($e->detail ? ': '.$e->detail : ''), 0, 255)])->save();
                // cle refusee : inutile d'insister ; plus de credit (ou trop de demandes) : apres les essais du lot
                if ($e->reason === 'auth' || ($e->reason === 'quota' && $batch->attempts >= MergeScanBatch::MAX_ATTEMPTS)) {
                    $scan->forceFill(['status' => 'failed', 'error' => $e->reason, 'error_detail' => $e->detail])->save();

                    return true;
                }
                if ($batch->attempts >= MergeScanBatch::MAX_ATTEMPTS) {
                    $batch->forceFill(['status' => 'failed'])->save();
                    $scan->increment('batches_failed');
                }
            }

            if (! $scan->batchRows()->where('status', 'pending')->exists()) {
                $scan->forceFill(['status' => 'done', 'finished_at' => now()])->save();
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    /** @param  list<string>  $codes */
    private static function signature(array $codes): string
    {
        sort($codes, SORT_STRING);

        return implode("\n", $codes);
    }

    /** Ce que l'IA doit savoir, en anglais (elle le suit mieux), avec ses raisons dans la langue du compte. */
    private static function system(MergeScan $scan): string
    {
        $language = self::LANGUAGES[$scan->locale] ?? 'English';

        return <<<TXT
        You review the product catalog of a trading company (mostly hardware, plumbing, electrical and building supplies).
        Each branch runs its own copy of the stock program and sometimes gives the same product a different code,
        or writes its designation differently (French, uppercase, abbreviations). Your job: say which items of different
        branches are the very same product, so that a person can merge their codes.

        Rules:
        - Same product = same kind, same sizes and dimensions, same material, colour, brand, model or reference,
          same unit and pack size. Abbreviations, spelling and word order do not matter (BLT = BOULON, 8X20 = 8*20).
        - Any difference in a number (size, diameter, length, thickness, voltage, power, quantity per pack) means
          different products. So does a different brand, colour, material or model.
        - An item shows the branches it exists in (letters). Never put in one group two items that share a branch letter.
        - Most items have no match. When unsure, leave items out or use confidence "low". Never guess.
        - confidence: "high" = certainly the same product; "medium" = very likely, a person should glance at it;
          "low" = possible, a person must check.
        - name: the clearest designation for the merged product, in the style of the catalog (French, uppercase).
        - reason: one short sentence in {$language}: what matches, and anything that differs.

        Answer only with JSON: {"groups": [{"items": [1, 2], "confidence": "high", "name": "...", "reason": "..."}]}
        where items are the item numbers (#). Return {"groups": []} when nothing matches.
        TXT;
    }

    /**
     * Le message du lot et, pour lire la reponse, chaque numero d'article : ses codes et ses branches.
     * Les designations sont relues maintenant (la branche a pu changer depuis le debut de l'analyse).
     *
     * @return array{prompt: string, items: array<int, array{keys: list<string>, mask: int, source: string}>}
     */
    private static function lookup(MergeScan $scan, MergeScanBatch $batch): array
    {
        $letters = [];
        foreach ($scan->branch_ids as $i => $id) {
            $letters[$id] = [chr(65 + $i % 26).($i >= 26 ? intdiv($i, 26) : ''), 1 << $i];
        }
        $keys = collect($batch->clusters)->pluck('units')->flatten()->unique()->values();
        $rows = BranchStock::whereIn('branch_id', $scan->branch_ids)->whereIn('code_key', $keys)
            ->get(['branch_id', 'code_key', 'code_raw', 'designation'])->groupBy('code_key');

        $n = 0;
        $items = $blocks = [];
        foreach ($batch->clusters as $c => $cluster) {
            $lines = [];
            foreach ($cluster['units'] as $unitKeys) {
                $found = collect($unitKeys)->flatMap(fn ($k) => $rows[$k] ?? [])->filter(fn ($r) => isset($letters[$r->branch_id]));
                if ($found->isEmpty()) {
                    continue;   // plus dans aucune branche analysee
                }
                $items[++$n] = [
                    'keys' => $unitKeys,
                    'mask' => $found->reduce(fn (int $m, $r) => $m | $letters[$r->branch_id][1], 0),
                    'source' => $cluster['source'],
                ];
                $lines[] = '#'.$n.' | '.$found->map(fn ($r) => $letters[$r->branch_id][0])->unique()->sort()->implode(',')
                    .' | '.$found->pluck('code_raw')->map(fn ($code) => trim((string) $code))->unique()->implode(' + ')
                    .' | '.$found->pluck('designation')->map(fn ($d) => trim((string) $d))->filter()->unique()->implode(' / ');
            }
            $blocks[] = $batch->kind === 'verify' ? 'Group '.($c + 1).":\n".implode("\n", $lines) : implode("\n", $lines);
        }

        $intro = $batch->kind === 'verify'
            ? "Candidate groups found by matching codes and designations. For each group, return the items that are the same product\n"
              ."(a group may hold several products, or none). Items are: # | branches | code | designation.\n\n"
            : "Items that exist in some branches only, sorted by designation. Find the items that are the same product as an item\n"
              ."of another branch; most have no match. Items are: # | branches | code | designation.\n\n";

        return ['prompt' => $intro.implode("\n\n", $blocks), 'items' => $items];
    }

    /**
     * Les groupes de la reponse qui tiennent debout : des numeros connus, aucune branche en commun entre deux articles,
     * aucune paire qu'un humain a declaree differente ; une proposition deja faite dans cette analyse n'est pas repetee.
     */
    private static function keep(MergeScan $scan, MergeScanBatch $batch, array $answer, array $lookup): void
    {
        $dismissed = MergeDismissal::pluck('pair')->flip();
        $made = $scan->suggestions()->get(['id', 'units'])->mapWithKeys(fn (MergeSuggestion $s) => [self::signature($s->codes()) => true]);
        foreach (is_array($answer['groups'] ?? null) ? $answer['groups'] : [] as $group) {
            if (! is_array($group) || ! is_array($group['items'] ?? null)) {
                continue;
            }
            $units = [];
            $mask = 0;
            $sources = [];
            foreach (array_unique(array_map('intval', $group['items'])) as $n) {
                $item = $lookup['items'][$n] ?? null;
                if (! $item || ($item['mask'] & $mask) !== 0) {
                    continue;
                }
                foreach ($units as $other) {
                    foreach ($other as $a) {
                        foreach ($item['keys'] as $b) {
                            if ($dismissed->has(MergeDismissal::pair($a, $b))) {
                                continue 4;
                            }
                        }
                    }
                }
                $units[] = $item['keys'];
                $mask |= $item['mask'];
                $sources[] = $item['source'];
            }
            if (count($units) < 2) {
                continue;
            }

            usort($units, fn (array $a, array $b) => strcmp($a[0], $b[0]));
            $signature = self::signature(array_merge(...$units));
            if ($made->has($signature)) {
                continue;
            }
            $made[$signature] = true;

            $scan->suggestions()->create([
                'units' => $units,
                'confidence' => in_array($group['confidence'] ?? null, MergeSuggestion::CONFIDENCES, true) ? $group['confidence'] : 'low',
                'source' => $batch->kind === 'discover' ? 'discover' : (collect($sources)->first(fn ($s) => $s === 'code') ?? $sources[0]),
                'name' => mb_substr(trim((string) ($group['name'] ?? '')), 0, 200) ?: null,
                'reason' => mb_substr(trim((string) ($group['reason'] ?? '')), 0, 500) ?: null,
            ]);
        }
    }
}
