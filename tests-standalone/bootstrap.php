<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;

$package = dirname(__DIR__);
$projectRoot = dirname($package, 3);
$sourceRoot = getenv('NGROK_STANDALONE_SRC') ?: $package.'/src';

require $projectRoot.'/vendor/autoload.php';

// The package is installed in vendor with symlink=false. Point the standalone
// suite at the canonical package source (or an explicit comparison source) so
// the tests exercise edited code.
spl_autoload_register(static function (string $class) use ($sourceRoot): void {
    $prefix = 'SimoneBianco\\LaravelNgrokServer\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $sourceRoot.'/'.str_replace('\\', '/', $relative).'.php';
    if (is_file($path)) {
        require $path;
    }
}, true, true);

spl_autoload_register(static function (string $class) use ($package): void {
    $prefix = 'SimoneBianco\\LaravelNgrokServer\\TestsStandalone\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $package.'/tests-standalone/'.str_replace('\\', '/', $relative).'.php';
    if (is_file($path)) {
        require $path;
    }
}, true, true);

require $package.'/tests/Support/NgrokRuntimeFakes.php';

$container = new Container;
$container->instance('config', new Repository([
    'app' => ['url' => 'https://approved.ngrok.app'],
    'ngrok-server' => [
        'runtime_root' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'ngrok-standalone-bootstrap',
        'upstream_url' => 'http://127.0.0.1:8000',
        'agent_api_port_min' => 4098,
        'agent_api_port_max' => 4099,
        'settings_reconcile_interval_ms' => 1000,
        'agent_api_startup_grace_ms' => 5000,
        'runtime_probe_failure_grace_ms' => 30000,
        'runtime_identity_probe_timeout_ms' => 5000,
        'runtime_identity_probe_degraded_interval_ms' => 3000,
        'ready_observation_max_age_ms' => 2000,
        'heartbeat_stale_after_ms' => 6000,
        'process_stop_grace_seconds' => 5,
        'manifest_schema_version' => 1,
        'ledger_schema_version' => 1,
        'snapshot_schema_version' => 1,
        'runtime_identity_protocol_version' => 1,
        'supervisor_php_binary' => PHP_BINARY,
    ],
]));
Container::setInstance($container);
