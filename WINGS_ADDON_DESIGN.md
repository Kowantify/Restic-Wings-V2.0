# iByte Restic Backups 2.0 - Modular Wings Backend

This version keeps the Panel plugin as the security authority and moves Restic execution into an isolated Wings add-on package.

## Design

Browser users only call Panel routes. Panel validates permissions, resolves the server owner and encryption key, then calls Wings with the existing daemon bearer token.

Wings receives only Panel-authenticated requests and runs fixed Restic CLI commands from the isolated add-on package.

```text
Browser -> Panel -> Wings daemon auth -> /api/servers/{server}/restic -> restic CLI
```

## Wings source changes

The add-on is isolated under:

```text
wings-addon/ibyte/restic
```

The only Wings core patch is:

```go
ibyterestic.Register(server.Group("/restic"))
```

plus the matching import.

## Target Wings release

Default target is the latest official GitHub release. As of this scaffold, GitHub marks `v1.13.1` as latest.

The build script falls back to `v1.13.1` if the latest-release API is unavailable.

## Build

On a Linux build machine with `git`, `curl`, `go`, and `make` installed:

```bash
cd iByte-ResticBackupsV2.0
bash wings-addon/scripts/build-wings-restic.sh
```

Pin a version explicitly:

```bash
WINGS_VERSION=v1.13.1 bash wings-addon/scripts/build-wings-restic.sh
```

The custom binary is written to:

```text
dist/wings-restic-{version}
```

## Restic route surface

All routes require existing Wings daemon auth and `ServerExists()` middleware because they are registered under `/api/servers/:server`.

```text
POST   /api/servers/{server}/restic/backups
POST   /api/servers/{server}/restic/backups/list
GET    /api/servers/{server}/restic/backups/status
POST   /api/servers/{server}/restic/backups/{snapshot}/restore
GET    /api/servers/{server}/restic/restore/status
POST   /api/servers/{server}/restic/backups/{snapshot}/lock
POST   /api/servers/{server}/restic/backups/{snapshot}/unlock
DELETE /api/servers/{server}/restic/backups/{snapshot}
POST   /api/servers/{server}/restic/backups/{snapshot}/prepare
GET    /api/servers/{server}/restic/backups/{snapshot}/prepare/status
GET    /api/servers/{server}/restic/backups/{snapshot}/download
POST   /api/servers/{server}/restic/stats
POST   /api/servers/{server}/restic/check
GET    /api/servers/{server}/restic/check/status
POST   /api/servers/{server}/restic/unlock
POST   /api/servers/{server}/restic/repo/exists
POST   /api/servers/{server}/restic/repo/size
DELETE /api/servers/{server}/restic/repo
```

## Simplified pruning rule

There is no pruning UI. After a successful backup, the add-on enforces the server backup limit passed by Panel:

```text
keep newest N snapshots
skip snapshots tagged `locked`
prune older unlocked snapshots
```

If every older snapshot is locked and the limit cannot be satisfied, the backup is reported as failed.

## Security rules

- Frontend never receives encryption keys, daemon tokens, repo paths, or node URLs.
- Panel-to-Wings requests that require an encryption key use JSON bodies, not query strings.
- Panel validates user/server permissions before calling Wings.
- Wings add-on trusts only existing Wings daemon bearer auth.
- Restic password is passed via `RESTIC_PASSWORD`, never command args.
- No shell execution is used.
- Snapshot IDs and owner usernames are strict allowlist validated.
- Repo and volume paths are derived from server UUID and checked to stay inside fixed roots.
- Downloads are prepared to a fixed temp root and should be streamed through Panel.

## Next implementation step

Panel controller backend calls target:

```text
/api/servers/{server}/restic
```

Keep the browser-facing Panel routes unchanged.
