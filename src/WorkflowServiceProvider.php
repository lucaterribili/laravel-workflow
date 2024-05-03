<?php

namespace LucaTerribili\LaravelWorkflow;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;

class WorkflowServiceProvider extends ServiceProvider
{
    protected $commands = [
        'LucaTerribili\LaravelWorkflow\Commands\WorkflowDumpCommand',
    ];

    /**
     * Bootstrap the application services...
     *
     * @return void
     */
    public function boot(): void
    {
        $configPath = $this->configPath();
        $databasePath = $this->databasePath();
        $this->publishes([
            "{$configPath}/workflow.php" => $this->publishPath('workflow.php'),
            "{$configPath}/workflow_registry.php" => $this->publishPath('workflow_registry.php'),
        ], 'config');
        $this->publishes([
            "{$databasePath}/migrations/" => database_path('migrations'),
        ], 'migrations');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            $this->configPath() . '/workflow_registry.php',
            'workflow_registry'
        );

        $this->commands($this->commands);

        $this->app->singleton('workflow', function ($app) {
            $workflowConfigs = $app->make('config')->get('workflow', []);
            $registryConfig = $app->make('config')->get('workflow_registry');

            return new WorkflowRegistry($workflowConfigs, $app->make(Dispatcher::class), $registryConfig);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['workflow'];
    }

    /**
     * @return string
     */
    protected function configPath()
    {
        return __DIR__ . '/../config';
    }

    /**
     * @return string
     */
    protected function databasePath()
    {
        return __DIR__ . '/../database';
    }

    protected function publishPath($configFile)
    {
        return (function_exists('config_path'))
            ? config_path($configFile)
            : base_path('config/' . $configFile);
    }
}
