<?php

namespace App\Providers;

use App\Cache\EncryptedDatabaseStore;
use App\Models\RosterImportState;
use App\Session\EncryptedDatabaseSessionHandler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureBrevoMailer();

        View::composer(['welcome', 'pages::auth.register'], function (\Illuminate\View\View $view): void {
            $view->with([
                'hasImportedStudentList' => RosterImportState::isImported(RosterImportState::STUDENTS),
                'hasImportedTeacherList' => RosterImportState::isImported(RosterImportState::TEACHERS),
            ]);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
        $this->configureEncryptedPersistence();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->uncompromised()
            : null,
        );
    }

    private function configureEncryptedPersistence(): void
    {
        Cache::extend('database', function ($app, array $config) {
            $connection = $app['db']->connection($config['connection'] ?? null);
            $store = new EncryptedDatabaseStore(
                $connection,
                $config['table'] ?? 'cache',
                $config['prefix'] ?? config('cache.prefix'),
                $config['lock_table'] ?? 'cache_locks',
                $config['lock_lottery'] ?? [2, 100],
                $config['lock_timeout'] ?? 86400,
                $config['serializable_classes'] ?? config('cache.serializable_classes'),
            );
            $store->setLockConnection($app['db']->connection(
                $config['lock_connection'] ?? $config['connection'] ?? null
            ));

            return $app['cache']->repository($store, $config);
        });

        Session::extend('database', function ($app) {
            $connection = $app['db']->connection(config('session.connection'));

            return new EncryptedDatabaseSessionHandler(
                $connection,
                config('session.table'),
                (int) config('session.lifetime'),
                $app,
            );
        });
    }

    private function configureBrevoMailer(): void
    {
        Mail::extend('brevo', static function (array $config): BrevoApiTransport {
            $apiKey = $config['key'] ?? null;

            if (! is_string($apiKey) || $apiKey === '') {
                throw new InvalidArgumentException('Set BREVO_API_KEY to use the Brevo mailer.');
            }

            return new BrevoApiTransport($apiKey, HttpClient::create());
        });
    }
}
