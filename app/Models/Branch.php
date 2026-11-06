<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// is_main et mode hors Fillable : is_main ne change que par BranchController::main (une seule branche principale),
// mode seulement par l'approbation d'une branche (docs/roadmap.md)
#[Fillable(['name', 'hostname', 'token_hash', 'active', 'agent_version', 'last_seen_at', 'last_sync_at', 'db_file_mtime', 'items_count', 'print_header', 'print_footer'])]
#[Hidden(['token_hash'])]
class Branch extends Model
{
    /** L'agent interroge l'API toutes les 10 s : sans nouvelle depuis 60 s, il est considere hors ligne. */
    public const ONLINE_SECONDS = 60;

    /**
     * Demandes que le serveur envoie a un agent, et donnees qu'il envoie => premiere version d'agent qui les connait.
     * Les branches mettent leur agent a jour a la main, quand on le decide : une demande n'est jamais envoyee a un agent
     * plus ancien (avant 1.0.4, une demande "sync" est lue comme un code vide et revient en invalid_code), et une page
     * qui a besoin des ventes dit "mettre a jour l'agent de cette branche" au lieu d'afficher zero.
     */
    public const AGENT_FEATURES = ['sync' => '1.0.4', 'documents' => '1.0.7', 'cash' => '1.0.8'];

    /**
     * synced : Gescomm est la source, l'agent synchronise, le web ne fait que lire.
     * native : branche approuvee, elle travaille dans le web (plus tard, docs/roadmap.md).
     */
    public const MODES = ['synced', 'native'];

    /**
     * Teinte de la couleur legere de chaque branche : fond de ses lignes (page d'un article, apercu, ventes et achats)
     * et pastille devant son nom. Donnee par l'id, donc toujours la meme. Ni rouge ni jaune : ils disent deja
     * "stock 0" et "stock negatif" (StockState). Clair / sombre : --tint-* dans partials/theme.
     */
    public const HUES = [214, 150, 274, 28, 310, 176, 248, 96];

    protected $attributes = [
        'mode' => 'synced',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_main' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'db_file_mtime' => 'datetime',
            'items_count' => 'integer',
        ];
    }

    public function stock(): HasMany
    {
        return $this->hasMany(BranchStock::class);
    }

    /** Branches dont ce compte voit le stock (User::stockBranchIds). */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $ids = $user->stockBranchIds();
        if ($ids !== null) {
            $query->whereKey($ids);
        }
    }

    /** Ces branches seulement (BranchScope::ids) ; null = toutes. */
    public function scopeAmong(Builder $query, ?array $ids): void
    {
        if ($ids !== null) {
            $query->whereKey($ids);
        }
    }

    public function isNative(): bool
    {
        return $this->mode === 'native';
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_SECONDS));
    }

    /** L'agent de la branche comprend-il cette demande (AGENT_FEATURES) ? Version inconnue = non. */
    public function agentSupports(string $feature): bool
    {
        return $this->agent_version !== null
            && version_compare($this->agent_version, self::AGENT_FEATURES[$feature], '>=');
    }

    /** @return list<string> les lignes d'en-tete de ses impressions (la premiere = le nom de la societe), sinon le nom de la branche */
    public function printHeaderLines(): array
    {
        return self::textLines($this->print_header) ?: [$this->name];
    }

    /** @return list<string> */
    public function printFooterLines(): array
    {
        return self::textLines($this->print_footer);
    }

    /** @return list<string> */
    private static function textLines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $text) ?: []), fn (string $line) => $line !== ''));
    }

    public function hue(): int
    {
        return self::hueFor($this->id);
    }

    public static function hueFor(int $id): int
    {
        return self::HUES[abs($id - 1) % count(self::HUES)];
    }

    /** Branche de reference des prix, si elle est active. */
    public static function main(): ?self
    {
        return self::where('is_main', true)->where('active', true)->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
