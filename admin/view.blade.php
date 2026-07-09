<div class="row">
  <div class="col-xs-12">
    @if($errors->any())
      <div class="alert alert-danger">
        @foreach($errors->all() as $error)
          <div>{{ $error }}</div>
        @endforeach
      </div>
    @endif

    @if(session('success'))
      <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('encryption_key_success'))
      <div class="alert alert-success">
        <strong>{{ session('encryption_key_success') }}</strong>
        @if(session('new_encryption_key'))
          <div style="margin-top:6px; word-break:break-all;"><code>{{ session('new_encryption_key') }}</code></div>
          <div class="text-danger" style="margin-top:6px;">Save this key now. It will not be shown again.</div>
        @endif
      </div>
    @endif

    @if(session('admin_tool_output'))
      @php
        $toolTitle = session('admin_tool_title', 'Result');
        $toolPayload = session('admin_tool_payload');
      @endphp
      <div class="box box-success">
        <div class="box-header with-border">
          <h3 class="box-title">{{ $toolTitle }}</h3>
        </div>
        <div class="box-body">
          @if(session('admin_tool_server_uuid'))
            <div class="text-muted" style="margin-bottom:8px;">Server: <code>{{ session('admin_tool_server_uuid') }}</code></div>
          @endif

          @if($toolTitle === 'Repository Stats' && is_array($toolPayload))
            @php
              $stats = data_get($toolPayload, 'stats.body', []);
              $disk = data_get($toolPayload, 'disk_usage.body', []);
              $compressed = data_get($stats, 'total_compressed_size', data_get($stats, 'total_size'));
              $uncompressed = data_get($stats, 'total_uncompressed_size');
              $ratio = ($compressed && $uncompressed) ? round($uncompressed / max($compressed, 1), 2) . 'x' : 'Unknown';
            @endphp
            <dl class="dl-horizontal">
              <dt>Repo disk usage</dt><dd>{{ number_format((int) data_get($disk, 'total_bytes', 0) / 1048576, 2) }} MiB</dd>
              <dt>Stored data</dt><dd>{{ is_numeric($compressed) ? number_format($compressed / 1048576, 2) . ' MiB' : 'Unknown' }}</dd>
              <dt>Original data</dt><dd>{{ is_numeric($uncompressed) ? number_format($uncompressed / 1048576, 2) . ' MiB' : 'Unknown' }}</dd>
              <dt>Efficiency ratio</dt><dd>{{ $ratio }}</dd>
              <dt>Snapshots</dt><dd>{{ data_get($stats, 'snapshots_count', 'Unknown') }}</dd>
            </dl>
            @if(data_get($stats, 'error') || data_get($disk, 'error'))
              <pre class="restic-result-pre">{{ session('admin_tool_output') }}</pre>
            @endif
          @elseif($toolTitle === 'Health Check' && is_array($toolPayload))
            @php
              $body = data_get($toolPayload, 'status.body', data_get($toolPayload, 'start.body', []));
              $status = data_get($body, 'status', data_get($body, 'message', 'unknown'));
              $message = data_get($body, 'message', '');
              $output = data_get($body, 'result.output', data_get($body, 'output', ''));
            @endphp
            <p><strong>Status:</strong> <span class="label label-{{ $status === 'completed' ? 'success' : ($status === 'failed' ? 'danger' : 'warning') }}">{{ $status }}</span></p>
            @if($message)<p><strong>Message:</strong> {{ $message }}</p>@endif
            @if($output)<pre class="restic-result-pre">{{ $output }}</pre>@endif
            @if(!$output && $status === 'running')
              <p class="text-muted">The check is still running on Wings. Run the health check again in a moment to refresh the status.</p>
            @endif
          @elseif(($toolTitle === 'Current Encryption Key' || $toolTitle === 'Latest Historical Encryption Key') && is_array($toolPayload))
            @if(data_get($toolPayload, 'owner_username'))
              <p><strong>Owner:</strong> {{ data_get($toolPayload, 'owner_username') }}</p>
            @endif
            @if(data_get($toolPayload, 'created_at'))
              <p><strong>Key record:</strong> {{ data_get($toolPayload, 'created_at') }}</p>
            @endif
            @if(data_get($toolPayload, 'encryption_key'))
              <code style="word-break:break-all; white-space:normal; display:block;">{{ data_get($toolPayload, 'encryption_key') }}</code>
            @else
              <span class="text-muted">No key found.</span>
            @endif
          @elseif($toolTitle === 'Key History' && is_array($toolPayload))
            @php $rows = data_get($toolPayload, 'rows', []); @endphp
            @if(count($rows))
              <div class="table-responsive">
                <table class="table table-condensed">
                  <thead><tr><th>Key</th><th>Owner</th><th>Created</th></tr></thead>
                  <tbody>
                    @foreach($rows as $row)
                      <tr>
                        <td style="word-break:break-all;"><code>{{ $row->encryption_key ?? data_get($row, 'encryption_key') }}</code></td>
                        <td>{{ $row->owner_username ?? data_get($row, 'owner_username', 'unknown') }}</td>
                        <td>{{ $row->created_at ?? data_get($row, 'created_at', 'unknown') }}</td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            @else
              <span class="text-muted">No key history found.</span>
            @endif
          @elseif($toolTitle === 'Repository Locks' && is_array($toolPayload))
            @php $body = data_get($toolPayload, 'body', []); @endphp
            <p><strong>Status:</strong> {{ data_get($toolPayload, 'status') }}</p>
            @if(data_get($body, 'message'))<p><strong>Message:</strong> {{ data_get($body, 'message') }}</p>@endif
            @if(data_get($body, 'error'))<p class="text-danger"><strong>Error:</strong> {{ data_get($body, 'error') }}</p>@endif
            <pre class="restic-result-pre">{{ json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
          @elseif($toolTitle === 'Node Repo Inventory' && is_array($toolPayload))
            @php $nodes = data_get($toolPayload, 'nodes', []); @endphp
            @if(count($nodes))
              @foreach($nodes as $node)
                @php
                  $status = data_get($node, 'status', 'unknown');
                  $body = data_get($node, 'body', []);
                  $liveRepos = data_get($body, 'live_repos', []);
                  $archivedRepos = data_get($body, 'archived_repos', []);
                @endphp
                <div class="panel panel-default">
                  <div class="panel-heading">
                    <strong>{{ data_get($node, 'node_name', 'Unknown node') }}</strong>
                    <span class="label label-{{ $status === 'ok' ? 'success' : ($status === 'skipped' ? 'default' : 'danger') }}">{{ $status }}</span>
                    @if(data_get($node, 'probe_server_uuid'))
                      <span class="text-muted">via <code>{{ data_get($node, 'probe_server_uuid') }}</code></span>
                    @endif
                  </div>
                  <div class="panel-body">
                    @if(data_get($node, 'message'))
                      <p class="text-muted">{{ data_get($node, 'message') }}</p>
                    @endif
                    @if($status === 'ok')
                      <p class="text-muted small">
                        Live root: <code>{{ data_get($body, 'repo_root') }}</code><br>
                        Archive root: <code>{{ data_get($body, 'archive_root') }}</code>
                      </p>
                      <div class="row">
                        <div class="col-xs-12 col-md-6">
                          <h4>Live Repos</h4>
                          @if(count($liveRepos))
                            <div class="table-responsive" style="max-height:220px; overflow:auto;">
                              <table class="table table-condensed">
                                <thead><tr><th>Name</th><th>Config</th><th>Modified</th><th>Action</th></tr></thead>
                                <tbody>
                                  @foreach($liveRepos as $repo)
                                    <tr>
                                      <td style="word-break:break-all;"><code>{{ data_get($repo, 'name') }}</code></td>
                                      <td>{!! data_get($repo, 'has_config') ? '<span class="label label-success">yes</span>' : '<span class="label label-warning">no</span>' !!}</td>
                                      <td class="text-muted small">{{ data_get($repo, 'modified') }}</td>
                                      <td>
                                        @if(data_get($repo, 'has_config'))
                                          <form method="POST" action="/admin/extensions/resticbackups" style="display:inline;">
                                            @csrf
                                            <input type="hidden" name="node_id" value="{{ data_get($node, 'node_id') }}">
                                            <input type="hidden" name="repo_name" value="{{ data_get($repo, 'name') }}">
                                            <button
                                              type="submit"
                                              class="btn btn-xs btn-warning"
                                              name="action"
                                              value="admin_archive_repo_by_name"
                                              onclick="return confirm('Move this live Restic repo directory into the archive folder?');"
                                            >Move to Archive</button>
                                          </form>
                                        @else
                                          <span class="text-muted small">invalid repo</span>
                                        @endif
                                      </td>
                                    </tr>
                                  @endforeach
                                </tbody>
                              </table>
                            </div>
                          @else
                            <p class="text-muted">No live Restic repos found.</p>
                          @endif
                        </div>
                        <div class="col-xs-12 col-md-6">
                          <h4>Archived Repos</h4>
                          @if(count($archivedRepos))
                            <div class="table-responsive" style="max-height:220px; overflow:auto;">
                              <table class="table table-condensed">
                                <thead><tr><th>Name</th><th>Config</th><th>Modified</th></tr></thead>
                                <tbody>
                                  @foreach($archivedRepos as $repo)
                                    <tr>
                                      <td style="word-break:break-all;"><code>{{ data_get($repo, 'name') }}</code></td>
                                      <td>{!! data_get($repo, 'has_config') ? '<span class="label label-success">yes</span>' : '<span class="label label-warning">no</span>' !!}</td>
                                      <td class="text-muted small">{{ data_get($repo, 'modified') }}</td>
                                    </tr>
                                  @endforeach
                                </tbody>
                              </table>
                            </div>
                          @else
                            <p class="text-muted">No archived Restic repos found.</p>
                          @endif
                        </div>
                      </div>
                    @endif
                  </div>
                </div>
              @endforeach
            @else
              <span class="text-muted">No node inventory returned.</span>
            @endif
          @elseif(($toolTitle === 'Unlock Repo' || $toolTitle === 'Force Unlock Repo') && is_array($toolPayload))
            @php $body = data_get($toolPayload, 'body', []); @endphp
            <p><strong>Status:</strong> {{ data_get($toolPayload, 'status') }}</p>
            <p><strong>Message:</strong> {{ data_get($body, 'message', data_get($body, 'error', 'No message returned.')) }}</p>
            @if(data_get($body, 'output'))<pre class="restic-result-pre">{{ data_get($body, 'output') }}</pre>@endif
          @else
            <pre class="restic-result-pre">{{ session('admin_tool_output') }}</pre>
          @endif
        </div>
      </div>
    @endif
  </div>
</div>

<div class="row">
  <div class="col-xs-12">
    <div class="box box-primary">
      <div class="box-header with-border"><h3 class="box-title">Restic Backups Settings</h3></div>
      <div class="box-body">
        <div class="row">
          <div class="col-xs-12 col-md-6">
            <form method="POST" action="/admin/extensions/resticbackups" class="form-inline">
              @csrf
              <input type="hidden" name="action" value="save_repo_multiplier">
              <label class="control-label" style="margin-right:8px;">Repo size multiplier</label>
              <input type="number" name="repo_multiplier" min="1" step="1" class="form-control" value="{{ $repoMultiplier ?? 2 }}" style="width:100px;">
              <button type="submit" class="btn btn-primary">Save</button>
            </form>
          </div>
          <div class="col-xs-12 col-md-6">
            <form method="POST" action="{{ route('admin.extensions.resticbackups.downloadScript') }}" class="pull-right">
              @csrf
              <button type="submit" class="btn btn-info">Download Wings Script</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-xs-12 col-md-6">
    <div class="box box-default">
      <div class="box-header with-border"><h3 class="box-title">Repository Tools</h3></div>
      <div class="box-body">
        <form method="POST" action="/admin/extensions/resticbackups">
          @csrf
          <div class="form-group">
            <label class="control-label">Server</label>
            <select class="form-control" name="server_uuid" required>
              <option value="">Select a server</option>
              @foreach($servers as $server)
                <option value="{{ $server->server_uuid }}" @if(session('admin_tool_server_uuid') === $server->server_uuid) selected @endif>{{ $server->server_name }} ({{ $server->server_uuid }})</option>
              @endforeach
            </select>
          </div>
          <div class="form-group">
            <label class="control-label">Health check subset</label>
            <input type="text" class="form-control" name="read_data_subset" placeholder="Optional, example: 1/100">
          </div>
          <button type="submit" class="btn btn-primary" name="action" value="admin_repo_stats">Load Stats</button>
          <button type="submit" class="btn btn-primary" name="action" value="admin_health_check">Run Health Check</button>
          <button type="submit" class="btn btn-default" name="action" value="admin_locks">Check Locks</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xs-12 col-md-6">
    <div class="box box-default">
      <div class="box-header with-border"><h3 class="box-title">Encryption Keys</h3></div>
      <div class="box-body">
        <form method="POST" action="/admin/extensions/resticbackups">
          @csrf
          <div class="form-group">
            <label class="control-label">Server</label>
            <select class="form-control" name="server_uuid" required>
              <option value="">Select a server</option>
              @foreach($servers as $server)
                <option value="{{ $server->server_uuid }}" @if(session('admin_tool_server_uuid') === $server->server_uuid) selected @endif>{{ $server->server_name }} ({{ $server->server_uuid }})</option>
              @endforeach
            </select>
            <p class="help-block">Current live servers only. Deleted and archived server keys are in the Archived / Historical Servers section below.</p>
          </div>
          <button type="submit" class="btn btn-warning" name="action" value="admin_reveal_key" onclick="return confirm('Reveal this server encryption key?');">Reveal Current Key</button>
          <button type="submit" class="btn btn-default" name="action" value="admin_key_history">Load Key History</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-xs-12 col-md-6">
    <div class="box box-danger">
      <div class="box-header with-border"><h3 class="box-title">Danger Zone</h3></div>
      <div class="box-body">
        <form method="POST" action="/admin/extensions/resticbackups">
          @csrf
          <div class="form-group">
            <label class="control-label">Server</label>
            <select class="form-control" name="server_uuid" required>
              <option value="">Select a server</option>
              @foreach($servers as $server)
                <option value="{{ $server->server_uuid }}" @if(session('admin_tool_server_uuid') === $server->server_uuid) selected @endif>{{ $server->server_name }} ({{ $server->server_uuid }})</option>
              @endforeach
            </select>
          </div>
          <button type="submit" class="btn btn-warning" name="action" value="admin_unlock_repo" onclick="return confirm('Unlock this repository?');">Unlock Repo</button>
          <button type="submit" class="btn btn-danger" name="action" value="admin_force_unlock_repo" onclick="return confirm('Force unlock only if no backup is running. Continue?');">Force Unlock Repo</button>
          <button type="submit" class="btn btn-warning" name="action" value="archive_repo" onclick="return confirm('Create a final locked backup if possible and move this repo to the archive folder?');">Archive Repo</button>
          <button type="submit" class="btn btn-danger" name="action" value="generate_key" onclick="return confirm('Generate a new encryption key? Existing backups may become unrecoverable if the old key is lost.');">Generate New Key</button>
          <button type="submit" class="btn btn-danger" name="action" value="delete_repo" onclick="return confirm('Delete this server Restic repository? This cannot be undone.');">Delete Repo</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xs-12 col-md-6">
    <div class="box box-default">
      <div class="box-header with-border"><h3 class="box-title">Failed Jobs</h3></div>
      <div class="box-body">
        @if(isset($failedJobs) && $failedJobs->count())
          <div class="table-responsive" style="max-height:260px; overflow:auto;">
            <table class="table table-condensed">
              <thead><tr><th>Server</th><th>Type</th><th>Message</th><th>Time</th></tr></thead>
              <tbody>
                @foreach($failedJobs as $job)
                  <tr>
                    <td><div>{{ $job->server_name ?? 'Unknown' }}</div><div class="text-muted small">{{ $job->server_uuid }}</div></td>
                    <td>{{ $job->job_type }}</td>
                    <td style="max-width:260px; word-break:break-word;">{{ $job->message }}</td>
                    <td class="text-muted small">{{ $job->created_at }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @else
          <div class="text-muted">No failed jobs recorded.</div>
        @endif
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-xs-12">
    <div class="box box-default">
      <div class="box-header with-border"><h3 class="box-title">Archived / Historical Servers</h3></div>
      <div class="box-body">
        <p class="text-muted small">Actual archived repos are discovered from Wings only when you scan nodes. Historical key records are separate and only prove that a key was preserved.</p>
        <form method="POST" action="/admin/extensions/resticbackups" style="margin-bottom:14px;">
          @csrf
          <button type="submit" class="btn btn-info" name="action" value="admin_repo_inventory">Scan Actual Node Repos</button>
          <span class="text-muted small">Lists live leftovers and actual archived Restic repo directories that physically exist on each node.</span>
        </form>
        <form method="GET" action="/admin/extensions/resticbackups" style="margin-bottom:14px;">
          <div class="row">
            <div class="col-xs-12 col-md-7">
              <label class="control-label">Search history</label>
              <input
                class="form-control"
                name="historical_search"
                placeholder="Search by server UUID or owner username"
                value="{{ $historicalSearch ?? '' }}"
              >
            </div>
            <div class="col-xs-12 col-md-5" style="padding-top:25px;">
              <button type="submit" class="btn btn-primary">Search</button>
              @if(!empty($historicalSearch))
                <a href="/admin/extensions/resticbackups" class="btn btn-default">Clear</a>
              @endif
            </div>
          </div>
        </form>
        @if(isset($keyHistoryOptions) && $keyHistoryOptions->count())
          <form method="POST" action="/admin/extensions/resticbackups" style="margin-bottom:14px;">
            @csrf
            <div class="row">
              <div class="col-xs-12 col-md-7">
                <div class="form-group">
                  <label class="control-label">Search archived or historical server</label>
                  <input
                    class="form-control"
                    name="server_uuid"
                    list="restic-archived-server-options"
                    placeholder="Type a server UUID or select from history"
                    value="{{ session('admin_tool_server_uuid') }}"
                    required
                  >
                  <datalist id="restic-archived-server-options">
                    @foreach($keyHistoryOptions as $row)
                      <option value="{{ $row->server_uuid }}">{{ $row->owner_username ?: 'Unknown owner' }} - latest key {{ $row->latest_created_at }}</option>
                    @endforeach
                  </datalist>
                  <p class="help-block">
                    Use this to recover preserved keys. This list shows {{ empty($historicalSearch) ? 'the 5 most recent historical key records' : 'matching historical key records' }}.
                  </p>
                </div>
              </div>
              <div class="col-xs-12 col-md-5" style="padding-top:25px;">
                <button type="submit" class="btn btn-warning" name="action" value="admin_reveal_historical_key" onclick="return confirm('Reveal the latest historical key for this server UUID?');">Reveal Latest Historical Key</button>
                <button type="submit" class="btn btn-default" name="action" value="admin_key_history">Load Full Key History</button>
              </div>
            </div>
          </form>
          <div class="table-responsive" style="max-height:260px; overflow:auto;">
            <table class="table table-condensed">
              <thead>
                <tr>
                  <th colspan="3">{{ empty($historicalSearch) ? 'Recent Historical Key Records' : 'Historical Key Search Results' }}</th>
                </tr>
                <tr><th>Server UUID</th><th>Last Known Owner</th><th>Latest Key Record</th></tr>
              </thead>
              <tbody>
                @foreach($keyHistoryOptions as $row)
                  @php
                    $owner = $row->owner_username ?: 'unknown';
                  @endphp
                  <tr>
                    <td><code>{{ $row->server_uuid }}</code></td>
                    <td>{{ $owner }}</td>
                    <td class="text-muted small">{{ $row->latest_created_at }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @else
          <div class="text-muted">No historical key records found.</div>
        @endif
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-xs-12">
    <div class="box box-default">
      <div class="box-header with-border"><h3 class="box-title">Admin Guide</h3></div>
      <div class="box-body">
        <details>
          <summary>Show installation and operations notes</summary>
          <div class="restic-guide" style="margin-top:12px; max-height:520px; overflow:auto; background:#0f172a; color:#e5e7eb; padding:16px; border-radius:4px;">
            {!! \Illuminate\Support\Str::markdown($guide) !!}
          </div>
        </details>
      </div>
    </div>
  </div>
</div>

<style>
  .restic-guide h1, .restic-guide h2, .restic-guide h3, .restic-guide h4 { color:#f9fafb; margin:16px 0 8px; }
  .restic-guide a { color:#60a5fa; text-decoration:underline; }
  .restic-guide code { background:#111827; color:#e5e7eb; padding:2px 4px; border-radius:4px; }
  .restic-guide pre { background:#111827; color:#e5e7eb; padding:12px; border-radius:4px; overflow:auto; }
  .restic-guide pre code { background:transparent; padding:0; }
  .restic-result-pre { white-space:pre-wrap; word-break:break-word; max-height:420px; overflow:auto; }
  .box-body form .btn { margin:0 6px 6px 0; }
</style>
