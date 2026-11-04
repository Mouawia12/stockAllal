<?php

namespace App\Support;

use App\Models\BranchStock;
use App\Models\MergeDismissal;
use App\Models\ProductGroupCode;
use Illuminate\Support\Str;

/**
 * Premiere passe de "دمج الأصناف" par l'IA, ici, sans l'IA : quels articles de branches differentes pourraient etre
 * le meme produit. Un article = un code (ou un groupe deja fusionne) et les branches ou il existe.
 *
 * Seuls deux articles qui n'ont aucune branche en commun peuvent etre fusionnes : une branche n'a pas deux codes
 * pour le meme produit, et un code present partout n'a personne a rejoindre. Les candidats :
 *  - code : le meme code sans tirets ni espaces (820-AC / 820AC) ;
 *  - name : la meme designation une fois ecrite pareil (BOULON 8X20 / BOULON 8*20) ;
 *  - similar : des designations proches avec les memes nombres (une mesure differente = un autre produit).
 * Ces groupes vont a l'IA pour verification (verify). Les autres articles, tries par designation, lui sont
 * envoyes par paquets pour trouver ce qu'aucune regle ne voit (discover, analyse approfondie).
 */
final class MergeCandidates
{
    /** Articles au plus dans un groupe candidat. */
    public const MAX_CLUSTER = 6;

    /** Groupes candidats par envoi a l'IA. */
    public const VERIFY_BATCH = 40;

    /** Groupes candidats au plus par analyse (les plus surs d'abord). */
    public const MAX_CLUSTERS = 600;

    /** Lignes par envoi de l'analyse approfondie, et paquets au plus. */
    public const DISCOVER_BATCH = 120;

    public const MAX_DISCOVER_BATCHES = 40;

    /** Un mot present dans plus d'articles que ca ne sert pas a rapprocher (BOULON, VIS...). */
    private const MAX_TOKEN_UNITS = 80;

    /** @var array<string, array{keys: list<string>, mask: int, name: string}> */
    private array $units = [];

    /** @var array<string, true> */
    private array $dismissed = [];

    /** @param  list<int>  $branchIds */
    private function __construct(private array $branchIds) {}

    /**
     * @param  list<int>  $branchIds  les branches analysees
     * @return array{units: int, verify: list<array{source: string, units: list<list<string>>}>, discover: list<list<list<string>>>}
     */
    public static function find(array $branchIds, bool $deep): array
    {
        $self = new self(array_values($branchIds));
        if (count($self->branchIds) < 2) {
            return ['units' => 0, 'verify' => [], 'discover' => []];
        }
        $self->load();
        $clusters = $self->clusters();
        $inCluster = array_fill_keys(array_merge(...array_column($clusters, 'ids') ?: [[]]), true);

        return [
            'units' => count($self->units),
            'verify' => array_map(fn (array $c) => ['source' => $c['source'], 'units' => array_map(fn ($id) => $self->units[$id]['keys'], $c['ids'])], $clusters),
            'discover' => $deep ? $self->discover($inCluster) : [],
        ];
    }

    /** Code compare sans ce qui n'est ni lettre ni chiffre. */
    public static function normCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($code)));
    }

    /** Designation ecrite pareil : majuscules sans accents, "8 X 20" = "8*20", "1,5" = "1.5", sans ponctuation. */
    public static function normName(string $name): string
    {
        $s = Str::upper(Str::ascii($name));
        $s = (string) preg_replace('/(\d),(\d)/', '$1.$2', $s);
        $s = (string) preg_replace('/(?<=\d)\s*[X*]\s*(?=\d)/', '*', $s);
        $s = (string) preg_replace('#[^A-Z0-9*./]+|(?<!\d)[./]|[./](?!\d)#', ' ', $s);   // "1.5" et "1/2" gardent leur signe

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** @return list<string> les nombres d'une designation normalisee, tries ("8*20" et "20*8" ont les memes) */
    public static function numbers(string $norm): array
    {
        preg_match_all('/\d+(?:\.\d+)?/', $norm, $m);
        $numbers = [];
        foreach ($m[0] as $n) {
            $n = ltrim($n, '0');   // "08" = "8", "0.5" reste "0.5"
            $numbers[] = $n === '' || $n[0] === '.' ? '0'.$n : $n;
        }
        sort($numbers);

        return $numbers;
    }

    /** Les articles des branches analysees qui manquent dans au moins une branche ; un groupe fusionne compte pour un. */
    private function load(): void
    {
        $bit = array_flip($this->branchIds);
        $groupOf = ProductGroupCode::pluck('product_group_id', 'code_key');
        $names = [];
        foreach (BranchStock::whereIn('branch_id', $this->branchIds)->select('branch_id', 'code_key', 'designation')->cursor() as $row) {
            $key = (string) $row->code_key;
            $id = $groupOf->has($key) ? 'g'.$groupOf[$key] : 'c'.$key;
            $this->units[$id] ??= ['keys' => [], 'mask' => 0, 'name' => ''];
            $this->units[$id]['keys'][$key] = $key;
            $this->units[$id]['mask'] |= 1 << $bit[$row->branch_id];
            $name = self::normName((string) $row->designation);
            if ($name !== '') {
                $names[$id][$name] = ($names[$id][$name] ?? 0) + 1;
            }
        }

        $all = (1 << count($this->branchIds)) - 1;
        foreach ($this->units as $id => $unit) {
            if ($unit['mask'] === $all) {
                unset($this->units[$id]);

                continue;
            }
            $keys = array_values($unit['keys']);
            sort($keys, SORT_STRING);
            $this->units[$id]['keys'] = $keys;
            if (isset($names[$id])) {
                arsort($names[$id]);
                $this->units[$id]['name'] = (string) array_key_first($names[$id]);
            }
        }
        ksort($this->units, SORT_STRING);
        $this->dismissed = array_fill_keys(MergeDismissal::pluck('pair')->all(), true);
    }

    /**
     * Les groupes candidats : les paires les plus sures d'abord, reunies tant qu'un groupe reste petit.
     *
     * @return list<array{source: string, ids: list<string>}>
     */
    private function clusters(): array
    {
        $edges = [...$this->sameKeyEdges('code', 3.5), ...$this->sameKeyEdges('name', 3.2), ...$this->similarEdges()];
        usort($edges, fn (array $a, array $b) => $b[0] <=> $a[0] ?: strcmp($a[1].$a[2], $b[1].$b[2]));

        $parent = $size = $source = [];
        $find = function (string $x) use (&$parent, &$find): string {
            return $parent[$x] === $x ? $x : $parent[$x] = $find($parent[$x]);
        };
        foreach ($edges as [, $a, $b, $kind]) {
            foreach ([$a, $b] as $x) {
                if (! isset($parent[$x])) {
                    [$parent[$x], $size[$x], $source[$x]] = [$x, 1, null];
                }
            }
            [$ra, $rb] = [$find($a), $find($b)];
            if ($ra === $rb || $size[$ra] + $size[$rb] > self::MAX_CLUSTER) {
                continue;
            }
            $parent[$rb] = $ra;
            $size[$ra] += $size[$rb];
            $source[$ra] ??= $source[$rb] ?? $kind;   // l'arete la plus sure qui a forme le groupe
        }

        $groups = [];
        foreach (array_keys($parent) as $x) {
            $groups[$find($x)][] = $x;
        }
        $out = [];
        foreach ($groups as $root => $ids) {
            if (count($ids) > 1) {
                sort($ids, SORT_STRING);
                $out[] = ['source' => $source[$root], 'ids' => $ids];
            }
        }

        return array_slice($out, 0, self::MAX_CLUSTERS);
    }

    /** @return list<array{0: float, 1: string, 2: string, 3: string}> */
    private function sameKeyEdges(string $kind, float $strength): array
    {
        $index = [];
        foreach ($this->units as $id => $unit) {
            $values = $kind === 'code' ? array_map(self::normCode(...), $unit['keys']) : [$unit['name']];
            foreach (array_unique($values) as $value) {
                if (strlen($value) >= ($kind === 'code' ? 3 : 4)) {
                    $index[$value][] = $id;
                }
            }
        }

        $edges = [];
        foreach ($index as $ids) {
            foreach ($this->pairs($ids) as [$a, $b]) {
                $edges[] = [$strength, $a, $b, $kind];
            }
        }

        return $edges;
    }

    /** @return list<array{0: float, 1: string, 2: string, 3: string}> */
    private function similarEdges(): array
    {
        $tokens = $numbers = $index = [];
        foreach ($this->units as $id => $unit) {
            if ($unit['name'] === '') {
                continue;
            }
            $tokens[$id] = array_unique(explode(' ', $unit['name']));
            $numbers[$id] = self::numbers($unit['name']);
            // un mot rare, ou le premier mot avec les mesures ("BOULON|8|20") : seuls ces articles sont compares
            foreach ($tokens[$id] as $t) {
                if (strlen($t) >= 3 && preg_match('/[A-Z]/', $t)) {
                    $index[$t][] = $id;
                }
            }
            if ($numbers[$id]) {
                $index[$tokens[$id][0].'|'.implode('|', $numbers[$id])][] = $id;
            }
        }

        $seen = $edges = [];
        foreach ($index as $ids) {
            if (count($ids) < 2 || count($ids) > self::MAX_TOKEN_UNITS) {
                continue;
            }
            foreach ($this->pairs($ids) as [$a, $b]) {
                if (isset($seen["{$a}\n{$b}"]) || $this->units[$a]['name'] === $this->units[$b]['name']) {
                    continue;
                }
                $seen["{$a}\n{$b}"] = true;
                $score = $this->similarity($tokens[$a], $tokens[$b], $this->units[$a]['name'], $this->units[$b]['name']);
                $sameNumbers = $numbers[$a] === $numbers[$b];
                // une mesure differente = un autre produit ; une designation sans mesure face a une avec : il faut plus de ressemblance
                if (($numbers[$a] && $numbers[$b] && ! $sameNumbers) || $score < ($sameNumbers ? 0.6 : 0.8)) {
                    continue;
                }
                $edges[] = [1 + $score, $a, $b, 'similar'];
            }
        }

        return $edges;
    }

    private function similarity(array $ta, array $tb, string $a, string $b): float
    {
        $jaccard = count(array_intersect($ta, $tb)) / max(1, count(array_unique([...$ta, ...$tb])));
        $long = max(strlen($a), strlen($b));
        $edit = $long > 0 && $long <= 255 ? 1 - levenshtein($a, $b) / $long : 0;

        return max($jaccard, $edit);
    }

    /**
     * Les paires d'articles qui n'ont aucune branche en commun et qu'aucun humain n'a declarees differentes.
     *
     * @param  list<string>  $ids
     * @return iterable<array{0: string, 1: string}>
     */
    private function pairs(array $ids): iterable
    {
        $ids = array_values(array_unique($ids));
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                if (($this->units[$a]['mask'] & $this->units[$b]['mask']) === 0 && ! $this->isDismissed($a, $b)) {
                    yield strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
                }
            }
        }
    }

    private function isDismissed(string $a, string $b): bool
    {
        if ($this->dismissed === []) {
            return false;
        }
        foreach ($this->units[$a]['keys'] as $ka) {
            foreach ($this->units[$b]['keys'] as $kb) {
                if (isset($this->dismissed[MergeDismissal::pair($ka, $kb)])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Les articles hors des groupes candidats, tries par designation, en paquets ; un paquet ou aucun article
     * n'a de branche differente d'un autre n'est pas envoye.
     *
     * @param  array<string, true>  $inCluster
     * @return list<list<list<string>>>
     */
    private function discover(array $inCluster): array
    {
        $rest = array_filter($this->units, fn (array $u, string $id) => ! isset($inCluster[$id]) && $u['name'] !== '', ARRAY_FILTER_USE_BOTH);
        uasort($rest, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        $batches = [];
        foreach (array_chunk($rest, self::DISCOVER_BATCH, true) as $chunk) {
            $masks = array_column($chunk, 'mask');
            $useful = false;
            foreach ($masks as $i => $m) {
                foreach (array_slice($masks, $i + 1) as $n) {
                    if (($m & $n) === 0) {
                        $useful = true;
                        break 2;
                    }
                }
            }
            if ($useful) {
                $batches[] = array_values(array_column($chunk, 'keys'));
            }
            if (count($batches) >= self::MAX_DISCOVER_BATCHES) {
                break;
            }
        }

        return $batches;
    }
}
