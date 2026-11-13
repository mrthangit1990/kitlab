<?php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'mysql' ||
            $app['config']->get('database.connections.mysql.database') !== 'kitlab_test') {
            throw new \RuntimeException('Tests require the dedicated kitlab_test MySQL database.');
        }
        return $app;
    }
}
