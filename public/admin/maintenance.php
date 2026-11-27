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

    if ($action === 'supply_clear') {
        printer_supply_clear();
        flash('success', 'Paper/ink hold cleared. The queue sends the next photo on its next run.');
        redirect('admin/maintenance.php');
    }

}

$pause      = printing_pause_info();
$supplyHold = printer_supply_block();

$pendingPhotos = (int) db_val("SELECT COUNT(*) FROM order_photos WHERE status = 'pending'");

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

<?php if ($supplyHold['active']): ?>
    <div class="banner banner-danger">
        <?= icon('alert') ?>
        <div>
            <strong>Printing is held: <?= e($supplyHold['label']) ?></strong>
            <p>
                The printer reported <span class="mono"><?= e($supplyHold['reason']) ?></span>
                <?= $supplyHold['since'] !== '' ? e(time_ago($supplyHold['since'])) : 'just now' ?>.
                No new photos are being sent, so the queue cannot fail against it.
                Load photo paper or replace the ink, then clear the hold.
            </p>
            <div class="banner-actions">
                <form method="post" action="<?= e(url('admin/maintenance.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="supply_clear">
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Clear and continue</button>
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
