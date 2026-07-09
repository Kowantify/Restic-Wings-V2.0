<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Admin\Extensions\resticbackups\resticbackupsExtensionController;

Route::middleware(['auth'])->group(function () {
    // GET backups (forward to node) - now outside /api/client and as controller method for uniformity
    Route::get('/servers/{server}/backups/restic', [resticbackupsExtensionController::class, 'listBackups']);

    // Get restic repo stats (forward to node)
    Route::get('/servers/{server}/backups/restic/stats', [resticbackupsExtensionController::class, 'getResticStats']);

    // Get restic backup status (forward to node)
    Route::get('/servers/{server}/backups/restic/status', [resticbackupsExtensionController::class, 'getResticStatus']);

    // Check if restic repo exists (forward to node)
    Route::get('/servers/{server}/backups/restic/repo/exists', [resticbackupsExtensionController::class, 'getResticRepoExists']);

    // Get restic repo disk usage (forward to node)
    Route::get('/servers/{server}/backups/restic/repo/size', [resticbackupsExtensionController::class, 'getResticRepoDiskUsage']);

    // Run restic repo health check (forward to node)
    Route::post('/servers/{server}/backups/restic/repo/check', [resticbackupsExtensionController::class, 'runResticRepoHealthCheck']);
    Route::get('/servers/{server}/backups/restic/repo/check/status', [resticbackupsExtensionController::class, 'getResticRepoHealthStatus']);

    // Download a specific backup
    Route::get('/servers/{server}/backups/restic/{backupId}/download', [resticbackupsExtensionController::class, 'downloadBackup']);
    Route::get('/servers/{server}/backups/restic/{backupId}/download/stream', [resticbackupsExtensionController::class, 'downloadBackupStream']);
    Route::post('/servers/{server}/backups/restic/{backupId}/download/prepare', [resticbackupsExtensionController::class, 'downloadBackupPrepare']);
    Route::get('/servers/{server}/backups/restic/{backupId}/download/status', [resticbackupsExtensionController::class, 'downloadBackupStatus']);

    // Restore a specific backup
    Route::post('/servers/{server}/backups/restic/{backupId}/restore', [resticbackupsExtensionController::class, 'restoreBackup']);
    Route::get('/servers/{server}/backups/restic/restore/status', [resticbackupsExtensionController::class, 'getResticRestoreStatus']);
    // Delete a specific backup
    Route::delete('/servers/{server}/backups/restic/{backupId}', [resticbackupsExtensionController::class, 'deleteBackup']);

    // Admin index view
    Route::post('/servers/{server}/backups/restic', [resticbackupsExtensionController::class, 'createBackup']);

    // Restic schedule settings
    Route::get('/servers/{server}/backups/restic/schedule', [resticbackupsExtensionController::class, 'getResticSchedule']);
    Route::post('/servers/{server}/backups/restic/schedule', [resticbackupsExtensionController::class, 'saveResticSchedule']);

    // Restic settings
    Route::get('/settings', [resticbackupsExtensionController::class, 'getResticSettings']);
    Route::get('/servers/{server}/backups/restic/limits', [resticbackupsExtensionController::class, 'getResticLimits']);

    // Lock/unlock a backup to prevent pruning
    Route::post('/servers/{server}/backups/restic/{backupId}/lock', [resticbackupsExtensionController::class, 'lockBackup']);
    Route::post('/servers/{server}/backups/restic/{backupId}/unlock', [resticbackupsExtensionController::class, 'unlockBackup']);
    Route::get('/servers/{server}/backups/restic/locks', [resticbackupsExtensionController::class, 'getResticLocks']);
    Route::post('/servers/{server}/backups/restic/unlock', [resticbackupsExtensionController::class, 'unlockResticRepo']);


    // Backup notes
    Route::post('/servers/{server}/backups/restic/{backupId}/note', [resticbackupsExtensionController::class, 'saveBackupNote']);


    Route::post('/download-script', [resticbackupsExtensionController::class, 'downloadScript'])->name('admin.extensions.resticbackups.downloadScript');

    Route::get('/admin/encryption-key', [resticbackupsExtensionController::class, 'adminGetEncryptionKey']);
    Route::get('/admin/key-history', [resticbackupsExtensionController::class, 'adminGetKeyHistory']);

    Route::post('/admin/servers/{server}/archive-delete', [resticbackupsExtensionController::class, 'archiveAndDeleteServer'])
        ->name('extensions.resticbackups.archiveDeleteServer');

    Route::post('/admin/extensions/resticbackups', [resticbackupsExtensionController::class, 'post'])
        ->name('extensions.resticbackups.post');
});
