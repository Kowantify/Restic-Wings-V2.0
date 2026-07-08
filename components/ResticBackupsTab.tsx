import React from 'react';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArchive, faBoxOpen, faCloudDownloadAlt, faEllipsisH, faLock, faStickyNote, faTrashAlt } from '@fortawesome/free-solid-svg-icons';
import { format, formatDistanceToNow } from 'date-fns';
import GreyRowBox from '@/components/elements/GreyRowBox';
import { bytesToString } from '@/lib/formatters';
import Input from '@/components/elements/Input';
import { Button } from '@/components/elements/button/index';
import { ServerContext } from '@/state/server';

const ResticBackupsTab: React.FC = () => {
  const [loadingMore, setLoadingMore] = React.useState(false);
  const [hasMore, setHasMore] = React.useState(false);
  const [nextCursor, setNextCursor] = React.useState<string | null>(null);
  const [sinceInput, setSinceInput] = React.useState('');
  const [untilInput, setUntilInput] = React.useState('');
  const [activeSince, setActiveSince] = React.useState('');
  const [activeUntil, setActiveUntil] = React.useState('');
  const [totalAvailable, setTotalAvailable] = React.useState<number | null>(null);
  const [activeTab, setActiveTab] = React.useState<'backups' | 'schedule'>('backups');
  const [scheduleValue, setScheduleValue] = React.useState<number>(24);
  const [scheduleUnit, setScheduleUnit] = React.useState<'minutes' | 'hours' | 'days'>('hours');
  const [scheduleEnabled, setScheduleEnabled] = React.useState(false);
  const [scheduleLoading, setScheduleLoading] = React.useState(false);
  const [scheduleSaving, setScheduleSaving] = React.useState(false);
  const [scheduleNextRun, setScheduleNextRun] = React.useState<string | null>(null);
  const [scheduleLastRun, setScheduleLastRun] = React.useState<string | null>(null);
  const [repoSizeBytes, setRepoSizeBytes] = React.useState<number | null>(null);
  const [maxRepoBytes, setMaxRepoBytes] = React.useState<number | null>(null);
  const [repoMultiplier, setRepoMultiplier] = React.useState<number | null>(null);
  const [hideAfterRestic] = React.useState(true);

  const [backups, setBackups] = React.useState<Array<{ id: string; short_id?: string; time: string; size?: number; data_added_packed?: number; paths?: string[]; tags?: string[]; locked?: boolean; note?: string }>>([]);
  const [creating, setCreating] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [success, setSuccess] = React.useState<string | JSX.Element | null>(null);
  const [progress, setProgress] = React.useState<string | null>(null);
  const [downloadNotice, setDownloadNotice] = React.useState<string | JSX.Element | null>(null);
  const [openActionMenu, setOpenActionMenu] = React.useState<string | null>(null);

  const getUuid = (): string | null => {
    // @ts-ignore
    if (typeof window !== 'undefined' && window.Panel && window.Panel.server) {
      // @ts-ignore
      return window.Panel.server.uuid || window.Panel.server.id || null;
    }
    const url = window?.location?.pathname || '';
    let match = url.match(/\/server\/([a-fA-F0-9\-]{36})/);
    if (match && match[1]) return match[1];
    match = url.match(/\/server\/([a-zA-Z0-9_-]{8,36})/);
    if (match && match[1]) return match[1];
    return null;
  };
  const serverData = ServerContext.useStoreState(state => state.server.data);
  const uuid = serverData?.uuid ?? getUuid();
  const isServerReady = Boolean(uuid);

  const backupLimitFromStore =
    // @ts-ignore
    serverData?.backup_limit ??
    // @ts-ignore
    serverData?.feature_limits?.backups ??
    // @ts-ignore
    serverData?.featureLimits?.backups ??
    null;
  const [backupLimit, setBackupLimit] = React.useState<number | null>(
    // @ts-ignore
    backupLimitFromStore ?? (typeof window !== 'undefined' && window.Panel?.server?.backup_limit != null
      // @ts-ignore
      ? window.Panel.server.backup_limit
      : null)
  );
  const getCookie = (name: string): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.match(new RegExp(`(?:^|; )${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : '';
  };
  const getCsrfToken = (): string => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      || (document.querySelector('input[name="_token"]') as HTMLInputElement | null)?.value
      || '';
  };
  const csrfHeaders = (): Record<string, string> => {
    const token = getCsrfToken();
    const xsrf = getCookie('XSRF-TOKEN');
    return {
      ...(token ? { 'X-CSRF-TOKEN': token } : {}),
      ...(!token && xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
    };
  };
  function formatBytes(bytes?: number): string {
    if (bytes === undefined || bytes === null || isNaN(bytes)) return '';
    return bytesToString(bytes);
  }

  const fetchBackups = async (opts?: { append?: boolean; cursor?: string | null; since?: string; until?: string }) => {
    if (!uuid) return;
    const append = opts?.append === true;
    if (!append) {
      setBackups([]);
      setError(null);
      setHasMore(false);
      setNextCursor(null);
      setTotalAvailable(null);
    }

    const params = new URLSearchParams();
    const pageLimit = 5;
    params.set('limit', String(pageLimit));
    const since = opts?.since ?? activeSince;
    const until = opts?.until ?? activeUntil;
    const cursor = opts?.cursor ?? null;
    if (since) params.set('since', since);
    if (until) params.set('until', until);
    if (cursor) params.set('cursor', cursor);

    try {
      const res = await fetch(`/extensions/resticbackups/servers/${uuid}/backups/restic?${params.toString()}`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
      });
      if (!res.ok) throw new Error('Failed to fetch backups');
      const data = await res.json();
      if (data && data.backups && Array.isArray(data.backups)) {
        const mapped = data.backups
          .map((snap: any) => ({
            id: snap.id || '',
            short_id: snap.short_id || (snap.id ? String(snap.id).slice(0, 8) : ''),
            time: snap.time || '',
            size: snap.size || snap.stats?.total_size || snap.stats?.size || undefined,
            data_added_packed: snap.data_added_packed || (snap.summary && snap.summary.data_added_packed) || undefined,
            paths: snap.paths || [],
            tags: Array.isArray(snap.tags) ? snap.tags : undefined,
            locked: typeof snap.locked === 'boolean' ? snap.locked : undefined,
            note: typeof snap.note === 'string' ? snap.note : undefined,
          }))
          .sort((a: { time: string }, b: { time: string }) => new Date(b.time).getTime() - new Date(a.time).getTime());

        setBackups(prev => (append ? [...prev, ...mapped] : mapped));
        if (typeof data.total === 'number') {
          setTotalAvailable(data.total);
        }
        const limitValue = typeof data.backup_limit === 'number'
          ? data.backup_limit
          : (typeof data.backup_limit === 'string' ? Number(data.backup_limit) : null);
        if (typeof limitValue === 'number' && !Number.isNaN(limitValue)) {
          setBackupLimit(limitValue);
        }
        let next = data.next_cursor || null;
        const noFilters = !since && !until;
        if (!next && noFilters && mapped.length >= pageLimit) {
          const lastTime = mapped[mapped.length - 1]?.time || null;
          next = lastTime || null;
        }
        setNextCursor(next);
        setHasMore(Boolean(next));
      } else {
        if (!append) setBackups([]);
        setHasMore(false);
        setNextCursor(null);
        if (!append) setTotalAvailable(null);
      }
    } catch (e: any) {
      if (!append) {
        setError('Could not load restic backups: ' + e.message);
        setBackups([]);
      }
      setHasMore(false);
      setNextCursor(null);
    } finally {
      setLoadingMore(false);
    }
  };

  React.useEffect(() => {
    if (!uuid) return;
    fetchBackups({ append: false, cursor: null, since: activeSince, until: activeUntil });
  }, [uuid, success, activeSince, activeUntil]);

  React.useEffect(() => {
    if (!uuid || activeTab !== 'backups') return;

    const fetchRepoUsage = async () => {
      try {
        const [statsResp, limitsResp] = await Promise.all([
          fetch(`/extensions/resticbackups/servers/${uuid}/backups/restic/stats`, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
          }),
          fetch(`/extensions/resticbackups/servers/${uuid}/backups/restic/limits`, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
          }),
        ]);

        if (statsResp.ok) {
          const statsData = await statsResp.json().catch(() => null);
          const size = typeof statsData?.total_compressed_size === 'number'
            ? statsData.total_compressed_size
            : (typeof statsData?.total_size === 'number' ? statsData.total_size : null);
          setRepoSizeBytes(size);
        } else {
          setRepoSizeBytes(null);
        }

        if (limitsResp.ok) {
          const limitsData = await limitsResp.json().catch(() => null);
          const maxBytes = typeof limitsData?.max_repo_bytes === 'number' ? limitsData.max_repo_bytes : null;
          const multiplier = typeof limitsData?.repo_multiplier === 'number' ? limitsData.repo_multiplier : null;
          setMaxRepoBytes(maxBytes);
          setRepoMultiplier(multiplier);
        } else {
          setMaxRepoBytes(null);
          setRepoMultiplier(null);
        }
      } catch {
        setRepoSizeBytes(null);
        setMaxRepoBytes(null);
        setRepoMultiplier(null);
      }
    };

    fetchRepoUsage();
  }, [uuid, activeTab, success]);



  React.useEffect(() => {
    if (!uuid || activeTab !== 'schedule') return;
    setScheduleLoading(true);
    fetch(`/extensions/resticbackups/servers/${uuid}/backups/restic/schedule`, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(res => res.json())
      .then(data => {
        if (typeof data.interval_value === 'number') setScheduleValue(data.interval_value);
        if (data.interval_unit === 'minutes' || data.interval_unit === 'hours' || data.interval_unit === 'days') {
          setScheduleUnit(data.interval_unit);
        }
        if (typeof data.enabled === 'boolean') setScheduleEnabled(data.enabled);
        if (typeof data.next_run_at === 'string' || data.next_run_at === null) setScheduleNextRun(data.next_run_at);
        if (typeof data.last_run_at === 'string' || data.last_run_at === null) setScheduleLastRun(data.last_run_at);
      })
      .catch(() => {})
      .finally(() => setScheduleLoading(false));
  }, [uuid, activeTab]);

  React.useEffect(() => {
    if (!openActionMenu) return;

    const close = () => setOpenActionMenu(null);
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  }, [openActionMenu]);

  React.useEffect(() => {
    const toggleBuiltInBackups = (hide: boolean) => {
      const containsRestic = (el: Element | null) => !!el?.querySelector?.('.restic-backups');

      const builtInRows = Array.from(
        document.querySelectorAll('div[class^="BackupRow___"], div[class*=" BackupRow___"]')
      ).filter(el => !el.closest('.restic-backups'));

      builtInRows.forEach(el => {
        if (!containsRestic(el)) {
          (el as HTMLElement).style.display = hide ? 'none' : '';
        }
      });

      const builtInCreateButtons = Array.from(document.querySelectorAll('button')).filter(
        btn => btn.textContent?.trim() === 'Create Backup' && !btn.closest('.restic-backups')
      );

      builtInCreateButtons.forEach(btn => {
        if (!containsRestic(btn)) {
          (btn as HTMLElement).style.display = hide ? 'none' : '';
        }
      });

      const backupHeadings = Array.from(document.querySelectorAll('h1,h2,h3,h4,h5')).filter(
        h => h.textContent?.trim() === 'Backups' && !h.closest('.restic-backups')
      );

      backupHeadings.forEach(h => {
        if (!containsRestic(h)) {
          (h as HTMLElement).style.display = hide ? 'none' : '';
        }
      });

      const builtInSummary = Array.from(document.querySelectorAll('p,span,div')).filter(el =>
        (el.textContent || '').toLowerCase().includes('backups have been created for this server') &&
        !el.closest('.restic-backups')
      );

      builtInSummary.forEach(el => {
        if (!containsRestic(el)) {
          (el as HTMLElement).style.display = hide ? 'none' : '';
        }
      });

      const emptyStateText = 'it looks like there are no backups currently stored for this server.';
      const builtInEmptyState = Array.from(document.querySelectorAll('p,span,div')).filter(el =>
        (el.textContent || '').toLowerCase().includes(emptyStateText) &&
        !el.closest('.restic-backups')
      );

      builtInEmptyState.forEach(el => {
        if (!containsRestic(el)) {
          (el as HTMLElement).style.display = hide ? 'none' : '';
        }
      });
    };

    const hideAfter = (hide: boolean) => {
      const resticRoot = document.querySelector('.restic-backups');
      if (!resticRoot) return;
      const resticContainer = resticRoot.closest('section,div');
      if (!resticContainer || !resticContainer.parentElement) return;

      const siblings = Array.from(resticContainer.parentElement.children);
      const index = siblings.indexOf(resticContainer as Element);
      if (index === -1) return;

      siblings.slice(index + 1).forEach(el => {
        if (el instanceof HTMLElement && !el.querySelector('.restic-backups')) {
          el.style.display = hide ? 'none' : '';
        }
      });
    };

    const applyVisibility = (hide: boolean) => {
      toggleBuiltInBackups(hide);
      hideAfter(hide);
    };

    if (!hideAfterRestic) {
      applyVisibility(false);
      return;
    }

    applyVisibility(true);
    const observer = new MutationObserver(() => applyVisibility(true));
    observer.observe(document.body, { childList: true, subtree: true });
    return () => observer.disconnect();
  }, [hideAfterRestic]);


  const isAtBackupLimit = backupLimit !== null && (totalAvailable ?? backups.length) >= backupLimit;

  const handleCreateBackup = async () => {
    if (isAtBackupLimit) {
      const confirmed = window.confirm('Backup limit reached. This will overwrite the oldest backup. Continue?');
      if (!confirmed) return;
    }
    setCreating(true);
    setError(null);
    setSuccess(null);
    setProgress('Starting backup...');
    try {
      if (!uuid) {
        setError('Server UUID is missing. Cannot create backup.');
        setCreating(false);
        setProgress(null);
        return;
      }
      const payload: { max_backups?: number } = {};
      if (typeof backupLimit === 'number' && !Number.isNaN(backupLimit)) {
        payload.max_backups = backupLimit;
      }

      const res = await fetch(`/extensions/resticbackups/servers/${uuid}/backups/restic?async=1`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...csrfHeaders(),
        },
        body: JSON.stringify(payload),
      });
      let data: any = null;
      let rawText: string | null = null;
      try {
        data = await res.clone().json();
      } catch (jsonErr) {
        try {
          rawText = await res.clone().text();
        } catch {}
      }
      if (!res.ok) {
          const message = 'Failed to create backup';
          throw new Error(message);
      }
      if ((data && data.message === 'backup created') || (data && data.response && data.response.message === 'backup created')) {
        setProgress(null);
        setSuccess('Backup created successfully!');
        setCreating(false);
        return;
      }
      if (data && data.message === 'backup started') {
        setProgress('Backup running...');
        let pollCount = 0;
        const startTime = Date.now();
        const pollMax = 150;
        const poll = async () => {
          pollCount++;
          if (!uuid) {
            setProgress(null);
            setCreating(false);
            return;
          }
          try {
            const res = await fetch(`/extensions/resticbackups/servers/${uuid}/backups/restic/status`, {
              method: 'GET',
              headers: { 'Accept': 'application/json' },
              credentials: 'same-origin',
            });
            if (res.ok) {
              const status = await res.json();
              if (status?.status === 'completed') {
                setProgress(null);
                setSuccess('Backup completed successfully!');
                setCreating(false);
                return;
              }
              if (status?.status === 'failed') {
                setProgress(null);
                setError(status?.message || 'Backup failed.');
                setCreating(false);
                return;
              }
            }
          } catch {
            // ignore transient errors while polling
          }

          if (pollCount >= pollMax) {
            setProgress(null);
            setError('Backup is still running. Please check later.');
            setCreating(false);
            return;
          }
          const elapsed = Math.floor((Date.now() - startTime) / 1000);
          setProgress(`Backup running... (${elapsed}s elapsed)`);
          const delay = Math.min(2000 + pollCount * 500, 10000);
          setTimeout(poll, delay);
        };
        poll();
        return;
      }
    } catch (err: any) {
      setError(err.message);
      setProgress(null);
      setCreating(false);
    }
  };

  const handleApplyFilters = () => {
    setActiveSince(sinceInput);
    setActiveUntil(untilInput);
  };

  const handleDownloadBackup = async (backup: any, forceSftp: boolean, archiveFormat: 'zip' | 'tar.zst') => {
    setOpenActionMenu(null);
    if (!uuid) return;

    const formatLabel = archiveFormat === 'tar.zst' ? 'TAR.ZST' : 'ZIP';
    setProgress(forceSftp ? `Preparing SFTP ${formatLabel} download...` : `Preparing ${formatLabel} download...`);
    setError(null);
    setSuccess(null);

    try {
      const downloadId = backup.id || backup.short_id;
      if (!downloadId) {
        alert('Backup ID is missing');
        setProgress(null);
        return;
      }

      const archiveDescription = archiveFormat === 'tar.zst'
        ? 'TAR.ZST is much smaller but requires zstd, 7-Zip, NanaZip, or another compatible extractor.'
        : 'ZIP is the most compatible option and uses fast compression.';
      setDownloadNotice(forceSftp
        ? `Preparing a temporary ${archiveFormat === 'tar.zst' ? '.tar.zst' : '.zip'} file in this server's files for SFTP download. ${archiveDescription}`
        : `Backups over 5GB are automatically prepared for SFTP download. ${archiveDescription}`
      );

      const delivery = forceSftp ? 'sftp' : 'stream';
      const prepareUrl = `/extensions/resticbackups/servers/${uuid}/backups/restic/${encodeURIComponent(downloadId)}/download/prepare`;
      const statusUrl = `/extensions/resticbackups/servers/${uuid}/backups/restic/${encodeURIComponent(downloadId)}/download/status`;
      const streamUrl = `/extensions/resticbackups/servers/${uuid}/backups/restic/${encodeURIComponent(downloadId)}/download/stream?skip_prepare=1`;
      const prepRes = await fetch(prepareUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...csrfHeaders(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ delivery, force_sftp: forceSftp, archive_format: archiveFormat }),
      });

      if (!prepRes.ok) {
        alert('Failed to prepare download.');
        setProgress(null);
        return;
      }

      let pollCount = 0;
      const startTime = Date.now();
      const pollMax = 300;
      const poll = async () => {
        pollCount++;
        try {
          const res = await fetch(statusUrl, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
          });
          if (res.ok) {
            const status = await res.json();
            if (status?.status === 'ready' || status?.status === 'completed') {
              setProgress('Downloading backup...');
              window.location.href = streamUrl;
              setTimeout(() => setProgress(null), 1500);
              return;
            }
            if (status?.status === 'sftp_ready') {
              setProgress(null);
              const sftpUrl = status?.sftp?.url;
              const sftpPath = status?.sftp?.path || status?.result?.sftp_path || status?.result?.file_name || 'the server root directory';
              const sftpUser = status?.sftp?.username;
              const sftpHost = status?.sftp?.host;
              const sftpPort = status?.sftp?.port;
              const expiresAt = status?.result?.expires_at ? new Date(status.result.expires_at) : null;
              const expiresText = expiresAt && !Number.isNaN(expiresAt.getTime())
                ? expiresAt.toLocaleString()
                : 'about 24 hours after it was created';
              setDownloadNotice(
                <div css={tw`space-y-1`}>
                  <div>This backup was packaged as a temporary {status?.result?.archive_format === 'tar.zst' ? '.tar.zst' : '.zip'} file in this server&apos;s files for SFTP download.</div>
                  <div>File: <code css={tw`text-neutral-200`}>{sftpPath}</code></div>
                  {sftpUser && sftpHost ? (
                    <div>Login: <code css={tw`text-neutral-200`}>{sftpUser}@{sftpHost}:{sftpPort || 2022}</code></div>
                  ) : null}
                  {sftpUrl ? (
                    <div>
                      <a css={tw`text-blue-300 hover:text-blue-200 underline`} href={sftpUrl}>
                        Open this backup in SFTP
                      </a>
                    </div>
                  ) : null}
                  <div>Use your normal Panel password when your SFTP client asks for it.</div>
                  <div>This temporary archive will be deleted automatically after {expiresText}.</div>
                </div>
              );
              setSuccess('Backup archive is ready in the server files.');
              return;
            }
            if (status?.status === 'failed') {
              setProgress(null);
              setError(status?.message || 'Failed to prepare download.');
              return;
            }
          }
        } catch {}

        if (pollCount >= pollMax) {
          setProgress(null);
          setError('Download is still preparing. Please try again later.');
          return;
        }
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        setProgress(`${forceSftp ? `Preparing SFTP ${formatLabel} download` : `Preparing ${formatLabel} download`}... (${elapsed}s elapsed)`);
        const delay = Math.min(2000 + pollCount * 500, 10000);
        setTimeout(poll, delay);
      };
      poll();
    } catch {
      alert('Failed to start download');
      setProgress(null);
    }
  };

  return (
    <div className="restic-backups">
      <style>{`
        @keyframes restic-progress {0%{transform:translateX(-100%);}100%{transform:translateX(100%);}}
        .restic-action-menu-button {
          appearance: none;
          background: transparent;
          border: 0;
          border-radius: 0.25rem;
          color: rgb(156 163 175);
          cursor: pointer;
          height: 2rem;
          line-height: 1;
          padding: 0.35rem 0.5rem;
          transition: background-color 120ms ease, color 120ms ease;
        }
        .restic-action-menu-button:hover,
        .restic-action-menu-button:focus {
          background: rgb(55 65 81 / 0.55);
          color: rgb(229 231 235);
          outline: none;
        }
        .restic-action-menu {
          background: rgb(31 41 55);
          border: 1px solid rgb(75 85 99);
          border-radius: 0.375rem;
          box-shadow: 0 14px 32px rgb(0 0 0 / 0.35);
          min-width: 12rem;
          overflow: visible;
          padding: 0.35rem;
          position: absolute;
          right: 0;
          top: calc(100% + 0.35rem);
          z-index: 10000;
        }
        .restic-action-menu-row {
          align-items: center;
          appearance: none;
          background: transparent;
          border: 0;
          border-radius: 0.25rem;
          color: rgb(209 213 219);
          cursor: pointer;
          display: flex;
          font-size: 0.875rem;
          line-height: 1.25rem;
          padding: 0.55rem 0.65rem;
          text-align: left;
          width: 100%;
        }
        .restic-action-menu-row:hover,
        .restic-action-menu-row:focus {
          background: rgb(55 65 81);
          color: rgb(249 250 251);
          outline: none;
        }
        .restic-action-menu-row svg {
          color: rgb(156 163 175);
          width: 1rem;
        }
      `}</style>
      <div css={tw`flex flex-wrap items-center justify-between gap-3 mb-4`}>
        <h2 css={tw`text-2xl font-semibold text-neutral-100`}>Restic Backups</h2>
        <Button
          onClick={handleCreateBackup}
          disabled={creating || !isServerReady}
          css={tw`text-sm`}
        >
          {creating ? 'Creating...' : (isServerReady ? 'Create Restic Backup' : 'Loading server...')}
        </Button>
      </div>
      {progress && (
        <div css={tw`mb-4`}>
          <div css={tw`text-sm text-neutral-300 mb-2`}>{progress}</div>
          <div css={tw`h-2 w-full bg-neutral-700 rounded overflow-hidden`}>
            <div
              css={tw`h-full w-full`}
              style={{
                background: 'linear-gradient(90deg, rgba(59,130,246,0) 0%, rgba(59,130,246,0.9) 50%, rgba(59,130,246,0) 100%)',
                animation: 'restic-progress 1.3s ease-in-out infinite',
                willChange: 'transform',
                transform: 'translateX(-100%)',
              }}
            />
          </div>
        </div>
      )}
      {error && <div css={tw`text-red-400 mb-4`}>{error}</div>}
      {success && <div css={tw`text-green-400 mb-4`}>{success}</div>}
      {downloadNotice && <div css={tw`text-xs text-neutral-400 mb-4 whitespace-pre-line`}>{downloadNotice}</div>}

      <div css={tw`flex gap-2 mb-4`}>
        <Button
          type="button"
          onClick={() => setActiveTab('backups')}
          className={activeTab === 'backups' ? 'restic-tab restic-tab--active' : 'restic-tab'}
          css={tw`text-sm`}
        >
          Backups
        </Button>
        <Button
          type="button"
          onClick={() => setActiveTab('schedule')}
          className={activeTab === 'schedule' ? 'restic-tab restic-tab--active' : 'restic-tab'}
          css={tw`text-sm`}
        >
          Schedule
        </Button>
      </div>

      {activeTab === 'schedule' ? (
        <GreyRowBox css={tw`flex flex-col gap-6`}>
          <div>
            <p css={tw`text-sm text-neutral-300 mb-1`}>How often do you want backups to be automatically created?</p>
            <p css={tw`text-xs text-neutral-500`}>Enter an interval in minutes, hours, or days.</p>
          </div>
          <div css={tw`flex flex-wrap items-center gap-3`}>
            <Input
              type="number"
              min={1}
              value={scheduleValue}
              onChange={e => setScheduleValue(Number(e.target.value))}
              css={tw`text-sm py-2 px-3 w-32`}
              disabled={scheduleLoading}
            />
            <select
              value={scheduleUnit}
              onChange={e => setScheduleUnit(e.target.value as 'minutes' | 'hours' | 'days')}
              css={tw`text-sm py-2 px-3 bg-neutral-900 border border-neutral-700 rounded`}
              disabled={scheduleLoading}
            >
              <option value="minutes">Minutes</option>
              <option value="hours">Hours</option>
              <option value="days">Days</option>
            </select>
            <label css={tw`text-sm text-neutral-300 flex items-center gap-2`}>
              <input
                type="checkbox"
                checked={scheduleEnabled}
                onChange={e => setScheduleEnabled(e.target.checked)}
                disabled={scheduleLoading}
              />
              Enable schedule
            </label>
          </div>
          <div>
            <Button
              onClick={async () => {
                if (!uuid) return;
                setScheduleSaving(true);
                setError(null);
                setSuccess(null);
                try {
                  const res = await fetch(`/extensions/resticbackups/servers/${uuid}/backups/restic/schedule`, {
                    method: 'POST',
                    headers: {
                      'Accept': 'application/json',
                      'Content-Type': 'application/json',
                      'X-Requested-With': 'XMLHttpRequest',
                      ...csrfHeaders(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                      interval_value: scheduleValue,
                      interval_unit: scheduleUnit,
                      enabled: scheduleEnabled,
                    }),
                  });
                  if (!res.ok) {
                    setError('Failed to save schedule.');
                    return;
                  }
                  const data = await res.json().catch(() => null);
                  if (data?.next_run_at !== undefined) setScheduleNextRun(data.next_run_at);
                  if (data?.last_run_at !== undefined) setScheduleLastRun(data.last_run_at);
                  setSuccess('Schedule saved.');
                } finally {
                  setScheduleSaving(false);
                }
              }}
              disabled={scheduleSaving || scheduleLoading}
            >
              {scheduleSaving ? 'Saving...' : 'Save schedule'}
            </Button>
          </div>
          <div>
            <p css={tw`text-xs text-neutral-500`}>
              Next run: {scheduleNextRun ? format(new Date(scheduleNextRun), 'EEE, MMM d, yyyy HH:mm') : 'Not scheduled'}
            </p>
            <p css={tw`text-xs text-neutral-500`}>
              Last run: {scheduleLastRun ? format(new Date(scheduleLastRun), 'EEE, MMM d, yyyy HH:mm') : 'Never'}
            </p>
          </div>
        </GreyRowBox>
      ) : null}

      {activeTab === 'backups' ? (
      <>
      <GreyRowBox css={tw`flex-wrap md:flex-nowrap items-center mb-2`} style={{ overflow: 'visible' }}>
        <div css={tw`flex items-center truncate w-full md:flex-1`}>
          <div css={tw`mr-4`}>
            <FontAwesomeIcon icon={faArchive} css={tw`text-neutral-300`} />
          </div>
          <div css={tw`flex flex-col truncate`}>
            <div css={tw`flex items-center text-sm mb-1`}>
              <p css={tw`break-words truncate`}>Restic Backups</p>
            </div>
            <p css={tw`mt-1 md:mt-0 text-xs text-neutral-400`}>
              {(() => {
                const hasFilter = Boolean(activeSince || activeUntil);
                const totalCount = totalAvailable ?? null;

                if (totalCount === null) {
                  if (backupLimit !== null) {
                    return `${backups.length} Backup${backups.length === 1 ? '' : 's'} / ${backupLimit} Max`;
                  }
                  return backups.length
                    ? `${backups.length} Backup${backups.length === 1 ? '' : 's'} Available`
                    : 'No backups found';
                }

                return backupLimit !== null
                  ? `${totalCount} Backup${totalCount === 1 ? '' : 's'} / ${backupLimit} Max`
                  : `${totalCount} Backup${totalCount === 1 ? '' : 's'} Available`;
              })()}
            </p>
            <p css={tw`mt-1 text-xs text-neutral-500`}>
              Repo size: {repoSizeBytes !== null ? formatBytes(repoSizeBytes) : 'Loading...'}{maxRepoBytes !== null ? ` / ${formatBytes(maxRepoBytes)} Max` : ''}{repoMultiplier !== null ? ` (${repoMultiplier}x)` : ''}
            </p>
          </div>
        </div>
        <div css={tw`mt-4 md:mt-0 ml-6`} style={{ marginRight: '-0.5rem' }} />
      </GreyRowBox>

      <div css={tw`flex flex-wrap items-end gap-4 mb-4`}>
        <div css={tw`flex flex-col`}>
          <label css={tw`text-xs text-neutral-400 mb-1`}>From date</label>
          <Input
            type="date"
            value={sinceInput}
            onChange={e => setSinceInput(e.target.value)}
            css={tw`text-sm py-2 px-3`}
          />
        </div>
        <div css={tw`flex flex-col`}>
          <label css={tw`text-xs text-neutral-400 mb-1`}>To date</label>
          <Input
            type="date"
            value={untilInput}
            onChange={e => setUntilInput(e.target.value)}
            css={tw`text-sm py-2 px-3`}
          />
        </div>
        <Button
          type="button"
          onClick={handleApplyFilters}
          css={tw`text-sm`}
        >
          Apply
        </Button>
      </div>

      {backups.length === 0 ? (
        <p css={tw`text-center text-sm text-neutral-300 mt-4`}>No backups found.</p>
      ) : (
        <div css={tw`mt-4`}>
          {backups.map((b, idx) => {
            const isLocked = typeof b.locked === 'boolean'
              ? b.locked
              : (Array.isArray(b.tags) && b.tags.includes('locked'));
            const createdAt = b.time ? new Date(b.time) : null;
            const createdLabel = createdAt && !isNaN(createdAt.getTime())
              ? formatDistanceToNow(createdAt, { includeSeconds: true, addSuffix: true })
              : 'Unknown time';
            const createdTitle = createdAt && !isNaN(createdAt.getTime())
              ? format(createdAt, 'EEE, MMMM do, yyyy HH:mm:ss')
              : '';
            const actionMenuId = b.id || b.short_id || b.time || `backup-${idx}`;

            return (
              <GreyRowBox
                key={b.id}
                css={[tw`flex-wrap md:flex-nowrap items-center`, idx > 0 ? tw`mt-2` : undefined]}
                className="BackupRow___StyledGreyRowBox-sc-1lzi0pw-0"
                style={{
                  overflow: 'visible',
                  position: 'relative',
                  zIndex: openActionMenu === actionMenuId ? 1000 : undefined,
                }}
              >
                <div css={tw`flex items-center truncate w-full md:flex-1`}>
                  <div css={tw`mr-4`}>
                    <FontAwesomeIcon icon={isLocked ? faLock : faArchive} css={tw`text-neutral-300`} />
                  </div>
                  <div css={tw`flex flex-col truncate`}>
                    <div css={tw`flex items-center text-sm mb-1`}>
                      <p css={tw`break-words truncate`}>
                        {createdAt && !isNaN(createdAt.getTime())
                          ? format(createdAt, 'yyyy-MM-dd HH-mm-ss')
                          : 'Backup'}
                      </p>
                      {b.size !== undefined && (
                        <span css={tw`ml-3 text-neutral-300 text-xs font-extralight hidden sm:inline`}>{formatBytes(b.size)}</span>
                      )}
                    </div>
                    <div css={tw`mt-1 md:mt-0 flex items-center gap-2 truncate`}>
                      <p css={tw`text-xs text-neutral-400 font-mono truncate`}>{b.id}</p>
                      {typeof b.note === 'string' && b.note.length > 0 && (
                        <span css={tw`text-sm text-neutral-200 truncate`} title={b.note}>- {b.note}</span>
                      )}
                    </div>
                  </div>
                </div>
                <div css={tw`flex-1 md:flex-none md:w-48 mt-4 md:mt-0 md:ml-8 md:text-center`}>
                  <p title={createdTitle} css={tw`text-sm`}>
                    {createdLabel}
                  </p>
                  <p css={tw`text-2xs text-neutral-500 uppercase mt-1`}>Created</p>
                </div>
                <div className="BackupRow___StyledDiv6-sc-1lzi0pw-14" css={tw`mt-4 md:mt-0 ml-6`} style={{ marginRight: '-0.5rem' }}>
                  <div css={tw`flex items-center gap-2`}>
                    <button
                      type="button"
                      css={tw`text-neutral-400 hover:text-neutral-200`}
                      title="Add/Edit note"
                      onClick={async () => {
                        if (!uuid) return;
                        const noteId = b.id || b.short_id;
                        if (!noteId) {
                          alert('Backup ID is missing');
                          return;
                        }
                        const current = typeof b.note === 'string' ? b.note : '';
                        const next = window.prompt('Enter a note for this backup:', current ?? '');
                        if (next === null) return;

                        setProgress('Saving note...');
                        setError(null);
                        setSuccess(null);
                        try {
                          const res = await fetch(`/extensions/resticbackups/servers/${uuid}/backups/restic/${encodeURIComponent(noteId)}/note`, {
                            method: 'POST',
                            headers: {
                              'Accept': 'application/json',
                              'Content-Type': 'application/json',
                              'X-Requested-With': 'XMLHttpRequest',
                              ...csrfHeaders(),
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({ note: next }),
                          });

                          if (!res.ok) {
                            setError('Failed to save note.');
                            setProgress(null);
                            return;
                          }

                          const data = await res.json().catch(() => null);
                          setBackups(prev => prev.map(item =>
                            (item.id === noteId || item.short_id === noteId)
                              ? { ...item, note: data?.note ?? next }
                              : item
                          ));
                          setProgress(null);
                          setSuccess('Note saved.');
                        } catch {
                          setProgress(null);
                          setError('Failed to save note.');
                        }
                      }}
                    >
                      <FontAwesomeIcon icon={faStickyNote} />
                    </button>
                    <div
                      css={tw`relative`}
                      style={{ zIndex: openActionMenu === actionMenuId ? 10001 : undefined }}
                      onClick={(e) => e.stopPropagation()}
                    >
                        <button
                          aria-haspopup="menu"
                          aria-expanded={openActionMenu === actionMenuId}
                          aria-label="Actions"
                          onClick={(e) => {
                            e.stopPropagation();
                            setOpenActionMenu(current => current === actionMenuId ? null : actionMenuId);
                          }}
                          className="restic-action-menu-button"
                          type="button"
                        >
                          <FontAwesomeIcon icon={faEllipsisH} />
                        </button>
                      {openActionMenu === actionMenuId && (
                    <div className="restic-action-menu" role="menu">
                      <button className="restic-action-menu-row" type="button" role="menuitem" onClick={() => {
                        setOpenActionMenu(null);
                        if (!uuid) return;
                        const lockId = b.id || b.short_id;
                        if (!lockId) {
                          alert('Backup ID is missing');
                          return;
                        }
                        setProgress(isLocked ? 'Unlocking backup...' : 'Locking backup...');
                        setError(null);
                        setSuccess(null);
                        const url = `/extensions/resticbackups/servers/${uuid}/backups/restic/${encodeURIComponent(lockId)}/${isLocked ? 'unlock' : 'lock'}`;
                        fetch(url, {
                          method: 'POST',
                          headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            ...csrfHeaders(),
                          },
                          credentials: 'same-origin',
                          body: JSON.stringify({}),
                        })
                          .then(async res => {
                            if (!res.ok) {
                              throw new Error('Failed to toggle lock');
                            }
                            let payload: any = null;
                            try {
                              payload = await res.json();
                            } catch {}
                            const payloadLocked = typeof payload?.locked === 'boolean' ? payload.locked : null;
                            const nextLocked = payloadLocked !== null ? payloadLocked : !isLocked;
                            setBackups(prev => prev.map(item => {
                              if (item.id !== lockId && item.short_id !== lockId) return item;
                              const nextTags = Array.isArray(item.tags)
                                ? (nextLocked
                                  ? Array.from(new Set([...item.tags, 'locked']))
                                  : item.tags.filter(tag => tag !== 'locked'))
                                : (nextLocked ? ['locked'] : item.tags);
                              return { ...item, locked: nextLocked, tags: nextTags };
                            }));
                            setProgress(null);
                            setSuccess(nextLocked ? 'Backup locked.' : 'Backup unlocked.');
                          })
                          .catch(() => {
                            setProgress(null);
                            setError('Failed to toggle lock. Please try again.');
                          });
                      }}>
                        <FontAwesomeIcon icon={faLock} />
                        <span className="BackupContextMenu___StyledSpan-sc-1p494ba-6" css={tw`ml-2`}>
                          {isLocked ? 'Unlock' : 'Lock'}
                        </span>
                      </button>
                      <button
                        className="restic-action-menu-row"
                        type="button"
                        role="menuitem"
                        onClick={() => handleDownloadBackup(b, false, 'zip')}
                      >
                        <FontAwesomeIcon icon={faCloudDownloadAlt} />
                        <span className="BackupContextMenu___StyledSpan-sc-1p494ba-6" css={tw`ml-2`}>
                          Download ZIP
                        </span>
                      </button>
                      <button
                        className="restic-action-menu-row"
                        type="button"
                        role="menuitem"
                        onClick={() => handleDownloadBackup(b, false, 'tar.zst')}
                      >
                        <FontAwesomeIcon icon={faCloudDownloadAlt} />
                        <span className="BackupContextMenu___StyledSpan-sc-1p494ba-6" css={tw`ml-2`}>
                          Download ZST
                        </span>
                      </button>
                      <button
                        className="restic-action-menu-row"
                        type="button"
                        role="menuitem"
                        onClick={() => handleDownloadBackup(b, true, 'zip')}
                      >
                        <FontAwesomeIcon icon={faCloudDownloadAlt} />
                        <span className="BackupContextMenu___StyledSpan-sc-1p494ba-6" css={tw`ml-2`}>
                          SFTP ZIP
                        </span>
                      </button>
                      <button
                        className="restic-action-menu-row"
                        type="button"
                        role="menuitem"
                        onClick={() => handleDownloadBackup(b, true, 'tar.zst')}
                      >
                        <FontAwesomeIcon icon={faCloudDownloadAlt} />
                        <span className="BackupContextMenu___StyledSpan-sc-1p494ba-6" css={tw`ml-2`}>
                          SFTP ZST
                        </span>
                      </button>
                      <button
                        className="restic-action-menu-row"
                        type="button"
                        role="menuitem"
                        onClick={async () => {
                          setOpenActionMenu(null);
                          if (!uuid) return;
                          const restoreId = b.id || b.short_id;
                          if (!restoreId) {
                            alert('Backup ID is missing');
                            return;
                          }
                          const confirmed = window.confirm('Restore this backup? This will overwrite current server files.');
                          if (!confirmed) return;

                          setProgress('Restoring restic backup...');
                          setError(null);
                          setSuccess(null);
                          try {
                            const url = `/extensions/resticbackups/servers/${uuid}/backups/restic/${encodeURIComponent(restoreId)}/restore`;
                            const res = await fetch(url, {
                              method: 'POST',
                              headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                ...csrfHeaders(),
                              },
                              credentials: 'same-origin',
                            });

                            if (!res.ok) {
                              setError('Failed to restore backup.');
                              setProgress(null);
                              return;
                            }

                            setProgress('Restore running...');
                            let pollCount = 0;
                            const startTime = Date.now();
                            const pollMax = 300;
                            const poll = async () => {
                              pollCount++;
                              try {
                                const statusRes = await fetch(`/extensions/resticbackups/servers/${uuid}/backups/restic/restore/status`, {
                                  method: 'GET',
                                  headers: { 'Accept': 'application/json' },
                                  credentials: 'same-origin',
                                });
                                if (statusRes.ok) {
                                  const status = await statusRes.json();
                                  if (status?.status === 'completed') {
                                    setProgress(null);
                                    setSuccess('Restic backup restored successfully.');
                                    return;
                                  }
                                  if (status?.status === 'failed') {
                                    setProgress(null);
                                    setError(status?.message || 'Restore failed.');
                                    return;
                                  }
                                }
                              } catch {}

                              if (pollCount >= pollMax) {
                                setProgress(null);
                                setError('Restore is still running. Please check later.');
                                return;
                              }
                              const elapsed = Math.floor((Date.now() - startTime) / 1000);
                              setProgress(`Restore running... (${elapsed}s elapsed)`);
                              const delay = Math.min(2000 + pollCount * 500, 10000);
                              setTimeout(poll, delay);
                            };
                            poll();
                          } catch (err: any) {
                            setProgress(null);
                            setError('Failed to restore restic backup.');
                          }
                        }}
                      >
                        <FontAwesomeIcon icon={faBoxOpen} />
                        <span className="BackupContextMenu___StyledSpan2-sc-1p494ba-8" css={tw`ml-2`}>
                          Restore
                        </span>
                      </button>
                      <button
                        className="restic-action-menu-row"
                        type="button"
                        role="menuitem"
                        onClick={async () => {
                          setOpenActionMenu(null);
                          if (!uuid) return;
                          if (isLocked) {
                            alert('This snapshot is locked and cannot be deleted.');
                            return;
                          }
                          const deleteId = b.id || b.short_id;
                          if (!deleteId) {
                            alert('Backup ID is missing');
                            return;
                          }
                          const confirmed = window.confirm('Delete this backup snapshot? This cannot be undone.');
                          if (!confirmed) return;

                          setProgress('Deleting restic backup...');
                          setError(null);
                          setSuccess(null);
                          try {
                            const url = `/extensions/resticbackups/servers/${uuid}/backups/restic/${encodeURIComponent(deleteId)}`;
                            const res = await fetch(url, {
                              method: 'DELETE',
                              headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                ...csrfHeaders(),
                              },
                              credentials: 'same-origin',
                            });

                            if (!res.ok) {
                              setError('Failed to delete backup.');
                              setProgress(null);
                              return;
                            }

                            setProgress(null);
                            setSuccess('Restic backup deleted successfully.');
                          } catch (err: any) {
                            setProgress(null);
                            setError('Failed to delete restic backup.');
                          }
                        }}
                      >
                        <FontAwesomeIcon icon={faTrashAlt} />
                        <span className="BackupContextMenu___StyledSpan3-sc-1p494ba-11" css={tw`ml-2`}>
                          Delete
                        </span>
                      </button>
                    </div>
                      )}
                    </div>
                  </div>
                </div>
              </GreyRowBox>
            );
          })}
          {(hasMore || (totalAvailable !== null && backups.length < totalAvailable)) ? (
            <button
              css={tw`mt-4 text-sm text-neutral-300`}
              disabled={loadingMore}
              onClick={() => {
                if (!nextCursor) return;
                setLoadingMore(true);
                fetchBackups({ append: true, cursor: nextCursor });
              }}
            >
              {loadingMore ? 'Loading...' : 'Load more'}
            </button>
          ) : (backups.length > 5 ? (
            <button
              css={tw`mt-4 text-sm text-neutral-300`}
              onClick={() => {
                fetchBackups({ append: false, cursor: null, since: activeSince, until: activeUntil });
              }}
            >
              Show less
            </button>
          ) : null)}
        </div>
      )}
      </>
      ) : null}

    </div>
  );
};

export default ResticBackupsTab;
