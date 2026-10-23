<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Database\NativeParameterConnectionFactory;
use App\Database\NativeParameterPostgresConnection;
use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use ReflectionProperty;
use Tests\TestCase;

final class NativeParameterDatabaseBindingTest extends TestCase
{
    public function test_application_factory_uses_native_select_and_keeps_configured_fallback(): void
    {
        self::assertInstanceOf(NativeParameterConnectionFactory::class, $this->app->make('db.factory'));
        self::assertTrue(config('database.connections.pgsql.native_select_parameters'));
        $manager = $this->app->make('db');
        try {
            foreach ([true, false] as $native) {
                $config = config('database.connections.pgsql');
                $config['native_select_parameters'] = $native;
                config(['database.connections.native_binding' => $config]);
                $connection = $manager->connection('native_binding');
                self::assertSame($native ? NativeParameterPostgresConnection::class : PostgresConnection::class, $connection::class);
                self::assertInstanceOf(Closure::class, $connection->getRawPdo());
                self::assertSame(7, $connection->selectOne('SELECT CAST(? AS integer) AS value', [7])->value);
                $manager->purge('native_binding');
            }
        } finally {
            $manager->purge('native_binding');
            config(['database.connections.native_binding' => null]);
        }
    }

    public function test_application_registration_preserves_an_existing_custom_factory(): void
    {
        $factory = new class($this->app) extends ConnectionFactory {};
        $this->app->instance('db.factory', $factory);
        (new AppServiceProvider($this->app))->register();
        self::assertSame($factory, $this->app->make('db.factory'));
    }

    public function test_factory_preserves_registered_resolver_and_other_drivers(): void
    {
        $factory = $this->app->make('db.factory');
        $sqlite = $factory->make(['driver' => 'sqlite', 'database' => ':memory:', 'native_select_parameters' => true], 'native_sqlite');
        self::assertSame(SQLiteConnection::class, $sqlite::class);
        self::assertInstanceOf(Closure::class, $sqlite->getRawPdo());
        $resolvers = new ReflectionProperty(Connection::class, 'resolvers');
        $previous = $resolvers->getValue();
        $custom = new PostgresConnection(static function (): never { throw new \LogicException('Resolver connection must remain lazy'); });
        $calls = 0;
        try {
            Connection::resolverFor('pgsql', static function () use ($custom, &$calls): PostgresConnection {
                $calls++;

                return $custom;
            });
            $config = config('database.connections.pgsql');
            $config['native_select_parameters'] = true;
            self::assertSame($custom, $factory->make($config, 'native_resolver'));
            self::assertSame(1, $calls);
            self::assertInstanceOf(Closure::class, $custom->getRawPdo());
        } finally {
            $resolvers->setValue(null, $previous);
        }
    }
}
