<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Le lien avec un fournisseur d'IA (App\Support\AiProviders) : sa cle d'API, chiffree avec APP_KEY, le modele choisi, actif ou non.
 * La cle ne sort jamais vers le navigateur : la page montre seulement ses 4 derniers caracteres (keyEnd).
 */
#[Fillable(['provider', 'model', 'enabled'])]
#[Hidden(['api_key'])]
class AiProvider extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'models' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /** La ligne d'un fournisseur, nouvelle s'il n'a jamais ete enregistre. */
    public static function for(string $provider): self
    {
        return self::firstOrNew(['provider' => $provider]);
    }

    /** null : pas de cle, ou une cle chiffree avec un autre APP_KEY (hasStoredKey dit laquelle). */
    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                try {
                    return $value === null ? null : Crypt::decryptString($value);
                } catch (DecryptException) {
                    return null;
                }
            },
            set: fn (?string $value) => $value === null || $value === '' ? null : Crypt::encryptString($value),
        );
    }

    public function hasStoredKey(): bool
    {
        return ($this->attributes['api_key'] ?? null) !== null;
    }

    public function keyEnd(): ?string
    {
        return $this->api_key === null ? null : mb_substr($this->api_key, -4);
    }

    /** Pret a servir : une cle lisible, le dernier test reussi et un modele. */
    public function connected(): bool
    {
        return $this->api_key !== null && $this->check_error === null && $this->checked_at !== null && $this->model !== null;
    }
}
