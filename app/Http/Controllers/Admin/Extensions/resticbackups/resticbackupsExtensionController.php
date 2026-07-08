<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\resticbackups;

use Illuminate\View\View;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Models\Permission;
use Illuminate\Support\Facades\Schema;

class resticbackupsExtensionController extends Controller
{
    private static function recordResticKeyHistory(string $serverUuid, ?string $ownerUsername, string $encryptionKey, $createdAt = null): void
    {
        if (!$serverUuid || !$encryptionKey) {
            return;
        }

        $exists = \DB::table('restic_key_history')
            ->where('server_uuid', $serverUuid)
            ->where('encryption_key', $encryptionKey)
            ->where(function ($q) use ($ownerUsername) {
                if ($ownerUsername === null) {
                    $q->whereNull('owner_username');
                } else {
                    $q->where('owner_username', $ownerUsername);
                }
            })
            ->exists();

        if ($exists) {
            return;
        }

        \DB::table('restic_key_history')->insert([
            'server_uuid' => $serverUuid,
            'owner_username' => $ownerUsername,
            'encryption_key' => $encryptionKey,
            'created_at' => $createdAt ?: now(),
        ]);
    }

    private function getRepoMultiplier(): int
    {
        $multiplierValue = \DB::table('settings')->where('key', 'restic.max_repo_multiplier')->value('value');
        $repoMultiplier = is_numeric($multiplierValue) ? (int) $multiplierValue : 2;
        return $repoMultiplier < 1 ? 1 : $repoMultiplier;
    }

    private function decodeResponseBody($response): array
    {
        $rawBody = (string) $response->getBody();
        $respBody = json_decode($rawBody, true);
        if ($respBody === null && $rawBody !== '') {
            $respBody = ['error' => 'Unexpected response from Wings', 'output' => $rawBody];
        }
        return $respBody ?: ['error' => 'Unknown error from Wings'];
    }

    private function isAdminUser($user): bool
    {
        return (bool) ($user->root_admin ?? false);
    }

    private function requireAdmin($user): void
    {
        if (!$this->isAdminUser($user)) {
            abort(403, 'This action is restricted to administrators.');
        }
    }

    private function scrubSecrets($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $keyText = is_string($key) ? strtolower($key) : '';
                if ($keyText !== '' && preg_match('/token|secret|password|passphrase|encryption|daemon/i', $keyText)) {
                    $out[$key] = '[redacted]';
                    continue;
                }
                $out[$key] = $this->scrubSecrets($item);
            }
            return $out;
        }

        if (is_string($value) && strlen($value) > 10000) {
            return substr($value, 0, 10000) . '...';
        }

        return $value;
    }

    private function respondWings($response, $user, string $fallbackMessage)
    {
        if (!$response) {
            return response()->json(['error' => $fallbackMessage], 500);
        }

        $statusCode = $response->getStatusCode();
        $respBody = $this->decodeResponseBody($response);

        if ($statusCode >= 400 && !$this->isAdminUser($user)) {
            return response()->json(['error' => $fallbackMessage], $statusCode);
        }

        if (!$this->isAdminUser($user) && is_array($respBody)) {
            $respBody = $this->scrubSecrets($respBody);
        }

        return response()->json($respBody, $statusCode);
    }

    private function exceptionResponse($user, string $fallbackMessage, \Throwable $e)
    {
        if ($this->isAdminUser($user)) {
            return response()->json([
                'error' => $fallbackMessage,
                'details' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'error' => $fallbackMessage,
        ], 500);
    }

    private function recordJobFailure(string $serverUuid, string $jobType, ?string $message, array $details = []): void
    {
        if (!$serverUuid || !$jobType) {
            return;
        }

        try {
            \DB::table('restic_job_history')->insert([
                'server_uuid' => $serverUuid,
                'job_type' => $jobType,
                'status' => 'failed',
                'message' => $message,
                'details' => !empty($details) ? json_encode($details) : null,
                'started_at' => now(),
                'finished_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to record restic job failure: ' . $e->getMessage());
        }
    }

    private function getServerContext(string $server, $user)
    {
        $serverRow = \DB::table('servers')
            ->where('uuid', $server)
            ->orWhere('uuidShort', $server)
            ->first();

        if (!$serverRow) {
            return response()->json(['error' => 'Server not found'], 404);
        }

        $isAdmin = (bool) ($user->root_admin ?? false);
        $isOwner = ($serverRow->owner_id ?? null) == ($user->id ?? null);
        $subuserRow = null;
        $isSubuser = false;
        $subuserPermissions = [];
        if (!$isAdmin && !$isOwner) {
            $subuserRow = \DB::table('subusers')
                ->where('server_id', $serverRow->id)
                ->where('user_id', $user->id)
                ->first();
            $isSubuser = (bool) $subuserRow;
            if ($subuserRow && isset($subuserRow->id)) {
                $subuserPermissions = \DB::table('permissions')
                    ->where('subuser_id', $subuserRow->id)
                    ->pluck('permission')
                    ->filter(fn ($permission) => is_string($permission) && $permission !== '')
                    ->values()
                    ->all();
            }
        }
        if (!$isAdmin && !$isOwner && !$isSubuser) {
            return response()->json(['error' => 'You do not have access to this server'], 403);
        }

        $node = \DB::table('nodes')->where('id', $serverRow->node_id)->first();
        if (!$node) {
            return response()->json(['error' => 'Node not found'], 404);
        }

        $port = isset($node->daemon_listen)
            ? $node->daemon_listen
            : (isset($node->daemonListen) ? $node->daemonListen : 8080);
        $nodeApiUrl = 'https://' . $node->fqdn . ':' . $port;

        $encryptedToken = $node->daemon_token ?? null;
        if (!$encryptedToken) {
            return response()->json(['error' => 'Node daemon token missing'], 500);
        }
        try {
            $token = app('encrypter')->decrypt($encryptedToken);
        } catch (\Exception $e) {
            if ($this->isAdminUser($user)) {
                return response()->json(['error' => 'Failed to decrypt daemon token', 'details' => $e->getMessage()], 500);
            }
            return response()->json(['error' => 'Failed to decrypt daemon token'], 500);
        }

        $fullUuid = $serverRow->uuid;
        $encryptionKey = \DB::table('restic')->where('server_uuid', $fullUuid)->value('encryption_key');
        $ownerUsername = $this->getResticOwnerUsername($serverRow, $user);

        return [
            'serverRow' => $serverRow,
            'node' => $node,
            'token' => $token,
            'nodeApiUrl' => $nodeApiUrl,
            'fullUuid' => $fullUuid,
            'encryptionKey' => $encryptionKey,
            'ownerUsername' => $ownerUsername,
            'isAdmin' => $isAdmin,
            'isOwner' => $isOwner,
            'isSubuser' => $isSubuser,
            'subuserPermissions' => $subuserPermissions,
        ];
    }

    private function requireSubuserPermission(array $ctx, $user, string $permission)
    {
        if ($this->isAdminUser($user) || (($ctx['isOwner'] ?? false) === true)) {
            return null;
        }

        if (($ctx['isSubuser'] ?? false) !== true) {
            return response()->json(['error' => 'You do not have access to this server'], 403);
        }

        $permissions = $ctx['subuserPermissions'] ?? [];
        if (!is_array($permissions) || !in_array($permission, $permissions, true)) {
            return response()->json(['error' => 'You do not have permission to perform this action.'], 403);
        }

        return null;
    }

    private function validateBackupId($backupId): ?string
    {
        if (!is_string($backupId)) {
            return null;
        }

        $backupId = trim($backupId);
        if ($backupId === '' || strlen($backupId) > 128) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._:-]+$/', $backupId) ? $backupId : null;
    }

    private function getPolicy(string $serverUuid)
    {
        return \DB::table('restic_policies')->where('server_uuid', $serverUuid)->first();
    }

    private function upsertPolicy(string $serverUuid, array $data): void
    {
        $data['updated_at'] = now();
        \DB::table('restic_policies')->updateOrInsert(
            ['server_uuid' => $serverUuid],
            $data + ['created_at' => now()]
        );
    }
    private function getResticOwnerUsername(object $serverRow, ?object $fallbackUser = null): ?string
    {
        $owner = \DB::table('users')->where('id', $serverRow->owner_id)->first();
        $ownerUsername = $owner->username ?? ($owner->id ?? null);
        if (!$ownerUsername && $fallbackUser) {
            $ownerUsername = $fallbackUser->username ?? ($fallbackUser->id ?? null);
        }
        return $ownerUsername;
    }

    // Download Wingsrestic.sh (no embedded credentials)
    public function downloadScript(Request $request)
    {
        $this->requireAdmin(auth()->user());

        $candidates = [
            base_path('.blueprint/dev/wings-addon/scripts/build-wings-restic.sh'),
            base_path('.blueprint/extensions/resticbackups/wings-addon/scripts/build-wings-restic.sh'),
            base_path('.blueprint/dev/fs/private/Wingsrestic.sh'),
            base_path('.blueprint/extensions/resticbackups/fs/private/Wingsrestic.sh'),
            __DIR__ . '/../../fs/private/Wingsrestic.sh',
        ];
        $scriptPath = null;
        foreach ($candidates as $path) {
            if (is_string($path) && file_exists($path)) {
                $scriptPath = $path;
                break;
            }
        }
        if (!$scriptPath) {
            return response()->json(['error' => 'Wingsrestic.sh not found'], 404);
        }
        $script = file_get_contents($scriptPath);
        return response($script)
            ->header('Content-Type', 'application/x-sh')
            ->header('Content-Disposition', 'attachment; filename="Wingsrestic.sh"');
    }

    public function generateEncryptionKey(Request $request): RedirectResponse
    {
        $this->requireAdmin(auth()->user());

        $uuid = $request->input('server_uuid');

        if (!$uuid) {
            return redirect()->back()->withErrors(['server_uuid' => 'Server UUID required']);
        }

        $encryptionKey = bin2hex(random_bytes(32));

        $serverRow = \DB::table('servers')->where('uuid', $uuid)->first();
        if ($serverRow) {
            $ownerUsername = $this->getResticOwnerUsername($serverRow);
            $deleteResult = $this->deleteResticRepoOnWings($serverRow, $ownerUsername);
            if (!$deleteResult['ok']) {
                return redirect()->back()->withErrors(['server_uuid' => $deleteResult['error'] ?? 'Failed to delete repo on Wings.']);
            }
        } else {
            return redirect()->back()->withErrors(['server_uuid' => 'Server not found.']);
        }

        \DB::table('restic')->updateOrInsert(
            ['server_uuid' => $uuid],
            [
                'encryption_key' => $encryptionKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        self::recordResticKeyHistory($uuid, $ownerUsername ?? null, $encryptionKey, now());

        return redirect()->back()->with([
            'encryption_key_success' => 'Encryption key regenerated.',
            'new_encryption_key'     => $encryptionKey,
            'server_uuid'            => $uuid,
        ]);
    }

    public function deleteResticRepo(Request $request): RedirectResponse
    {
        $this->requireAdmin(auth()->user());

        $uuid = $request->input('server_uuid');

        if (!$uuid) {
            return redirect()->back()->withErrors(['server_uuid' => 'Server UUID required']);
        }

        $serverRow = \DB::table('servers')->where('uuid', $uuid)->first();
        if (!$serverRow) {
            return redirect()->back()->withErrors(['server_uuid' => 'Server not found.']);
        }

        $ownerUsername = $this->getResticOwnerUsername($serverRow);
        $deleteResult = $this->deleteResticRepoOnWings($serverRow, $ownerUsername);
        if (!$deleteResult['ok']) {
            return redirect()->back()->withErrors(['server_uuid' => $deleteResult['error'] ?? 'Failed to delete repo on Wings.']);
        }

        return redirect()->back()->with(['encryption_key_success' => 'Restic repo deleted on Wings.']);
    }

    private function deleteResticRepoOnWings(object $serverRow, ?string $ownerUsername): array
    {
        $node = \DB::table('nodes')->where('id', $serverRow->node_id)->first();
        if (!$node) {
            $this->recordJobFailure($serverRow->uuid, 'delete_repo', 'Node not found');
            return ['ok' => false, 'error' => 'Node not found.'];
        }

        $port = property_exists($node, 'daemonListen')
            ? $node->daemonListen
            : (property_exists($node, 'daemon_listen') ? $node->daemon_listen : 8080);
        $nodeApiUrl = 'https://' . $node->fqdn . ':' . $port;

        $encryptedToken = $node->daemon_token ?? null;
        if (!$encryptedToken) {
            $this->recordJobFailure($serverRow->uuid, 'delete_repo', 'Node daemon token missing');
            return ['ok' => false, 'error' => 'Node daemon token missing.'];
        }
        try {
            $token = app('encrypter')->decrypt($encryptedToken);
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'delete_repo', 'Failed to decrypt daemon token', [
                'exception' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'Failed to decrypt daemon token.'];
        }

        $fullUuid = $serverRow->uuid;

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->delete($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/repo', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                ],
            ]);

            if ($response->getStatusCode() === 404) {
                $response = $client->delete($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/repo', [
                    'http_errors' => false,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept'        => 'application/json',
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'owner_username' => $ownerUsername,
                    ],
                ]);
            }

            if ($response->getStatusCode() >= 300) {
                $rawBody = (string) $response->getBody();
                $this->recordJobFailure($serverRow->uuid, 'delete_repo', $rawBody ?: 'Failed to delete repo on Wings', [
                    'status' => $response->getStatusCode(),
                    'response' => $rawBody,
                ]);
                return ['ok' => false, 'error' => $rawBody ?: 'Failed to delete repo on Wings.'];
            }

            return ['ok' => true];
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'delete_repo', $e->getMessage(), [
                'exception' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'Failed to delete repo on Wings.'];
        }
    }

    // Ensure all servers have encryption keys in restic
    public static function ensureResticKeysForAllServers()
    {
        $allServers = \DB::table('servers')->select('uuid', 'name', 'owner_id')->get();
        foreach ($allServers as $server) {
            $keyRow = \DB::table('restic')
                ->where('server_uuid', $server->uuid)
                ->first();
            if (!$keyRow) {
                try {
                    $encryptionKey = bin2hex(random_bytes(32));
                    \DB::table('restic')->insert([
                        'server_uuid'       => $server->uuid,
                        'encryption_key'    => $encryptionKey,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
                    $ownerUsername = \DB::table('users')->where('id', $server->owner_id)->value('username');
                    self::recordResticKeyHistory($server->uuid, $ownerUsername ?: null, $encryptionKey, now());
                } catch (\Exception $e) {
                    \Log::error(
                        'Failed to insert restic key for server: ' .
                        $server->uuid . ' - ' . $e->getMessage()
                    );
                }
            }
        }
    }

    public function index(): View
    {
        $this->requireAdmin(auth()->user());

        // Ensure keys exist for all servers
        self::ensureResticKeysForAllServers();


        // Get all servers and their keys
        $allServers = \DB::table('servers')->select('uuid', 'name', 'owner_id')->get();
        $servers = [];
        foreach ($allServers as $server) {
            $keyRow = \DB::table('restic')
                ->where('server_uuid', $server->uuid)
                ->first();
            if ($keyRow && $keyRow->encryption_key) {
                $ownerUsername = \DB::table('users')->where('id', $server->owner_id)->value('username');
                self::recordResticKeyHistory($server->uuid, $ownerUsername ?: null, $keyRow->encryption_key, $keyRow->created_at ?? now());
            }
            $servers[] = (object) [
                'server_uuid'    => $server->uuid,
                'encryption_key' => null,
                'server_name'    => $server->name,
            ];
        }

        $keyHistoryOptions = \DB::table('restic_key_history')
            ->select('server_uuid', 'owner_username')
            ->selectRaw('MAX(created_at) as latest_created_at')
            ->groupBy('server_uuid', 'owner_username')
            ->orderBy('latest_created_at', 'desc')
            ->get();

        $failedJobs = \DB::table('restic_job_history')
            ->leftJoin('servers', 'restic_job_history.server_uuid', '=', 'servers.uuid')
            ->select(
                'restic_job_history.*',
                'servers.name as server_name'
            )
            ->orderBy('restic_job_history.created_at', 'desc')
            ->limit(50)
            ->get();

        $blueprint = new BlueprintExtensionLibrary();

        $guideCandidates = [
            base_path('.blueprint/extensions/resticbackups/fs/private/EXTENSION_GUIDE.md'),
            base_path('.blueprint/extensions/resticbackups/private/EXTENSION_GUIDE.md'),
            base_path('.blueprint/dev/fs/private/EXTENSION_GUIDE.md'),
            __DIR__ . '/../../fs/private/EXTENSION_GUIDE.md',
        ];
        $guidePath = null;
        foreach ($guideCandidates as $candidate) {
            if (is_string($candidate) && file_exists($candidate)) {
                $guidePath = $candidate;
                break;
            }
        }
        $guideContents = ($guidePath && file_exists($guidePath)) ? file_get_contents($guidePath) : 'Guide not found.';
        $repoMultiplier = $this->getRepoMultiplier();

        return view('admin.extensions.resticbackups.index', [
            'servers'   => collect($servers),
            'root'      => '/admin/extensions/resticbackups',
            'blueprint' => $blueprint,
            'guide'     => $guideContents,
            'repoMultiplier' => $repoMultiplier,
            'keyHistoryOptions' => $keyHistoryOptions,
            'failedJobs' => $failedJobs,
        ]);
    }

    public function adminGetEncryptionKey(Request $request)
    {
        $this->requireAdmin(auth()->user());

        $serverUuid = (string) $request->query('server_uuid', '');
        if (!preg_match('/^[A-Fa-f0-9-]{8,36}$/', $serverUuid)) {
            return response()->json(['error' => 'Invalid server UUID.'], 422);
        }

        $server = \DB::table('servers')
            ->where('uuid', $serverUuid)
            ->orWhere('uuidShort', $serverUuid)
            ->first();
        if (!$server) {
            return response()->json(['error' => 'Server not found.'], 404);
        }

        $key = \DB::table('restic')->where('server_uuid', $server->uuid)->value('encryption_key');
        return response()->json([
            'server_uuid' => $server->uuid,
            'encryption_key' => is_string($key) ? $key : null,
        ]);
    }

    public function adminGetKeyHistory(Request $request)
    {
        $this->requireAdmin(auth()->user());

        $serverUuid = (string) $request->query('server_uuid', '');
        $ownerUsername = $request->query('owner_username');
        if (!preg_match('/^[A-Fa-f0-9-]{8,36}$/', $serverUuid)) {
            return response()->json(['error' => 'Invalid server UUID.'], 422);
        }
        if ($ownerUsername !== null && !is_string($ownerUsername)) {
            return response()->json(['error' => 'Invalid owner username.'], 422);
        }

        $query = \DB::table('restic_key_history')->where('server_uuid', $serverUuid);
        if ($ownerUsername !== null && $ownerUsername !== '') {
            $query->where('owner_username', $ownerUsername);
        }

        return response()->json([
            'rows' => $query->orderBy('created_at', 'desc')->get(),
        ]);
    }

    private function jsonPayload($response): array
    {
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            return [
                'status' => $response->getStatusCode(),
                'body' => $response->getData(true),
            ];
        }

        return [
            'status' => 500,
            'body' => ['error' => 'Unexpected internal response.'],
        ];
    }

    private function adminToolOutput(string $title, $payload, ?string $serverUuid = null): RedirectResponse
    {
        return redirect()->back()->with([
            'admin_tool_title' => $title,
            'admin_tool_payload' => $payload,
            'admin_tool_output' => is_string($payload) ? $payload : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'admin_tool_server_uuid' => $serverUuid,
        ]);
    }

    private function requireAdminServerUuid(Request $request): string
    {
        $serverUuid = (string) $request->input('server_uuid', '');
        if (!preg_match('/^[A-Fa-f0-9-]{8,36}$/', $serverUuid)) {
            throw new \InvalidArgumentException('Server UUID required.');
        }

        return $serverUuid;
    }

    private function handleAdminTool(Request $request): RedirectResponse
    {
        try {
            $serverUuid = $this->requireAdminServerUuid($request);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['server_uuid' => $e->getMessage()]);
        }

        $action = (string) $request->input('action', '');

        if ($action === 'admin_repo_stats') {
            return $this->adminToolOutput('Repository Stats', [
                'stats' => $this->jsonPayload($this->getResticStats($serverUuid, $request)),
                'disk_usage' => $this->jsonPayload($this->getResticRepoDiskUsage($serverUuid, $request)),
            ], $serverUuid);
        }

        if ($action === 'admin_health_check') {
            $start = $this->jsonPayload($this->runResticRepoHealthCheck($serverUuid, $request));
            $payload = ['start' => $start];

            if (($start['status'] ?? 500) < 400) {
                for ($i = 0; $i < 30; $i++) {
                    sleep(2);
                    $status = $this->jsonPayload($this->getResticRepoHealthStatus($serverUuid, $request));
                    $payload['status'] = $status;
                    $body = $status['body'] ?? [];
                    if (is_array($body) && ($body['status'] ?? '') !== 'running') {
                        break;
                    }
                }
            }

            return $this->adminToolOutput(
                'Health Check',
                $payload,
                $serverUuid
            );
        }

        if ($action === 'admin_locks') {
            return $this->adminToolOutput(
                'Repository Locks',
                $this->jsonPayload($this->getResticLocks($serverUuid, $request)),
                $serverUuid
            );
        }

        if ($action === 'admin_reveal_key') {
            $key = \DB::table('restic')->where('server_uuid', $serverUuid)->value('encryption_key');
            return $this->adminToolOutput('Current Encryption Key', [
                'server_uuid' => $serverUuid,
                'encryption_key' => is_string($key) ? $key : null,
            ], $serverUuid);
        }

        if ($action === 'admin_key_history') {
            $rows = \DB::table('restic_key_history')
                ->where('server_uuid', $serverUuid)
                ->orderBy('created_at', 'desc')
                ->get();
            return $this->adminToolOutput('Key History', [
                'server_uuid' => $serverUuid,
                'rows' => $rows,
            ], $serverUuid);
        }

        if ($action === 'admin_unlock_repo' || $action === 'admin_force_unlock_repo') {
            if ($action === 'admin_force_unlock_repo') {
                $request->query->set('force', '1');
            }

            return $this->adminToolOutput(
                $action === 'admin_force_unlock_repo' ? 'Force Unlock Repo' : 'Unlock Repo',
                $this->jsonPayload($this->unlockResticRepo($serverUuid, $request)),
                $serverUuid
            );
        }

        return redirect()->back()->withErrors(['action' => 'Invalid admin action.']);
    }

    public function post(Request $request): RedirectResponse
    {
        $this->requireAdmin(auth()->user());

        $action = $request->input('action');
        if (is_string($action) && strpos($action, 'admin_') === 0) {
            return $this->handleAdminTool($request);
        }
        if ($action === 'generate_key') {
            return $this->generateEncryptionKey($request);
        }
        if ($action === 'delete_repo') {
            return $this->deleteResticRepo($request);
        }
        if ($action === 'save_repo_multiplier') {
            $value = $request->input('repo_multiplier');
            if (!is_numeric($value) || (int) $value < 1) {
                return redirect()->back()->withErrors(['repo_multiplier' => 'Repo multiplier must be a number >= 1']);
            }
            \DB::table('settings')->updateOrInsert(
                ['key' => 'restic.max_repo_multiplier'],
                ['value' => (string) ((int) $value)]
            );
            return redirect()->back()->with(['success' => 'Repo size multiplier updated.']);
        }

        return redirect()->back()->withErrors(['action' => 'Invalid action.']);
    }

    public function adminActions(Request $request): RedirectResponse
    {
        return $this->post($request);
    }

    public function getResticSettings(Request $request)
    {
            $repoMultiplier = $this->getRepoMultiplier();

        return response()->json([
            'repo_multiplier' => $repoMultiplier,
        ]);
    }

    public function getResticLimits($server, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_READ)) {
            return $deny;
        }

        $serverRow = $ctx['serverRow'];
        $repoMultiplier = $this->getRepoMultiplier();
        $diskLimitMb = isset($serverRow->disk) ? (int) $serverRow->disk : null;
        $maxRepoBytes = ($diskLimitMb && $diskLimitMb > 0)
            ? ($diskLimitMb * 1024 * 1024 * $repoMultiplier)
            : null;

        return response()->json([
            'disk_limit_mb' => $diskLimitMb,
            'repo_multiplier' => $repoMultiplier,
            'max_repo_bytes' => $maxRepoBytes,
        ]);
    }

    // Backend API: Trigger Restic backup for a server
    public function createBackup($server, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_CREATE)) {
            return $deny;
        }

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        if (!$encryptionKey) {
            $encryptionKey = bin2hex(random_bytes(32));
            \DB::table('restic')->updateOrInsert(
                ['server_uuid' => $fullUuid],
                [
                    'encryption_key' => $encryptionKey,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            self::recordResticKeyHistory($fullUuid, $ownerUsername, $encryptionKey, now());
        }

        $requestedMax = $request->input('max_backups');
        $requestedMaxRepoBytes = $request->input('max_repo_bytes');
        $repoMultiplier = $this->getRepoMultiplier();
        $diskLimitMb = isset($serverRow->disk) ? (int) $serverRow->disk : null;
        $computedMaxRepoBytes = ($diskLimitMb && $diskLimitMb > 0)
            ? ($diskLimitMb * 1024 * 1024 * $repoMultiplier)
            : null;
        $finalMaxRepoBytes = $computedMaxRepoBytes !== null
            ? $computedMaxRepoBytes
            : (is_numeric($requestedMaxRepoBytes) ? (int) $requestedMaxRepoBytes : null);
        $maxBackups = is_numeric($serverRow->backup_limit)
            ? (int) $serverRow->backup_limit
            : (is_numeric($requestedMax) ? (int) $requestedMax : null);

        try {
            $client = new \GuzzleHttp\Client();
            $async = $request->query('async');
            $asyncQuery = ($async === '1' || $async === 'true' || $async === 'yes') ? '?async=1' : '';
            $response = $client->post($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/backups' . $asyncQuery, [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                    'max_backups'    => $maxBackups,
                    'max_repo_bytes' => $finalMaxRepoBytes,
                ],
            ]);
            $respBody = $this->decodeResponseBody($response);
            if ($response->getStatusCode() >= 300) {
                $message = is_array($respBody) ? ($respBody['error'] ?? $respBody['message'] ?? 'Failed to trigger backup') : 'Failed to trigger backup';
                $this->recordJobFailure($serverRow->uuid, 'backup', $message, [
                    'status' => $response->getStatusCode(),
                    'response' => $respBody,
                ]);
            }
            return $this->respondWings($response, $user, 'Failed to trigger backup');
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'backup', $e->getMessage(), [
                'exception' => $e->getMessage(),
            ]);
            return $this->exceptionResponse($user, 'Failed to trigger backup', $e);
        }
    }

    // Backend API: List Restic backups for a server (uniform with createBackup)
    public function listBackups($server, Request $request)
    {

        // Accept either full or short UUID, but always use the full UUID for Wings
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_READ)) {
            return $deny;
        }

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        $limit = $request->query('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;
        $cursor = $request->query('cursor');
        $since = $request->query('since');
        $until = $request->query('until');


        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/backups/list', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                    'limit' => $limit,
                    'cursor' => $cursor,
                    'since' => $since,
                    'until' => $until,
                    'include_total' => '1',
                ],
            ]);
            if ($response->getStatusCode() >= 400) {
                return $this->respondWings($response, $user, 'Failed to list backups');
            }

            $respBody = $this->decodeResponseBody($response);
            // Allowlist: Only return date, time, ID, and total size for each backup
            if (isset($respBody['backups']) && is_array($respBody['backups'])) {
                $filtered = [];
                foreach ($respBody['backups'] as $backup) {
                    $fullId = $backup['id'] ?? null;
                    $shortId = $backup['short_id'] ?? (is_string($fullId) ? substr($fullId, 0, 8) : null);
                    $idForDownload = $fullId ?: $shortId;
                    $filtered[] = [
                        'id' => $idForDownload,
                        'short_id' => $shortId,
                        'time' => $backup['time'] ?? ($backup['summary']['backup_start'] ?? null),
                        'size' => $backup['size'] ?? $backup['stats']['total_size'] ?? $backup['summary']['data_added_packed'] ?? null,
                        'tags' => $backup['tags'] ?? null,
                        'locked' => $backup['locked'] ?? null,
                    ];
                }

                $ids = array_values(array_filter(array_map(fn ($b) => $b['id'] ?? null, $filtered)));
                if (!empty($ids)) {
                    $notes = \DB::table('restic_backup_notes')
                        ->where('server_uuid', $serverRow->uuid)
                        ->whereIn('backup_id', $ids)
                        ->pluck('note', 'backup_id')
                        ->toArray();
                    foreach ($filtered as &$item) {
                        $note = $item['id'] && isset($notes[$item['id']]) ? $notes[$item['id']] : null;
                        $item['note'] = $note;
                    }
                    unset($item);
                }
                $respBody['backups'] = $filtered;
            }
            if (isset($serverRow->backup_limit)) {
                $respBody['backup_limit'] = is_numeric($serverRow->backup_limit) ? (int) $serverRow->backup_limit : $serverRow->backup_limit;
            }
            if ($response->getStatusCode() >= 400) {
                return $this->respondWings($response, $user, 'Failed to list backups');
            }
            return response()->json($respBody, $response->getStatusCode());
        } catch (\Exception $e) {
            return $this->exceptionResponse($user, 'Failed to list backups', $e);
        }
    }

    // Backend API: Get Restic repo stats for a server
    public function getResticStats($server, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_READ)) {
            return $deny;
        }

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/stats', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                ],
            ]);

            $respBody = $this->decodeResponseBody($response);
            if (!is_array($respBody) || $response->getStatusCode() >= 400) {
                $rawBody = $response ? (string) $response->getBody() : null;
                $message = is_array($respBody) ? ($respBody['error'] ?? $respBody['message'] ?? 'Failed to fetch stats') : ($rawBody ?: 'Failed to fetch stats');
                $this->recordJobFailure($serverRow->uuid, 'stats', $message, [
                    'status' => $response ? $response->getStatusCode() : null,
                    'response' => $respBody ?? $rawBody,
                ]);
                return $this->respondWings($response, $user, 'Failed to fetch stats');
            }

            return response()->json([
                'total_size' => isset($respBody['total_size']) ? (int) $respBody['total_size'] : null,
                'total_compressed_size' => isset($respBody['total_compressed_size']) ? (int) $respBody['total_compressed_size'] : null,
                'total_uncompressed_size' => isset($respBody['total_uncompressed_size']) ? (int) $respBody['total_uncompressed_size'] : null,
                'total_deduped_size' => isset($respBody['total_deduped_size']) ? (int) $respBody['total_deduped_size'] : null,
                'snapshots_count' => isset($respBody['snapshots_count']) ? (int) $respBody['snapshots_count'] : null,
                'uncompressed_error' => isset($respBody['uncompressed_error']) ? (string) $respBody['uncompressed_error'] : null,
            ], $response->getStatusCode());
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'stats', $e->getMessage(), [
                'exception' => $e->getMessage(),
            ]);
            return $this->exceptionResponse($user, 'Failed to fetch stats', $e);
        }
    }

    // Backend API: Get Restic backup status for a server
    public function getResticStatus($server, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_READ)) {
            return $deny;
        }

        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/backups/status', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
            ]);

            return $this->respondWings($response, $user, 'Failed to fetch status');
        } catch (\Exception $e) {
            return $this->exceptionResponse($user, 'Failed to fetch status', $e);
        }
    }

    public function getResticRestoreStatus($server, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_RESTORE)) {
            return $deny;
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get($ctx['nodeApiUrl'] . '/api/servers/' . $ctx['fullUuid'] . '/restic/restore/status', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $ctx['token'],
                    'Accept' => 'application/json',
                ],
            ]);

            return $this->respondWings($response, $user, 'Failed to fetch restore status');
        } catch (\Exception $e) {
            return $this->exceptionResponse($user, 'Failed to fetch restore status', $e);
        }
    }

    public function getResticRepoHealthStatus($server, Request $request)
    {
        $user = auth()->user();
        $this->requireAdmin($user);

        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get($ctx['nodeApiUrl'] . '/api/servers/' . $ctx['fullUuid'] . '/restic/check/status', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $ctx['token'],
                    'Accept' => 'application/json',
                ],
            ]);

            return $this->respondWings($response, $user, 'Failed to fetch health check status');
        } catch (\Exception $e) {
            return $this->exceptionResponse($user, 'Failed to fetch health check status', $e);
        }
    }

    public function getResticRepoExists($server, Request $request)
    {
        $user = auth()->user();
        $this->requireAdmin($user);

        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/repo/exists', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ctx['ownerUsername'],
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                $rawBody = $response ? (string) $response->getBody() : null;
                $message = $rawBody ?: 'Failed to check repo';
                $this->recordJobFailure($serverRow->uuid, 'repo_exists', $message, [
                    'status' => $response ? $response->getStatusCode() : null,
                    'response' => $rawBody,
                ]);
            }
            return $this->respondWings($response, $user, 'Failed to check repo');
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'repo_exists', $e->getMessage(), [
                'exception' => $e->getMessage(),
            ]);
            return $this->exceptionResponse($user, 'Failed to check repo', $e);
        }
    }

    public function getResticRepoDiskUsage($server, Request $request)
    {
        $user = auth()->user();
        $this->requireAdmin($user);

        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/repo/size', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ctx['ownerUsername'],
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                $rawBody = $response ? (string) $response->getBody() : null;
                $message = $rawBody ?: 'Failed to fetch repo disk usage';
                $this->recordJobFailure($serverRow->uuid, 'repo_size', $message, [
                    'status' => $response ? $response->getStatusCode() : null,
                    'response' => $rawBody,
                ]);
            }
            return $this->respondWings($response, $user, 'Failed to fetch repo disk usage');
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'repo_size', $e->getMessage(), [
                'exception' => $e->getMessage(),
            ]);
            return $this->exceptionResponse($user, 'Failed to fetch repo disk usage', $e);
        }
    }

    public function runResticRepoHealthCheck($server, Request $request)
    {
        $user = auth()->user();
        $this->requireAdmin($user);

        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        $readDataSubset = $request->input('read_data_subset');

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/check', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                    'read_data_subset' => is_string($readDataSubset) ? $readDataSubset : null,
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                $rawBody = $response ? (string) $response->getBody() : null;
                $message = $rawBody ?: 'Failed to run health check';
                $this->recordJobFailure($serverRow->uuid, 'health_check', $message, [
                    'status' => $response ? $response->getStatusCode() : null,
                    'response' => $rawBody,
                ]);
            }
            return $this->respondWings($response, $user, 'Failed to run health check');
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'health_check', $e->getMessage(), [
                'exception' => $e->getMessage(),
            ]);
            return $this->exceptionResponse($user, 'Failed to run health check', $e);
        }
    }

    public function saveBackupNote($server, $backupId, Request $request)
    {
        $user = auth()->user();

        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_CREATE)) {
            return $deny;
        }

        $serverRow = $ctx['serverRow'];
        $note = $request->input('note');
        if ($note !== null && !is_string($note)) {
            return response()->json(['error' => 'Invalid note'], 422);
        }

        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }

        $backupIdValidated = $this->validateBackupId($backupId);
        if ($backupIdValidated === null) {
            return response()->json(['error' => 'Invalid backup ID'], 422);
        }
        $backupId = $backupIdValidated;

        \DB::table('restic_backup_notes')->updateOrInsert(
            ['server_uuid' => $serverRow->uuid, 'backup_id' => $backupId],
            ['note' => $note, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['backup_id' => $backupId, 'note' => $note]);
    }

    // Backend API: Download a Restic backup for a server
    public function downloadBackup($server, $backupId, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_DOWNLOAD)) {
            return $deny;
        }
        $backupIdValidated = $this->validateBackupId($backupId);
        if ($backupIdValidated === null) {
            return response()->json(['error' => 'Invalid backup ID'], 422);
        }
        $backupId = $backupIdValidated;

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $daemonToken = trim((string) $ctx['token']);
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];
        $delivery = $request->input('delivery') === 'sftp' ? 'sftp' : 'stream';

        // Prepare the archive on Wings (server-to-server only)
        try {
            $client = new \GuzzleHttp\Client();
            $encodedId = rawurlencode($backupId);
            $response = $client->post($nodeApiUrl . '/api/servers/' . $serverRow->uuid . '/restic/backups/' . $encodedId . '/prepare', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $daemonToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                    'delivery' => $delivery,
                    'force_sftp' => $delivery === 'sftp',
                ],
            ]);
            if (!$response || $response->getStatusCode() >= 300) {
                $message = $response ? (string) $response->getBody() : 'No response from Wings';
                $this->recordJobFailure($serverRow->uuid, 'download_prepare', 'Failed to prepare backup archive', [
                    'status' => $response ? $response->getStatusCode() : null,
                    'response' => $message,
                    'backup_id' => $backupId,
                ]);
                return $this->respondWings($response, $user, 'Failed to prepare backup archive');
            }
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'download_prepare', $e->getMessage(), [
                'exception' => $e->getMessage(),
                'backup_id' => $backupId,
            ]);
            return $this->exceptionResponse($user, 'Failed to prepare backup archive', $e);
        }

        $url = sprintf(
            '/extensions/resticbackups/servers/%s/backups/restic/%s/download/stream?skip_prepare=1',
            rawurlencode($serverRow->uuid),
            rawurlencode($backupId)
        );

        return response()->json([
            'object' => 'signed_url',
            'attributes' => ['url' => $url],
        ]);
    }

    // Backend API: Stream a Restic backup through the panel (avoids cross-node download issues)
    public function downloadBackupStream($server, $backupId, Request $request)
    {
        @set_time_limit(0);
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_DOWNLOAD)) {
            return $deny;
        }
        $backupIdValidated = $this->validateBackupId($backupId);
        if ($backupIdValidated === null) {
            return response()->json(['error' => 'Invalid backup ID'], 422);
        }
        $backupId = $backupIdValidated;

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $daemonToken = trim((string) $ctx['token']);
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        $skipPrepare = $request->query('skip_prepare') === '1';

        if (!$skipPrepare) {
            // Prepare the archive on Wings (server-to-server only)
            try {
                $client = new \GuzzleHttp\Client();
                $encodedId = rawurlencode($backupId);
                $response = $client->post($nodeApiUrl . '/api/servers/' . $serverRow->uuid . '/restic/backups/' . $encodedId . '/prepare', [
                    'http_errors' => false,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $daemonToken,
                        'Accept'        => 'application/json',
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'owner_username' => $ownerUsername,
                        'encryption_key' => $encryptionKey,
                    ],
                ]);
                if (!$response || $response->getStatusCode() >= 300) {
                    $message = $response ? (string) $response->getBody() : 'No response from Wings';
                    $this->recordJobFailure($serverRow->uuid, 'download_prepare', 'Failed to prepare backup archive', [
                        'status' => $response ? $response->getStatusCode() : null,
                        'response' => $message,
                        'backup_id' => $backupId,
                    ]);
                    return $this->respondWings($response, $user, 'Failed to prepare backup archive');
                }
            } catch (\Exception $e) {
                $this->recordJobFailure($serverRow->uuid, 'download_prepare', $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'backup_id' => $backupId,
                ]);
                return $this->exceptionResponse($user, 'Failed to prepare backup archive', $e);
            }
        }

        try {
            $client = new \GuzzleHttp\Client();
            $encodedId = rawurlencode($backupId);
            $url = $nodeApiUrl . '/api/servers/' . $serverRow->uuid . '/restic/backups/' . $encodedId . '/download';
            $resp = $client->get($url, [
                'stream' => true,
                'http_errors' => false,
                'timeout' => 0,
                'read_timeout' => 0,
                'headers' => [
                    'Authorization' => 'Bearer ' . $daemonToken,
                    'Accept' => 'application/octet-stream',
                ],
            ]);

            if ($resp->getStatusCode() >= 300) {
                $bodyText = (string) $resp->getBody();
                if ($this->isAdminUser($user)) {
                    return response()->json([
                        'error' => 'Failed to download backup from node',
                        'details' => $bodyText,
                    ], 502);
                }
                return response()->json([
                    'error' => 'Failed to download backup from node',
                ], 502);
            }

            $headers = [
                'Content-Type' => $resp->getHeaderLine('Content-Type') ?: 'application/octet-stream',
                'Content-Disposition' => $resp->getHeaderLine('Content-Disposition') ?: ('attachment; filename="backup-' . $backupId . '.zip"'),
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-store',
            ];
            $length = $resp->getHeaderLine('Content-Length');
            if ($length && $length !== '0') {
                $headers['Content-Length'] = $length;
            }

            return response()->stream(function () use ($resp) {
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', '0');
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                $body = $resp->getBody();
                $resource = method_exists($body, 'detach') ? $body->detach() : null;
                if (is_resource($resource)) {
                    $out = fopen('php://output', 'wb');
                    if ($out !== false) {
                        stream_copy_to_stream($resource, $out);
                        fclose($out);
                    }
                    fclose($resource);
                    return;
                }

                while (!$body->eof()) {
                    $chunk = $body->read(8192);
                    if ($chunk === '') {
                        usleep(20000);
                        continue;
                    }
                    echo $chunk;
                    @ob_flush();
                    flush();
                }
            }, 200, $headers);
        } catch (\Throwable $e) {
            $this->recordJobFailure($serverRow->uuid, 'download_stream', $e->getMessage(), [
                'exception' => $e->getMessage(),
                'backup_id' => $backupId,
                'skip_prepare' => $skipPrepare,
            ]);
            return $this->exceptionResponse($user, 'Failed to stream backup', $e);
        }
    }

    // Backend API: Start prepare for a Restic download (async)
    public function downloadBackupPrepare($server, $backupId, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_DOWNLOAD)) {
            return $deny;
        }
        $backupIdValidated = $this->validateBackupId($backupId);
        if ($backupIdValidated === null) {
            return response()->json(['error' => 'Invalid backup ID'], 422);
        }
        $backupId = $backupIdValidated;

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $daemonToken = trim((string) $ctx['token']);
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];
        $delivery = $request->input('delivery') === 'sftp' ? 'sftp' : 'stream';

        try {
            $client = new \GuzzleHttp\Client();
            $encodedId = rawurlencode($backupId);
            $response = $client->post($nodeApiUrl . '/api/servers/' . $serverRow->uuid . '/restic/backups/' . $encodedId . '/prepare?async=1', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $daemonToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                    'delivery' => $delivery,
                    'force_sftp' => $delivery === 'sftp',
                ],
            ]);

            $respBody = $this->decodeResponseBody($response);
            if ($response->getStatusCode() >= 300) {
                $message = is_array($respBody) ? ($respBody['error'] ?? $respBody['message'] ?? 'Failed to start prepare') : 'Failed to start prepare';
                $this->recordJobFailure($serverRow->uuid, 'download_prepare', $message, [
                    'status' => $response->getStatusCode(),
                    'response' => $respBody,
                    'backup_id' => $backupId,
                ]);
            }
            return $this->respondWings($response, $user, 'Failed to start prepare');
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'download_prepare', $e->getMessage(), [
                'exception' => $e->getMessage(),
                'backup_id' => $backupId,
            ]);
            return $this->exceptionResponse($user, 'Failed to start prepare', $e);
        }
    }

    // Backend API: Get prepare status for a Restic download
    public function downloadBackupStatus($server, $backupId, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_DOWNLOAD)) {
            return $deny;
        }
        $backupIdValidated = $this->validateBackupId($backupId);
        if ($backupIdValidated === null) {
            return response()->json(['error' => 'Invalid backup ID'], 422);
        }
        $backupId = $backupIdValidated;

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $daemonToken = trim((string) $ctx['token']);
        $ownerUsername = $ctx['ownerUsername'];

        try {
            $client = new \GuzzleHttp\Client();
            $encodedId = rawurlencode($backupId);
            $response = $client->get($nodeApiUrl . '/api/servers/' . $serverRow->uuid . '/restic/backups/' . $encodedId . '/prepare/status', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $daemonToken,
                    'Accept'        => 'application/json',
                ],
            ]);

            $respBody = $this->decodeResponseBody($response);
            if ($response->getStatusCode() >= 400) {
                $message = is_array($respBody) ? ($respBody['error'] ?? $respBody['message'] ?? 'Failed to fetch prepare status') : 'Failed to fetch prepare status';
                $this->recordJobFailure($serverRow->uuid, 'download_status', $message, [
                    'status' => $response->getStatusCode(),
                    'response' => $respBody,
                    'backup_id' => $backupId,
                    'owner_username' => $ownerUsername,
                ]);
            } elseif (is_array($respBody) && ($respBody['status'] ?? null) === 'failed') {
                $message = $respBody['message'] ?? 'Download preparation failed';
                $this->recordJobFailure($serverRow->uuid, 'download_prepare', $message, [
                    'status' => 200,
                    'response' => $respBody,
                    'backup_id' => $backupId,
                    'owner_username' => $ownerUsername,
                ]);
            }

            if (is_array($respBody) && ($respBody['status'] ?? null) === 'sftp_ready') {
                $sftpPort = $ctx['node']->daemon_sftp
                    ?? ($ctx['node']->daemonSFTP ?? 2022);
                $serverIdentifier = $serverRow->uuidShort ?? substr((string) $serverRow->uuid, 0, 8);
                $sftpUsername = ($user->username ?? 'username') . '.' . $serverIdentifier;
                $filePath = $respBody['result']['sftp_path'] ?? null;
                $fileName = $respBody['result']['file_name'] ?? null;
                $sftpUrlPath = $filePath ?: ('/' . $fileName);
                $sftpUrlPath = '/' . ltrim((string) $sftpUrlPath, '/');
                $respBody['sftp'] = [
                    'host' => $ctx['node']->fqdn,
                    'port' => (int) $sftpPort,
                    'username' => $sftpUsername,
                    'path' => $filePath,
                    'file_name' => $fileName,
                    'url' => 'sftp://' . rawurlencode($sftpUsername) . '@' . $ctx['node']->fqdn . ':' . (int) $sftpPort . str_replace('%2F', '/', rawurlencode($sftpUrlPath)),
                ];

                return response()->json($respBody, $response->getStatusCode());
            }

            return $this->respondWings($response, $user, 'Failed to fetch prepare status');
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'download_status', $e->getMessage(), [
                'exception' => $e->getMessage(),
                'backup_id' => $backupId,
                'owner_username' => $ownerUsername,
            ]);
            return $this->exceptionResponse($user, 'Failed to fetch prepare status', $e);
        }
    }

    public function lockBackup($server, $backupId, Request $request)
    {
        return $this->toggleBackupLock($server, $backupId, true);
    }

    public function unlockBackup($server, $backupId, Request $request)
    {
        return $this->toggleBackupLock($server, $backupId, false);
    }

    // Backend API: List Restic repo locks for a server
    public function getResticLocks($server, Request $request)
    {
        $user = auth()->user();
        $this->requireAdmin($user);

        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/backups/list', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                $rawBody = $response ? (string) $response->getBody() : null;
                $message = $rawBody ?: 'Failed to fetch locks';
                $this->recordJobFailure($serverRow->uuid, 'locks', $message, [
                    'status' => $response ? $response->getStatusCode() : null,
                    'response' => $rawBody,
                ]);
            }
            return $this->respondWings($response, $user, 'Failed to fetch locks');
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'locks', $e->getMessage(), [
                'exception' => $e->getMessage(),
            ]);
            return $this->exceptionResponse($user, 'Failed to fetch locks', $e);
        }
    }

    // Backend API: Unlock Restic repo for a server
    public function unlockResticRepo($server, Request $request)
    {
        $user = auth()->user();
        $this->requireAdmin($user);

        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        try {
            $client = new \GuzzleHttp\Client();
            $force = $request->query('force');
            $forceQuery = ($force === '1' || $force === 'true' || $force === 'yes') ? '?force=1' : '';
            $response = $client->post($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/unlock' . $forceQuery, [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                $rawBody = $response ? (string) $response->getBody() : null;
                $message = $rawBody ?: 'Failed to unlock repo';
                $this->recordJobFailure($serverRow->uuid, 'unlock_repo', $message, [
                    'status' => $response ? $response->getStatusCode() : null,
                    'response' => $rawBody,
                ]);
            }
            return $this->respondWings($response, $user, 'Failed to unlock repo');
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'unlock_repo', $e->getMessage(), [
                'exception' => $e->getMessage(),
            ]);
            return $this->exceptionResponse($user, 'Failed to unlock repo', $e);
        }
    }

    private function toggleBackupLock($server, $backupId, bool $lock)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_DELETE)) {
            return $deny;
        }
        $backupIdValidated = $this->validateBackupId($backupId);
        if ($backupIdValidated === null) {
            return response()->json(['error' => 'Invalid backup ID'], 422);
        }
        $backupId = $backupIdValidated;

        $nodeApiUrl = $ctx['nodeApiUrl'];
        $token = $ctx['token'];
        $fullUuid = $ctx['fullUuid'];
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        try {
            $client = new \GuzzleHttp\Client();
            $endpoint = $lock ? 'lock' : 'unlock';
            $response = $client->post($nodeApiUrl . '/api/servers/' . $fullUuid . '/restic/backups/' . rawurlencode($backupId) . '/' . $endpoint, [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                ],
            ]);

            return $this->respondWings($response, $user, 'Failed to toggle lock');
        } catch (\Exception $e) {
            return $this->exceptionResponse($user, 'Failed to toggle lock', $e);
        }
    }

    // Backend API: Restore a Restic backup for a server
    public function restoreBackup($server, $backupId, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_RESTORE)) {
            return $deny;
        }
        $backupIdValidated = $this->validateBackupId($backupId);
        if ($backupIdValidated === null) {
            return response()->json(['error' => 'Invalid backup ID'], 422);
        }
        $backupId = $backupIdValidated;

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $daemonToken = trim((string) $ctx['token']);
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        try {
            $client = new \GuzzleHttp\Client();
            $encodedId = rawurlencode($backupId);
            $response = $client->post($nodeApiUrl . '/api/servers/' . $serverRow->uuid . '/restic/backups/' . $encodedId . '/restore', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $daemonToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                ],
            ]);

            if ($response && $response->getStatusCode() === 404) {
                $shortId = strlen($backupId) > 8 ? substr($backupId, 0, 8) : $backupId;
                // Retry with short id
                $response = $client->post($nodeApiUrl . '/api/servers/' . $serverRow->uuid . '/restic/backups/' . $shortId . '/restore', [
                    'http_errors' => false,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $daemonToken,
                        'Accept'        => 'application/json',
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'owner_username' => $ownerUsername,
                        'encryption_key' => $encryptionKey,
                    ],
                ]);

            }

            if (!$response || $response->getStatusCode() >= 300) {
                $message = $response ? (string) $response->getBody() : 'No response from Wings';
                $this->recordJobFailure($serverRow->uuid, 'restore', 'Failed to restore backup', [
                    'status' => $response ? $response->getStatusCode() : null,
                    'response' => $message,
                ]);
                return $this->respondWings($response, $user, 'Failed to restore backup');
            }

            return $this->respondWings($response, $user, 'Failed to restore backup');
        } catch (\Exception $e) {
            $this->recordJobFailure($serverRow->uuid, 'restore', $e->getMessage(), [
                'exception' => $e->getMessage(),
            ]);
            return $this->exceptionResponse($user, 'Failed to restore backup', $e);
        }
    }

    // Backend API: Delete a Restic backup for a server
    public function deleteBackup($server, $backupId, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_DELETE)) {
            return $deny;
        }
        $backupIdValidated = $this->validateBackupId($backupId);
        if ($backupIdValidated === null) {
            return response()->json(['error' => 'Invalid backup ID'], 422);
        }
        $backupId = $backupIdValidated;

        $serverRow = $ctx['serverRow'];
        $nodeApiUrl = $ctx['nodeApiUrl'];
        $daemonToken = trim((string) $ctx['token']);
        $encryptionKey = $ctx['encryptionKey'];
        $ownerUsername = $ctx['ownerUsername'];

        try {
            $client = new \GuzzleHttp\Client();
            $encodedId = rawurlencode($backupId);
            $response = $client->delete($nodeApiUrl . '/api/servers/' . $serverRow->uuid . '/restic/backups/' . $encodedId, [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $daemonToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'owner_username' => $ownerUsername,
                    'encryption_key' => $encryptionKey,
                ],
            ]);

            return $this->respondWings($response, $user, 'Failed to delete backup');
        } catch (\Exception $e) {
            return $this->exceptionResponse($user, 'Failed to delete backup', $e);
        }
    }

    // Backend API: Get Restic schedule settings for a server
    public function getResticSchedule($server, Request $request)
    {
        $user = auth()->user();
        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_READ)) {
            return $deny;
        }

        $serverRow = $ctx['serverRow'];
        $policy = $this->getPolicy($serverRow->uuid);

        $intervalValue = 24;
        $intervalUnit = 'hours';
        $enabled = false;
        $lastRunAt = null;
        $updatedAt = null;

        if ($policy) {
            $intervalValue = (int) ($policy->interval_value ?? 24);
            $intervalUnit = $policy->interval_unit ?: 'hours';
            $enabled = (bool) $policy->schedule_enabled;
            $lastRunAt = $policy->schedule_last_run_at;
            $updatedAt = $policy->updated_at;
        } elseif (Schema::hasTable('restic_schedules')) {
            $schedule = \DB::table('restic_schedules')->where('server_uuid', $serverRow->uuid)->first();
            if ($schedule) {
                $intervalValue = (int) $schedule->interval_value;
                $intervalUnit = $schedule->interval_unit;
                $enabled = (bool) $schedule->enabled;
                $lastRunAt = $schedule->last_run_at;
                $updatedAt = $schedule->updated_at;
            }
        }

        $lastRun = $lastRunAt ? \Carbon\Carbon::parse($lastRunAt) : null;
        $updatedAtParsed = $updatedAt ? \Carbon\Carbon::parse($updatedAt) : null;
        $nextRun = null;
        if ($enabled && $intervalValue > 0) {
            $base = $lastRun ?: ($updatedAtParsed ?: \Carbon\Carbon::now());
            switch ($intervalUnit) {
                case 'minutes':
                    $nextRun = $base->copy()->addMinutes($intervalValue);
                    break;
                case 'hours':
                    $nextRun = $base->copy()->addHours($intervalValue);
                    break;
                case 'days':
                default:
                    $nextRun = $base->copy()->addDays($intervalValue);
                    break;
            }
        }

        return response()->json([
            'interval_value' => $intervalValue,
            'interval_unit' => $intervalUnit,
            'enabled' => $enabled,
            'last_run_at' => $lastRun ? $lastRun->toIso8601String() : null,
            'next_run_at' => $nextRun ? $nextRun->toIso8601String() : null,
        ]);
    }

    // Backend API: Save Restic schedule settings for a server
    public function saveResticSchedule($server, Request $request)
    {
        $user = auth()->user();

        $ctx = $this->getServerContext($server, $user);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }
        if ($deny = $this->requireSubuserPermission($ctx, $user, Permission::ACTION_BACKUP_CREATE)) {
            return $deny;
        }

        $serverRow = $ctx['serverRow'];

        $intervalValue = (int) $request->input('interval_value', 0);
        $intervalUnit = (string) $request->input('interval_unit', 'hours');
        $enabled = (bool) $request->input('enabled', false);

        if ($intervalValue < 1) {
            return response()->json(['error' => 'Interval value must be at least 1'], 422);
        }
        if (!in_array($intervalUnit, ['minutes', 'hours', 'days'], true)) {
            return response()->json(['error' => 'Invalid interval unit'], 422);
        }
        if ($intervalUnit === 'minutes' && $intervalValue < 30) {
            return response()->json(['error' => 'Minimum interval is 30 minutes'], 422);
        }

        $policy = $this->getPolicy($serverRow->uuid);
        $lastRunAt = $policy ? $policy->schedule_last_run_at : null;

        $this->upsertPolicy($serverRow->uuid, [
            'interval_value' => $intervalValue,
            'interval_unit' => $intervalUnit,
            'schedule_enabled' => $enabled,
        ]);

        $nextRunAt = null;
        if ($enabled && $intervalValue > 0) {
            $base = $lastRunAt ? \Carbon\Carbon::parse($lastRunAt) : now();
            switch ($intervalUnit) {
                case 'minutes':
                    $nextRunAt = $base->copy()->addMinutes($intervalValue);
                    break;
                case 'hours':
                    $nextRunAt = $base->copy()->addHours($intervalValue);
                    break;
                case 'days':
                default:
                    $nextRunAt = $base->copy()->addDays($intervalValue);
                    break;
            }
        }

        $lastRunAtIso = null;
        if ($lastRunAt) {
            $lastRunAtIso = $lastRunAt instanceof \Carbon\Carbon
                ? $lastRunAt->toIso8601String()
                : \Carbon\Carbon::parse($lastRunAt)->toIso8601String();
        }

        return response()->json([
            'interval_value' => $intervalValue,
            'interval_unit' => $intervalUnit,
            'enabled' => $enabled,
            'last_run_at' => $lastRunAtIso,
            'next_run_at' => $nextRunAt ? $nextRunAt->toIso8601String() : null,
        ]);
    }

}
