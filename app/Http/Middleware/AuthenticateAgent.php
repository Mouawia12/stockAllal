<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifie la branche par le token de son agent (Authorization: Bearer ...).
 * La branche vient du token, jamais du contenu envoye : une branche ne peut pas ecrire chez une autre.
 */
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $branch = $token
            ? Branch::where('token_hash', Branch::hashToken($token))->where('active', true)->first()
            : null;

        if (! $branch) {
            return response()->json(['error' => 'token invalide ou branche desactivee'], 401);
        }

        // l'agent appelle toutes les 10 s : une ecriture toutes les 30 s suffit pour "en ligne" (Branch::ONLINE_SECONDS = 60)
        $version = $request->header('X-Agent-Version') ?: $branch->agent_version;
        if (! $branch->last_seen_at || $branch->last_seen_at->lt(now()->subSeconds(30)) || $version !== $branch->agent_version) {
            $branch->forceFill(['last_seen_at' => now(), 'agent_version' => $version])->save();
        }

        $request->attributes->set('branch', $branch);

        return $next($request);
    }
}
