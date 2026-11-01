<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InstallCode;
use App\Models\RefreshJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** API appelee par les agents des branches (voir agent/README.md pour le contrat). */
class AgentController extends Controller
{
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
            $branch = Branch::create([
                'name' => trim($data['branch_name']),
                'hostname' => $data['hostname'] ?? null,
                'agent_version' => $data['agent_version'] ?? null,
                'token_hash' => Branch::hashToken($token),
                'active' => true,
                'last_seen_at' => now(),
            ]);
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

        DB::transaction(function () use ($branch, $rows, $now, $request) {
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                BranchStock::upsert($chunk, ['branch_id', 'code_raw'], ['code_key', 'designation', 'stock', 'seen_at', 'updated_at']);
            }
            BranchStock::where('branch_id', $branch->id)->where('seen_at', '<', $now)->delete();

            $branch->forceFill([
                'last_sync_at' => $now,
                'items_count' => count($rows),
                'hostname' => $request->input('hostname') ?: $branch->hostname,
                'db_file_mtime' => $this->parseDate($request->input('db_file_mtime')) ?? $branch->db_file_mtime,
            ])->save();
        });

        return response()->json(['ok' => true, 'count' => count($rows)]);
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
                BranchStock::upsert($rows, ['branch_id', 'code_raw'], ['code_key', 'designation', 'stock', 'seen_at', 'updated_at']);
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

        return [
            'branch_id' => $branch->id,
            'code_raw' => $code,
            'code_key' => BranchStock::keyFor($code),
            'designation' => $designation === '' ? null : mb_substr($designation, 0, 200),
            'stock' => is_numeric($stock) ? $stock : null,
            'seen_at' => $now,
            'updated_at' => $now,
        ];
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
