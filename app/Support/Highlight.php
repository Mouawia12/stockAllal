<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/** Met en evidence (<mark>) les mots recherches dans un texte, sans jamais laisser passer de HTML du texte. */
class Highlight
{
    /**
     * Les mots d'une recherche (au plus 6) : chacun doit se trouver, dans n'importe quel ordre (bons, clients,
     * fournisseurs, comme la recherche du stock), et chacun est mis en evidence.
     *
     * @return list<string>
     */
    public static function terms(string $q): array
    {
        return array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);
    }

    /** Un mot pour LIKE ... ESCAPE '!', en majuscules (les colonnes sont comparees en UPPER). */
    public static function like(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtoupper($term));
    }

    /** @param  list<string>  $terms */
    public static function html(?string $text, array $terms): HtmlString
    {
        $text ??= '';
        $terms = array_values(array_filter($terms, fn (string $term) => $term !== ''));
        if ($text === '' || $terms === []) {
            return new HtmlString(e($text));
        }

        // les plus longs d'abord : "820AC" avant "820" quand les deux sont cherches
        usort($terms, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/('.implode('|', array_map(fn (string $term) => preg_quote($term, '/'), $terms)).')/iu';

        // decoupe le texte brut puis echappe chaque morceau : un mot cherche ne peut pas couper une entite HTML
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return new HtmlString(e($text));
        }
        $html = '';
        foreach ($parts as $i => $part) {
            $html .= $i % 2 === 1 ? '<mark>'.e($part).'</mark>' : e($part);
        }

        return new HtmlString($html);
    }
}
