package restic

import (
	"archive/tar"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
    "path/filepath"
    "regexp"
    "sort"
    "strings"
    "sync"
    "time"

    "github.com/gin-gonic/gin"

    "github.com/pterodactyl/wings/config"
    "github.com/pterodactyl/wings/router/middleware"
)

var safeID = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,128}$`)
var safeOwner = regexp.MustCompile(`^[A-Za-z0-9_.@-]{1,128}$`)
var safeRepo = regexp.MustCompile(`^[A-Za-z0-9_.:@+-]{1,256}$`)

const lockedTag = "locked"

type resticRequest struct {
	OwnerUsername string `json:"owner_username" form:"owner_username"`
	EncryptionKey string `json:"encryption_key" form:"encryption_key"`
	MaxBackups    int    `json:"max_backups" form:"max_backups"`
	MaxRepoBytes  int64  `json:"max_repo_bytes" form:"max_repo_bytes"`
	Delivery      string `json:"delivery" form:"delivery"`
	ForceSFTP     bool   `json:"force_sftp" form:"force_sftp"`
	ArchiveFormat string `json:"archive_format" form:"archive_format"`
	RepoName      string `json:"repo_name" form:"repo_name"`
	Limit         int    `json:"limit" form:"limit"`
	Cursor        string `json:"cursor" form:"cursor"`
	Since         string `json:"since" form:"since"`
	Until         string `json:"until" form:"until"`
	IncludeTotal  string `json:"include_total" form:"include_total"`
}

const exportDirName = ".restic-downloads"
const archivedTag = "archived"

type pruneRequest struct {
    resticRequest
}

type checkRequest struct {
    resticRequest
    ReadDataSubset string `json:"read_data_subset" form:"read_data_subset"`
}

type snapshot struct {
    ID      string    `json:"id"`
    ShortID string    `json:"short_id,omitempty"`
    Time    time.Time `json:"time"`
    Tags    []string  `json:"tags,omitempty"`
    Paths   []string  `json:"paths,omitempty"`
    Summary any       `json:"summary,omitempty"`
    Locked  bool      `json:"locked"`
}

type jobState struct {
    Status     string         `json:"status"`
    Message    string         `json:"message,omitempty"`
    StartedAt  *time.Time     `json:"started_at,omitempty"`
    FinishedAt *time.Time     `json:"finished_at,omitempty"`
    Result     map[string]any `json:"result,omitempty"`
}

var jobs = struct {
    sync.Mutex
    values map[string]jobState
}{values: map[string]jobState{}}

var serverLocks sync.Map

func bindResticRequest(c *gin.Context, requireKey bool) (resticRequest, error) {
	var req resticRequest
	if err := c.ShouldBindJSON(&req); err != nil && err != io.EOF {
		return req, err
	}
    req.OwnerUsername = strings.TrimSpace(req.OwnerUsername)
    req.EncryptionKey = strings.TrimSpace(req.EncryptionKey)
    if req.OwnerUsername != "" && !safeOwner.MatchString(req.OwnerUsername) {
        return req, errors.New("invalid owner username")
    }
    if requireKey && (len(req.EncryptionKey) < 16 || len(req.EncryptionKey) > 4096) {
        return req, errors.New("invalid encryption key")
    }
    return req, nil
}

func validateSnapshotID(id string) (string, error) {
    id = strings.TrimSpace(id)
    if !safeID.MatchString(id) {
        return "", errors.New("invalid snapshot id")
    }
    return id, nil
}

func repoName(serverID, owner string) string {
    if owner != "" {
        return serverID + "+" + owner
    }
    return serverID
}

func repoRoot() string {
    return filepath.Join(config.Get().System.Data, "..", "restic")
}

func archiveRoot() string {
    return filepath.Join(repoRoot(), "archive")
}

func cleanUnder(root, child string) (string, error) {
    path, err := cleanUnderOrEqual(root, child)
    if err != nil {
        return "", err
    }
    rootAbs, err := filepath.Abs(root)
    if err != nil {
        return "", err
    }
    if path == rootAbs {
        return "", errors.New("unsafe path")
    }
    return path, nil
}

func cleanUnderOrEqual(root, child string) (string, error) {
    rootAbs, err := filepath.Abs(root)
    if err != nil {
        return "", err
    }
    childAbs, err := filepath.Abs(child)
    if err != nil {
        return "", err
    }
    rel, err := filepath.Rel(rootAbs, childAbs)
    if err != nil {
        return "", err
    }
    if rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) || filepath.IsAbs(rel) {
        return "", errors.New("unsafe path")
    }
    return childAbs, nil
}

func isUnderOrEqual(root, child string) bool {
    _, err := cleanUnderOrEqual(root, child)
    return err == nil
}

func repoPath(serverID, owner string) (string, error) {
    root := repoRoot()
    preferred := filepath.Join(root, repoName(serverID, owner))
    serverOnly := filepath.Join(root, serverID)
    if _, err := os.Stat(preferred); err == nil {
        return cleanUnder(root, preferred)
    }
    if _, err := os.Stat(serverOnly); err == nil {
        return cleanUnder(root, serverOnly)
    }
    return cleanUnder(root, preferred)
}

func archivedRepoPath(serverID, owner string) (string, error) {
    root := archiveRoot()
    return cleanUnder(root, filepath.Join(root, repoName(serverID, owner)))
}

func volumePath(serverID string) (string, error) {
    root := config.Get().System.Data
    return cleanUnder(root, filepath.Join(root, serverID))
}

func tempRoot() string {
    return filepath.Join(repoRoot(), "temp")
}

func cleanupOldTempDirs(maxAge time.Duration) {
    root := tempRoot()
    entries, err := os.ReadDir(root)
    if err != nil {
        return
    }
    cutoff := time.Now().Add(-maxAge)
    for _, entry := range entries {
        if !entry.IsDir() {
            continue
        }
        path := filepath.Join(root, entry.Name())
        safe, err := cleanUnder(root, path)
        if err != nil {
            continue
        }
        info, err := entry.Info()
        if err != nil {
            continue
        }
        if info.ModTime().Before(cutoff) {
            _ = os.RemoveAll(safe)
        }
    }
}

func cleanupOldSFTPArchives(volume string, maxAge time.Duration) {
    cleanupOldSFTPExportDir(volume, maxAge)

    entries, err := os.ReadDir(volume)
    if err != nil {
        return
    }
    cutoff := time.Now().Add(-maxAge)
    for _, entry := range entries {
        if entry.IsDir() {
            continue
        }
        name := entry.Name()
        if !isResticExportArchive(name) {
            continue
        }
        info, err := entry.Info()
        if err != nil || !info.ModTime().Before(cutoff) {
            continue
        }
        path, err := cleanUnder(volume, filepath.Join(volume, name))
        if err != nil {
            continue
        }
        _ = os.Remove(path)
    }
}

func cleanupSFTPArchives(volume string) {
    if dir, err := exportDirPath(volume); err == nil {
        _ = os.RemoveAll(dir)
    }

    entries, err := os.ReadDir(volume)
    if err != nil {
        return
    }
    for _, entry := range entries {
        if entry.IsDir() {
            continue
        }
        name := entry.Name()
        if !isResticExportArchive(name) {
            continue
        }
        path, err := cleanUnder(volume, filepath.Join(volume, name))
        if err != nil {
            continue
        }
        _ = os.Remove(path)
    }
}

func exportDirPath(volume string) (string, error) {
    return cleanUnder(volume, filepath.Join(volume, exportDirName))
}

func cleanupOldSFTPExportDir(volume string, maxAge time.Duration) {
    dir, err := exportDirPath(volume)
    if err != nil {
        return
    }
    info, err := os.Stat(dir)
    if err != nil || !info.IsDir() {
        return
    }
    if info.ModTime().Before(time.Now().Add(-maxAge)) {
        _ = os.RemoveAll(dir)
    }
}

func isResticExportArchive(name string) bool {
    if !strings.HasPrefix(name, "restic-backup-") {
        return false
    }
    return strings.HasSuffix(name, ".tar.zst")
}

func normalizeArchiveFormat(format string) string {
    return "tar.zst"
}

func archiveExtension(format string) string {
    return ".tar.zst"
}

func archiveContentType(format string) string {
    return "application/zstd"
}

func repoDiskSize(repo string) (int64, error) {
    var total int64
    err := filepath.Walk(repo, func(_ string, info os.FileInfo, err error) error {
        if err != nil {
            return err
        }
        if info == nil || info.IsDir() {
            return nil
        }
        total += info.Size()
        return nil
    })
    return total, err
}

func runRestic(ctx context.Context, repo, key string, args ...string) ([]byte, []byte, error) {
    return runResticInDir(ctx, "", repo, key, args...)
}

func runResticInDir(ctx context.Context, dir, repo, key string, args ...string) ([]byte, []byte, error) {
    fullArgs := append([]string{"-r", repo}, args...)
    cmd := exec.CommandContext(ctx, "restic", fullArgs...)
    if dir != "" {
        cmd.Dir = dir
    }
    env := os.Environ()
    env = append(env, "RESTIC_PASSWORD="+key)
    cmd.Env = env
    var stdout, stderr strings.Builder
    cmd.Stdout = &stdout
    cmd.Stderr = &stderr
    err := cmd.Run()
    return []byte(stdout.String()), []byte(stderr.String()), err
}

func ensureRepo(ctx context.Context, repo, key string) error {
    if _, err := os.Stat(filepath.Join(repo, "config")); err == nil {
        return nil
    }
    if err := os.MkdirAll(repo, 0o700); err != nil {
        return err
    }
    _, stderr, err := runRestic(ctx, repo, key, "init")
    if err != nil && !strings.Contains(strings.ToLower(string(stderr)), "config file already exists") {
        return fmt.Errorf("restic init failed: %s", string(stderr))
    }
    return nil
}

func listSnapshots(ctx context.Context, repo, key string) ([]snapshot, error) {
    if _, err := os.Stat(filepath.Join(repo, "config")); err != nil {
        return []snapshot{}, nil
    }
    stdout, stderr, err := runRestic(ctx, repo, key, "snapshots", "--json", "--no-lock")
    if err != nil {
        return nil, fmt.Errorf("restic snapshots failed: %s", string(stderr))
    }
    var snaps []snapshot
    if len(strings.TrimSpace(string(stdout))) == 0 {
        return []snapshot{}, nil
    }
    if err := json.Unmarshal(stdout, &snaps); err != nil {
        return nil, err
    }
    for i := range snaps {
        if snaps[i].ShortID == "" && len(snaps[i].ID) >= 8 {
            snaps[i].ShortID = snaps[i].ID[:8]
        }
        snaps[i].Locked = hasTag(snaps[i].Tags, lockedTag)
    }
    sort.Slice(snaps, func(i, j int) bool { return snaps[i].Time.After(snaps[j].Time) })
    return snaps, nil
}

func snapshotBelongsToServer(snap snapshot, serverID, volume string) bool {
    taggedForServer := hasTag(snap.Tags, serverID)
    if len(snap.Paths) == 0 {
        return taggedForServer
    }

    hasAbsolute := false
    hasRelative := false
    for _, path := range snap.Paths {
        if filepath.IsAbs(path) {
            hasAbsolute = true
            if !isUnderOrEqual(volume, path) {
                return false
            }
        } else {
            hasRelative = true
        }
    }

    if hasAbsolute && hasRelative {
        return false
    }

    if hasRelative {
        return taggedForServer
    }

    return taggedForServer || hasAbsolute
}

func filterSnapshotsForServer(snaps []snapshot, serverID, volume string) []snapshot {
    filtered := make([]snapshot, 0, len(snaps))
    for _, snap := range snaps {
        if snapshotBelongsToServer(snap, serverID, volume) {
            filtered = append(filtered, snap)
        }
    }
    return filtered
}

func findSnapshotForServer(snaps []snapshot, snapshotID, serverID, volume string) (*snapshot, error) {
    for i := range snaps {
        if snaps[i].ID != snapshotID && snaps[i].ShortID != snapshotID {
            continue
        }
        if !snapshotBelongsToServer(snaps[i], serverID, volume) {
            return nil, errors.New("snapshot does not belong to this server")
        }
        return &snaps[i], nil
    }
    return nil, errors.New("snapshot was not found")
}

func hasTag(tags []string, tag string) bool {
    for _, t := range tags {
        if t == tag {
            return true
        }
    }
    return false
}

func enforceBackupLimit(ctx context.Context, repo, key string, max int) error {
    if max < 1 {
        return nil
    }
    snaps, err := listSnapshots(ctx, repo, key)
    if err != nil {
        return err
    }
    for len(snaps) > max {
        var victim *snapshot
        for i := len(snaps) - 1; i >= 0; i-- {
            if !snaps[i].Locked {
                victim = &snaps[i]
                break
            }
		}
		if victim == nil {
			return nil
		}
        id := victim.ID
        if id == "" {
            id = victim.ShortID
        }
        _, stderr, err := runRestic(ctx, repo, key, "forget", id, "--prune")
        if err != nil {
            return fmt.Errorf("failed enforcing backup retention: %s", string(stderr))
        }
        snaps, err = listSnapshots(ctx, repo, key)
        if err != nil {
            return err
        }
    }
    return nil
}

func enforceRepoSizeLimit(ctx context.Context, repo, key string, maxBytes int64) error {
    if maxBytes < 1 {
        return nil
    }
    for {
        size, err := repoDiskSize(repo)
        if err != nil {
            return err
        }
        if size <= maxBytes {
            return nil
        }

        snaps, err := listSnapshots(ctx, repo, key)
        if err != nil {
            return err
        }

        var victim *snapshot
        for i := len(snaps) - 1; i >= 0; i-- {
            if !snaps[i].Locked {
                victim = &snaps[i]
                break
            }
        }
        if victim == nil {
            return fmt.Errorf("repo size %d bytes exceeds limit %d bytes, and all snapshots are locked", size, maxBytes)
        }

        id := victim.ID
        if id == "" {
            id = victim.ShortID
        }
        _, stderr, err := runRestic(ctx, repo, key, "forget", id, "--prune")
        if err != nil {
            return fmt.Errorf("failed enforcing repo size retention: %s", string(stderr))
        }
    }
}

func setJob(key string, state jobState) {
    jobs.Lock()
    jobs.values[key] = state
    jobs.Unlock()
}

func getJob(key string) jobState {
    jobs.Lock()
    state, ok := jobs.values[key]
    jobs.Unlock()
    if !ok {
        return jobState{Status: "idle"}
    }
    return state
}

func jobKey(serverID, name string) string {
    return serverID + ":" + name
}

func lockForServer(serverID string) *sync.Mutex {
    lock, _ := serverLocks.LoadOrStore(serverID, &sync.Mutex{})
    return lock.(*sync.Mutex)
}

func writeTarZstWithRoot(source, target, rootName string) error {
    rootName = strings.Trim(filepath.ToSlash(rootName), "/")
    if rootName != "" && (!safeID.MatchString(rootName) || strings.Contains(rootName, "..")) {
        return errors.New("invalid archive root name")
    }
    if _, err := exec.LookPath("zstd"); err != nil {
        return errors.New("zstd is not installed")
    }
    if err := os.MkdirAll(filepath.Dir(target), 0o700); err != nil {
        return err
    }

    cmd := exec.Command("zstd", "-T0", "-3", "-q", "-f", "-o", target, "-")
    stdin, err := cmd.StdinPipe()
    if err != nil {
        return err
    }
    cmd.Stderr = os.Stderr
    if err := cmd.Start(); err != nil {
        _ = stdin.Close()
        return err
    }

    tarWriter := tar.NewWriter(stdin)
    walkErr := filepath.Walk(source, func(path string, info os.FileInfo, err error) error {
        if err != nil {
            return err
        }
        if path == source {
            if rootName == "" {
                return nil
            }
            header, err := tar.FileInfoHeader(info, "")
            if err != nil {
                return err
            }
            header.Name = rootName
            return tarWriter.WriteHeader(header)
        }
        rel, err := filepath.Rel(source, path)
        if err != nil {
            return err
        }
        header, err := tar.FileInfoHeader(info, "")
        if err != nil {
            return err
        }
        header.Name = filepath.ToSlash(rel)
        if rootName != "" {
            header.Name = filepath.ToSlash(filepath.Join(rootName, rel))
        }
        if err := tarWriter.WriteHeader(header); err != nil {
            return err
        }
        if info.IsDir() {
            return nil
        }
        in, err := os.Open(path)
        if err != nil {
            return err
        }
        defer in.Close()
        _, err = io.Copy(tarWriter, in)
        return err
    })

    closeErr := tarWriter.Close()
    pipeErr := stdin.Close()
    waitErr := cmd.Wait()
    if walkErr != nil {
        return walkErr
    }
    if closeErr != nil {
        return closeErr
    }
    if pipeErr != nil {
        return pipeErr
    }
    return waitErr
}

func directorySize(path string) (int64, error) {
    var total int64
    err := filepath.Walk(path, func(_ string, info os.FileInfo, err error) error {
        if err != nil {
            return err
        }
        if info == nil || info.IsDir() {
            return nil
        }
        total += info.Size()
        return nil
    })
    return total, err
}

func restoredVolumeSource(restoreDir, originalVolume, serverID string) (string, error) {
    cleanedVolume := filepath.Clean(originalVolume)
    relativeVolume := strings.TrimPrefix(cleanedVolume, string(filepath.Separator))

    candidates := []string{
        filepath.Join(restoreDir, relativeVolume),
        filepath.Join(restoreDir, serverID),
    }

    for _, candidate := range candidates {
        path, err := cleanUnder(restoreDir, candidate)
        if err != nil {
            continue
        }
        if info, err := os.Stat(path); err == nil && info.IsDir() {
            return path, nil
        }
    }

    var found string
    _ = filepath.Walk(restoreDir, func(path string, info os.FileInfo, err error) error {
        if err != nil || info == nil || !info.IsDir() || found != "" {
            return nil
        }
        if filepath.Base(path) == serverID {
            if safe, err := cleanUnder(restoreDir, path); err == nil {
                found = safe
                return filepath.SkipDir
            }
        }
        return nil
    })
    if found != "" {
        return found, nil
    }

    if hasDirectoryEntries(restoreDir) {
        return restoreDir, nil
    }

    return "", errors.New("restored server volume was not found")
}

func hasDirectoryEntries(path string) bool {
    entries, err := os.ReadDir(path)
    return err == nil && len(entries) > 0
}

func serverID(c *gin.Context) string {
    return middleware.ExtractServer(c).ID()
}
