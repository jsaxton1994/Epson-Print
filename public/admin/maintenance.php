<?php
/**
 * Printer maintenance: pause the queue, then work through head cleaning and
 * nozzle checks on the printer itself.
 *
 * Epson Connect has no maintenance commands (no head cleaning or nozzle check
 * over the API), so this page pauses the queue around work done at the printer
 * and shows what the printer is doing while it is paused.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();
$epson = EpsonClient::fromSettings();

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'pause') {
        pause_printing((string) $admin['name'], input('note'));
        flash('success', 'Printing paused. A sheet already on the printer finishes normally; nothing new is sent until you resume.');
        redirect('admin/maintenance.php');
    }

    if ($action === 'resume') {
        resume_printing((string) $admin['name']);
        flash('success', 'Printing resumed. The queue carries on with the oldest accepted order.');
        redirect('admin/maintenance.php');
    }

    if ($action === 'refresh') {
        @set_time_limit(60);
        set_setting('printer_status_cache', '');

        $fresh = printer_live_status($epson);

        if (($fresh['state'] ?? '') === 'unreachable') {
            flash('error', 'Epson did not answer: ' . (string) ($fresh['detail'] ?? 'connection failed'));
        } else {
            flash('info', 'Printer status refreshed.');
        }

        redirect('admin/maintenance.php');
    }

    if ($action === 'timing_report') {
        // Temporary diagnostics: how long a sheet really takes, and how much of
        // the gap between sheets is the printer and how much is the queue.
        @set_time_limit(60);
        header('Content-Type: application/json; charset=utf-8');

        $rows = db_all(
            'SELECT p.id, p.order_id, p.status, p.position, p.created_at, '
            . (print_started_column_ready() ? 'p.print_started_at, ' : 'NULL AS print_started_at, ')
            . 'p.printed_at, o.reference
             FROM order_photos p
             JOIN orders o ON o.id = p.order_id
             WHERE p.printed_at IS NOT NULL
             ORDER BY p.printed_at DESC
             LIMIT 30'
        );

        // Rows arrive newest first, so the previous loop iteration is always the
        // sheet that came after this one: that is what the gap is measured against.
        $prevStart = null;
        $prevPrint = null;
        $printSecs = [];
        $gapSecs   = [];
        $out       = ['at' => now(), 'worker_last_run' => (string) setting('print_queue_last_run', ''), 'sheets' => []];

        foreach ($rows as $row) {
            $printedTs = strtotime((string) $row['printed_at']) ?: null;
            $startedTs = !empty($row['print_started_at']) ? (strtotime((string) $row['print_started_at']) ?: null) : null;
            $print     = $startedTs !== null && $printedTs !== null ? $printedTs - $startedTs : null;
            $gap       = $prevStart !== null && $printedTs !== null ? $prevStart - $printedTs : null;

            if ($print !== null) {
                $printSecs[] = $print;
            }

            if ($gap !== null && $gap >= 0) {
                $gapSecs[] = $gap;
            }

            $out['sheets'][] = [
                'reference'    => (string) $row['reference'],
                'position'     => (int) $row['position'],
                'started'      => $row['print_started_at'],
                'printed'      => (string) $row['printed_at'],
                'print_secs'   => $print,
                'gap_after'    => $gap,
                'since_prev'   => $prevPrint !== null && $printedTs !== null ? $prevPrint - $printedTs : null,
            ];

            $prevStart = $startedTs;
            $prevPrint = $printedTs;
        }

        $avgPrint = $printSecs !== [] ? array_sum($printSecs) / count($printSecs) : null;
        $avgGap   = $gapSecs !== [] ? array_sum($gapSecs) / count($gapSecs) : null;

        $out['averages'] = [
            'print_secs' => $avgPrint !== null ? round($avgPrint, 1) : null,
            'gap_secs'   => $avgGap !== null ? round($avgGap, 1) : null,
            'cycle_secs' => $avgPrint !== null && $avgGap !== null ? round($avgPrint + $avgGap, 1) : null,
            'per_hour'   => $avgPrint !== null && $avgGap !== null && ($avgPrint + $avgGap) > 0
                ? round(3600 / ($avgPrint + $avgGap), 1)
                : null,
        ];

        $out['printed_last_hour'] = (int) db_val(
            'SELECT COUNT(*) FROM order_photos WHERE printed_at IS NOT NULL AND printed_at >= ?',
            [date('Y-m-d H:i:s', time() - 3600)]
        );

        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'net_probe') {
        // Temporary diagnostics: what this server can actually reach, so a host
        // egress problem can be told apart from an Epson-side block.
        @set_time_limit(120);
        header('Content-Type: application/json; charset=utf-8');

        $hosts = ['auth.epsonconnect.com', 'api.epsonconnect.com'];
        $out   = ['at' => now(), 'dns' => [], 'tests' => []];

        foreach ($hosts as $host) {
            $out['dns'][$host] = @gethostbynamel($host) ?: [];
        }

        $tests = [];
        foreach ($hosts as $host) {
            foreach (array_slice($out['dns'][$host], 0, 2) as $ip) {
                $tests[] = [$host, $ip];
            }
        }
        foreach (['52.85.129.2', '52.85.129.33', '52.85.129.9', '52.85.129.3'] as $ip) {
            $tests[] = ['auth.epsonconnect.com', $ip];
        }
        $tests[] = ['www.cloudflare.com', null];
        $tests[] = ['api.ipify.org', null];

        $seen = [];
        foreach ($tests as [$host, $ip]) {
            $key = $host . '|' . ($ip ?? 'dns');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $ch      = curl_init('https://' . $host . '/');
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER     => ['Accept: */*'],
            ];

            if ($ip !== null) {
                $options[CURLOPT_RESOLVE] = [$host . ':443:' . $ip];
            }

            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            $info = curl_getinfo($ch);

            $out['tests'][] = [
                'host'       => $host,
                'ip'         => $ip ?? (string) ($info['primary_ip'] ?? ''),
                'http'       => (int) ($info['http_code'] ?? 0),
                'errno'      => curl_errno($ch),
                'error'      => mb_substr(curl_error($ch), 0, 120),
                'dns_ms'     => (int) round(1000 * (float) ($info['namelookup_time'] ?? 0)),
                'connect_ms' => (int) round(1000 * (float) ($info['connect_time'] ?? 0)),
                'total_ms'   => (int) round(1000 * (float) ($info['total_time'] ?? 0)),
                'body'       => $host === 'api.ipify.org' ? trim((string) $body) : null,
            ];
            curl_close($ch);
        }

        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'notify_watch_reset') {
        epson_notify_watch_restart();
        flash('info', 'Webhook watch restarted. It now counts sheets finished and requests received from this moment.');
        redirect('admin/maintenance.php');
    }

    if ($action === 'test_connection') {
        // Diagnostics: one request with the fail-fast circuit skipped.
        @set_time_limit(60);
        $epson->bypassCircuit = true;
        $started = microtime(true);

        try {
            $device = $epson->deviceInfo();
            $ms     = (int) round((microtime(true) - $started) * 1000);
            flash('success', 'Epson answered in ' . $ms . ' ms.' . (!empty($device['connected']) ? ' The printer is linked.' : ' The printer is not reporting as connected.'));
        } catch (EpsonException $e) {
            $ms = (int) round((microtime(true) - $started) * 1000);
            flash('error', 'Epson did not answer after ' . $ms . ' ms: ' . $e->getMessage());
        }

        redirect('admin/maintenance.php');
    }
}

$pause  = printing_pause_info();
$health = queue_health();
$status = printer_live_status($epson, false);

$epsonStats    = epson_request_stats();
$circuitLeft   = epson_circuit_seconds();
$epsonOutage   = epson_outage_state();
$recentRequests = epson_recent_requests(8);
$successRate   = (int) $epsonStats['total'] > 0
    ? (int) round((1 - ((int) $epsonStats['failed'] / (int) $epsonStats['total'])) * 100)
    : null;

$pendingPhotos = (int) db_val("SELECT COUNT(*) FROM order_photos WHERE status = 'pending'");
$waitingOrders = (int) $health['queued'];
$notifyWatch   = epson_notify_watch_summary();

app_start('admin', 'maintenance', 'Printer maintenance', 'Pause the queue and look after the printer between jobs.');
?>

<?php if ($pause['paused']): ?>
    <div class="banner banner-warn">
        <?= icon('clock') ?>
        <div>
            <strong>Printing is paused</strong>
            <p>
                Paused <?= $pause['at'] !== '' ? e(time_ago($pause['at'])) : 'just now' ?><?= $pause['by'] !== '' ? ' by ' . e($pause['by']) : '' ?><?= $pause['note'] !== '' ? ' — ' . e($pause['note']) : '' ?>.
                The cron worker still checks the job already on the printer, but sends nothing new.
                <?= $pendingPhotos ?> photo<?= $pendingPhotos === 1 ? '' : 's' ?> are waiting.
            </p>
            <div class="banner-actions">
                <form method="post" action="<?= e(url('admin/maintenance.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resume">
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Resume printing</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="columns">
    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3><?= $pause['paused'] ? 'Printing is paused' : 'Pause printing' ?></h3>
                    <p>Keep the queue still while you clean the heads or change paper</p>
                </div>
            </div>
            <div class="panel-body">
                <?php if ($pause['paused']): ?>
                    <p class="muted" style="margin-top:0">
                        Nothing new will be sent to <?= e(EPSON_PRINTER_MODEL) ?> until you resume, so a cleaning cycle cannot be
                        interrupted by a print job. Photos stay queued in the same order.
                    </p>
                    <form method="post" action="<?= e(url('admin/maintenance.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resume">
                        <button class="btn btn-primary" type="submit"><?= icon('check') ?> Resume printing</button>
                    </form>
                <?php else: ?>
                    <p class="muted" style="margin-top:0">
                        Pause before starting a head cleaning or nozzle check on the printer. A sheet already printing finishes
                        first; after that the printer stays free until you resume.
                    </p>
                    <form method="post" action="<?= e(url('admin/maintenance.php')) ?>" style="display:flex;flex-direction:column;gap:10px;align-items:flex-start">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="pause">
                        <label class="field" style="width:100%;margin:0">
                            <span class="field-label">Reason (optional)</span>
                            <input class="input" type="text" name="note" maxlength="200" placeholder="e.g. head cleaning after banding">
                        </label>
                        <button class="btn btn-soft" type="submit"><?= icon('clock') ?> Pause printing</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Clean the print heads</h3>
                    <p>For banding lines, streaks or missing colour</p>
                </div>
            </div>
            <div class="panel-body">
                <p class="muted" style="margin-top:0">
                    Epson Connect has no head-cleaning command, so cleaning runs on the printer or in Epson Smart Panel —
                    this page makes sure the queue is not printing while you do it.
                </p>
                <ol class="maintenance-steps">
                    <li><strong>Pause printing</strong> above and wait for any sheet already printing to come out.</li>
                    <li>On the printer panel open <strong>Settings → Maintenance → Print Head Nozzle Check</strong> and print the pattern.</li>
                    <li>If lines or gaps appear, run <strong>Print Head Cleaning</strong>, then print the nozzle check again.</li>
                    <li>Repeat cleaning up to three times. If the pattern is still broken, use <strong>Power Cleaning</strong>, then leave the printer for about 12 hours before printing photos.</li>
                    <li>Check <strong>Paper Setting</strong> matches the paper loaded (Epson photo paper, 4 × 6 in) and that quality is High on the order.</li>
                    <li><strong>Resume printing</strong> when the nozzle check pattern is clean.</li>
                </ol>
                <p class="muted" style="margin-bottom:0">
                    In Epson Smart Panel the same steps are under <strong>Maintenance</strong> for the ET-2926. Cleaning uses ink,
                    so only run it when a print shows banding or gaps.
                </p>
            </div>
        </section>
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Printer right now</h3>
                    <p>
                        <?= !empty($status['never_checked']) ? 'Not checked yet' : 'Last checked ' . e(time_ago((string) $status['checked_at'])) ?><?= !empty($status['stale']) ? ' · cached while Epson is unreachable' : '' ?>
                    </p>
                </div>
                <form method="post" action="<?= e(url('admin/maintenance.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refresh">
                    <button class="btn btn-soft btn-sm" type="submit"><?= icon('refresh') ?> Refresh</button>
                </form>
            </div>
            <div class="panel-body">
                <div class="summary-list" style="margin:0">
                    <div class="summary-row"><span>Status</span><strong><?= e($status['label']) ?></strong></div>
                    <div class="summary-row"><span>Detail</span><strong style="text-align:right"><?= e($status['detail']) ?></strong></div>
                    <?php if (!empty($status['order_reference'])): ?>
                        <div class="summary-row"><span>Order on printer</span><strong><?= e((string) $status['order_reference']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (isset($status['connected'])): ?>
                        <div class="summary-row"><span>Linked to Epson Connect</span><strong><?= $status['connected'] ? 'Yes' : 'No' ?></strong></div>
                    <?php endif; ?>
                    <div class="summary-row"><span>Waiting orders</span><strong><?= $waitingOrders ?></strong></div>
                    <div class="summary-row"><span>Photos waiting</span><strong><?= $pendingPhotos ?></strong></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Epson connection</h3>
                    <p>Request health and recent attempts</p>
                </div>
                <form method="post" action="<?= e(url('admin/maintenance.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test_connection">
                    <button class="btn btn-soft btn-sm" type="submit"><?= icon('bolt') ?> Test</button>
                </form>
            </div>
            <div class="panel-body">
                <div class="summary-list" style="margin:0 0 12px">
                    <div class="summary-row"><span>State</span><strong><?= $circuitLeft > 0 ? 'Failing fast, retry in ' . $circuitLeft . ' s' : 'Calling Epson normally' ?></strong></div>
                    <div class="summary-row"><span>Epson edge</span><strong><?php
                        $edgePins = array_values(array_unique(array_filter([
                            epson_edge_ip('auth.epsonconnect.com'),
                            epson_edge_ip('api.epsonconnect.com'),
                        ])));
                        ?><?= $edgePins === [] ? 'Automatic (DNS)' : e(implode(', ', $edgePins)) . ' (pinned)' ?></strong></div>
                    <div class="summary-row"><span>Requests / failures</span><strong><?= (int) $epsonStats['total'] ?> / <?= (int) $epsonStats['failed'] ?><?= $successRate !== null ? ' · ' . $successRate . '% ok' : '' ?></strong></div>
                    <div class="summary-row"><span>Consecutive failures</span><strong><?= (int) $epsonStats['consecutive'] ?></strong></div>
                    <div class="summary-row"><span>Outage</span><strong><?= $epsonOutage['active']
                        ? e(epson_outage_summary($epsonOutage))
                        : ($epsonOutage['recovered_at'] !== ''
                            ? 'Recovered ' . e(time_ago((string) $epsonOutage['recovered_at']))
                                . ($epsonOutage['last_duration'] !== null ? ' after ' . max(1, (int) floor($epsonOutage['last_duration'] / 60)) . ' min' : '')
                            : 'None logged') ?></strong></div>
                    <div class="summary-row"><span>Last success</span><strong><?= $epsonStats['last_ok_at'] !== '' ? e(time_ago((string) $epsonStats['last_ok_at'])) : 'None yet' ?></strong></div>
                </div>

                <?php if ($recentRequests === []): ?>
                    <p class="muted" style="margin:0">No Epson requests logged yet. The next queue run fills this in.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr><th>Time</th><th>Request</th><th>Result</th><th>Total</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentRequests as $entry): ?>
                                    <?php $entryAt = strtotime((string) $entry['at']) ?: time(); ?>
                                    <tr>
                                        <td class="muted"><?= e(date('H:i:s', $entryAt)) ?></td>
                                        <td><?= e((string) $entry['path']) ?></td>
                                        <td><?= !empty($entry['ok']) ? 'OK' : 'cURL ' . (int) $entry['errno'] ?></td>
                                        <td><?= (int) $entry['total_ms'] ?> ms</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($epsonOutage['active']): ?>
                    <p class="muted" style="margin:12px 0 0;font-size:.78rem">
                        Send failures are retried inside the same worker run, so printing restarts by itself when the connection returns.
                        <?php if ($epsonOutage['alerted_at'] !== ''): ?>
                            Alert emailed <?= e(time_ago((string) $epsonOutage['alerted_at'])) ?> to <?= e(queue_alert_recipient()) ?>;
                            it repeats every <?= (int) EPSON_OUTAGE_REMINDER_MINUTES ?> minutes and once more when Epson is back.
                        <?php else: ?>
                            An alert email goes out after <?= (int) EPSON_OUTAGE_ALERT_MINUTES ?> minutes with orders waiting.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if ($epsonStats['last_error'] !== ''): ?>
                    <p class="muted" style="margin:12px 0 0;font-size:.78rem">
                        Last error <?= e(time_ago((string) $epsonStats['last_error_at'])) ?>:
                        <?= e((string) $epsonStats['last_error']) ?>
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Webhook watch</h3>
                    <p>Whether Epson's job callback actually reaches this site</p>
                </div>
                <form method="post" action="<?= e(url('admin/maintenance.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="notify_watch_reset">
                    <button class="btn btn-soft btn-sm" type="submit"><?= icon('refresh') ?> Restart watch</button>
                </form>
            </div>
            <div class="panel-body">
                <div class="summary-list" style="margin:0 0 12px">
                    <div class="summary-row"><span>Watching since</span><strong><?= e($notifyWatch['started_at']) ?><?= $notifyWatch['started_at'] !== '' ? ' · ' . e(time_ago($notifyWatch['started_at'])) : '' ?></strong></div>
                    <div class="summary-row"><span>Sheets finished since</span><strong><?= (int) $notifyWatch['sheets'] ?></strong></div>
                    <div class="summary-row"><span>Requests received</span><strong><?= (int) $notifyWatch['total'] ?> (<?= (int) $notifyWatch['posts'] ?> POST)</strong></div>
                    <div class="summary-row"><span>Last request</span><strong><?php
                        echo $notifyWatch['last_at'] === ''
                            ? 'None'
                            : e($notifyWatch['last_at']) . ' · ' . e($notifyWatch['last_method']) . ' from ' . e($notifyWatch['last_ip'] !== '' ? $notifyWatch['last_ip'] : 'unknown IP');
                        ?></strong></div>
                    <div class="summary-row"><span>Notifications</span><strong><?= epson_notifications_enabled() ? 'On' : 'Off — observing only' ?></strong></div>
                </div>
                <p style="margin:0 0 8px;font-weight:600"><?= e($notifyWatch['verdict']) ?></p>
                <p class="muted" style="margin:0;font-size:.78rem">
                    Every request that gets past the host and into PHP is counted here, matching token or not. Sheets finished is the
                    control: a sheet that finishes with no request means Epson is not delivering, while a counted request with
                    <span class="mono">observed</span> means it is. While notifications are off nothing is applied to any photo.
                </p>
                <?php $watchDebug = epson_notify_debug_entries(5); ?>
                <?php if ($watchDebug !== []): ?>
                    <div class="table-wrap" style="margin-top:10px">
                        <table class="table">
                            <thead><tr><th>Time</th><th>From</th><th>Outcome</th><th>HTTP</th><th>Token</th></tr></thead>
                            <tbody>
                                <?php foreach ($watchDebug as $row): ?>
                                    <tr>
                                        <td class="muted"><?= e(date('H:i:s', strtotime((string) $row['at']) ?: time())) ?></td>
                                        <td class="mono"><?= e((string) ($row['ip'] ?? '—')) ?></td>
                                        <td><?= e((string) ($row['outcome'] ?? '')) ?></td>
                                        <td><?= (int) ($row['status'] ?? 0) ?></td>
                                        <td><?= e((string) ($row['token'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>What pausing does</h3>
                    <p>How the queue behaves while paused</p>
                </div>
            </div>
            <div class="panel-body">
                <ul class="maintenance-list">
                    <li>The cron worker keeps running and still records when the sheet on the printer finishes.</li>
                    <li>No new photo is uploaded or sent, and <strong>Send now</strong> and <strong>Print</strong> are refused with a reminder to resume.</li>
                    <li>Queue-stalled and printer emails from the watchdog are held while printing is paused.</li>
                    <li>Orders keep their place in the queue and the accepted order is not changed.</li>
                </ul>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>
