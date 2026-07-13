<?php

namespace Technikali\SsoClient\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Technikali\SsoClient\SsoClientServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SsoClientServiceProvider::class,
        ];
    }
}
