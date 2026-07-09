package restic

import (
	"net/http"

	"github.com/gin-gonic/gin"
)

func Register(group *gin.RouterGroup) {
	group.POST("/backups", createBackup)
	group.POST("/backups/list", listBackups)
	group.GET("/backups/status", backupStatus)

	group.POST("/backups/:snapshot/restore", restoreBackup)
	group.GET("/restore/status", restoreStatus)
	group.POST("/backups/:snapshot/lock", lockBackup)
	group.POST("/backups/:snapshot/unlock", unlockBackup)
	group.DELETE("/backups/:snapshot", deleteBackup)

	group.POST("/backups/:snapshot/prepare", prepareDownload)
	group.GET("/backups/:snapshot/prepare/status", prepareStatus)
	group.GET("/backups/:snapshot/download", streamDownload)

	group.POST("/stats", stats)
	group.POST("/check", checkRepo)
	group.GET("/check/status", checkStatus)
	group.POST("/unlock", unlockRepo)
	group.POST("/repo/exists", repoExists)
	group.POST("/repo/size", repoSize)
	group.POST("/repo/inventory", repoInventory)
	group.POST("/repo/archive", archiveRepo)
	group.POST("/repo/archive-by-name", archiveRepoByName)
	group.DELETE("/repo", deleteRepo)
}

func errorJSON(c *gin.Context, status int, message string) {
	c.AbortWithStatusJSON(status, gin.H{"error": message})
}

func notImplemented(c *gin.Context) {
	errorJSON(c, http.StatusNotImplemented, "not implemented")
}
