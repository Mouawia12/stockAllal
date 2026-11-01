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

        $branch->forceFill([
            'last_seen_at' => now(),
            'agent_version' => $request->header('X-Agent-Version') ?: $branch->agent_version,
        ])->save();

        $request->attributes->set('branch', $branch);

        return $next($request);
    }
}
