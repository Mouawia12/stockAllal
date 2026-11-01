<?php

namespace Tests\Unit;

use App\Models\BranchStock;
use App\Models\InstallCode;
use PHPUnit\Framework\TestCase;

class CodesTest extends TestCase
{
    public function test_code_key_only_trims_and_uppercases(): void
    {
        $this->assertSame('820AC', BranchStock::keyFor(' 820ac '));
        $this->assertSame('820-AC', BranchStock::keyFor('820-ac'));
        $this->assertSame('R A', BranchStock::keyFor('R A  '));
    }

    public function test_install_code_hash_ignores_case_and_separators(): void
    {
        $this->assertSame(InstallCode::hash('ABCDEFGHJ'), InstallCode::hash('abc-def ghj'));
        $this->assertNotSame(InstallCode::hash('ABCDEFGHJ'), InstallCode::hash('ABCDEFGHK'));
    }
}
