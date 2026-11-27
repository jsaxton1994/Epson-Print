<?php
/**
 * Printer Settings: the ET-2926 link, its live ink/paper state, the Epson
 * Connect credentials and the facts about the device.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_admin();
$epson = EpsonClient::fromSettings();

$defaultRedirect = absolute_url('admin/epson-callback.php');

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'refresh') {
        $fresh = printer_live_status_fresh($epson);

        if (($fresh['state'] ?? '') === 'unreachable') {
            flash('error', 'Epson did not answer: ' . (string) ($fresh['detail'] ?? 'connection failed'));
        } else {
            flash('info', 'Printer status refreshed.');
        }

        redirect('admin/printer-settings.php');
    }

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
}

$epsonFresh = EpsonClient::fromSettings();
$deviceName = $epsonFresh->deviceName();
$redirect   = setting('epson_redirect_uri', '') ?: $defaultRedirect;
// This page asks Epson every time it opens, and keeps the last reading marked
// stale if Epson does not answer, so a timeout never blanks the cards.
$live       = printer_live_status_fresh($epsonFresh);
$hold       = printer_supply_block();

// Ink and paper come from what the printer reports. Epson Connect API v2 only
// returns the model, serial and link state, so a tank level is only shown when
// the printer itself has flagged a stop.
$inkTone  = 'brand';
$inkValue = 'No ink data from Epson';
$inkHint  = 'Epson Connect has no ink fields at all: its device API returns only the model, serial and link state. Check the tanks or Epson Smart Panel — a job that reports empty ink holds the queue by itself.';

if ($hold['active'] && $hold['reason'] === 'marker_supply_empty') {
    $inkTone  = 'amber';
    $inkValue = 'Empty — refill';
    $inkHint  = 'The printer reported empty ink ' . ($hold['since'] !== '' ? time_ago($hold['since']) : 'just now') . '; nothing new is sent until the hold is cleared.';
}

$paperTone  = 'brand';
$paperValue = 'Ready';
$paperHint  = 'Photo paper loaded; the queue is sending sheets.';

if ($hold['active'] && in_array($hold['reason'], ['media_empty', 'media_jam'], true)) {
    $paperTone  = 'amber';
    $paperValue = $hold['reason'] === 'media_jam' ? 'Paper jam' : 'Out of paper';
    $paperHint  = 'The queue has been held ' . ($hold['since'] !== '' ? time_ago($hold['since']) : 'just now') . ' until the printer is loaded and the hold is cleared.';
}

app_start('admin', 'printer-settings', 'Printer Settings', 'The Epson ET-2926, its ink and paper state, and the credentials behind the link.');
?>

<div class="device-card">
    <div class="device-top">
        <span class="device-label">Epson Connect</span>
        <?php if ($epsonFresh->isConnected()): ?>
            <span class="device-state"><span class="status-dot"></span> Linked</span>
        <?php else: ?>
            <span class="device-state is-off">Not linked</span>
        <?php endif; ?>
    </div>
    <div class="device-name">
        <span class="device-icon"><?= icon('printer') ?></span>
        <div>
            <h3><?= e($epsonFresh->isConnected() ? $deviceName : EPSON_PRINTER_MODEL) ?></h3>
            <p>
                <?php if ($epsonFresh->isConnected()): ?>
                    Serial <?= e(setting('epson_device_serial', '') !== '' ? (string) setting('epson_device_serial', '') : 'not reported') ?> · Connect v2
                <?php else: ?>
                    Authorise the printer to send jobs automatically
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="device-meta">
        <span class="device-chip"><?= icon('image') ?> Photo mode</span>
        <span class="device-chip"><?= icon('droplet') ?> No tank data from Epson</span>
        <?php if (setting('accepting_orders', '1') === '1'): ?>
            <span class="device-chip"><?= icon('check') ?> Queue open</span>
        <?php else: ?>
            <span class="device-chip"><?= icon('clock') ?> Queue paused</span>
        <?php endif; ?>
    </div>
    <div class="device-actions">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="refresh">
            <button class="btn btn-sm btn-primary" type="submit"><?= icon('refresh') ?> Refresh printer state</button>
        </form>
        <a class="btn btn-sm" href="<?= e(url('admin/maintenance.php')) ?>"><?= icon('wrench') ?> Maintenance</a>
    </div>
</div>

<p class="muted" style="font-size:.8rem;margin:10px 2px 18px">
    <?= !empty($live['never_checked']) ? 'Not checked yet' : 'Checked ' . e(time_ago((string) $live['checked_at'])) ?>
    <?= !empty($live['stale']) ? ' · last known reading — Epson did not answer' : '' ?>
</p>

<div class="stats-grid is-stacked">
    <?php stat_card('Printer', (string) $live['label'], 'printer', 'brand', (string) $live['detail']); ?>
    <?php stat_card('Ink', $inkValue, 'droplet', $inkTone, $inkHint); ?>
    <?php stat_card('Paper', $paperValue, 'file', $paperTone, $paperHint); ?>
</div>

<div class="settings-layout">
    <div class="stack">
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
