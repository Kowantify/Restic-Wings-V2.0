package restic

import (
	"context"
	"fmt"
	"net/http"
    "os"
    "path/filepath"
    "strings"
    "time"

    "github.com/gin-gonic/gin"
)

func createBackup(c *gin.Context) {
    req, err := bindResticRequest(c, true)
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
    if _, err := os.Stat(volume); err != nil {
        errorJSON(c, http.StatusNotFound, "server volume was not found")
        return
    }

    run := func() (map[string]any, error) {
        lock := lockForServer(sid)
        lock.Lock()
        defer lock.Unlock()
        ctx, cancel := context.WithTimeout(context.Background(), 6*time.Hour)
        defer cancel()
        if err := ensureRepo(ctx, repo, req.EncryptionKey); err != nil {
            return nil, err
        }
        stdout, stderr, err := runRestic(ctx, repo, req.EncryptionKey, "backup", volume, "--json", "--tag", "pterodactyl", "--tag", sid)
        if err != nil {
            return nil, fmt.Errorf("restic backup failed: %s", string(stderr))
        }
        if err := enforceBackupLimit(ctx, repo, req.EncryptionKey, req.MaxBackups); err != nil {
            return nil, err
        }
        return map[string]any{"output": strings.TrimSpace(string(stdout)), "repo": filepath.Base(repo)}, nil
    }

    if c.Query("async") == "1" {
        started := time.Now().UTC()
        key := jobKey(sid, "backup")
        setJob(key, jobState{Status: "running", StartedAt: &started})
        go func() {
            result, err := run()
            finished := time.Now().UTC()
            if err != nil {
                setJob(key, jobState{Status: "failed", Message: err.Error(), StartedAt: &started, FinishedAt: &finished})
                return
            }
            setJob(key, jobState{Status: "completed", Message: "backup created", StartedAt: &started, FinishedAt: &finished, Result: result})
        }()
        c.JSON(http.StatusAccepted, gin.H{"message": "backup started", "status": "running"})
        return
    }

    result, err := run()
    if err != nil {
        errorJSON(c, http.StatusBadGateway, err.Error())
        return
    }
    c.JSON(http.StatusOK, gin.H{"message": "backup created", "status": "completed", "result": result})
}

func backupStatus(c *gin.Context) {
    c.JSON(http.StatusOK, getJob(jobKey(serverID(c), "backup")))
}

func listBackups(c *gin.Context) {
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
    ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
    defer cancel()
    snaps, err := listSnapshots(ctx, repo, req.EncryptionKey)
	if err != nil {
		errorJSON(c, http.StatusBadGateway, err.Error())
		return
	}
	total := len(snaps)
	filtered := filterSnapshots(snaps, req)
	limit := req.Limit
	if limit < 1 || limit > 100 {
		limit = 25
	}
	nextCursor := ""
	if len(filtered) > limit {
		nextCursor = filtered[limit-1].Time.Format(time.RFC3339Nano)
		filtered = filtered[:limit]
	}
	c.JSON(http.StatusOK, gin.H{"backups": filtered, "total": total, "next_cursor": nextCursor})
}

func filterSnapshots(snaps []snapshot, req resticRequest) []snapshot {
	var sinceTime, untilTime, cursorTime time.Time
	if req.Since != "" {
		sinceTime, _ = time.Parse(time.RFC3339, req.Since)
		if sinceTime.IsZero() {
			sinceTime, _ = time.Parse("2006-01-02", req.Since)
		}
	}
	if req.Until != "" {
		untilTime, _ = time.Parse(time.RFC3339, req.Until)
		if untilTime.IsZero() {
			untilTime, _ = time.Parse("2006-01-02", req.Until)
			if !untilTime.IsZero() {
				untilTime = untilTime.Add(24*time.Hour - time.Nanosecond)
			}
		}
	}
	if req.Cursor != "" {
		cursorTime, _ = time.Parse(time.RFC3339Nano, req.Cursor)
		if cursorTime.IsZero() {
			cursorTime, _ = time.Parse(time.RFC3339, req.Cursor)
		}
	}
	filtered := make([]snapshot, 0, len(snaps))
	for _, snap := range snaps {
		if !sinceTime.IsZero() && snap.Time.Before(sinceTime) {
			continue
		}
		if !untilTime.IsZero() && snap.Time.After(untilTime) {
			continue
		}
		if !cursorTime.IsZero() && !snap.Time.Before(cursorTime) {
			continue
		}
		filtered = append(filtered, snap)
	}
	return filtered
}

func restoreBackup(c *gin.Context) {
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
    key := jobKey(sid, "restore")
    setJob(key, jobState{Status: "running", StartedAt: &started})
    go func() {
        lock := lockForServer(sid)
        lock.Lock()
        defer lock.Unlock()
        ctx, cancel := context.WithTimeout(context.Background(), 6*time.Hour)
        defer cancel()
        if err := os.MkdirAll(volume, 0o755); err != nil {
            finished := time.Now().UTC()
            setJob(key, jobState{Status: "failed", Message: err.Error(), StartedAt: &started, FinishedAt: &finished})
            return
        }
        _, stderr, err := runRestic(ctx, repo, req.EncryptionKey, "restore", snapshotID, "--target", volume)
        finished := time.Now().UTC()
        if err != nil {
            setJob(key, jobState{Status: "failed", Message: string(stderr), StartedAt: &started, FinishedAt: &finished})
            return
        }
        setJob(key, jobState{Status: "completed", Message: "restore completed", StartedAt: &started, FinishedAt: &finished})
    }()
    c.JSON(http.StatusAccepted, gin.H{"message": "restore started", "status": "running"})
}

func restoreStatus(c *gin.Context) {
    c.JSON(http.StatusOK, getJob(jobKey(serverID(c), "restore")))
}

func deleteBackup(c *gin.Context) {
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
    repo, err := repoPath(serverID(c), req.OwnerUsername)
    if err != nil {
        errorJSON(c, http.StatusBadRequest, err.Error())
        return
    }
    ctx, cancel := context.WithTimeout(context.Background(), 2*time.Hour)
    defer cancel()
    snaps, err := listSnapshots(ctx, repo, req.EncryptionKey)
    if err != nil {
        errorJSON(c, http.StatusBadGateway, err.Error())
        return
    }
    for _, snap := range snaps {
        if (snap.ID == snapshotID || snap.ShortID == snapshotID) && snap.Locked {
            errorJSON(c, http.StatusConflict, "snapshot is locked")
            return
        }
    }
    _, stderr, err := runRestic(ctx, repo, req.EncryptionKey, "forget", snapshotID, "--prune")
    if err != nil {
        errorJSON(c, http.StatusBadGateway, string(stderr))
        return
    }
	c.JSON(http.StatusOK, gin.H{"message": "snapshot deleted"})
}

func lockBackup(c *gin.Context) {
	tagSnapshot(c, true)
}

func unlockBackup(c *gin.Context) {
	tagSnapshot(c, false)
}

func tagSnapshot(c *gin.Context, locked bool) {
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
	repo, err := repoPath(serverID(c), req.OwnerUsername)
	if err != nil {
		errorJSON(c, http.StatusBadRequest, err.Error())
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
	defer cancel()
	action := "--add"
	message := "snapshot locked"
	if !locked {
		action = "--remove"
		message = "snapshot unlocked"
	}
	_, stderr, err := runRestic(ctx, repo, req.EncryptionKey, "tag", action, lockedTag, snapshotID)
	if err != nil {
		errorJSON(c, http.StatusBadGateway, string(stderr))
		return
	}
	c.JSON(http.StatusOK, gin.H{"message": message, "locked": locked})
}
