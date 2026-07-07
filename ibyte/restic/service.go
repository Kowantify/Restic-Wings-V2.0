package restic

import (
	"archive/tar"
	"compress/gzip"
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

const lockedTag = "locked"

type resticRequest struct {
	OwnerUsername string `json:"owner_username" form:"owner_username"`
	EncryptionKey string `json:"encryption_key" form:"encryption_key"`
	MaxBackups    int    `json:"max_backups" form:"max_backups"`
	Limit         int    `json:"limit" form:"limit"`
	Cursor        string `json:"cursor" form:"cursor"`
	Since         string `json:"since" form:"since"`
	Until         string `json:"until" form:"until"`
	IncludeTotal  string `json:"include_total" form:"include_total"`
}

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

func cleanUnder(root, child string) (string, error) {
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
    if rel == "." || strings.HasPrefix(rel, "..") || filepath.IsAbs(rel) {
        return "", errors.New("unsafe path")
    }
    return childAbs, nil
}

func repoPath(serverID, owner string) (string, error) {
    root := repoRoot()
    preferred := filepath.Join(root, repoName(serverID, owner))
    legacy := filepath.Join(root, serverID)
    if _, err := os.Stat(preferred); err == nil {
        return cleanUnder(root, preferred)
    }
    if _, err := os.Stat(legacy); err == nil {
        return cleanUnder(root, legacy)
    }
    return cleanUnder(root, preferred)
}

func volumePath(serverID string) (string, error) {
    root := config.Get().System.Data
    return cleanUnder(root, filepath.Join(root, serverID))
}

func tempRoot() string {
    return filepath.Join(repoRoot(), "temp")
}

func runRestic(ctx context.Context, repo, key string, args ...string) ([]byte, []byte, error) {
    fullArgs := append([]string{"-r", repo}, args...)
    cmd := exec.CommandContext(ctx, "restic", fullArgs...)
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
            return fmt.Errorf("failed pruning old snapshot: %s", string(stderr))
        }
        snaps, err = listSnapshots(ctx, repo, key)
        if err != nil {
            return err
        }
    }
    return nil
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

func writeTarGz(source, target string) error {
    if err := os.MkdirAll(filepath.Dir(target), 0o700); err != nil {
        return err
    }
    file, err := os.Create(target)
    if err != nil {
        return err
    }
    defer file.Close()
    gzipWriter := gzip.NewWriter(file)
    defer gzipWriter.Close()
    tarWriter := tar.NewWriter(gzipWriter)
    defer tarWriter.Close()

    return filepath.Walk(source, func(path string, info os.FileInfo, err error) error {
        if err != nil {
            return err
        }
        if path == source {
            return nil
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
}

func serverID(c *gin.Context) string {
    return middleware.ExtractServer(c).ID()
}
