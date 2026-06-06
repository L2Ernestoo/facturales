<?php

namespace Lc\Fel\Providers;

use Illuminate\Support\ServiceProvider;
use Lc\Fel\Contracts\CertifierInterface;
use Lc\Fel\Contracts\FelConfigRepositoryInterface;

class FelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/fel.php', 'fel');

        foreach (config('fel.bindings', []) as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }

        $this->app->bind(CertifierInterface::class, function ($app) {
            $repository = $app->make(FelConfigRepositoryInterface::class);
            $company = $repository->company();
            $certifier = $company?->certifier ?: config('fel.default_certifier', 'guatefacturas');
            $class = config("fel.certifiers.{$certifier}");

            return $app->make($class ?: config('fel.certifiers.guatefacturas'));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/fel.php' => config_path('fel.php'),
        ], 'fel-config');

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}
