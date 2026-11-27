<?php
/**
 * Site Settings: identity and contact addresses, print defaults, the order
 * details sheet and the landing page copy.
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
        $posted  = array_map('strval', (array) ($_POST['paper_sizes'] ?? []));
        $enabled = array_values(array_intersect(array_keys(paper_size_options()), $posted));

        if ($enabled === []) {
            flash('error', 'Keep at least one paper size enabled, otherwise customers cannot order a print.');
            redirect('admin/site-settings.php');
        }

        set_setting('paper_sizes_enabled', implode(',', $enabled));
        set_setting('default_paper_size', in_array($paper, $enabled, true) ? $paper : $enabled[0]);
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

    $contentSections = [
        'content_home'    => 'home',
        'content_how'     => 'how',
        'content_printer' => 'printer',
        'content_types'   => 'types',
        'content_promise' => 'promise',
        'content_faq'     => 'faq',
        'content_cta'     => 'cta',
    ];

    if (array_key_exists(input('action'), $contentSections)) {
        $section = $contentSections[input('action')];

        landing_store($section, landing_from_post($section, $_POST));

        flash('success', 'Landing page content saved.');
        redirect('admin/site-settings.php');
    }
}

$landing        = landing_content();
$homeContent    = $landing['home'];
$howContent     = $landing['how'];
$printerContent = $landing['printer'];
$typesContent   = $landing['types'];
$promiseContent = $landing['promise'];
$faqContent     = $landing['faq'];
$ctaContent     = $landing['cta'];
$faqRows        = array_values($faqContent['items']);
$faqRows[]      = ['q' => '', 'a' => ''];
$faqRows[]      = ['q' => '', 'a' => ''];

app_start('admin', 'site-settings', 'Site Settings', 'The name this site shows, the addresses it mails, the print defaults and the landing page copy.');
?>

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
                            <?php $defaultPaper = setting('default_paper_size', '4x6'); ?>
                            <?php foreach (enabled_paper_sizes() as $value): ?>
                                <option value="<?= e($value) ?>" <?= $defaultPaper === $value ? 'selected' : '' ?>><?= e(paper_size_options()[$value]) ?></option>
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

                <div class="field">
                    <span class="field-label">Allowed paper sizes</span>
                    <div style="display:flex;flex-wrap:wrap;gap:10px 16px">
                        <?php foreach (paper_size_options() as $value => $label): ?>
                            <label class="choice" style="width:auto;max-width:none;padding:10px 14px">
                                <input type="checkbox" name="paper_sizes[]" value="<?= e($value) ?>" <?= in_array($value, enabled_paper_sizes(), true) ? 'checked' : '' ?>>
                                <span class="choice-dot"></span>
                                <span class="choice-text"><strong><?= e(paper_size_label($value)) ?></strong></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="help">Customers can only pick the ticked sizes, and the default paper must be one of them. Keep at least one ticked.</p>
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

<div class="stack" style="margin-top:24px">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Homepage content</h3>
                <p>The hero at the top of the landing page</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="content_home">

                <div class="field-row">
                    <div class="field">
                        <label for="home_title">Heading</label>
                        <input class="input" type="text" id="home_title" name="title" value="<?= e($homeContent['title']) ?>" maxlength="120" required>
                    </div>
                    <div class="field">
                        <label for="home_accent">Highlighted line</label>
                        <input class="input" type="text" id="home_accent" name="accent" value="<?= e($homeContent['accent']) ?>" maxlength="120" required>
                    </div>
                </div>

                <div class="field">
                    <label for="home_lead">Intro paragraph</label>
                    <textarea class="textarea" id="home_lead" name="lead" rows="4" maxlength="700"><?= e($homeContent['lead']) ?></textarea>
                </div>

                <div class="field-row">
                    <?php foreach ($homeContent['trust'] as $index => $trustItem): ?>
                        <div class="field">
                            <label for="home_trust_<?= (int) $index ?>">Trust point <?= (int) $index + 1 ?></label>
                            <input class="input" type="text" id="home_trust_<?= (int) $index ?>" name="trust[<?= (int) $index ?>]" value="<?= e($trustItem) ?>" maxlength="80">
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save homepage content</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>How it works content</h3>
                <p>The three steps below the hero</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="content_how">

                <div class="field-row">
                    <div class="field">
                        <label for="how_eyebrow">Eyebrow</label>
                        <input class="input" type="text" id="how_eyebrow" name="eyebrow" value="<?= e($howContent['eyebrow']) ?>" maxlength="80">
                    </div>
                    <div class="field">
                        <label for="how_heading">Heading</label>
                        <input class="input" type="text" id="how_heading" name="heading" value="<?= e($howContent['heading']) ?>" maxlength="120" required>
                    </div>
                </div>

                <div class="field">
                    <label for="how_intro">Intro paragraph</label>
                    <textarea class="textarea" id="how_intro" name="intro" rows="2" maxlength="500"><?= e($howContent['intro']) ?></textarea>
                </div>

                <?php foreach ($howContent['steps'] as $index => $step): ?>
                    <div class="field" style="border-top:1px solid #e6ecf5;padding-top:16px;margin-top:16px">
                        <label for="how_step_title_<?= (int) $index ?>">Step <?= (int) $index + 1 ?> title</label>
                        <input class="input" type="text" id="how_step_title_<?= (int) $index ?>" name="steps[<?= (int) $index ?>][title]" value="<?= e($step['title']) ?>" maxlength="120" required>
                    </div>
                    <div class="field">
                        <label for="how_step_text_<?= (int) $index ?>">Step <?= (int) $index + 1 ?> text</label>
                        <textarea class="textarea" id="how_step_text_<?= (int) $index ?>" name="steps[<?= (int) $index ?>][text]" rows="3" maxlength="600"><?= e($step['text']) ?></textarea>
                    </div>
                <?php endforeach; ?>

                <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save how it works content</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>The printer content</h3>
                <p>The hardware section and its checklist</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="content_printer">

                <div class="field-row">
                    <div class="field">
                        <label for="printer_eyebrow">Eyebrow</label>
                        <input class="input" type="text" id="printer_eyebrow" name="eyebrow" value="<?= e($printerContent['eyebrow']) ?>" maxlength="80">
                    </div>
                    <div class="field">
                        <label for="printer_heading">Heading</label>
                        <input class="input" type="text" id="printer_heading" name="heading" value="<?= e($printerContent['heading']) ?>" maxlength="120" required>
                    </div>
                </div>

                <div class="field">
                    <label for="printer_body">Paragraph</label>
                    <textarea class="textarea" id="printer_body" name="body" rows="4" maxlength="800"><?= e($printerContent['body']) ?></textarea>
                </div>

                <div class="field-row">
                    <?php foreach ($printerContent['points'] as $index => $point): ?>
                        <div class="field">
                            <label for="printer_point_<?= (int) $index ?>">Checklist point <?= (int) $index + 1 ?></label>
                            <input class="input" type="text" id="printer_point_<?= (int) $index ?>" name="points[<?= (int) $index ?>]" value="<?= e($point) ?>" maxlength="160">
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save printer content</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Photo types content</h3>
                <p>The row of photo ideas below the printer section</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="content_types">

                <div class="field-row">
                    <div class="field">
                        <label for="types_eyebrow">Eyebrow</label>
                        <input class="input" type="text" id="types_eyebrow" name="eyebrow" value="<?= e($typesContent['eyebrow']) ?>" maxlength="80">
                    </div>
                    <div class="field">
                        <label for="types_heading">Heading</label>
                        <input class="input" type="text" id="types_heading" name="heading" value="<?= e($typesContent['heading']) ?>" maxlength="120" required>
                    </div>
                </div>

                <div class="field">
                    <label for="types_intro">Intro</label>
                    <textarea class="textarea" id="types_intro" name="intro" rows="2" maxlength="400"><?= e($typesContent['intro']) ?></textarea>
                </div>

                <div class="field-row">
                    <?php foreach ($typesContent['items'] as $index => $item): ?>
                        <div class="field">
                            <label for="types_item_<?= (int) $index ?>">Photo type <?= (int) $index + 1 ?></label>
                            <input class="input" type="text" id="types_item_<?= (int) $index ?>" name="items[<?= (int) $index ?>]" value="<?= e($item) ?>" maxlength="80">
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save photo types content</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Promise content</h3>
                <p>The promise band below the photo types</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="content_promise">

                <div class="field-row">
                    <div class="field">
                        <label for="promise_eyebrow">Eyebrow</label>
                        <input class="input" type="text" id="promise_eyebrow" name="eyebrow" value="<?= e($promiseContent['eyebrow']) ?>" maxlength="80">
                    </div>
                    <div class="field">
                        <label for="promise_heading">Heading</label>
                        <input class="input" type="text" id="promise_heading" name="heading" value="<?= e($promiseContent['heading']) ?>" maxlength="120" required>
                    </div>
                </div>

                <div class="field">
                    <label for="promise_body">Paragraph</label>
                    <textarea class="textarea" id="promise_body" name="body" rows="3" maxlength="500"><?= e($promiseContent['body']) ?></textarea>
                </div>

                <?php foreach ($promiseContent['items'] as $index => $item): ?>
                    <div class="field" style="border-top:1px solid #e6ecf5;padding-top:16px;margin-top:16px">
                        <label for="promise_title_<?= (int) $index ?>">Promise <?= (int) $index + 1 ?> title</label>
                        <input class="input" type="text" id="promise_title_<?= (int) $index ?>" name="items[<?= (int) $index ?>][title]" value="<?= e($item['title']) ?>" maxlength="120">
                    </div>
                    <div class="field">
                        <label for="promise_text_<?= (int) $index ?>">Promise <?= (int) $index + 1 ?> text</label>
                        <textarea class="textarea" id="promise_text_<?= (int) $index ?>" name="items[<?= (int) $index ?>][text]" rows="2" maxlength="300"><?= e($item['text']) ?></textarea>
                    </div>
                <?php endforeach; ?>

                <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save promise content</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>FAQ content</h3>
                <p>The questions above the closing call to action</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="content_faq">

                <div class="field-row">
                    <div class="field">
                        <label for="faq_eyebrow">Eyebrow</label>
                        <input class="input" type="text" id="faq_eyebrow" name="eyebrow" value="<?= e($faqContent['eyebrow']) ?>" maxlength="80">
                    </div>
                    <div class="field">
                        <label for="faq_heading">Heading</label>
                        <input class="input" type="text" id="faq_heading" name="heading" value="<?= e($faqContent['heading']) ?>" maxlength="120" required>
                    </div>
                </div>

                <p class="help">Leave a question blank to remove that item. New items appear in the order shown here.</p>

                <?php $faqCount = 0; ?>
                <?php foreach ($faqRows as $index => $item): ?>
                    <?php $faqCount++; ?>
                    <div class="field" style="border-top:1px solid #e6ecf5;padding-top:16px;margin-top:16px">
                        <label for="faq_q_<?= (int) $index ?>">Question <?= $faqCount ?></label>
                        <input class="input" type="text" id="faq_q_<?= (int) $index ?>" name="faq[<?= (int) $index ?>][q]" value="<?= e($item['q']) ?>" maxlength="200">
                    </div>
                    <div class="field">
                        <label for="faq_a_<?= (int) $index ?>">Answer <?= $faqCount ?></label>
                        <textarea class="textarea" id="faq_a_<?= (int) $index ?>" name="faq[<?= (int) $index ?>][a]" rows="2" maxlength="1500"><?= e($item['a']) ?></textarea>
                    </div>
                <?php endforeach; ?>

                <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save FAQ content</button>
            </form>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Closing content</h3>
                <p>The call to action at the very bottom of the landing page</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="content_cta">

                <div class="field-row">
                    <div class="field">
                        <label for="cta_eyebrow">Eyebrow</label>
                        <input class="input" type="text" id="cta_eyebrow" name="eyebrow" value="<?= e($ctaContent['eyebrow']) ?>" maxlength="80">
                    </div>
                    <div class="field">
                        <label for="cta_heading">Heading</label>
                        <input class="input" type="text" id="cta_heading" name="heading" value="<?= e($ctaContent['heading']) ?>" maxlength="120" required>
                    </div>
                </div>

                <div class="field">
                    <label for="cta_body">Paragraph</label>
                    <textarea class="textarea" id="cta_body" name="body" rows="2" maxlength="400"><?= e($ctaContent['body']) ?></textarea>
                </div>

                <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save closing content</button>
            </form>
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

    <section class="panel">
        <div class="panel-head"><h3>Where these appear</h3></div>
        <div class="panel-body">
            <ul class="check-list" style="gap:12px">
                <li><?= icon('home') ?><span><strong>Site name</strong> — page titles, header, footer and emails.</span></li>
                <li><?= icon('mail') ?><span><strong>Support email</strong> — footer contact link and the reply-to address.</span></li>
                <li><?= icon('alert') ?><span><strong>Queue alert email</strong> — the watchdog and the print worker's failure alerts.</span></li>
                <li><?= icon('printer') ?><span><strong>Order details sheet</strong> — printed after the photos of every new order, as its own 4 × 6 sheet.</span></li>
                <li><?= icon('layers') ?><span><strong>Landing page content</strong> — the hero, how it works, printer, photo types, promise, FAQ and closing sections.</span></li>
            </ul>
        </div>
    </section>
</div>

<?php app_end(); ?>
