<?php

namespace App\Support;

/**
 * Un montant en toutes lettres, en francais, comme au bas d'un bon de Gescomm : "cent vingt-six mille deux cents
 * dinars". Ecrit ici et pas avec NumberFormatter : l'extension intl n'est pas garantie sur le serveur.
 */
final class AmountInWords
{
    private const UNITS = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze',
        'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];

    private const TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    public static function french(float $amount): string
    {
        $cents = (int) round(abs($amount) * 100);
        $whole = intdiv($cents, 100);
        $rest = $cents % 100;
        $text = self::number($whole).' '.($whole > 1 ? 'dinars' : 'dinar');
        // "un million de dinars" : "de" apres million ou milliard tout rond
        if ($whole >= 1000000 && $whole % 1000000 === 0) {
            $text = self::number($whole).' de dinars';
        }
        if ($rest > 0) {
            $text .= ' et '.self::number($rest).' '.($rest > 1 ? 'centimes' : 'centime');
        }

        return ($amount < 0 && $cents > 0 ? 'moins ' : '').$text;
    }

    private static function number(int $n): string
    {
        if ($n < 1000) {
            return self::below1000($n, true);
        }
        $parts = [];
        $milliards = intdiv($n, 1000000000);
        $millions = intdiv($n % 1000000000, 1000000);
        $thousands = intdiv($n % 1000000, 1000);
        $rest = $n % 1000;
        if ($milliards > 0) {
            $parts[] = self::below1000($milliards, true).($milliards > 1 ? ' milliards' : ' milliard');
        }
        if ($millions > 0) {
            $parts[] = self::below1000($millions, true).($millions > 1 ? ' millions' : ' million');
        }
        if ($thousands > 0) {
            // "mille" sans "un", et "deux cent mille", "quatre-vingt mille" : pas de s devant mille
            $parts[] = $thousands === 1 ? 'mille' : self::below1000($thousands, false).' mille';
        }
        if ($rest > 0) {
            $parts[] = self::below1000($rest, true);
        }

        return implode(' ', $parts);
    }

    /** $plural : "deux cents", "quatre-vingts" en fin de nombre ; "deux cent mille", "quatre-vingt mille" devant mille. */
    private static function below1000(int $n, bool $plural): string
    {
        if ($n < 100) {
            return self::below100($n, $plural);
        }
        $hundreds = intdiv($n, 100);
        $rest = $n % 100;
        $text = $hundreds === 1 ? 'cent' : self::UNITS[$hundreds].' cent';
        if ($rest > 0) {
            return $text.' '.self::below100($rest, $plural);
        }

        return $hundreds > 1 && $plural ? $text.'s' : $text;
    }

    private static function below100(int $n, bool $plural): string
    {
        if ($n < 20) {
            return self::UNITS[$n];
        }
        if ($n < 70) {
            $ten = intdiv($n, 10);
            $unit = $n % 10;
            $text = self::TENS[$ten];

            return match (true) {
                $unit === 0 => $text,
                $unit === 1 => $text.' et un',
                default => $text.'-'.self::UNITS[$unit],
            };
        }
        if ($n < 80) {
            return $n === 71 ? 'soixante et onze' : 'soixante-'.self::UNITS[$n - 60];
        }
        if ($n === 80) {
            return $plural ? 'quatre-vingts' : 'quatre-vingt';
        }

        return 'quatre-vingt-'.self::UNITS[$n - 80];
    }
}
