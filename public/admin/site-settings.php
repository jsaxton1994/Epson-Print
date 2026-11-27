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
}

app_start('admin', 'site-settings', 'Site Settings', 'The name this site shows and the addresses customers and alerts use.');
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
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h3>Where these appear</h3></div>
            <div class="panel-body">
                <ul class="check-list" style="gap:12px">
                    <li><?= icon('home') ?><span><strong>Site name</strong> — page titles, header, footer and emails.</span></li>
                    <li><?= icon('mail') ?><span><strong>Support email</strong> — footer contact link and the reply-to address.</span></li>
                    <li><?= icon('alert') ?><span><strong>Queue alert email</strong> — the watchdog and the print worker's failure alerts.</span></li>
                </ul>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>
