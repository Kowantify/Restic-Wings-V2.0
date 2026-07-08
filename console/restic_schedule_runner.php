<?php

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Http\Controllers\Admin\Extensions\resticbackups\resticbackupsExtensionController;

function resticScheduleLog(string $message, array $context = []): void
{
    $line = '[' . Carbon::now()->toIso8601String() . '] ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context);
    }
    $line .= PHP_EOL;
    @file_put_contents(storage_path('logs/restic_schedule.log'), $line, FILE_APPEND);
}

$now = Carbon::now();

$schedules = \DB::table('restic_policies')
    ->where('schedule_enabled', true)
    ->get();

if ($schedules->isEmpty() && Schema::hasTable('restic_schedules')) {
    $schedules = \DB::table('restic_schedules')
        ->where('enabled', true)
        ->get();
}

foreach ($schedules as $schedule) {
    $server = \DB::table('servers')->where('uuid', $schedule->server_uuid)->first();
    if (!$server) {
        continue;
    }

    $intervalValue = (int) $schedule->interval_value;
    $intervalUnit = $schedule->interval_unit;

    if ($intervalValue < 1) {
        continue;
    }
    if ($intervalUnit === 'minutes' && $intervalValue < 30) {
        continue;
    }

    $rawLastRun = $schedule->schedule_last_run_at ?? $schedule->last_run_at ?? null;
    $lastRunAt = $rawLastRun ? Carbon::parse($rawLastRun) : null;
    $base = $lastRunAt ?: (!empty($schedule->updated_at) ? Carbon::parse($schedule->updated_at) : $now);

    switch ($intervalUnit) {
        case 'minutes':
            $dueAt = $base->copy()->addMinutes($intervalValue);
            break;
        case 'hours':
            $dueAt = $base->copy()->addHours($intervalValue);
            break;
        case 'days':
        default:
            $dueAt = $base->copy()->addDays($intervalValue);
            break;
    }

    if ($dueAt->greaterThan($now)) {
        continue;
    }

    resticScheduleLog('schedule_due', [
        'server_uuid' => $server->uuid,
        'interval_value' => $intervalValue,
        'interval_unit' => $intervalUnit,
        'last_run_at' => $rawLastRun,
        'due_at' => $dueAt->toIso8601String(),
    ]);

    $runAt = Carbon::now();
    if (\DB::table('restic_policies')->where('server_uuid', $server->uuid)->exists()) {
        $claimed = \DB::table('restic_policies')
            ->where('server_uuid', $server->uuid)
            ->where(function ($q) use ($dueAt) {
                $q->whereNull('schedule_last_run_at')
                  ->orWhere('schedule_last_run_at', '<=', $dueAt);
            })
            ->update(['schedule_last_run_at' => $runAt, 'updated_at' => $runAt]);
    } elseif (Schema::hasTable('restic_schedules')) {
        $claimed = \DB::table('restic_schedules')
            ->where('server_uuid', $server->uuid)
            ->where(function ($q) use ($dueAt) {
                $q->whereNull('last_run_at')
                  ->orWhere('last_run_at', '<=', $dueAt);
            })
            ->update(['last_run_at' => $runAt]);
    } else {
        $claimed = false;
    }
    if (empty($claimed)) {
        resticScheduleLog('schedule_claim_skipped', [
            'server_uuid' => $server->uuid,
            'due_at' => $dueAt->toIso8601String(),
        ]);
        continue;
    }

    resticScheduleLog('schedule_claimed', [
        'server_uuid' => $server->uuid,
        'run_at' => $runAt->toIso8601String(),
    ]);

    $lockPath = storage_path('framework/cache/restic_schedule_' . $server->uuid . '.lock');
    $lockHandle = @fopen($lockPath, 'c');
    if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if ($lockHandle) {
            fclose($lockHandle);
        }
        continue;
    }

    if (!empty($server->owner_id)) {
        auth()->loginUsingId($server->owner_id);
    }

    try {
        $multiplierValue = \DB::table('settings')->where('key', 'restic.max_repo_multiplier')->value('value');
        $repoMultiplier = is_numeric($multiplierValue) ? (int) $multiplierValue : 2;
        if ($repoMultiplier < 1) {
            $repoMultiplier = 1;
        }
        $diskLimitMb = isset($server->disk) ? (int) $server->disk : null;
        $maxRepoBytes = ($diskLimitMb && $diskLimitMb > 0)
            ? ($diskLimitMb * 1024 * 1024 * $repoMultiplier)
            : null;

        $payload = [];
        if (is_numeric($server->backup_limit)) {
            $payload['max_backups'] = (int) $server->backup_limit;
        }
        if ($maxRepoBytes !== null) {
            $payload['max_repo_bytes'] = $maxRepoBytes;
        }

        $controller = app(resticbackupsExtensionController::class);
        $response = $controller->createBackup($server->uuid, Request::create('/', 'POST', $payload));

        $statusCode = $response ? $response->getStatusCode() : null;
        $body = $response ? (string) $response->getContent() : '';
        resticScheduleLog('schedule_backup_response', [
            'server_uuid' => $server->uuid,
            'status' => $statusCode,
            'body_bytes' => strlen($body),
        ]);

        if ($response && $response->getStatusCode() < 300) {
            if (\DB::table('restic_policies')->where('server_uuid', $server->uuid)->exists()) {
                \DB::table('restic_policies')
                    ->where('server_uuid', $server->uuid)
                    ->update(['schedule_last_run_at' => $runAt, 'updated_at' => $runAt]);
            } elseif (Schema::hasTable('restic_schedules')) {
                \DB::table('restic_schedules')
                    ->where('server_uuid', $server->uuid)
                    ->update(['last_run_at' => $runAt]);
            }
        } elseif ($response && $response->getStatusCode() === 409) {
            // Repo busy: back off so we don't hammer and re-lock immediately.
            if (\DB::table('restic_policies')->where('server_uuid', $server->uuid)->exists()) {
                \DB::table('restic_policies')
                    ->where('server_uuid', $server->uuid)
                    ->update(['schedule_last_run_at' => $runAt, 'updated_at' => $runAt]);
            } elseif (Schema::hasTable('restic_schedules')) {
                \DB::table('restic_schedules')
                    ->where('server_uuid', $server->uuid)
                    ->update(['last_run_at' => $runAt]);
            }
        } elseif ($response) {
            // Any other error: back off to avoid constant retries.
            if (\DB::table('restic_policies')->where('server_uuid', $server->uuid)->exists()) {
                \DB::table('restic_policies')
                    ->where('server_uuid', $server->uuid)
                    ->update(['schedule_last_run_at' => $runAt, 'updated_at' => $runAt]);
            } elseif (Schema::hasTable('restic_schedules')) {
                \DB::table('restic_schedules')
                    ->where('server_uuid', $server->uuid)
                    ->update(['last_run_at' => $runAt]);
            }
        }
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
