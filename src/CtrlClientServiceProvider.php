<?php

namespace Phnxdgtl\CtrlClient;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

use Phnxdgtl\CtrlClient\CtrlToken;
use Phnxdgtl\CtrlClient\CtrlCommand;

class CtrlClientServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {

        $this->commands([
            CtrlCommand::class,
        ]);

        include __DIR__.'/routes.php';
        $this->loadViewsFrom(__DIR__.'/views', 'ctrl');

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('ctrl-client', CtrlToken::class);
    }
}
