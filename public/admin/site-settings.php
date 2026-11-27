<?php
/**
 * Site Settings: the name the site shows and the addresses it mails.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_admin();

if (is_post()) {
    verify_csrf();

    if (input('action') === 'site') {
        $siteName   = mb_substr(input('site_name'), 0, 60);
        $email      = input('support_email');
        $alertEmail = input('queue_alert_email');

        set_setting('site_name', $siteName !== '' ? $siteName : APP_NAME);
        set_setting('support_email', filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : (string) setting('support_email', ''));
        set_setting('queue_alert_email', filter_var($alertEmail, FILTER_VALIDATE_EMAIL) ? $alertEmail : '');

        flash('success', 'Site settings saved.');
        redirect('admin/site-settings.php');
    }

    if (input('action') === 'print') {
        $paper   = input('default_paper_size');
        $quality = input('default_print_quality');

        set_setting('default_paper_size', array_key_exists($paper, paper_size_options()) ? $paper : 'A4');
        set_setting('default_print_quality', array_key_exists($quality, quality_options()) ? $quality : 'normal');
        set_setting('accepting_orders', isset($_POST['accepting_orders']) ? '1' : '0');

        flash('success', 'Print settings saved.');
        redirect('admin/site-settings.php');
    }

    if (input('action') === 'details_sheet') {
        $enabled = isset($_POST['order_details_sheet_enabled']);

        set_setting('order_details_sheet_enabled', $enabled ? '1' : '0');

        if (!$enabled) {
            // A sheet that is only queued should not print after the option is
            // switched off; one already on the printer finishes normally.
            order_details_sheet_discard_pending();
        }

        flash('success', 'Order details sheet ' . ($enabled ? 'enabled' : 'disabled') . '.');
        redirect('admin/site-settings.php');
    }
}

app_start('admin', 'site-settings', 'Site Settings', 'The name this site shows, the addresses it mails and the print defaults for new orders.');
?>

<div class="settings-layout">
    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Site identity</h3>
                    <p>Used across the site, emails and the browser tab</p>
                </div>
            </div>
            <div class="panel-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="site">

                    <div class="field-row">
                        <div class="field">
                            <label for="site_name">Site name</label>
                            <input class="input" type="text" id="site_name" name="site_name" value="<?= e(site_name()) ?>" maxlength="60" required>
                        </div>
                        <div class="field">
                            <label for="support_email">Support email</label>
                            <input class="input" type="email" id="support_email" name="support_email" value="<?= e(setting('support_email', '')) ?>" maxlength="190" required>
                            <p class="help">Shown in the footer and used as the reply-to on the queue's own emails.</p>
                        </div>
                    </div>

                    <div class="field">
                        <label for="queue_alert_email">Queue alert email</label>
                        <input class="input" type="email" id="queue_alert_email" name="queue_alert_email"
                               value="<?= e(setting('queue_alert_email', '')) ?>" maxlength="190"
                               placeholder="<?= e(setting('support_email', '')) ?>">
                        <p class="help">Where stalled-worker and stuck-order alerts go. Leave blank to use the support email, then an admin account.</p>
                    </div>

                    <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save site settings</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Print Settings</h3>
                    <p>Defaults for new orders and whether the queue is open</p>
                </div>
            </div>
            <div class="panel-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="print">

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

                    <label class="choice" style="max-width:560px">
                        <input type="checkbox" name="accepting_orders" value="1" <?= setting('accepting_orders', '1') === '1' ? 'checked' : '' ?>>
                        <span class="choice-dot"></span>
                        <span class="choice-text">
                            <strong>Accept new orders</strong>
                            <span>Untick to pause the queue. Customers keep their history and can still sign in.</span>
                        </span>
                    </label>

                    <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save print settings</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Order details sheet</h3>
                    <p>What prints after the last photo of each order</p>
                </div>
            </div>
            <div class="panel-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="details_sheet">

                    <label class="choice" style="max-width:600px">
                        <input type="checkbox" name="order_details_sheet_enabled" value="1" <?= order_details_sheet_enabled() ? 'checked' : '' ?>>
                        <span class="choice-dot"></span>
                        <span class="choice-text">
                            <strong>Print a details sheet after each order</strong>
                            <span>
                                A plain sheet with the order reference, the customer's name, email and phone, the photo count
                                and the print settings comes out after the order's last photo, so finished photos can be
                                matched to an order without checking the site. Sheets already queued are dropped when this is
                                turned off; a sheet already printing finishes normally.
                            </span>
                        </span>
                    </label>

                    <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save details sheet setting</button>
                </form>
            </div>
        </section>
    </div>

    <div class="stack">
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

        <section class="panel">
            <div class="panel-head"><h3>Where these appear</h3></div>
            <div class="panel-body">
                <ul class="check-list" style="gap:12px">
                    <li><?= icon('home') ?><span><strong>Site name</strong> — page titles, header, footer and emails.</span></li>
                    <li><?= icon('mail') ?><span><strong>Support email</strong> — footer contact link and the reply-to address.</span></li>
                    <li><?= icon('alert') ?><span><strong>Queue alert email</strong> — the watchdog and the print worker's failure alerts.</span></li>
                    <li><?= icon('printer') ?><span><strong>Order details sheet</strong> — printed after the photos of every new order, as its own 4 × 6 sheet.</span></li>
                </ul>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>
