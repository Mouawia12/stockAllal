<?php

namespace App\Support;

use RuntimeException;

/** Un test de lien (AiProviders::models) qui a echoue : reason = cle "ai.errors.*" des traductions, detail = ce que le fournisseur a repondu. */
final class AiProviderError extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct($reason);
    }
}
