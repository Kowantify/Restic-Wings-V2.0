# iByte Restic Backups 2.0 Admin Guide

This version keeps the Panel plugin as the security authority and moves Restic execution into a small isolated Wings add-on.

## Architecture

Browser users only call Panel extension routes. Panel validates Pterodactyl permissions, resolves the server owner and encryption key, then calls Wings with the existing daemon bearer token.

```text
Browser -> Panel extension -> Wings daemon auth -> /api/servers/{server}/restic -> restic CLI
```

The frontend never receives encryption keys, daemon tokens, node URLs, or repo paths.

## Wings Backend

V2 uses the modular Wings add-on package:

```text
wings-addon/ibyte/restic
```

The only Wings router patch is:

```go
ibyterestic.Register(server.Group("/restic"))
```

plus the matching import.

## Build Custom Wings

On a Linux build machine with `git`, `curl`, `go`, `make`, and `patch`:

```bash
cd iByte-ResticBackupsV2.0
bash wings-addon/scripts/build-wings-restic.sh
```

Pin a Wings release if desired:

```bash
WINGS_VERSION=v1.13.1 bash wings-addon/scripts/build-wings-restic.sh
```

The binary is created under:

```text
dist/wings-restic-{version}
```

Install it as `/usr/local/bin/wings` during a normal Wings maintenance window, then restart Wings.

## Node Requirements

Each node needs:

- the custom Wings binary built by this extension
- `restic` installed and available in `PATH`
- normal Panel-to-Wings daemon connectivity

No extra public port is required. Users do not connect directly to the Restic backend.

## Retention

Manual pruning settings were removed. After every successful backup, Wings keeps the newest `backup_limit` snapshots and deletes the oldest unlocked snapshots.

Locked snapshots are skipped. If all old snapshots are locked and the limit cannot be satisfied, the backup job fails instead of deleting protected data.

## Encryption Keys

Panel stores the active per-server key and key history. Panel sends the active key to Wings only over daemon-authenticated server-to-server requests as JSON. Keys are passed to Restic through `RESTIC_PASSWORD`, not command arguments.

## User Features

Users can:

- create backups
- list backups
- download prepared archives through the Panel
- restore snapshots
- delete unlocked snapshots
- lock or unlock snapshots
- save notes
- choose a backup schedule

## Admin Features

Admins can:

- view active keys
- view key history
- regenerate keys
- delete repos
- check repo existence and size
- run repo health checks
- review failed job history

## Security Notes

- Panel is the only public API surface for users.
- Wings routes require existing daemon authorization and `ServerExists()` middleware.
- No shell is used to run Restic.
- Snapshot IDs and owner usernames are allowlist validated.
- Repo and volume paths are derived from server UUID and checked under fixed roots.
- Download archives are prepared under a fixed temp directory and streamed through Panel.
