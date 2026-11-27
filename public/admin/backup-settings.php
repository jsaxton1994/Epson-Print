<?php
/**
 * Backup Settings: the nightly database dump kept in the backup folder.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/backup.php';

require_admin();

if (is_post()) {
    verify_csrf();

    if (input('action') === 'backup_run') {
        $result = run_backup();
        flash($result['ok'] ? 'success' : 'error', ($result['ok'] ? 'Backup stored: ' : 'Backup failed: ') . $result['note']);
        redirect('admin/backup-settings.php');
    }
}

$backupFolder = backup_directory();
$backupFiles  = backup_files();

app_start('admin', 'backup-settings', 'Backup Settings', 'Nightly database dumps, kept on the host outside the web root.');
?>

<div class="settings-layout">
    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Backups</h3>
                    <p>A nightly database dump kept on the host, outside the web root</p>
                </div>
            </div>
            <div class="panel-body">
                <div class="summary-list" style="margin:0 0 14px">
                    <div class="summary-row"><span>Last backup</span><strong><?= setting('backup_last_run', '') !== '' ? e(time_ago((string) setting('backup_last_run'))) : 'Never' ?></strong></div>
                    <div class="summary-row"><span>Result</span><strong><?= e(setting('backup_last_status', 'unknown') === 'ok' ? 'Stored' : ucfirst((string) setting('backup_last_status', 'unknown'))) ?></strong></div>
                    <div class="summary-row"><span>File</span><strong class="mono"><?= e(setting('backup_last_file', '') !== '' ? (string) setting('backup_last_file', '') : '—') ?></strong></div>
                    <div class="summary-row"><span>Size</span><strong><?= e(human_size((int) setting('backup_last_bytes', '0'))) ?> · <?= (int) setting('backup_last_rows', '0') ?> rows</strong></div>
                    <div class="summary-row"><span>Folder</span><strong class="mono" style="text-align:right;word-break:break-all"><?= e($backupFolder['dir'] !== '' ? $backupFolder['dir'] : 'Not writable') ?></strong></div>
                    <div class="summary-row"><span>Dumps kept</span><strong><?= count($backupFiles) ?> · newest <?= (int) BACKUP_KEEP_FILES ?></strong></div>
                </div>

                <?php if (setting('backup_last_note', '') !== ''): ?>
                    <p class="help"><?= e((string) setting('backup_last_note', '')) ?></p>
                <?php endif; ?>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="backup_run">
                    <button class="btn btn-soft btn-sm" type="submit"><?= icon('download') ?> Run a backup now</button>
                </form>

                <p class="help" style="margin-top:12px">
                    Run <span class="mono">cron/backup.php</span> once a night from cPanel. Dumps are gzipped SQL without the photo BLOBs;
                    restore with <span class="mono">gunzip</span> then <span class="mono">mysql db &lt; db-….sql</span>.
                </p>
            </div>
        </section>

        <?php if ($backupFiles !== []): ?>
            <section class="panel">
                <div class="panel-head"><h3>Stored dumps</h3></div>
                <div class="panel-body pad-sm">
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>File</th><th>Written</th><th>Size</th></tr></thead>
                            <tbody>
                                <?php foreach ($backupFiles as $file): ?>
                                    <tr>
                                        <td class="mono"><?= e($file['name']) ?></td>
                                        <td class="muted"><?= e(date('j M Y H:i', $file['at'])) ?></td>
                                        <td><?= e(human_size($file['bytes'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h3>What a dump holds</h3></div>
            <div class="panel-body">
                <ul class="check-list" style="gap:12px">
                    <li><?= icon('layers') ?><span>Every table's schema and rows — users, orders, photos, events and settings.</span></li>
                    <li><?= icon('image') ?><span>The photo columns are left out: the images stay in the database and would dwarf a nightly file.</span></li>
                    <li><?= icon('shield') ?><span>Written outside <span class="mono">public_html</span> with an Apache deny file, so the file cannot be downloaded.</span></li>
                    <li><?= icon('refresh') ?><span>Only the newest <?= (int) BACKUP_KEEP_FILES ?> dumps are kept; older ones are deleted automatically.</span></li>
                </ul>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>
