<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchAccess;
use App\Models\BranchStock;
use App\Models\BranchTask;
use App\Models\InstallCode;
use App\Models\RefreshJob;
use App\Models\User;
use App\Support\AgentDatasets;
use App\Support\DatasetSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** API appelee par les agents des branches (voir agent/README.md pour le contrat). */
class AgentController extends Controller
{
    /** Colonnes de branch_stock qu'une synchro ou un "تحديث الآن" reecrit. */
    private const STOCK_COLUMNS = ['code_key', 'designation', 'stock', ...BranchStock::PRICES, ...BranchStock::LEVELS, ...BranchStock::DETAILS, 'seen_at', 'updated_at'];

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'install_code' => ['required', 'string', 'max:50'],
            'branch_name' => ['required', 'string', 'max:100'],
            'hostname' => ['nullable', 'string', 'max:100'],
            'agent_version' => ['nullable', 'string', 'max:20'],
        ]);

        return DB::transaction(function () use ($data) {
            $code = InstallCode::where('code_hash', InstallCode::hash($data['install_code']))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $code) {
                return response()->json(['error' => "code d'installation invalide, expire ou deja utilise"], 422);
            }

            $token = Str::random(48);
            $agent = [
                'hostname' => $data['hostname'] ?? null,
                'agent_version' => $data['agent_version'] ?? null,
                'token_hash' => Branch::hashToken($token),
                'last_seen_at' => now(),
            ];
            if ($code->for_branch_id !== null) {
                // reinstallation : meme branche (comptes, acces, stock), nouveau token ; l'ancien agent recoit 401
                $branch = Branch::findOrFail($code->for_branch_id);
                $branch->update($agent);
            } else {
                $branch = Branch::create(['name' => trim($data['branch_name']), 'active' => true] + $agent);
                // comme avant les roles : elle voit les autres et les autres la voient, on restreint ensuite
                BranchAccess::openNewBranch($branch);
                // installee par un directeur de la societe : elle fait partie de ses branches
                $creator = User::find($code->created_by);
                if ($creator?->isOwner()) {
                    $creator->branches()->syncWithoutDetaching([$branch->id]);
                }
            }
            $code->update(['used_at' => now(), 'branch_id' => $branch->id]);

            return response()->json([
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'token' => $token,
            ], 201);
        });
    }

    public function ping(Request $request): JsonResponse
    {
        $branch = $this->branch($request);

        return response()->json(['ok' => true, 'branch_id' => $branch->id, 'branch_name' => $branch->name]);
    }

    /** Stock complet de la branche : remplace l'existant, les articles absents sont supprimes. */
    public function sync(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $items = $request->input('items');
        // liste vide refusee : une base Gescomm sans aucun article = lecture ratee, pas de quoi effacer la branche
        if (! is_array($items) || $items === []) {
            return response()->json(['error' => 'items manquant ou vide'], 422);
        }

        $now = now();
        $rows = [];
        foreach ($items as $item) {
            $row = $this->stockRow($branch, $item, $now);
            if ($row !== null) {
                $rows[$row['code_raw']] = $row;
            }
        }

        // Seulement ce qui a change : d'une synchro a l'autre, presque tout le stock est identique.
        // Une synchro sans changement n'ecrit donc que la ligne de la branche, la recherche ne la sent pas.
        // La fraicheur d'une valeur inchangee = derniere synchro de la branche (voir StockController::product).
        $existing = BranchStock::where('branch_id', $branch->id)
            ->get(['id', 'code_raw', ...array_diff(self::STOCK_COLUMNS, ['seen_at', 'updated_at'])])
            ->keyBy('code_raw');
        $changed = array_values(array_filter($rows, function (array $row, int|string $code) use ($existing) {
            $old = $existing->get($code);

            return $old === null || ! $this->sameStock($old, $row);
        }, ARRAY_FILTER_USE_BOTH));
        // absent de l'envoi complet = supprime du Gescomm de la branche
        $removed = $existing->diffKeys($rows)->pluck('id');

        DB::transaction(function () use ($branch, $rows, $changed, $removed, $now, $request) {
            foreach (array_chunk($changed, 500) as $chunk) {
                BranchStock::upsert($chunk, ['branch_id', 'code_raw'], self::STOCK_COLUMNS);
            }
            foreach ($removed->chunk(500) as $ids) {
                BranchStock::whereIn('id', $ids)->delete();
            }

            $branch->forceFill([
                'last_sync_at' => $now,
                'items_count' => count($rows),
                'hostname' => $request->input('hostname') ?: $branch->hostname,
                'db_file_mtime' => $this->parseDate($request->input('db_file_mtime')) ?? $branch->db_file_mtime,
            ])->save();
        });

        return response()->json(['ok' => true, 'count' => count($rows), 'changed' => count($changed), 'removed' => $removed->count()]);
    }

    /** Meme article, memes valeurs ? Les nombres sont compares au centime pres (colonnes decimal(15,2)). */
    private function sameStock(BranchStock $old, array $new): bool
    {
        $sameNumber = fn (?float $a, mixed $b) => $a === null || $b === null
            ? $a === null && $b === null
            : abs($a - (float) $b) < 0.005;

        return $old->code_key === $new['code_key']
            && $old->designation === $new['designation']
            && $sameNumber($old->stock, $new['stock'])
            && collect([...BranchStock::PRICES, ...BranchStock::LEVELS])->every(fn (string $field) => $sameNumber($old->{$field}, $new[$field]))
            && collect(BranchStock::DETAILS)->every(fn (string $field) => $old->{$field} === $new[$field]);
    }

    /** Demandes "mettre a jour" en attente pour cette branche ; elles passent a "taken". */
    public function pending(Request $request): JsonResponse
    {
        $branch = $this->branch($request);

        $jobs = RefreshJob::where('branch_id', $branch->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(RefreshJob::PICKUP_MINUTES))
            ->orderBy('id')
            ->limit(20)
            ->get();

        if ($jobs->isNotEmpty()) {
            RefreshJob::whereIn('id', $jobs->pluck('id'))->update(['status' => 'taken', 'taken_at' => now()]);
        }

        return response()->json([
            'jobs' => $jobs->map(fn (RefreshJob $job) => ['id' => $job->id, 'type' => $job->type, 'code' => $job->code_key])->values(),
        ]);
    }

    public function result(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $job = RefreshJob::where('id', $request->integer('job_id'))->where('branch_id', $branch->id)->first();
        if (! $job) {
            return response()->json(['error' => 'demande inconnue'], 404);
        }

        $error = $request->input('error');
        if ($job->type === 'sync') {
            // le stock lui-meme est deja arrive par POST /agent/sync ; ici l'agent confirme seulement
            $job->update([
                'status' => $error ? 'error' : 'done',
                'answered_at' => now(),
                'result' => $request->only(['error', 'count', 'read_at']),
            ]);

            return response()->json(['ok' => true]);
        }

        $items = $request->input('items');
        $items = is_array($items) ? $items : [];
        $status = $error ? 'error' : ($request->boolean('found') && $items ? 'done' : 'not_found');
        $now = now();

        DB::transaction(function () use ($branch, $job, $items, $status, $now, $request) {
            if ($status === 'done') {
                $rows = array_values(array_filter(array_map(fn ($item) => $this->stockRow($branch, $item, $now), $items)));
                BranchStock::upsert($rows, ['branch_id', 'code_raw'], self::STOCK_COLUMNS);
            } elseif ($status === 'not_found') {
                // l'article n'existe plus dans le Gescomm de la branche : la lecture en direct fait foi
                BranchStock::where('branch_id', $branch->id)->where('code_key', $job->code_key)->delete();
            }

            $job->update([
                'status' => $status,
                'answered_at' => $now,
                'result' => $request->only(['found', 'items', 'error', 'read_at']),
            ]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Agent 1.0.7 : empreintes des groupes d'un jeu (ventes par jour, clients par paquet...), le serveur repond ceux
     * qu'il faut lui envoyer. Avec error : la tache a echoue dans la branche (lecture de Gescomm), on le note.
     */
    public function digest(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $name = (string) $request->input('dataset');
        $set = AgentDatasets::get($name);
        if ($set === null) {
            return response()->json(['error' => 'unknown_dataset'], 422);
        }

        $error = $request->input('error');
        if (is_string($error) && $error !== '') {
            $message = $request->input('message');
            BranchTask::failed($branch, $name, $error.(is_string($message) && $message !== '' ? ': '.$message : ''));

            return response()->json(['send' => [], 'page_rows' => DatasetSync::PAGE_ROWS]);
        }

        $from = $request->input('from');
        $to = $request->input('to');
        foreach ([$from, $to] as $bound) {
            if ($bound !== null && (! $set['dated'] || ! AgentDatasets::validGroup($set, $bound))) {
                return response()->json(['error' => 'invalid_range'], 422);
            }
        }
        $groups = $request->input('groups') ?? [];
        // pas un seul client dans tout Gescomm = lecture ratee, pas de quoi tout effacer (comme une synchro vide)
        if (! is_array($groups) || ($groups === [] && ! $set['dated'])) {
            return $this->refuse($branch, $name, 'groups manquant ou vide');
        }

        $send = DatasetSync::differing($branch, $set, $groups, $from, $to);
        if ($send === []) {
            BranchTask::ok($branch, $name);
        }

        return response()->json(['send' => $send, 'page_rows' => DatasetSync::PAGE_ROWS]);
    }

    /** Agent 1.0.7 : les groupes demandes par digest, complets ; chacun remplace le groupe du serveur. */
    public function records(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $name = (string) $request->input('dataset');
        $set = AgentDatasets::get($name);
        if ($set === null) {
            return response()->json(['error' => 'unknown_dataset'], 422);
        }
        $groups = $request->input('groups');
        if (! is_array($groups) || $groups === []) {
            return response()->json(['error' => 'groups manquant ou vide'], 422);
        }
        foreach (array_keys($groups) as $group) {
            if (! AgentDatasets::validGroup($set, (string) $group)) {
                return $this->refuse($branch, $name, 'invalid_group', ['group' => (string) $group]);
            }
        }
        if (DatasetSync::rowCount($groups) > DatasetSync::MAX_ROWS) {
            return $this->refuse($branch, $name, 'too_many_rows', ['max_rows' => DatasetSync::MAX_ROWS]);
        }

        $result = DatasetSync::store($branch, $set, $groups);
        BranchTask::ok($branch, $name);

        return response()->json(['ok' => true] + $result);
    }

    /** Envoi refuse : la page des branches le montre (l'agent, lui, ne renvoie pas une erreur de l'API a l'API). */
    private function refuse(Branch $branch, string $task, string $error, array $extra = []): JsonResponse
    {
        BranchTask::failed($branch, $task, $error.($extra ? ' '.json_encode($extra) : ''));

        return response()->json(['error' => $error] + $extra, 422);
    }

    private function branch(Request $request): Branch
    {
        return $request->attributes->get('branch');
    }

    private function stockRow(Branch $branch, mixed $item, Carbon $now): ?array
    {
        if (! is_array($item)) {
            return null;
        }
        $code = rtrim((string) ($item['code'] ?? ''));
        if ($code === '' || mb_strlen($code) > 30) {
            return null;
        }
        $designation = trim((string) ($item['designation'] ?? ''));
        $stock = $item['stock'] ?? null;

        $row = [
            'branch_id' => $branch->id,
            'code_raw' => $code,
            'code_key' => BranchStock::keyFor($code),
            'designation' => $designation === '' ? null : mb_substr($designation, 0, 200),
            'stock' => is_numeric($stock) ? $stock : null,
        ];
        // prix (detail, gros, achat) : envoyes a partir de l'agent 1.0.5, absents avant (null = inconnu)
        foreach (BranchStock::PRICES as $field) {
            $value = $item[$field] ?? null;
            $row[$field] = is_numeric($value) ? $value : null;
        }
        // seuils, famille et unite : a partir de l'agent 1.0.7
        foreach (BranchStock::LEVELS as $field) {
            $value = $item[$field] ?? null;
            $row[$field] = is_numeric($value) ? $value : null;
        }
        foreach (BranchStock::DETAILS as $field) {
            $value = is_scalar($item[$field] ?? null) ? trim((string) $item[$field]) : '';
            $row[$field] = $value === '' ? null : mb_substr($value, 0, 20);
        }

        return $row + ['seen_at' => $now, 'updated_at' => $now];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
