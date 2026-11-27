<?php
/**
 * Printer Settings: the ET-2926 link, maintenance mode, the Epson Connect
 * credentials, connection diagnostics and the facts about the device.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();
$epson = EpsonClient::fromSettings();

$defaultRedirect = absolute_url('admin/epson-callback.php');

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'epson') {
        $clientId     = mb_substr(input('epson_client_id'), 0, 200);
        $clientSecret = trim((string) ($_POST['epson_client_secret'] ?? ''));
        $apiKey       = mb_substr(input('epson_api_key'), 0, 300);
        $redirectUri  = mb_substr(input('epson_redirect_uri'), 0, 300);

        set_setting('epson_client_id', $clientId);
        set_setting('epson_api_key', $apiKey);
        set_setting('epson_redirect_uri', $redirectUri);

        if ($clientSecret !== '') {
            set_setting('epson_client_secret', $clientSecret);
        }

        flash('success', 'Epson credentials saved.');
        redirect('admin/printer-settings.php');
    }

    if ($action === 'connect') {
        if (!$epson->isConfigured()) {
            flash('error', 'Save your Client ID, Client Secret and API Key first.');
            redirect('admin/printer-settings.php');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['epson_state'] = $state;
        $_SESSION['epson_state_time'] = time();
        redirect($epson->authorizeUrl($state));
    }

    if ($action === 'test') {
        if (!$epson->isConnected()) {
            flash('error', 'Link the printer first, then test the connection.');
            redirect('admin/printer-settings.php');
        }

        $epson->bypassCircuit = true;

        try {
            $device = $epson->deviceInfo();
            if (!empty($device['productName'])) {
                set_setting('epson_device_name', (string) $device['productName']);
            }
            if (!empty($device['serialNumber'])) {
                set_setting('epson_device_serial', (string) $device['serialNumber']);
            }

            $name = $device['productName'] ?? $epson->deviceName();
            $connected = array_key_exists('connected', $device) ? ($device['connected'] ? 'connected' : 'offline') : 'reachable';
            flash('success', 'Epson replied: ' . $name . ' is ' . $connected . '.');
        } catch (EpsonException $e) {
            flash('error', 'Epson test failed: ' . $e->getMessage());
        }

        redirect('admin/printer-settings.php');
    }

    if ($action === 'disconnect') {
        $epson->disconnect();
        flash('success', 'Printer unlinked. Credentials were kept so you can reconnect quickly.');
        redirect('admin/printer-settings.php');
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

        redirect('admin/printer-settings.php');
    }

    if ($action === 'maintenance_on') {
        pause_printing((string) $admin['name'], input('note'));
        flash('success', 'Maintenance mode enabled. A sheet already on the printer finishes; nothing new is sent until it is turned off.');
        redirect('admin/printer-settings.php');
    }

    if ($action === 'maintenance_off') {
        resume_printing((string) $admin['name']);
        flash('success', 'Maintenance mode off. The queue carries on with the oldest accepted order.');
        redirect('admin/printer-settings.php');
    }
}

$epsonFresh = EpsonClient::fromSettings();
$deviceName = $epsonFresh->deviceName();
$redirect   = setting('epson_redirect_uri', '') ?: $defaultRedirect;

$epsonStats     = epson_request_stats();
$circuitLeft    = epson_circuit_seconds();
$epsonOutage    = epson_outage_state();
$recentRequests = epson_recent_requests(8);
$successRate    = (int) $epsonStats['total'] > 0
    ? (int) round((1 - ((int) $epsonStats['failed'] / (int) $epsonStats['total'])) * 100)
    : null;
$edgePins       = array_values(array_unique(array_filter([
    epson_edge_ip('auth.epsonconnect.com'),
    epson_edge_ip('api.epsonconnect.com'),
])));

$maintenance   = printing_pause_info();
$pendingPhotos = (int) db_val("SELECT COUNT(*) FROM order_photos WHERE status = 'pending'" . order_photo_only());

app_start('admin', 'printer-settings', 'Printer Settings', 'The Epson ET-2926 link, maintenance mode and the diagnostics behind it.');
?>

<div class="settings-layout">
    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Maintenance mode</h3>
                    <p>Keep the queue off the printer while you clean or reload it</p>
                </div>
                <?php if ($maintenance['paused']): ?>
                    <span class="badge badge-pending"><?= icon('clock', 'badge-icon') ?> On</span>
                <?php else: ?>
                    <span class="badge badge-neutral"><?= icon('check', 'badge-icon') ?> Off</span>
                <?php endif; ?>
            </div>
            <div class="panel-body">
                <?php if ($maintenance['paused']): ?>
                    <p class="muted" style="margin-top:0">
                        Enabled <?= $maintenance['at'] !== '' ? e(time_ago($maintenance['at'])) : 'just now' ?><?= $maintenance['by'] !== '' ? ' by ' . e($maintenance['by']) : '' ?><?= $maintenance['note'] !== '' ? ' — ' . e($maintenance['note']) : '' ?>.
                        The worker still records the sheet already on the printer but sends nothing new, so photos keep their place.
                    </p>
                    <div class="table-actions" style="justify-content:flex-start;flex-wrap:wrap">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="maintenance_off">
                            <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Disable maintenance mode</button>
                        </form>
                        <a class="btn btn-soft btn-sm" href="<?= e(url('admin/maintenance.php')) ?>"><?= icon('wrench') ?> Cleaning steps</a>
                    </div>
                <?php else: ?>
                    <p class="muted" style="margin-top:0">
                        Turn this on before a head cleaning, nozzle check or paper change: nothing new is sent to
                        <?= e(EPSON_PRINTER_MODEL) ?> until you turn it off, and <span class="mono">Send now</span>/<span class="mono">Print</span> are refused meanwhile.
                    </p>
                    <form method="post" style="display:flex;flex-direction:column;gap:10px;align-items:flex-start">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="maintenance_on">
                        <label class="field" style="width:100%;max-width:420px;margin:0">
                            <span class="field-label">Reason (optional)</span>
                            <input class="input" type="text" name="note" maxlength="200" placeholder="e.g. head cleaning after banding">
                        </label>
                        <button class="btn btn-soft" type="submit"><?= icon('clock') ?> Enable maintenance mode</button>
                    </form>
                <?php endif; ?>
                <p class="help" style="margin-bottom:0">
                    <?= $pendingPhotos ?> photo<?= $pendingPhotos === 1 ? '' : 's' ?> waiting ·
                    <a href="<?= e(url('admin/maintenance.php')) ?>">nozzle check and head cleaning steps</a>
                </p>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Epson Connect API v2</h3>
                    <p>Where orders are sent to print</p>
                </div>
                <?php if ($epsonFresh->isConnected()): ?>
                    <span class="badge badge-completed"><?= icon('check', 'badge-icon') ?> Linked</span>
                <?php elseif ($epsonFresh->isConfigured()): ?>
                    <span class="badge badge-pending"><?= icon('clock', 'badge-icon') ?> Not linked</span>
                <?php else: ?>
                    <span class="badge badge-neutral"><?= icon('gear', 'badge-icon') ?> Not configured</span>
                <?php endif; ?>
            </div>

            <div class="panel-body">
                <?php if ($epsonFresh->isConnected()): ?>
                    <div class="alert alert-success">
                        <?= icon('printer') ?>
                        <div>
                            <strong><?= e($deviceName) ?></strong>
                            <p>
                                Linked <?= e(format_date(setting('epson_connected_at', ''), 'j M Y')) ?>
                                <?php if (setting('epson_device_serial', '')): ?>· Serial <?= e(setting('epson_device_serial', '')) ?><?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="settings-section">
                    <h3>App credentials</h3>
                    <p>Create an app at developer.epsonconnect.com, then paste the values here. The Client Secret is never shown again afterwards.</p>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="epson">

                        <div class="field-row">
                            <div class="field">
                                <label for="epson_client_id">Client ID</label>
                                <input class="input" type="text" id="epson_client_id" name="epson_client_id"
                                       value="<?= e(setting('epson_client_id', '')) ?>" autocomplete="off" spellcheck="false">
                            </div>
                            <div class="field">
                                <label for="epson_client_secret">Client Secret</label>
                                <input class="input" type="password" id="epson_client_secret" name="epson_client_secret"
                                       value="" autocomplete="new-password"
                                       placeholder="<?= setting('epson_client_secret', '') ? '•••••••• saved — leave blank to keep' : 'Paste the secret' ?>">
                            </div>
                        </div>

                        <div class="field">
                            <label for="epson_api_key">API Key</label>
                            <input class="input" type="text" id="epson_api_key" name="epson_api_key"
                                   value="<?= e(setting('epson_api_key', '')) ?>" autocomplete="off" spellcheck="false">
                        </div>

                        <div class="field">
                            <label for="epson_redirect_uri">Redirect URI</label>
                            <input class="input" type="url" id="epson_redirect_uri" name="epson_redirect_uri"
                                   value="<?= e(setting('epson_redirect_uri', '')) ?>" placeholder="<?= e($defaultRedirect) ?>" spellcheck="false">
                            <p class="help">Must match the Redirect URI registered on your Epson app. Leave blank to use the address below.</p>
                        </div>

                        <div class="code-box" style="margin-bottom:18px">
                            <span><?= e($redirect) ?></span>
                            <button class="copy-btn" type="button" data-copy="<?= e($redirect) ?>">Copy</button>
                        </div>

                        <div class="table-actions" style="justify-content:flex-start;flex-wrap:wrap">
                            <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Save credentials</button>
                        </div>
                    </form>
                </div>

                <div class="settings-section">
                    <h3>Printer link</h3>
                    <p>Epson uses OAuth2 device authorisation. You sign in once with your Epson account and pick the ET-2926.</p>

                    <div class="table-actions" style="justify-content:flex-start;flex-wrap:wrap">
                        <?php if ($epsonFresh->isConnected()): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="test">
                                <button class="btn btn-soft btn-sm" type="submit"><?= icon('wifi') ?> Test connection</button>
                            </form>
                            <form method="post" data-confirm="Unlink the printer? You will need to authorise it again before printing.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="disconnect">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('close') ?> Unlink printer</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="connect">
                                <button class="btn btn-primary btn-sm" type="submit" <?= $epsonFresh->isConfigured() ? '' : 'disabled' ?>>
                                    <?= icon('printer') ?> Connect <?= e(EPSON_PRINTER_MODEL) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (!$epsonFresh->isConfigured()): ?>
                        <p class="help mt-2">Save the credentials first — the connect button unlocks once they are stored.</p>
                    <?php endif; ?>
                </div>

                <div class="settings-section">
                    <h3>How the print flow works</h3>
                    <ol class="settings-steps">
                        <li>Register the printer with Epson Connect and create an app to get a Client ID, Client Secret and API Key.</li>
                        <li>Add <span class="mono"><?= e($redirect) ?></span> as the Redirect URI on that app.</li>
                        <li>Save the credentials here, then press <strong>Connect <?= e(EPSON_PRINTER_MODEL) ?></strong> and pick the printer on the Epson page.</li>
                        <li>When a photo order arrives, open it and press <strong>Print</strong>. Each photo becomes its own Epson job and is uploaded and started through the API.</li>
                    </ol>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Epson connection</h3>
                    <p>Request health and recent attempts</p>
                </div>
                <form method="post" action="<?= e(url('admin/printer-settings.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test_connection">
                    <button class="btn btn-soft btn-sm" type="submit"><?= icon('bolt') ?> Test</button>
                </form>
            </div>
            <div class="panel-body">
                <div class="summary-list" style="margin:0 0 12px">
                    <div class="summary-row"><span>State</span><strong><?= $circuitLeft > 0 ? 'Failing fast, retry in ' . $circuitLeft . ' s' : 'Calling Epson normally' ?></strong></div>
                    <div class="summary-row"><span>Epson edge</span><strong><?= $edgePins === [] ? 'Automatic (DNS)' : e(implode(', ', $edgePins)) . ' (pinned)' ?></strong></div>
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
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h3>Printer facts</h3></div>
            <div class="panel-body">
                <div class="profile-facts">
                    <div class="profile-fact"><span>Model</span><strong><?= e(EPSON_PRINTER_MODEL) ?></strong></div>
                    <div class="profile-fact"><span>API</span><strong>Connect v2</strong></div>
                    <div class="profile-fact"><span>Queue</span><strong><?= setting('accepting_orders', '1') === '1' ? 'Open' : 'Paused' ?></strong></div>
                </div>
                <a class="btn btn-ghost btn-block btn-sm mt-3" href="<?= e(url('admin/maintenance.php')) ?>">
                    <?= icon('wrench') ?> Head cleaning and maintenance
                </a>
                <a class="btn btn-ghost btn-block btn-sm mt-2" href="https://developer.epsonconnect.com/" target="_blank" rel="noopener noreferrer">
                    <?= icon('arrow-right') ?> Epson developer portal
                </a>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>
