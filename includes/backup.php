<?php
/**
 * Off-host backups.
 *
 * The whole business sits in one MySQL database on one shared host, including
 * every customer photo. This builds a restorable SQL dump (schema + rows, with
 * the large image columns left out) and sends it to the relay vault, so a lost
 * host, a bad migration or an accidental DELETE does not take the orders with
 * it. Photos that are still waiting to print are copied to the vault too, a
 * bounded number per run, because those are the ones that can no longer be
 * reprinted from anywhere else.
 *
 * The vault is the relay Worker (see relay/README.md); PHP never needs cloud
 * credentials, only the relay URL and its token from Admin -> Settings.
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
 * Build a restorable SQL dump as a gzipped string.
 *
 * BLOB columns are skipped: they are the photos, which are far too large for a
 * nightly dump and are backed up individually into the vault instead.
 *
 * @return array{gz:string,bytes:int,int:int,int} bytes = dump size, int = rows
 */
function backup_build_dump(): array
{
    $pdo  = db();
    $out  = "-- PrintFree database backup\n";
    $out .= '-- version: ' . APP_VERSION . "\n";
    $out .= '-- created: ' . now() . " (UTC)\n";
    $out .= "-- image_data and thumb_data are excluded; photos live in the vault.\n";
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
 * Email the dump to the alert address when the vault is not reachable.
 *
 * A few hundred kilobytes of metadata is far better than nothing, and the
 * mailbox is already off the host.
 */
function backup_mail_dump(string $gz, string $name): bool
{
    $to = queue_alert_recipient();

    if ($to === '' || $gz === '' || strlen($gz) > BACKUP_MAIL_MAX_BYTES) {
        return false;
    }

    $support = trim((string) setting('support_email', ''));
    $domain  = str_contains($support, '@') ? substr(strrchr($support, '@'), 1) : 'localhost';
    $from    = 'no-reply@' . ($domain !== '' ? $domain : 'localhost');
    $site    = str_replace(["\r", "\n"], ' ', site_name());
    $boundary = 'pf-' . bin2hex(random_bytes(8));

    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        . "Automatic PrintFree database backup attached.\r\n"
        . "Restore: gunzip, then import into MySQL.\r\n"
        . "Photos are in the relay vault, not in this file.\r\n\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Type: application/gzip; name="' . $name . "\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . 'Content-Disposition: attachment; filename="' . $name . "\"\r\n\r\n"
        . chunk_split(base64_encode($gz)) . "\r\n"
        . '--' . $boundary . "--";

    $headers = implode("\r\n", [
        'From: ' . $site . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'X-Mailer: PHP/' . phpversion(),
    ]);

    return @mail($to, '[' . $site . '] Backup ' . gmdate('Y-m-d'), $body, $headers);
}

/**
 * Run one backup: dump, upload, copy queued photos, record the result.
 *
 * @return array{ok:bool,note:string,bytes:int,rows:int,photos:int,where:string}
 */
function run_backup(bool $includePhotos = true): array
{
    @set_time_limit(240);

    $result = ['ok' => false, 'note' => '', 'bytes' => 0, 'rows' => 0, 'photos' => 0, 'where' => 'none'];
    $dump   = backup_build_dump();

    if ($dump['gz'] === '') {
        $result['note'] = 'The database dump could not be compressed.';
        set_setting('backup_last_run', now());
        set_setting('backup_last_status', 'error');
        set_setting('backup_last_note', $result['note']);

        return $result;
    }

    $result['bytes'] = $dump['bytes'];
    $result['rows']  = $dump['rows'];
    $name            = 'db-' . gmdate('Ymd-His') . '.sql.gz';
    $uploaded        = false;

    if (relay_ready()) {
        $put = relay_request('PUT', '/vault/backup/' . $name, $dump['gz'], 120, 'application/gzip');

        if ($put['ok']) {
            $uploaded       = true;
            $result['where'] = 'relay vault';
        } else {
            $result['note'] = 'Vault upload failed: ' . ($put['error'] !== '' ? $put['error'] : 'HTTP ' . $put['code']) . '. ';
        }
    } else {
        $result['note'] = 'Relay is not configured. ';
    }

    if (!$uploaded && backup_mail_dump($dump['gz'], $name)) {
        $uploaded       = true;
        $result['where'] = 'email';
        $result['note'] .= 'Dump emailed to the alert address instead.';
    }

    // Photos of the order the queue has committed to (accepted or printing):
    // the only copies whose loss would stop the business printing, and small
    // enough to keep inside the vault's storage allowance. Pending orders stay
    // editable and the customer still holds those originals.
    if ($includePhotos && relay_ready()
        && schema_column_ready('order_photos', 'backed_up_at', 'printed_at', 'schema_backed_up_at')) {
        $queued = db_all(
            "SELECT p.id
             FROM order_photos p JOIN orders o ON o.id = p.order_id
             WHERE o.status IN ('accepted', 'printing')
               AND p.backed_up_at IS NULL
             ORDER BY p.id ASC
             LIMIT " . BACKUP_PHOTOS_PER_RUN
        );

        $bytesSent = 0;

        foreach ($queued as $photo) {
            $bytes = photo_binary(['id' => (int) $photo['id']]);

            if ($bytes === null || $bytes === '') {
                db_update('order_photos', ['backed_up_at' => now()], 'id = ?', [(int) $photo['id']]);
                continue;
            }

            if ($bytesSent + strlen($bytes) > BACKUP_PHOTO_BYTES_RUN) {
                break;
            }

            $key = 'photo/' . (int) $photo['id'] . '.bin';
            $put = relay_request('PUT', '/vault/' . $key, $bytes, 120, 'application/octet-stream');

            if (!$put['ok']) {
                $result['note'] .= ' Photo upload stopped at #' . (int) $photo['id'] . '.';
                break;
            }

            db_update('order_photos', ['backed_up_at' => now()], 'id = ?', [(int) $photo['id']]);
            $result['photos']++;
            $bytesSent += strlen($bytes);
        }
    }

    // Once an order is printed its vaulted photos are not needed again, so they
    // are removed to keep the vault near the size of the queue rather than
    // growing for ever.
    if ($includePhotos && relay_ready()
        && schema_column_ready('order_photos', 'backed_up_at', 'printed_at', 'schema_backed_up_at')) {
        $done = db_all(
            "SELECT p.id
             FROM order_photos p JOIN orders o ON o.id = p.order_id
             WHERE o.status IN ('completed', 'cancelled')
               AND p.backed_up_at IS NOT NULL
             ORDER BY p.id ASC
             LIMIT 200"
        );

        foreach ($done as $photo) {
            $deleted = relay_request('DELETE', '/vault/photo/' . (int) $photo['id'] . '.bin', null, 20);

            if ($deleted['ok']) {
                db_update('order_photos', ['backed_up_at' => null], 'id = ?', [(int) $photo['id']]);
            }
        }
    }

    $result['ok'] = $uploaded;

    if ($result['ok']) {
        $result['note'] = trim($result['note']) !== ''
            ? trim($result['note'])
            : 'Dump ' . human_size($result['bytes']) . ' (' . $result['rows'] . ' rows) to the ' . $result['where'] . '.';
    } elseif ($result['note'] === '') {
        $result['note'] = 'The backup did not leave the host.';
    }

    set_setting('backup_last_run', now());
    set_setting('backup_last_status', $result['ok'] ? 'ok' : 'error');
    set_setting('backup_last_note', mb_substr($result['note'], 0, 500));
    set_setting('backup_last_bytes', (string) $result['bytes']);
    set_setting('backup_last_rows', (string) $result['rows']);
    set_setting('backup_last_photos', (string) $result['photos']);
    set_setting('backup_last_where', $result['where']);

    return $result;
}
