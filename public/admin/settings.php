<?php
/**
 * Service settings, Epson Connect credentials and admin account.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/backup.php';

$admin = require_admin();
$epson = EpsonClient::fromSettings();

$defaultRedirect = absolute_url('admin/epson-callback.php');

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'service') {
        $siteName   = mb_substr(input('site_name'), 0, 60);
        $email      = input('support_email');
        $alertEmail = input('queue_alert_email');
        $paper      = input('default_paper_size');
        $quality    = input('default_print_quality');

        set_setting('site_name', $siteName !== '' ? $siteName : APP_NAME);
        set_setting('support_email', filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : (string) setting('support_email', ''));
        set_setting('queue_alert_email', filter_var($alertEmail, FILTER_VALIDATE_EMAIL) ? $alertEmail : '');
        set_setting('accepting_orders', isset($_POST['accepting_orders']) ? '1' : '0');
        set_setting('default_paper_size', array_key_exists($paper, paper_size_options()) ? $paper : 'A4');
        set_setting('default_print_quality', array_key_exists($quality, quality_options()) ? $quality : 'normal');

        flash('success', 'Service settings saved.');
        redirect('admin/settings.php');
    }

    if ($action === 'monitor') {
        $url = trim((string) ($_POST['monitor_ping_url'] ?? ''));

        if ($url !== '' && !preg_match('#^https://#i', $url)) {
            flash('error', 'The heartbeat URL must start with https://.');
            redirect('admin/settings.php');
        }

        set_setting('monitor_ping_url', mb_substr($url, 0, 500));
        set_setting('customer_emails_enabled', isset($_POST['customer_emails_enabled']) ? '1' : '0');
        flash('success', 'Heartbeat and customer email settings saved.');
        redirect('admin/settings.php');
    }

    if ($action === 'monitor_test') {
        $ping = monitor_ping('ok', ['test' => true, 'source' => 'admin']);

        if ($ping === null) {
            flash('error', 'Add a heartbeat URL first.');
        } elseif ($ping['ok']) {
            flash('success', 'Heartbeat accepted the test ping (HTTP ' . $ping['code'] . ').');
        } else {
            flash('error', 'Heartbeat ping failed: ' . ($ping['error'] !== '' ? $ping['error'] : 'HTTP ' . $ping['code']));
        }

        redirect('admin/settings.php');
    }

    if ($action === 'relay') {
        $url = trim((string) ($_POST['relay_url'] ?? ''));

        if ($url !== '' && !preg_match('#^https://#i', $url)) {
            flash('error', 'The relay URL must start with https://.');
            redirect('admin/settings.php');
        }

        set_setting('relay_url', mb_substr(rtrim($url, '/'), 0, 500));
        flash('success', 'Relay URL saved.');
        redirect('admin/settings.php');
    }

    if ($action === 'relay_test') {
        if (!relay_configured()) {
            flash('error', 'Save the relay URL and token first.');
            redirect('admin/settings.php');
        }

        $health = relay_request('GET', '/health', null, 15);

        if ($health['ok']) {
            $hooks = relay_request('GET', '/hooks?limit=1', null, 15);
            set_setting('relay_pull_enabled', $hooks['ok'] ? '1' : '');
            flash($hooks['ok'] ? 'success' : 'error', $hooks['ok']
                ? 'Relay answered and the inbox is readable. Backups and callback collection are enabled.'
                : 'Relay health is fine but the inbox answered HTTP ' . $hooks['code'] . ' — set RELAY_TOKEN on the relay to this site\'s webhook token.');
        } else {
            flash('error', 'Relay did not answer: ' . ($health['error'] !== '' ? $health['error'] : 'HTTP ' . $health['code']));
        }

        redirect('admin/settings.php');
    }

    if ($action === 'relay_register') {
        if (!relay_configured()) {
            flash('error', 'Save the relay URL and token first.');
            redirect('admin/settings.php');
        }

        if (!$epson->isConnected()) {
            flash('error', 'Link the printer first.');
            redirect('admin/settings.php');
        }

        $epson->bypassCircuit = true;

        try {
            $epson->setNotification(true, relay_base_url() . '/hook?token=' . rawurlencode(relay_token()));
            set_setting('epson_notifications_enabled', '1');
            set_setting('relay_pull_enabled', '1');
            set_setting('epson_notify_registered_at', now());
            flash('success', 'Epson will now post job updates to the relay, and the print worker collects them each run.');
        } catch (EpsonException $e) {
            flash('error', 'Epson did not accept the relay callback: ' . $e->getMessage());
        }

        redirect('admin/settings.php');
    }

    if ($action === 'backup_run') {
        $result = run_backup();
        flash($result['ok'] ? 'success' : 'error', ($result['ok'] ? 'Backup stored: ' : 'Backup failed: ') . $result['note']);
        redirect('admin/settings.php');
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
        redirect('admin/settings.php');
    }

    if ($action === 'connect') {
        if (!$epson->isConfigured()) {
            flash('error', 'Save your Client ID, Client Secret and API Key first.');
            redirect('admin/settings.php');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['epson_state'] = $state;
        $_SESSION['epson_state_time'] = time();
        redirect($epson->authorizeUrl($state));
    }

    if ($action === 'test') {
        if (!$epson->isConnected()) {
            flash('error', 'Link the printer first, then test the connection.');
            redirect('admin/settings.php');
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

        redirect('admin/settings.php');
    }

    if ($action === 'disconnect') {
        $epson->disconnect();
        set_setting('epson_notifications_enabled', '');
        flash('success', 'Printer unlinked. Credentials were kept so you can reconnect quickly.');
        redirect('admin/settings.php');
    }

    if (in_array($action, ['notify_debug_clear', 'notify_sink_register', 'notify_restore'], true)) {
        if ($action === 'notify_debug_clear') {
            set_setting('epson_notify_debug', '');
            flash('success', 'Callback diagnostics cleared.');
            redirect('admin/settings.php');
        }

        if (!$epson->isConnected()) {
            flash('error', 'Link the printer before changing the callback URL.');
            redirect('admin/settings.php');
        }

        $epson->bypassCircuit = true;

        try {
            if ($action === 'notify_sink_register') {
                $sink = trim((string) ($_POST['sink_url'] ?? ''));

                if (!preg_match('#^https://#i', $sink) || strlen($sink) < 11 || strlen($sink) > 2048) {
                    flash('error', 'Enter a full https:// callback URL (11-2048 characters).');
                    redirect('admin/settings.php');
                }

                // Diagnostic only: parks Epson's notifications on a temporary bin.
                $epson->setNotification(true, $sink);
                flash('success', 'Epson will send job notifications to ' . $sink . ' for now. Point it back at this site when the test is over.');
            }

            if ($action === 'notify_restore') {
                $epson->setNotification(true, epson_notify_url());
                flash('success', 'Epson is pointed back at this site\'s webhook.');
            }
        } catch (EpsonException $e) {
            flash('error', 'Epson did not accept the callback URL: ' . $e->getMessage());
        }

        redirect('admin/settings.php');
    }

    if ($action === 'notify_check') {
        if (!$epson->isConnected()) {
            flash('error', 'Link the printer first.');
            redirect('admin/settings.php');
        }

        $epson->bypassCircuit = true;

        try {
            $info = $epson->notificationSettings();
            flash('info', 'Epson reports: ' . json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (EpsonException $e) {
            flash('error', 'Could not read the notification setting: ' . $e->getMessage());
        }

        redirect('admin/settings.php');
    }

    if (in_array($action, ['notify_enable', 'notify_disable', 'notify_reset'], true)) {
        if (!$epson->isConnected()) {
            flash('error', 'Link the printer before changing job notifications.');
            redirect('admin/settings.php');
        }

        // An administrator asking for this should always make one real attempt.
        $epson->bypassCircuit = true;

        try {
            if ($action === 'notify_enable') {
                $epson->setNotification(true, epson_notify_url());
                set_setting('epson_notifications_enabled', '1');
                set_setting('epson_notify_registered_at', now());
                flash('success', 'Epson will now push job updates to this site; status polling drops to a slow safety net.');
            }

            if ($action === 'notify_reset') {
                $epson->setNotification(true, epson_notify_url(true));
                set_setting('epson_notifications_enabled', '1');
                set_setting('epson_notify_registered_at', now());
                flash('success', 'New webhook secret generated and registered. The old URL no longer works.');
            }

            if ($action === 'notify_disable') {
                // Off for this site first: the queue must go back to normal
                // polling even when Epson cannot be reached to deregister.
                set_setting('epson_notifications_enabled', '');
                $epson->setNotification(false);
                flash('success', 'Job notifications turned off. The queue is back to polling Epson for job status.');
            }
        } catch (EpsonException $e) {
            flash('error', $action === 'notify_disable'
                ? 'Notifications are off for this site, but Epson could not be told: ' . $e->getMessage()
                : 'Epson did not accept the notification change: ' . $e->getMessage());
        }

        redirect('admin/settings.php');
    }

    if ($action === 'account') {
        $name  = input('name');
        $email = mb_strtolower(input('email'));
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');

        $errors = [];
        if (mb_strlen($name) < 2) {
            $errors[] = 'Enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } elseif ((int) db_val('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $admin['id']]) > 0) {
            $errors[] = 'That email is used by another account.';
        }

        if ($new !== '') {
            if (!password_verify($current, (string) $admin['password_hash'])) {
                $errors[] = 'Your current password is not correct.';
            } elseif (strlen($new) < 6) {
                $errors[] = 'Use at least 6 characters for the new password.';
            }
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
        } else {
            $data = ['name' => $name, 'email' => $email];
            if ($new !== '') {
                $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            }
            db_update('users', $data, 'id = ?', [$admin['id']]);
            current_user(true);
            flash('success', 'Your admin account has been updated.');
        }

        redirect('admin/settings.php');
    }
}

$epsonFresh = EpsonClient::fromSettings();
$deviceName = $epsonFresh->deviceName();
$redirect   = setting('epson_redirect_uri', '') ?: $defaultRedirect;

app_start('admin', 'settings', 'Settings', 'Service preferences, the Epson ET-2926 link and your admin account.');
?>

<div class="settings-layout">
    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Service</h3>
                    <p>Branding, defaults and whether the queue accepts new orders</p>
                </div>
            </div>
            <div class="panel-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="service">

                    <div class="field-row">
                        <div class="field">
                            <label for="site_name">Site name</label>
                            <input class="input" type="text" id="site_name" name="site_name" value="<?= e(site_name()) ?>" maxlength="60" required>
                        </div>
                        <div class="field">
                            <label for="support_email">Support email</label>
                            <input class="input" type="email" id="support_email" name="support_email" value="<?= e(setting('support_email', '')) ?>" maxlength="190" required>
                        </div>
                    </div>

                    <div class="field">
                        <label for="queue_alert_email">Queue alert email</label>
                        <input class="input" type="email" id="queue_alert_email" name="queue_alert_email"
                               value="<?= e(setting('queue_alert_email', '')) ?>" maxlength="190"
                               placeholder="<?= e(setting('support_email', '')) ?>">
                        <p class="help">Where stalled-worker and stuck-order alerts go. Leave blank to use the support email, then an admin account.</p>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="default_paper_size">Default paper</label>
                            <select class="select" id="default_paper_size" name="default_paper_size">
                                <?php foreach (paper_size_options() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= setting('default_paper_size', 'A4') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="default_print_quality">Default quality</label>
                            <select class="select" id="default_print_quality" name="default_print_quality">
                                <?php foreach (quality_options() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= setting('default_print_quality', 'normal') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label class="choice" style="max-width:520px">
                        <input type="checkbox" name="accepting_orders" value="1" <?= setting('accepting_orders', '1') === '1' ? 'checked' : '' ?>>
                        <span class="choice-dot"></span>
                        <span class="choice-text">
                            <strong>Accept new orders</strong>
                            <span>Untick to pause the queue. Customers keep their history and can still sign in.</span>
                        </span>
                    </label>

                    <button class="btn btn-primary mt-3" type="submit">Save service settings</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Heartbeat, backup relaying and customer email</h3>
                    <p>What watches the queue from outside the host, where backups go, and what customers are told</p>
                </div>
            </div>
            <div class="panel-body">
                <div class="settings-section" style="margin-top:0;padding-top:0;border-top:0">
                    <h3>Heartbeat</h3>
                    <p>Ping an outside monitor after every worker run. If the host or the cron entry dies, no ping arrives and the monitor alarms — the host's own watchdog cannot do that.</p>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="monitor">

                        <div class="field">
                            <label for="monitor_ping_url">Heartbeat ping URL</label>
                            <input class="input" type="url" id="monitor_ping_url" name="monitor_ping_url"
                                   value="<?= e(setting('monitor_ping_url', '')) ?>" maxlength="500"
                                   placeholder="https://ping.example.com/…">
                            <p class="help">Any dead-man's-switch service that alarms when a ping is missing. Failures append <span class="mono">/fail</span>.</p>
                        </div>

                        <label class="choice" style="max-width:640px">
                            <input type="checkbox" name="customer_emails_enabled" value="1" <?= setting('customer_emails_enabled', '1') === '1' ? 'checked' : '' ?>>
                            <span class="choice-dot"></span>
                            <span class="choice-text">
                                <strong>Email customers when their photos are ready</strong>
                                <span>One message per order, when every photo has printed.</span>
                            </span>
                        </label>

                        <div class="table-actions" style="justify-content:flex-start;margin-top:14px">
                            <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Save</button>
                        </div>
                    </form>

                    <form method="post" style="margin-top:10px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="monitor_test">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('bolt') ?> Send a test ping</button>
                    </form>

                    <?php $lastPing = monitor_last_ping(); ?>
                    <?php if ($lastPing !== []): ?>
                        <p class="help" style="margin-top:10px">
                            Last ping <?= e(time_ago((string) ($lastPing['at'] ?? ''))) ?>:
                            <?= !empty($lastPing['ok']) ? 'accepted (HTTP ' . (int) ($lastPing['code'] ?? 0) . ')' : 'failed' ?>
                            <?php if (!empty($lastPing['error'])): ?>· <?= e((string) $lastPing['error']) ?><?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="settings-section">
                    <h3>Relay (callback inbox and off-host vault)</h3>
                    <p>A Cloudflare Worker that receives Epson's callbacks (the host's bot protection blocks them before PHP) and stores backups. Deployed from <span class="mono">relay/</span> in the repository.</p>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="relay">

                        <div class="field">
                            <label for="relay_url">Relay URL</label>
                            <input class="input" type="url" id="relay_url" name="relay_url"
                                   value="<?= e(relay_base_url()) ?>" maxlength="500" placeholder="https://snapprints-relay.…workers.dev">
                        </div>

                        <p class="help">The relay's <span class="mono">RELAY_TOKEN</span> secret is the token in this site's webhook URL below — copy it from there into the relay's secrets, never into code.</p>

                        <div class="table-actions" style="justify-content:flex-start">
                            <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Save relay</button>
                        </div>
                    </form>

                    <div class="table-actions" style="justify-content:flex-start;flex-wrap:wrap;margin-top:10px">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="relay_test">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('wifi') ?> Test relay</button>
                        </form>
                        <form method="post" data-confirm="Point Epson's job notifications at the relay? The print worker collects them on every run.">

                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="relay_register">
                            <button class="btn btn-soft btn-sm" type="submit"><?= icon('bolt') ?> Point Epson at the relay</button>
                        </form>
                    </div>

                    <?php if (relay_configured()): ?>
                        <p class="help" style="margin-top:10px">Callback URL: <span class="mono"><?= e(relay_base_url()) ?>/hook?token=…</span></p>
                    <?php endif; ?>
                </div>

                <div class="settings-section">
                    <h3>Backups</h3>
                    <p>The database (schema and rows, photos excluded) leaves the host every night, and photos of orders that are still waiting to print are copied to the vault. Run from <span class="mono">cron/backup.php</span>.</p>

                    <div class="summary-list" style="margin:0 0 14px">
                        <div class="summary-row"><span>Last backup</span><strong><?= setting('backup_last_run', '') !== '' ? e(time_ago((string) setting('backup_last_run'))) : 'Never' ?></strong></div>
                        <div class="summary-row"><span>Result</span><strong><?= e(setting('backup_last_status', 'unknown') === 'ok' ? 'Stored' : ucfirst((string) setting('backup_last_status', 'unknown'))) ?></strong></div>
                        <div class="summary-row"><span>Destination</span><strong><?= e(setting('backup_last_where', 'none')) ?></strong></div>
                        <div class="summary-row"><span>Size</span><strong><?= e(human_size((int) setting('backup_last_bytes', '0'))) ?> · <?= (int) setting('backup_last_rows', '0') ?> rows</strong></div>
                        <div class="summary-row"><span>Photos vaulted</span><strong><?= (int) setting('backup_last_photos', '0') ?></strong></div>
                    </div>

                    <?php if (setting('backup_last_note', '') !== ''): ?>
                        <p class="help"><?= e((string) setting('backup_last_note', '')) ?></p>
                    <?php endif; ?>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="backup_run">
                        <button class="btn btn-soft btn-sm" type="submit"><?= icon('download') ?> Run a backup now</button>
                    </form>
                </div>
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
                    <h3>Print-job notifications</h3>
                    <p>
                        Epson can push job updates to this site instead of the queue asking every few seconds.
                        When it is on, the worker only checks as a slow safety net (<?= (int) PRINT_POLL_SAFETY_SECONDS ?> s),
                        which means far fewer connections to Epson and far less exposure to its timeouts.
                    </p>

                    <div class="field">
                        <label for="epson_notify_url">Webhook URL</label>
                        <div class="code-box">
                            <span><?= e(epson_notify_url()) ?></span>
                            <button class="copy-btn" type="button" data-copy="<?= e(epson_notify_url()) ?>">Copy</button>
                        </div>
                        <p class="help">Keep this URL private: the token is the only protection, so treat it like a password.</p>
                    </div>

                    <div class="summary-list" style="margin:0 0 14px">
                        <div class="summary-row"><span>Status</span><strong><?= epson_notifications_enabled() ? 'On' : 'Off' ?></strong></div>
                        <?php if (epson_notifications_enabled() && setting('epson_notify_registered_at', '') !== ''): ?>
                            <div class="summary-row"><span>Registered</span><strong><?= e(time_ago((string) setting('epson_notify_registered_at', ''))) ?></strong></div>
                        <?php endif; ?>
                        <?php if (setting('epson_notify_last_at', '') !== ''): ?>
                            <div class="summary-row"><span>Last notification</span><strong><?= e(time_ago((string) setting('epson_notify_last_at', ''))) ?></strong></div>
                        <?php endif; ?>
                    </div>

                    <?php $notifyLog = epson_notification_log(4); ?>
                    <?php if ($notifyLog !== []): ?>
                        <div class="table-wrap" style="margin-bottom:14px">
                            <table class="table">
                                <thead><tr><th>Time</th><th>Job status</th><th>Photo</th></tr></thead>
                                <tbody>
                                    <?php foreach ($notifyLog as $entry): ?>
                                        <?php $entryAt = strtotime((string) $entry['at']) ?: time(); ?>
                                        <tr>
                                            <td class="muted"><?= e(date('H:i:s', $entryAt)) ?></td>
                                            <td><?= e((string) $entry['status']) ?></td>
                                            <td><?= !empty($entry['matched']) ? '#' . (int) $entry['photo_id'] . ' → ' . e((string) $entry['result']) : 'no match' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php $notifyDebug = epson_notify_debug_entries(6); ?>
                    <details class="debug-block" style="margin-bottom:14px">
                        <summary style="cursor:pointer;font-weight:600;font-size:.9rem">Temporary diagnostics — requests that reached the webhook</summary>
                        <p class="help">
                            Added while the host investigates why Epson's callbacks never arrive. A row here means a request got
                            past the host's bot protection and into PHP; <span class="mono">token_missing</span> /
                            <span class="mono">token_mismatch</span> means it was turned away at the door, and any other outcome
                            carries the body Epson sent. Remove this block together with the diagnostic helpers when it is no longer needed.
                        </p>
                        <div class="table-actions" style="justify-content:flex-start;flex-wrap:wrap;margin-bottom:10px">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="notify_debug_clear">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?> Clear diagnostics</button>
                            </form>
                            <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="notify_sink_register">
                                <input class="input" type="url" name="sink_url" placeholder="https://webhook.site/…" style="min-width:260px" required>
                                <button class="btn btn-soft btn-sm" type="submit"><?= icon('bolt') ?> Point Epson at a test bin</button>
                            </form>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="notify_restore">
                                <button class="btn btn-soft btn-sm" type="submit"><?= icon('refresh') ?> Restore our webhook</button>
                            </form>
                        </div>

                        <?php if ($notifyDebug === []): ?>
                            <p class="muted" style="font-size:.82rem">No request has reached the endpoint yet.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead><tr><th>Time</th><th>Outcome</th><th>HTTP</th><th>Token</th><th>Request</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($notifyDebug as $row): ?>
                                            <?php $rowAt = strtotime((string) ($row['at'] ?? '')) ?: time(); ?>
                                            <tr>
                                                <td class="muted"><?= e(date('H:i:s', $rowAt)) ?></td>
                                                <td>
                                                    <?= e((string) ($row['outcome'] ?? '')) ?>
                                                    <?php if (!empty($row['note'])): ?>
                                                        <br><span class="muted" style="font-size:.75rem"><?= e((string) $row['note']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= (int) ($row['status'] ?? 0) ?></td>
                                                <td><?= e((string) ($row['token'] ?? '')) ?></td>
                                                <td style="max-width:380px">
                                                    <span class="muted" style="font-size:.75rem;word-break:break-word">
                                                        <?= e((string) ($row['method'] ?? '')) ?> · <?= e((string) ($row['content_type'] ?? '')) ?> · <?= (int) ($row['length'] ?? 0) ?> B<br>
                                                        UA: <?= e((string) ($row['user_agent'] ?? '')) ?><br>
                                                        body: <?= e((string) ($row['body'] ?? '')) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </details>

                    <div class="table-actions" style="justify-content:flex-start;flex-wrap:wrap">
                        <?php if ($epsonFresh->isConnected()): ?>
                            <?php if (epson_notifications_enabled()): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="notify_check">
                                    <button class="btn btn-soft btn-sm" type="submit"><?= icon('eye') ?> Check registration</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="notify_reset">
                                    <button class="btn btn-soft btn-sm" type="submit"><?= icon('refresh') ?> Re-register / new secret</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="notify_disable">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= icon('close') ?> Turn off notifications</button>
                                </form>
                            <?php else: ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="notify_enable">
                                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('bolt') ?> Turn on notifications</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted" style="font-size:.82rem">Link the printer to register the webhook with Epson.</span>
                        <?php endif; ?>
                    </div>
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
            <div class="panel-head"><h3>Your admin account</h3></div>
            <div class="panel-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="account">

                    <div class="field">
                        <label for="name">Name</label>
                        <input class="input" type="text" id="name" name="name" value="<?= e($admin['name']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" type="email" id="email" name="email" value="<?= e($admin['email']) ?>" required>
                    </div>

                    <div class="settings-section" style="margin-top:0;padding-top:18px">
                        <h3>Change password</h3>
                        <p>Leave both fields blank to keep your current password.</p>
                        <div class="field">
                            <label for="current_password">Current password</label>
                            <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password">
                        </div>
                        <div class="field">
                            <label for="new_password">New password</label>
                            <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password">
                        </div>
                    </div>

                    <button class="btn btn-primary btn-block" type="submit">Save account</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h3>Printer facts</h3></div>
            <div class="panel-body">
                <div class="profile-facts">
                    <div class="profile-fact"><span>Model</span><strong><?= e(EPSON_PRINTER_MODEL) ?></strong></div>
                    <div class="profile-fact"><span>API</span><strong>Connect v2</strong></div>
                    <div class="profile-fact"><span>Device</span><strong><?= e($epsonFresh->isConnected() ? $deviceName : 'Not linked') ?></strong></div>
                    <div class="profile-fact"><span>Queue</span><strong><?= setting('accepting_orders', '1') === '1' ? 'Open' : 'Paused' ?></strong></div>
                </div>
                <a class="btn btn-ghost btn-block btn-sm mt-3" href="https://developer.epsonconnect.com/" target="_blank" rel="noopener noreferrer">
                    <?= icon('arrow-right') ?> Epson developer portal
                </a>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h3>Storage</h3></div>
            <div class="panel-body">
                <div class="summary-list">
                    <div class="summary-row"><span>Driver</span><strong><?= e(strtoupper(db_driver())) ?></strong></div>
                    <div class="summary-row"><span>Photos per order</span><strong><?= (int) MAX_PHOTOS_PER_ORDER ?></strong></div>
                    <div class="summary-row"><span>Photos per batch</span><strong><?= (int) MAX_PHOTOS_PER_BATCH ?></strong></div>
                    <div class="summary-row"><span>Upload limit</span><strong><?= e(MAX_UPLOAD_MB) ?> MB each</strong></div>
                    <div class="summary-row"><span>Order storage cap</span><strong><?= order_storage_limit_bytes() !== null ? e(human_size(order_storage_limit_bytes())) : 'No cap' ?></strong></div>
                    <div class="summary-row"><span>Database size</span><strong><?= e(human_size(database_size_bytes())) ?></strong></div>
                    <div class="summary-row"><span>Price per order</span><strong>Free</strong></div>
                </div>
                <p class="help">Photos are stored as BLOBs inside the database, not as files on disk. Large orders are added in batches and capped so one order cannot grow without bound.</p>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>
