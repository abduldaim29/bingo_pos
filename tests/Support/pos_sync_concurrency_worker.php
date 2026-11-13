<?php

use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\PosSyncUuidBarrier;

$basePath = dirname(__DIR__, 2);

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($input)) {
        throw new RuntimeException('Sync worker input must be a JSON object.');
    }

    require $basePath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
    $app = require $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    assertDisposableWorkerDatabase();

    $uuid = strtolower((string) ($input['uuid'] ?? ''));
    $workerId = (string) ($input['worker_id'] ?? '');
    $barrierDirectory = (string) ($input['barrier_directory'] ?? '');
    $barrierReached = false;

    $request = Request::create(
        '/api/v1/pos/transactions/sync',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.(string) ($input['token'] ?? ''),
        ],
        json_encode([
            'transactions' => [$input['payload'] ?? []],
        ], JSON_THROW_ON_ERROR)
    );
    $request->attributes->set(
        'offline_sync_uuid_precheck_barrier',
        static function (string $checkedUuid) use (
            $uuid,
            $workerId,
            $barrierDirectory,
            &$barrierReached
        ): void {
            if ($checkedUuid !== $uuid) {
                throw new RuntimeException('The sync worker reached the barrier for an unexpected UUID.');
            }

            $barrierReached = true;
            PosSyncUuidBarrier::waitForPeer($barrierDirectory, $workerId);
        }
    );

    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    if (! $barrierReached) {
        throw new RuntimeException('The UUID pre-check did not reach the synchronization barrier.');
    }

    $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    fwrite(STDOUT, json_encode([
        'pid' => getmypid(),
        'status_code' => $response->getStatusCode(),
        'body' => $body,
    ], JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

function assertDisposableWorkerDatabase(): void
{
    // These checks catch configuration mistakes; the workflow's disposable service is the isolation boundary.
    $runId = (string) getenv('GITHUB_RUN_ID');
    $runAttempt = (string) getenv('GITHUB_RUN_ATTEMPT');
    $expectedDatabase = "bingopos_pos_sync_{$runId}_{$runAttempt}";

    if (
        getenv('CI') !== 'true'
        || getenv('GITHUB_ACTIONS') !== 'true'
        || ! preg_match('/^[0-9]+$/', $runId)
        || ! preg_match('/^[0-9]+$/', $runAttempt)
        || config('database.default') !== 'pgsql'
    ) {
        throw new RuntimeException('The worker is not running in the isolated PostgreSQL CI job.');
    }

    $configuration = config('database.connections.pgsql');
    if (! is_array($configuration)) {
        throw new RuntimeException('The worker has no PostgreSQL connection configuration.');
    }

    $url = parse_url((string) $configuration['url']);
    $urlQuery = [];
    if (is_array($url)) {
        parse_str($url['query'] ?? '', $urlQuery);
    }

    if (
        $configuration['host'] !== '127.0.0.1'
        || (string) $configuration['port'] !== '5432'
        || $configuration['database'] !== $expectedDatabase
        || $configuration['username'] !== 'pos_sync_test'
        || ! is_array($url)
        || ($url['scheme'] ?? null) !== 'pgsql'
        || ($url['user'] ?? null) !== 'pos_sync_test'
        || ($url['host'] ?? null) !== '127.0.0.1'
        || (string) ($url['port'] ?? '') !== '5432'
        || ($url['path'] ?? null) !== '/'.$expectedDatabase
        || ($urlQuery['sslmode'] ?? null) !== 'prefer'
    ) {
        throw new RuntimeException('The worker database configuration is not the isolated CI target.');
    }

    DB::purge('pgsql');
    $database = DB::connection('pgsql');
    $identity = $database->selectOne(
        'SELECT current_database() AS database_name, current_user AS database_user, '
        .'inet_server_addr()::text AS server_address, inet_server_port() AS server_port'
    );

    if (
        $database->getDriverName() !== 'pgsql'
        || $database->getDatabaseName() !== $expectedDatabase
        || $identity->database_name !== $expectedDatabase
        || $identity->database_user !== 'pos_sync_test'
        || (string) $identity->server_port !== '5432'
        || $identity->server_address === '127.0.0.1'
        || filter_var($identity->server_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || filter_var(
            $identity->server_address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false
    ) {
        throw new RuntimeException('The worker connection is not the isolated PostgreSQL service.');
    }

    $role = $database->selectOne(
        'SELECT CASE WHEN rolcanlogin THEN 1 ELSE 0 END AS can_login, '
        .'CASE WHEN rolsuper THEN 1 ELSE 0 END AS is_superuser, '
        .'CASE WHEN rolcreatedb THEN 1 ELSE 0 END AS can_create_database, '
        .'CASE WHEN rolcreaterole THEN 1 ELSE 0 END AS can_create_roles, '
        .'CASE WHEN rolreplication THEN 1 ELSE 0 END AS can_replicate, '
        .'CASE WHEN rolbypassrls THEN 1 ELSE 0 END AS can_bypass_rls '
        .'FROM pg_roles WHERE rolname = current_user'
    );
    if (
        $role === null
        || (int) $role->can_login !== 1
        || (int) $role->is_superuser !== 0
        || (int) $role->can_create_database !== 0
        || (int) $role->can_create_roles !== 0
        || (int) $role->can_replicate !== 0
        || (int) $role->can_bypass_rls !== 0
    ) {
        throw new RuntimeException('The worker database role is not appropriately restricted.');
    }

    $owner = $database->selectOne(
        'SELECT pg_get_userbyid(datdba) AS database_owner '
        .'FROM pg_database WHERE datname = current_database()'
    );
    if ($owner === null || $owner->database_owner !== 'pos_sync_test') {
        throw new RuntimeException('The worker database is not owned by the restricted test role.');
    }

    $otherDatabaseAccess = $database->select(
        "SELECT datname FROM pg_database WHERE datallowconn "
        ."AND datname <> current_database() "
        ."AND has_database_privilege(current_user, datname, 'CONNECT')"
    );
    if ($otherDatabaseAccess !== []) {
        throw new RuntimeException('The worker role can connect to a database outside the test target.');
    }
}
