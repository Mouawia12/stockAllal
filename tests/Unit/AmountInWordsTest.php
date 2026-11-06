<?php

namespace Tests\Unit;

use App\Support\AmountInWords;
use PHPUnit\Framework\TestCase;

/** Le montant en lettres au bas d'un bon : les regles du francais, jamais un chiffre faux sur un papier remis au client. */
class AmountInWordsTest extends TestCase
{
    public function test_french_numbers_follow_the_grammar(): void
    {
        foreach ([
            0 => 'zéro dinar',
            1 => 'un dinar',
            2 => 'deux dinars',
            16 => 'seize dinars',
            17 => 'dix-sept dinars',
            21 => 'vingt et un dinars',
            22 => 'vingt-deux dinars',
            70 => 'soixante-dix dinars',
            71 => 'soixante et onze dinars',
            72 => 'soixante-douze dinars',
            80 => 'quatre-vingts dinars',
            81 => 'quatre-vingt-un dinars',
            91 => 'quatre-vingt-onze dinars',
            100 => 'cent dinars',
            200 => 'deux cents dinars',
            201 => 'deux cent un dinars',
            1000 => 'mille dinars',
            1080 => 'mille quatre-vingts dinars',
            2000 => 'deux mille dinars',
            80000 => 'quatre-vingt mille dinars',
            200000 => 'deux cent mille dinars',
            126200 => 'cent vingt-six mille deux cents dinars',
            576000 => 'cinq cent soixante-seize mille dinars',
            1000000 => 'un million de dinars',
            2000000 => 'deux millions de dinars',
            1099975 => 'un million quatre-vingt-dix-neuf mille neuf cent soixante-quinze dinars',
            1000000000 => 'un milliard de dinars',
        ] as $amount => $words) {
            $this->assertSame($words, AmountInWords::french((float) $amount), (string) $amount);
        }
    }

    public function test_centimes_and_returns(): void
    {
        $this->assertSame('mille cent dinars et cinquante centimes', AmountInWords::french(1100.50));
        $this->assertSame('un dinar et un centime', AmountInWords::french(1.01));
        // un bon de retour a un total negatif
        $this->assertSame('moins quatre mille quatre cents dinars', AmountInWords::french(-4400));
    }
}
