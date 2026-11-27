<?php
/**
 * Database backups.
 *
 * The whole business sits in one MySQL database on one shared host. This builds
 * a restorable SQL dump (schema + rows, with the large image columns left out)
 * and writes it, gzipped, into a folder that is not served by the web server:
 * by default `backups/` beside `public_html`, which a deploy never touches.
 * The folder gets an Apache deny file as a second line of defence, and only the
 * newest few dumps are kept.
 *
 * Restore: gunzip the file and import it with `mysql db < db-YYYYmmdd-HHMMSS.sql`.
 */

declare(strict_types=1);

/**
 * Tables this application owns. Anything else in the schema is left alone, so
 * the dump never tries to copy another application's data out of a shared
 * database.
 */
function backup_tables(): array
{
    return ['users', 'orders', 'order_photos', 'order_events', 'settings', 'sessions'];
}

/**
 * Where dumps are written.
 *
 * The first choice is a sibling of the web root, so the files cannot be fetched
 * over HTTP and a deployment that copies into public_html cannot overwrite them.
 * If that directory cannot be created or written (some hosts confine PHP to the
 * docroot), `public_html/backups` is used instead; it is denied by .htaccess and
 * is not part of the repository, so a deploy does not remove it.
 *
 * @return array{dir:string,reliable:bool}
 */
function backup_directory(): array
{
    $outside = dirname(__DIR__, 2) . '/backups';

    foreach ([$outside, dirname(__DIR__) . '/backups'] as $index => $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            continue;
        }

        $htaccess = $dir . '/.htaccess';

        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }

        return ['dir' => $dir, 'reliable' => $index === 0];
    }

    return ['dir' => '', 'reliable' => false];
}

/**
 * Build a restorable SQL dump as a gzipped string.
 *
 * BLOB columns are skipped: they are the photos. Copying every customer image
 * into a nightly file would dwarf the database and fill the host's disk, and the
 * originals are still in the database itself.
 *
 * @return array{gz:string,bytes:int,rows:int}
 */
function backup_build_dump(): array
{
    $pdo  = db();
    $out  = "-- PrintFree database backup\n";
    $out .= '-- version: ' . APP_VERSION . "\n";
    $out .= '-- created: ' . now() . " (UTC)\n";
    $out .= "-- image_data and thumb_data are excluded (photos stay in the database).\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    $rowsTotal = 0;

    foreach (backup_tables() as $table) {
        try {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            continue; // table not present in this install
        }

        if (!is_array($create) || empty($create['Create Table'])) {
            continue;
        }

        $out .= 'DROP TABLE IF EXISTS `' . $table . "`;\n";
        $out .= $create['Create Table'] . ";\n\n";

        $columns = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        $select  = [];

        foreach ($columns as $column) {
            $type = strtolower((string) ($column['Type'] ?? ''));

            if (str_contains($type, 'blob') || str_contains($type, 'binary')) {
                continue;
            }

            $select[] = '`' . (string) $column['Field'] . '`';
        }

        if ($select === []) {
            continue;
        }

        $columnSql = implode(', ', $select);
        $offset    = 0;

        while (true) {
            $rows = $pdo->query(
                'SELECT ' . $columnSql . ' FROM `' . $table . '` LIMIT 500 OFFSET ' . $offset
            )->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $values = [];

                foreach ($row as $value) {
                    $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                }

                $out .= 'INSERT INTO `' . $table . '` (' . $columnSql . ') VALUES (' . implode(', ', $values) . ");\n";
                $rowsTotal++;
            }

            $offset += 500;
        }

        $out .= "\n";
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $gz = gzencode($out, 6);

    return ['gz' => $gz === false ? '' : $gz, 'bytes' => $gz === false ? 0 : strlen($gz), 'rows' => $rowsTotal];
}

/**
 * Existing dumps, newest first.
 *
 * @return list<array{name:string,path:string,bytes:int,at:int}>
 */
function backup_files(): array
{
    $dir = backup_directory()['dir'];

    if ($dir === '') {
        return [];
    }

    $files = glob($dir . '/db-*.sql.gz') ?: [];
    $out   = [];

    foreach ($files as $path) {
        $out[] = [
            'name'  => basename($path),
            'path'  => $path,
            'bytes' => (int) @filesize($path),
            'at'    => (int) @filemtime($path),
        ];
    }

    usort($out, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);

    return $out;
}

/** Delete all but the newest BACKUP_KEEP_FILES dumps. */
function backup_prune(): void
{
    foreach (array_slice(backup_files(), BACKUP_KEEP_FILES) as $old) {
        @unlink($old['path']);
    }
}

/**
 * Run one backup: dump the database, write it into the backup folder, record
 * the result for the settings page.
 *
 * @return array{ok:bool,note:string,bytes:int,rows:int,where:string,file:string}
 */
function run_backup(): array
{
    @set_time_limit(240);

    $result = ['ok' => false, 'note' => '', 'bytes' => 0, 'rows' => 0, 'where' => '', 'file' => ''];
    $dump   = backup_build_dump();

    if ($dump['gz'] === '') {
        $result['note'] = 'The database dump could not be compressed.';
        backup_record($result);

        return $result;
    }

    $result['bytes'] = $dump['bytes'];
    $result['rows']  = $dump['rows'];

    $target = backup_directory();

    if ($target['dir'] === '') {
        $result['note'] = 'No writable backup folder. Create one outside public_html and make it writable.';
        backup_record($result);

        return $result;
    }

    $name = 'db-' . gmdate('Ymd-His') . '.sql.gz';
    $path = $target['dir'] . '/' . $name;
    $temp = $path . '.part';

    // Write to a temporary name first, so an interrupted run never leaves a
    // half-written file that looks like a usable backup.
    if (@file_put_contents($temp, $dump['gz'], LOCK_EX) !== strlen($dump['gz']) || !@rename($temp, $path)) {
        @unlink($temp);
        $result['note'] = 'Could not write ' . $name . ' to the backup folder.';
        backup_record($result);

        return $result;
    }

    @chmod($path, 0600);
    backup_prune();

    $result['ok']    = true;
    $result['file']  = $name;
    $result['where'] = $target['dir'];
    $result['note']  = 'Dump ' . human_size($result['bytes']) . ' (' . $result['rows'] . ' rows) written to ' . $name
        . ($target['reliable'] ? '.' : ' — inside the web root, protected by .htaccess only.');

    backup_record($result);

    return $result;
}

/**
 * Store the last result for the settings page.
 *
 * @param array{ok:bool,note:string,bytes:int,rows:int,where:string,file:string} $result
 */
function backup_record(array $result): void
{
    set_setting('backup_last_run', now());
    set_setting('backup_last_status', $result['ok'] ? 'ok' : 'error');
    set_setting('backup_last_note', mb_substr($result['note'], 0, 500));
    set_setting('backup_last_bytes', (string) $result['bytes']);
    set_setting('backup_last_rows', (string) $result['rows']);
    set_setting('backup_last_where', $result['where']);
    set_setting('backup_last_file', $result['file']);
}
