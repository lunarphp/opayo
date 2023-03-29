<?php

namespace Lunar\Opayo;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Lunar\Facades\Payments;
use Lunar\Opayo\Components\PaymentForm;

class OpayoServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // Register our payment type.
        Payments::extend('opayo', function ($app) {
            return $app->make(OpayoPaymentType::class);
        });

        $this->app->singleton(OpayoInterface::class, function ($app) {
            return $app->make(Opayo::class);
        });

        $this->mergeConfigFrom(__DIR__.'/../config/opayo.php', 'lunar.opayo');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Blade::directive('opayoScripts', function () {
            $url = 'https://pi-test.sagepay.com/api/v1/js/sagepay.js';

            $manifest = json_decode(file_get_contents(__DIR__.'/../dist/mix-manifest.json'), true);

            $jsUrl = asset('/vendor/opayo'.$manifest['/opayo.js']);

            if (config('services.opayo.env', 'test') == 'live') {
                $url = 'https://pi-live.sagepay.com/api/v1/js/sagepay.js';
            }

            $manifest = json_decode(file_get_contents(__DIR__.'/../dist/mix-manifest.json'), true);

            $jsUrl = asset('/vendor/lunar'.$manifest['/opayo.js']);

            return  <<<EOT
                <script src="{$jsUrl}"></script>
                <script src="{$url}"></script>
            EOT;
        });

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lunar');

        $this->publishes([
            __DIR__.'/../config/opayo.php' => config_path('lunar/opayo.php'),
        ], 'lunar.opayo.config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/lunar'),
        ], 'lunar.opayo.components');

        $this->publishes([
            __DIR__.'/../dist' => public_path('vendor/lunar'),
        ], 'lunar.opayo.public');

        // Register the stripe payment component.
        Livewire::component('opayo.payment', PaymentForm::class);
    }
}
