<?php

namespace Tests;

use App\Support\Tenancy\ClientScope;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ClientScope::set(null);
    }
}
