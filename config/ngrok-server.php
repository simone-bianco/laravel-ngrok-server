<?php

declare(strict_types=1);

return [
    'binary' => env('NGROK_SERVER_BINARY', 'ngrok'),
    'supervisor_php_binary' => env('NGROK_SERVER_PHP_BINARY'),
    'runtime_root' => env('NGROK_SERVER_RUNTIME_ROOT', storage_path('app/ngrok-server')),
    'upstream_url' => env('NGROK_SERVER_UPSTREAM_URL', 'http://127.0.0.1:8000'),
    'agent_api_port_min' => (int) env('NGROK_SERVER_AGENT_API_PORT_MIN', 4041),
    'agent_api_port_max' => (int) env('NGROK_SERVER_AGENT_API_PORT_MAX', 4099),
    'db_lock_timeout_ms' => (int) env('NGROK_SERVER_DB_LOCK_TIMEOUT_MS', 2000),
    'settings_reconcile_interval_ms' => (int) env('NGROK_SERVER_RECONCILE_INTERVAL_MS', 1000),
    'agent_api_startup_grace_ms' => (int) env('NGROK_SERVER_AGENT_API_STARTUP_GRACE_MS', 5000),
    'runtime_probe_failure_grace_ms' => (int) env('NGROK_SERVER_RUNTIME_PROBE_FAILURE_GRACE_MS', 30000),
    'runtime_identity_probe_timeout_ms' => (int) env('NGROK_SERVER_RUNTIME_IDENTITY_PROBE_TIMEOUT_MS', 5000),
    'runtime_identity_probe_degraded_interval_ms' => (int) env('NGROK_SERVER_RUNTIME_IDENTITY_PROBE_DEGRADED_INTERVAL_MS', 3000),
    'ready_observation_max_age_ms' => (int) env('NGROK_SERVER_READY_OBSERVATION_MAX_AGE_MS', 2000),
    'heartbeat_stale_after_ms' => (int) env('NGROK_SERVER_HEARTBEAT_STALE_AFTER_MS', 6000),
    'process_stop_grace_seconds' => (int) env('NGROK_SERVER_STOP_GRACE_SECONDS', 5),
    'launcher_shutdown_grace_seconds' => (int) env('NGROK_SERVER_LAUNCHER_SHUTDOWN_GRACE_SECONDS', 20),
    'manifest_schema_version' => 1,
    'ledger_schema_version' => 1,
    'snapshot_schema_version' => 1,
    'runtime_identity_protocol_version' => 1,
    'supervisor' => [
        'show_console' => (bool) env('NGROK_SERVER_SUPERVISOR_SHOW_CONSOLE', false),
    ],
    'commands' => [
        'doctor' => 'ngrok-server:doctor',
        'supervisor' => 'ngrok-server:ngrok-supervisor',
        'bootstrap' => 'ngrok-server:ngrok-bootstrap',
        'recover' => 'ngrok-server:ngrok-recover',
        'shutdown' => 'ngrok-server:ngrok-shutdown',
    ],
];
