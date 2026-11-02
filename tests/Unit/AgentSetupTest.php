<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GescomAgent-Setup.exe est compile sur Windows (agent/setup/build.ps1) puis commite : le serveur ne peut pas le construire.
 * Ces tests empechent d'oublier de le recompiler, et gardent l'exe d'accord avec les scripts de l'agent.
 */
class AgentSetupTest extends TestCase
{
    private const AGENT = __DIR__.'/../../agent';

    /** Meme calcul que Get-SourceHash dans agent/setup/build.ps1. */
    private static function sourceHash(): string
    {
        $dir = self::AGENT.'/setup';
        $names = array_values(array_filter(scandir($dir), fn ($n) => is_file("$dir/$n") && $n !== 'build.sha256' && $n[0] !== '.'));
        sort($names, SORT_STRING);
        $data = '';
        foreach ($names as $n) {
            $data .= "== $n\n".str_replace("\r", '', file_get_contents("$dir/$n"));
        }

        return hash('sha256', $data);
    }

    /** @return array{source?: string, exe?: string} */
    private static function recorded(): array
    {
        $hashes = [];
        foreach (file(self::AGENT.'/setup/build.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$key, $value] = explode(' ', trim($line), 2);
            $hashes[$key] = $value;
        }

        return $hashes;
    }

    public function test_setup_exe_is_built_from_the_current_sources(): void
    {
        $rebuild = 'run agent\\setup\\build.ps1 on Windows, then commit agent/GescomAgent-Setup.exe and agent/setup/build.sha256';
        $this->assertSame(self::recorded()['source'] ?? null, self::sourceHash(), "agent/setup changed since the last build: $rebuild");
        $this->assertFileExists(self::AGENT.'/GescomAgent-Setup.exe');
        $this->assertSame(self::recorded()['exe'] ?? null, hash_file('sha256', self::AGENT.'/GescomAgent-Setup.exe'),
            "GescomAgent-Setup.exe does not match build.sha256: $rebuild");
    }

    public function test_setup_checks_the_same_columns_as_the_agent(): void
    {
        preg_match("/\\\$Fields = @\\(([^)]*)\\)/", file_get_contents(self::AGENT.'/agent.ps1'), $agent);
        preg_match('/RequiredFields = \{([^}]*)\}/', file_get_contents(self::AGENT.'/setup/Installer.cs'), $setup);
        $this->assertNotEmpty($agent);
        $this->assertNotEmpty($setup);
        $list = fn (string $s) => array_map(fn ($v) => trim($v, " '\""), explode(',', $s));
        $this->assertSame($list($agent[1]), $list($setup[1]), 'Installer.RequiredFields must match $Fields in agent.ps1');
    }

    public function test_setup_and_install_script_use_the_same_api_url(): void
    {
        preg_match("/\\\$DefaultApiUrl = '([^']+)'/", file_get_contents(self::AGENT.'/install.ps1'), $script);
        preg_match('/DefaultApiUrl = "([^"]+)"/', file_get_contents(self::AGENT.'/setup/Installer.cs'), $setup);
        $this->assertSame($script[1] ?? null, $setup[1] ?? null);
    }
}
