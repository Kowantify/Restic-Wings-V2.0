<script>
(() => {
  const injectResticArchiveDelete = () => {
    const match = window.location.pathname.match(/^\/admin\/servers\/view\/([0-9]+)\/delete\/?$/);
    if (!match || document.getElementById('restic-archive-delete-card')) return;

    const token =
      document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
      document.querySelector('input[name="_token"]')?.getAttribute('value') ||
      '';
    if (!token) return;

    const serverId = match[1];
    const card = document.createElement('div');
    card.className = 'col-xs-12 col-md-6';
    card.id = 'restic-archive-delete-card';
    card.innerHTML = `
      <div class="box box-warning" style="border-top-color:#f59e0b;">
        <div class="box-header with-border">
          <h3 class="box-title">Archive Restic Backup + Delete Server</h3>
        </div>
        <div class="box-body">
          <p>This will create a final locked Restic archive if possible, move the repo to archive storage, then safely delete the server.</p>
          <p class="text-warning"><strong>If the Restic archive fails, the server will not be deleted.</strong></p>
        </div>
        <div class="box-footer">
          <form method="POST" action="/extensions/resticbackups/admin/servers/${serverId}/archive-delete">
            <input type="hidden" name="_token" value="${token.replace(/"/g, '&quot;')}">
            <button
              type="submit"
              class="btn btn-warning"
              onclick="return confirm('Archive the Restic repo, then safely delete this server? If archiving fails, deletion will be cancelled.');"
            >Archive Backup and Delete Server</button>
          </form>
        </div>
      </div>
    `;

    const rows = Array.from(document.querySelectorAll('.content .row, .content-wrapper .row, section.content .row'));
    const target = rows.reverse().find(row => row.querySelector('.box-danger, .box.box-danger, form'));
    if (target) {
      target.appendChild(card);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', injectResticArchiveDelete);
  } else {
    injectResticArchiveDelete();
  }
})();
</script>
