<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Les scripts PowerShell de l'agent et de ses outils (CLAUDE.md) : ASCII seulement (PowerShell 5.1 lit un fichier sans BOM
 * en ANSI), et jamais d'ecriture dans la base Gescomm : un script qui lance isql ouvre une transaction READ ONLY, et aucun
 * ne contient d'ordre SQL qui ecrit. Le meme controle tourne sous Windows (agent/tests/run-tests.ps1), ici sans Windows.
 */
class AgentScriptsTest extends TestCase
{
    /** @return list<string> */
    private function scripts(): array
    {
        $root = dirname(__DIR__, 2).'/agent';

        return array_merge(glob("{$root}/*.ps1"), glob("{$root}/setup/*.ps1"), glob("{$root}/tools/*.ps1"), glob("{$root}/tests/*.ps1"));
    }

    public function test_every_script_is_ascii_only(): void
    {
        $this->assertNotEmpty($this->scripts());
        foreach ($this->scripts() as $file) {
            $this->assertSame(0, preg_match('/[^\x00-\x7F]/', file_get_contents($file)), basename($file).' contient un caractere non ASCII');
        }
    }

    public function test_scripts_that_run_isql_read_only_and_never_write(): void
    {
        $runners = 0;
        foreach ($this->scripts() as $file) {
            $source = file_get_contents($file);
            if (preg_match('/Start-Process\s+-FilePath\s+\$(Isql|cfg\.isql)\b/i', $source)) {
                $runners++;
                $this->assertStringContainsString("'SET TRANSACTION READ ONLY", $source, basename($file).' lance isql sans transaction en lecture seule');
            }
            $this->assertSame(0, preg_match('/\b(INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+[A-Z0-9_$]+\s+SET|ALTER\s+(TABLE|PROCEDURE|TRIGGER)|DROP\s+(TABLE|PROCEDURE|TRIGGER|INDEX)|CREATE\s+(TABLE|PROCEDURE|TRIGGER|INDEX))\b/i', $source, $m),
                basename($file).' contient un ordre SQL qui ecrit : '.($m[0] ?? ''));
        }
        // l'agent, dump-schema et check-documents
        $this->assertGreaterThanOrEqual(3, $runners);
    }
}
