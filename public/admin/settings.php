<?php
/**
 * Service settings, Epson Connect credentials and admin account.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();
$epson = EpsonClient::fromSettings();

$defaultRedirect = absolute_url('admin/epson-callback.php');

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'service') {
        $siteName = mb_substr(input('site_name'), 0, 60);
        $email    = input('support_email');
        $paper    = input('default_paper_size');
        $quality  = input('default_print_quality');

        set_setting('site_name', $siteName !== '' ? $siteName : APP_NAME);
        set_setting('support_email', filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : (string) setting('support_email', ''));
        set_setting('accepting_orders', isset($_POST['accepting_orders']) ? '1' : '0');
        set_setting('default_paper_size', array_key_exists($paper, paper_size_options()) ? $paper : 'A4');
        set_setting('default_print_quality', array_key_exists($quality, quality_options()) ? $quality : 'normal');

        flash('success', 'Service settings saved.');
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
        flash('success', 'Printer unlinked. Credentials were kept so you can reconnect quickly.');
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
