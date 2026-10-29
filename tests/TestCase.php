<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Corta antes de que los traits (DatabaseMigrations, etc.) toquen una BD que no sea la de tests.
     */
    protected function setUpTraits()
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with((string) $database, '_testing')) {
            throw new RuntimeException("Los tests deben correr contra una BD *_testing, no contra [{$database}].");
        }

        return parent::setUpTraits();
    }
}
