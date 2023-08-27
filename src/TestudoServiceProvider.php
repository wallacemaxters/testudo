<?php

namespace WallaceMaxters\Testudo;

use Illuminate\Support\ServiceProvider;

class TestudoServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole())
        {
            $this->commands(
                MakeModelTest::class,
            );
        }
    }
}