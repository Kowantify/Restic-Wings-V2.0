package restic

import (
    "context"
    "encoding/json"
    "fmt"
    "net/http"
    "os"
    "path/filepath"
    "regexp"
    "strings"
    "time"

    "github.com/gin-gonic/gin"
)

var subsetRE = regexp.MustCompile(`^[0-9]+/[0-9]+$`)
const largeDownloadThresholdBytes int64 = 10 * 1024 * 1024 * 1024
const snapshotStatsTimeout = 45 * time.Second

type statsResponse struct {
    TotalSize             int64  `json:"total_size"`
    TotalCompressedSize   int64  `json:"total_compressed_size"`
    TotalUncompressedSize int64  `json:"total_uncompressed_size,omitempty"`
    TotalDedupedSize      int64  `json:"total_deduped_size,omitempty"`
    SnapshotsCount        int    `json:"snapshots_count"`
    UncompressedError     string `json:"uncompressed_error,omitempty"`
}

type resticStats struct {
    TotalSize int64 `json:"total_size"`
}

func prepareDownload(c *gin.Context) {
    req, err := bindResticRequest(c, true)
    if err != nil {
        errorJSON(c, http.StatusUnprocessableEntity, err.Error())
        return
    }
    snapshotID, err := validateSnapshotID(c.Param("snapshot"))
    if err != nil {
        errorJSON(c, http.StatusUnprocessableEntity, err.Error())
        return
    }
    sid := serverID(c)
    repo, err := repoPath(sid, req.OwnerUsername)
    if err != nil {
        errorJSON(c, http.StatusBadRequest, err.Error())
        return
    }
    volume, err := volumePath(sid)
    if err != nil {
        errorJSON(c, http.StatusBadRequest, err.Error())
        return
    }
    started := time.Now().UTC()
    key := jobKey(sid, "prepare:"+snapshotID)
    setJob(key, jobState{Status: "running", StartedAt: &started})
    go func() {
        cleanupOldTempDirs(24 * time.Hour)
        base, _ := cleanUnder(tempRoot(), filepath.Join(tempRoot(), sid+"-"+snapshotID))
        restoreDir := filepath.Join(base, "restore")
        tempArchivePath := filepath.Join(base, "backup.tar.zst")
        archivePath := tempArchivePath
        archiveFileName := ""
        largeDownload := false
        estimatedBytes := int64(0)
        sizeSource := "unknown"
        _ = os.RemoveAll(base)
        _ = os.MkdirAll(restoreDir, 0o700)
    ctx, cancel := context.WithTimeout(context.Background(), 6*time.Hour)
    defer cancel()

        snaps, err := listSnapshots(ctx, repo, req.EncryptionKey)
        if err != nil {
            finished := time.Now().UTC()
            setJob(key, jobState{Status: "failed", Message: err.Error(), StartedAt: &started, FinishedAt: &finished})
            _ = os.RemoveAll(base)
            return
        }
        if _, err := findSnapshotForServer(snaps, snapshotID, sid, volume); err != nil {
            finished := time.Now().UTC()
            setJob(key, jobState{Status: "failed", Message: err.Error(), StartedAt: &started, FinishedAt: &finished})
            _ = os.RemoveAll(base)
            return
        }

        statsCtx, statsCancel := context.WithTimeout(context.Background(), snapshotStatsTimeout)
        if snapshotBytes, statsErr := snapshotRestoreSize(statsCtx, repo, req.EncryptionKey, snapshotID); statsErr == nil {
            estimatedBytes = snapshotBytes
            sizeSource = "restic_stats"
            if estimatedBytes > largeDownloadThresholdBytes {
                var pathErr error
                largeDownload = true
                archiveFileName = sftpArchiveFileName(snapshotID)
                archivePath, pathErr = cleanUnder(volume, filepath.Join(volume, archiveFileName))
                if pathErr != nil {
                    finished := time.Now().UTC()
                    setJob(key, jobState{Status: "failed", Message: pathErr.Error(), StartedAt: &started, FinishedAt: &finished})
                    statsCancel()
                    _ = os.RemoveAll(base)
                    return
                }
            }
        }
        statsCancel()

        _, stderr, err := runRestic(ctx, repo, req.EncryptionKey, "restore", snapshotID, "--target", restoreDir)
        if err == nil {
            archiveSource, sourceErr := restoredVolumeSource(restoreDir, volume, sid)
            if sourceErr != nil {
                err = sourceErr
            } else {
                if sizeSource == "unknown" {
                    restoredBytes, sizeErr := directorySize(archiveSource)
                    if sizeErr != nil {
                        err = sizeErr
                    } else {
                        estimatedBytes = restoredBytes
                        sizeSource = "restored_directory"
                        if restoredBytes > largeDownloadThresholdBytes {
                            var pathErr error
                            largeDownload = true
                            archiveFileName = sftpArchiveFileName(snapshotID)
                            archivePath, pathErr = cleanUnder(volume, filepath.Join(volume, archiveFileName))
                            if pathErr != nil {
                                err = pathErr
                            }
                        }
                    }
                }
                if err == nil {
                    err = writeTarZstWithRoot(archiveSource, archivePath, sid)
                }
            }
        }
        _ = os.RemoveAll(restoreDir)
        finished := time.Now().UTC()
        if err != nil {
            _ = os.RemoveAll(base)
            if largeDownload && archivePath != "" {
                if safeArchivePath, cleanErr := cleanUnder(volume, archivePath); cleanErr == nil {
                    _ = os.Remove(safeArchivePath)
                }
            }
            msg := err.Error()
            if len(stderr) > 0 {
                msg = string(stderr)
            }
            setJob(key, jobState{Status: "failed", Message: msg, StartedAt: &started, FinishedAt: &finished})
            return
        }
        if largeDownload {
            setJob(key, jobState{
                Status:     "sftp_ready",
                Message:    "backup archive was placed in the server files for SFTP download",
                StartedAt:  &started,
                FinishedAt: &finished,
                Result: map[string]any{
                    "file_name":      archiveFileName,
                    "sftp_path":      "/" + archiveFileName,
                    "size_bytes":     estimatedBytes,
                    "size_source":    sizeSource,
                    "archive_format": "tar.zst",
                    "large_download": true,
                },
            })
            _ = os.RemoveAll(base)
            return
        }
        setJob(key, jobState{Status: "ready", Message: "archive ready", StartedAt: &started, FinishedAt: &finished, Result: map[string]any{"path": archivePath, "size_bytes": estimatedBytes, "size_source": sizeSource, "archive_format": "tar.zst"}})
        go func(path string) {
            time.Sleep(24 * time.Hour)
            _ = os.RemoveAll(filepath.Dir(path))
        }(archivePath)
    }()
    c.JSON(http.StatusAccepted, gin.H{"message": "prepare started", "status": "running"})
}

func prepareStatus(c *gin.Context) {
    snapshotID, err := validateSnapshotID(c.Param("snapshot"))
    if err != nil {
        errorJSON(c, http.StatusUnprocessableEntity, err.Error())
        return
    }
    state := getJob(jobKey(serverID(c), "prepare:"+snapshotID))
    if state.Result != nil {
        redacted := make(map[string]any, len(state.Result))
        for key, value := range state.Result {
            if key == "path" {
                continue
            }
            redacted[key] = value
        }
        state.Result = redacted
    }
    c.JSON(http.StatusOK, state)
}

func streamDownload(c *gin.Context) {
    snapshotID, err := validateSnapshotID(c.Param("snapshot"))
    if err != nil {
        errorJSON(c, http.StatusUnprocessableEntity, err.Error())
        return
    }
    state := getJob(jobKey(serverID(c), "prepare:"+snapshotID))
    if state.Status != "ready" || state.Result == nil {
        errorJSON(c, http.StatusNotFound, "prepared archive was not found")
        return
    }
    archivePath, _ := state.Result["path"].(string)
    if archivePath == "" {
        errorJSON(c, http.StatusNotFound, "prepared archive was not found")
        return
    }
    if _, err := cleanUnder(tempRoot(), archivePath); err != nil {
        errorJSON(c, http.StatusBadRequest, "unsafe archive path")
        return
    }
    if _, err := os.Stat(archivePath); err != nil {
        errorJSON(c, http.StatusNotFound, "prepared archive was not found")
        return
    }
    c.Header("Content-Type", "application/zstd")
    c.Header("Content-Disposition", fmt.Sprintf("attachment; filename=\"restic-%s-%s.tar.zst\"", serverID(c), snapshotID))
    c.File(archivePath)
    go func() {
        time.Sleep(30 * time.Second)
        _ = os.RemoveAll(filepath.Dir(archivePath))
    }()
}

func stats(c *gin.Context) {
    req, err := bindResticRequest(c, true)
    if err != nil {
        errorJSON(c, http.StatusUnprocessableEntity, err.Error())
        return
    }
    repo, err := repoPath(serverID(c), req.OwnerUsername)
    if err != nil {
        errorJSON(c, http.StatusBadRequest, err.Error())
        return
    }
    ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
    defer cancel()
    raw, err := statsMode(ctx, repo, req.EncryptionKey, "raw-data")
    if err != nil {
        errorJSON(c, http.StatusBadGateway, err.Error())
        return
    }
    restore, restoreErr := statsMode(ctx, repo, req.EncryptionKey, "restore-size")
    files, _ := statsMode(ctx, repo, req.EncryptionKey, "files-by-contents")
    snaps, _ := listSnapshots(ctx, repo, req.EncryptionKey)
    resp := statsResponse{TotalSize: raw.TotalSize, TotalCompressedSize: raw.TotalSize, SnapshotsCount: len(snaps)}
    if restoreErr == nil {
        resp.TotalUncompressedSize = restore.TotalSize
    } else {
        resp.UncompressedError = restoreErr.Error()
    }
    resp.TotalDedupedSize = files.TotalSize
    c.JSON(http.StatusOK, resp)
}

func statsMode(ctx context.Context, repo, key, mode string) (resticStats, error) {
    stdout, stderr, err := runRestic(ctx, repo, key, "stats", "--json", "--mode", mode, "--no-lock")
    if err != nil {
        return resticStats{}, fmt.Errorf("restic stats failed: %s", string(stderr))
    }
    var parsed resticStats
    if err := json.Unmarshal(stdout, &parsed); err != nil {
        return resticStats{}, err
    }
    return parsed, nil
}

func snapshotRestoreSize(ctx context.Context, repo, key, snapshotID string) (int64, error) {
    stdout, stderr, err := runRestic(ctx, repo, key, "stats", "--json", "--mode", "restore-size", "--no-lock", snapshotID)
    if err != nil {
        return 0, fmt.Errorf("restic snapshot stats failed: %s", string(stderr))
    }
    var parsed resticStats
    if err := json.Unmarshal(stdout, &parsed); err != nil {
        return 0, err
    }
    return parsed.TotalSize, nil
}

func sftpArchiveFileName(snapshotID string) string {
    shortID := snapshotID
    if len(shortID) > 12 {
        shortID = shortID[:12]
    }
    return "restic-backup-" + shortID + ".tar.zst"
}

func checkRepo(c *gin.Context) {
    var req checkRequest
    if err := c.ShouldBindJSON(&req); err != nil {
        errorJSON(c, http.StatusUnprocessableEntity, err.Error())
        return
    }
    if strings.TrimSpace(req.EncryptionKey) == "" {
        errorJSON(c, http.StatusUnprocessableEntity, "invalid encryption key")
        return
    }
    if req.ReadDataSubset != "" && !subsetRE.MatchString(req.ReadDataSubset) {
        errorJSON(c, http.StatusUnprocessableEntity, "invalid read_data_subset")
        return
    }
    sid := serverID(c)
    repo, err := repoPath(sid, req.OwnerUsername)
    if err != nil {
        errorJSON(c, http.StatusBadRequest, err.Error())
        return
    }
    started := time.Now().UTC()
    key := jobKey(sid, "check")
    setJob(key, jobState{Status: "running", StartedAt: &started})
    go func() {
        ctx, cancel := context.WithTimeout(context.Background(), 6*time.Hour)
        defer cancel()
        args := []string{"check"}
        if req.ReadDataSubset != "" {
            args = append(args, "--read-data-subset", req.ReadDataSubset)
        }
        stdout, stderr, err := runRestic(ctx, repo, req.EncryptionKey, args...)
        finished := time.Now().UTC()
        if err != nil {
            setJob(key, jobState{Status: "failed", Message: string(stderr), StartedAt: &started, FinishedAt: &finished})
            return
        }
        setJob(key, jobState{Status: "completed", Message: "check completed", StartedAt: &started, FinishedAt: &finished, Result: map[string]any{"output": string(stdout) + string(stderr)}})
    }()
    c.JSON(http.StatusAccepted, gin.H{"message": "check started", "status": "running"})
}

func checkStatus(c *gin.Context) {
	c.JSON(http.StatusOK, getJob(jobKey(serverID(c), "check")))
}

func unlockRepo(c *gin.Context) {
	req, err := bindResticRequest(c, true)
	if err != nil {
		errorJSON(c, http.StatusUnprocessableEntity, err.Error())
		return
	}
	repo, err := repoPath(serverID(c), req.OwnerUsername)
	if err != nil {
		errorJSON(c, http.StatusBadRequest, err.Error())
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
	defer cancel()
	stdout, stderr, err := runRestic(ctx, repo, req.EncryptionKey, "unlock")
	if err != nil {
		errorJSON(c, http.StatusBadGateway, string(stderr))
		return
	}
	c.JSON(http.StatusOK, gin.H{"message": "repo locks removed", "output": string(stdout) + string(stderr)})
}

func repoExists(c *gin.Context) {
    req, _ := bindResticRequest(c, false)
    repo, err := repoPath(serverID(c), req.OwnerUsername)
    if err != nil {
        errorJSON(c, http.StatusBadRequest, err.Error())
        return
    }
    _, err = os.Stat(filepath.Join(repo, "config"))
    c.JSON(http.StatusOK, gin.H{"exists": err == nil, "count": func() int { if err == nil { return 1 }; return 0 }()})
}

func repoSize(c *gin.Context) {
    req, _ := bindResticRequest(c, false)
    repo, err := repoPath(serverID(c), req.OwnerUsername)
    if err != nil {
        errorJSON(c, http.StatusBadRequest, err.Error())
        return
    }
    size := int64(0)
    _ = filepath.Walk(repo, func(_ string, info os.FileInfo, err error) error {
        if err == nil && info != nil && !info.IsDir() {
            size += info.Size()
        }
        return nil
    })
    c.JSON(http.StatusOK, gin.H{"exists": size > 0, "total_bytes": size, "repos": []gin.H{{"name": filepath.Base(repo), "size_bytes": size}}})
}

func deleteRepo(c *gin.Context) {
    req, err := bindResticRequest(c, false)
    if err != nil {
        errorJSON(c, http.StatusUnprocessableEntity, err.Error())
        return
    }
    repo, err := repoPath(serverID(c), req.OwnerUsername)
    if err != nil {
        errorJSON(c, http.StatusBadRequest, err.Error())
        return
    }
    if err := os.RemoveAll(repo); err != nil {
        errorJSON(c, http.StatusInternalServerError, err.Error())
        return
    }
    c.JSON(http.StatusOK, gin.H{"message": "repo deleted"})
}
