# Installation And Configuration

## Install

```bash
composer require simone-bianco/laravel-ngrok-server
php artisan vendor:publish --tag=ngrok-server-config
```

Laravel auto-discovers `LaravelNgrokServerServiceProvider`. The package requires PHP 8.4+, Laravel 13, Symfony Process 7.4.5+ or 8.0.5+, and a writable installation-stable runtime root. Windows process ownership additionally requires PHP `ext-ffi`.

Publishing creates `config/ngrok-server.php`. Keep one configuration authority: override the published `ngrok-server` keys rather than introducing a second ngrok config namespace.

## Required Host Adapters

The host must bind each interface below before running operational commands. `ngrok-server:doctor` reports missing bindings and container construction failures.

| Contract | Host responsibility |
| --- | --- |
| `NgrokRealtimeStarter` | Start the host's realtime delivery surface after desired state commits. |
| `NgrokRemoteAccessPolicy` | Decide whether remote access is allowed by application policy. |
| `NgrokDesiredStateStore` | Atomically change desired state and return its committed version and changed flag. |
| `NgrokDirectStatusProjection` | Project direct host status without exposing credentials or control authority. |
| `NgrokRuntimeIdentityVerifier` | Verify runtime identity evidence used by the package. |
| `NgrokRuntimeLifecycleFence` | Serialize settings and process lifecycle transitions, normally with a database-backed fence. |
| `NgrokRuntimeRemoteAccessPolicy` | Enforce runtime/debug restrictions before launch and Ready publication. |
| `NgrokRuntimeSettingsSnapshotProvider` | Capture one coherent versioned settings snapshot, including resolved secrets. |

Bind adapters in a host service provider, then run:

```bash
php artisan ngrok-server:doctor
```

The doctor also constructs `NgrokRuntimeControlService`, `NgrokRuntimeRecoveryService`, `NgrokSupervisor`, and `NgrokRuntimeStatusService`. On Windows it fails when `ext-ffi` is unavailable.

## Configuration Reference

| Environment variable | Default | Purpose |
| --- | --- | --- |
| `NGROK_SERVER_BINARY` | `ngrok` | Executable name or absolute path. |
| `NGROK_SERVER_PHP_BINARY` | current PHP | PHP binary used for the detached supervisor. |
| `NGROK_SERVER_RUNTIME_ROOT` | `storage/app/ngrok-server` | Installation-scoped durable artifacts. |
| `NGROK_SERVER_UPSTREAM_URL` | `http://127.0.0.1:8000` | Loopback application upstream. |
| `NGROK_SERVER_AGENT_API_PORT_MIN` | `4041` | First loopback Agent API candidate port. |
| `NGROK_SERVER_AGENT_API_PORT_MAX` | `4099` | Last loopback Agent API candidate port. |
| `NGROK_SERVER_DB_LOCK_TIMEOUT_MS` | `2000` | Host lifecycle fence acquisition budget. |
| `NGROK_SERVER_RECONCILE_INTERVAL_MS` | `1000` | Supervisor reconciliation interval. |
| `NGROK_SERVER_AGENT_API_STARTUP_GRACE_MS` | `5000` | Agent API startup grace. |
| `NGROK_SERVER_READY_OBSERVATION_MAX_AGE_MS` | `2000` | Maximum Ready evidence age. |
| `NGROK_SERVER_HEARTBEAT_STALE_AFTER_MS` | `6000` | Status heartbeat staleness threshold. |
| `NGROK_SERVER_STOP_GRACE_SECONDS` | `5` | Owned child graceful-stop budget. |
| `NGROK_SERVER_LAUNCHER_SHUTDOWN_GRACE_SECONDS` | `20` | Launcher shutdown acknowledgement budget. |
| `NGROK_SERVER_SUPERVISOR_SHOW_CONSOLE` | `false` | Windows-only local diagnostic console switch. |

Command names are also configurable under `ngrok-server.commands`. Hosts may preserve an existing public Artisan surface by mapping these entries without wrapping or duplicating package behavior.

## Runtime Root Rules

- Use one stable path per installation, not a temporary or release-specific directory.
- Keep it writable only by the application runtime identity.
- Do not use configuration deployment to reset, migrate, or fabricate manifests, ledgers, gates, snapshots, shutdown requests, or acknowledgements.
- Do not share one runtime root between installations.
- Back up or migrate durable artifacts only through a reviewed installation migration procedure while desired state is disabled.
