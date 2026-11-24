<?php
/**
 * Submit a new photo print order (first batch of up to MAX_PHOTOS_PER_BATCH photos).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/index.php');
}

$accepting = setting('accepting_orders', '1') === '1';
$papers    = paper_size_options();
$qualities = quality_options();

$limits     = upload_limits();
$batchLimit = min(MAX_PHOTOS_PER_BATCH, $limits['max_files']);
$fileLimit  = round($limits['file_bytes'] / 1048576, 1);

$paper      = setting('default_paper_size', '4x6');
$quality    = setting('default_print_quality', 'high');
$color      = 'color';
$borderless = 1;
$notes      = '';

if (!array_key_exists($paper, $papers)) {
    $paper = '4x6';
}
if (!array_key_exists($quality, $qualities)) {
    $quality = 'high';
}

$back = 'customer/new-order.php';

if (is_post()) {
    if (post_request_too_large()) {
        $serverLimit = ini_bytes((string) ini_get('post_max_size'));
        abort_upload(
            'That upload is larger than this server accepts in one request (' . human_size($serverLimit)
            . '). Add fewer or smaller photos, or ask the host to raise post_max_size.',
            $back
        );
    }

    verify_csrf();

    $paper      = in_array(input('paper_size'), array_keys($papers), true) ? input('paper_size') : '4x6';
    $quality    = in_array(input('print_quality'), array_keys($qualities), true) ? input('print_quality') : 'high';
    $color      = input('color_mode') === 'mono' ? 'mono' : 'color';
    $borderless = input('borderless') === '0' ? 0 : 1;
    $notes      = mb_substr(input('notes'), 0, 500);

    if (!$accepting) {
        abort_upload('The print queue is paused right now. Please try again shortly.', $back);
    }

    $orderId = 0;

    try {
        $files = validate_photo_uploads(
            normalize_uploaded_files($_FILES['photos'] ?? []),
            $batchLimit,
            MAX_ORDER_STORAGE_MB > 0 ? MAX_ORDER_STORAGE_MB * 1024 * 1024 : null
        );

        $orderId = db_insert('orders', [
            'reference'     => generate_reference(),
            'user_id'       => $user['id'],
            'color_mode'    => $color,
            'borderless'    => $borderless,
            'paper_size'    => $paper,
            'print_quality' => $quality,
            'notes'         => $notes !== '' ? $notes : null,
            'status'        => 'pending',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // One photo at a time, so the order never sits in memory whole.
        foreach ($files as $index => $file) {
            insert_photo_upload($orderId, $file, $index + 1);
        }

        $count = count($files);
        log_order_event(
            $orderId,
            'pending',
            $count . ' photo' . ($count === 1 ? '' : 's') . ' submitted by ' . $user['name'] . '.',
            $user['name']
        );

        // The browser sends the rest of a large selection as add-photo batches.
        if (is_ajax_upload()) {
            upload_json([
                'ok'       => true,
                'order_id' => $orderId,
                'added'    => $count,
                'total'    => $count,
            ]);
        }

        flash('success', ($count === 1
            ? 'Your photo is in the queue.'
            : $count . ' photos are in the queue.')
            . ' You can add more photos to this order in batches of ' . $batchLimit . ' — up to ' . MAX_PHOTOS_PER_ORDER . ' in total.');
        redirect('customer/order.php?id=' . $orderId);
    } catch (RuntimeException $e) {
        if ($orderId > 0) {
            db_query('DELETE FROM orders WHERE id = ?', [$orderId]);
        }

        abort_upload($e->getMessage(), $back);
    }
}

app_start(
    'customer',
    'new',
    'New photo order',
    'Select up to ' . MAX_PHOTOS_PER_ORDER . ' photos — they upload ' . $batchLimit . ' at a time.'
);
?>

<?php if (!$accepting): ?>
    <div class="banner banner-warn">
        <?= icon('clock') ?>
        <div>
            <strong>The queue is paused</strong>
            <p>An administrator has paused new orders. You can still browse your history — check back shortly.</p>
        </div>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="order-form-layout" data-order-form data-submit-once
      data-batch-upload data-batch-size="<?= (int) $batchLimit ?>"
      data-add-url="<?= e(url('customer/add-photos.php')) ?>"
      data-order-url="<?= e(url('customer/order.php')) ?>">
    <?= csrf_field() ?>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Photos &amp; print settings</h3>
                <p>Everything here is free — no pricing, no credits.</p>
            </div>
        </div>

        <div class="panel-body">
            <div class="form-section">
                <div class="form-section-head">
                    <h3>1. Your photos</h3>
                    <span>JPG or PNG · up to <?= (int) MAX_PHOTOS_PER_ORDER ?></span>
                </div>

                <label class="dropzone dropzone-photo" data-dropzone data-multi
                       data-max-bytes="<?= (int) $limits['file_bytes'] ?>"
                       data-max-files="<?= (int) MAX_PHOTOS_PER_ORDER ?>"
                       data-batch-size="<?= (int) $batchLimit ?>"
                       data-max-total-bytes="<?= (int) $limits['post_bytes'] ?>">
                    <input type="file" name="photos[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" multiple required>
                    <span class="dropzone-icon"><?= icon('image') ?></span>
                    <span class="dropzone-title">Drop your photos here</span>
                    <span class="dropzone-hint" data-file-hint>
                        or click to browse — JPG or PNG, up to <?= e($fileLimit) ?> MB each. Select up to <?= (int) MAX_PHOTOS_PER_ORDER ?>; they upload <?= (int) $batchLimit ?> at a time.
                    </span>
                    <span class="dropzone-grid" data-photo-grid></span>
                    <span class="dropzone-list" data-photo-list></span>
                    <span class="dropzone-file" data-file-name></span>
                </label>

                <p class="help">
                    Select all the photos you need — up to <?= (int) MAX_PHOTOS_PER_ORDER ?>. A large selection is uploaded
                    automatically in batches of <?= (int) $batchLimit ?>, so you can leave this page open while it finishes.
                </p>
            </div>

            <div class="form-section">
                <div class="form-section-head">
                    <h3>2. Print size</h3>
                    <span>4 × 6 is the classic snap</span>
                </div>

                <div class="field mb-0">
                    <label for="paper_size">Print size</label>
                    <select class="select" id="paper_size" name="paper_size">
                        <?php foreach ($papers as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $paper === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="help">Every photo in the order prints at this size.</p>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-head">
                    <h3>3. Finish &amp; colour</h3>
                </div>

                <div class="field">
                    <span class="field-label">Border</span>
                    <div class="choice-group">
                        <label class="choice">
                            <input type="radio" name="borderless" value="1" data-label="Borderless" <?= $borderless === 1 ? 'checked' : '' ?>>
                            <span class="choice-dot"></span>
                            <span class="choice-text">
                                <strong>Borderless</strong>
                                <span>Full bleed photo print</span>
                            </span>
                        </label>
                        <label class="choice">
                            <input type="radio" name="borderless" value="0" data-label="White border" <?= $borderless === 0 ? 'checked' : '' ?>>
                            <span class="choice-dot"></span>
                            <span class="choice-text">
                                <strong>White border</strong>
                                <span>A little frame around the photo</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="field mb-0">
                    <span class="field-label">Colour</span>
                    <div class="choice-group">
                        <?php foreach (color_options() as $value => $label): ?>
                            <label class="choice">
                                <input type="radio" name="color_mode" value="<?= e($value) ?>"
                                       data-label="<?= e($label) ?>" <?= $color === $value ? 'checked' : '' ?>>
                                <span class="choice-dot"></span>
                                <span class="choice-text">
                                    <strong><?= e($label) ?></strong>
                                    <span><?= $value === 'mono' ? 'Black and white print' : 'Full colour print' ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-head">
                    <h3>4. Quality</h3>
                </div>

                <div class="choice-group">
                    <?php foreach ($qualities as $value => $label): ?>
                        <label class="choice">
                            <input type="radio" name="print_quality" value="<?= e($value) ?>"
                                   data-label="<?= e(explode(' — ', $label)[0]) ?>" <?= $quality === $value ? 'checked' : '' ?>>
                            <span class="choice-dot"></span>
                            <span class="choice-text">
                                <strong><?= e(explode(' — ', $label)[0]) ?></strong>
                                <span><?= e(explode(' — ', $label)[1] ?? '') ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-head">
                    <h3>5. Notes</h3>
                    <span>Optional</span>
                </div>
                <div class="field mb-0">
                    <label for="notes" class="sr-only">Notes for the administrator</label>
                    <textarea class="textarea" id="notes" name="notes" maxlength="500"
                              placeholder="Anything we should know? e.g. crop the second photo to the dog's face, or this is for a birthday tomorrow."><?= e($notes) ?></textarea>
                    <p class="help">Up to 500 characters.</p>
                </div>
            </div>
        </div>
    </section>

    <aside class="stack">
        <section class="panel summary-panel">
            <div class="panel-head"><h3>Print summary</h3></div>
            <div class="panel-body">
                <div class="summary-file">
                    <span class="doc-icon"><?= icon('image') ?></span>
                    <span class="doc-text">
                        <span class="doc-name" data-file-name>No photos chosen</span>
                        <span class="doc-meta">JPG or PNG · uploaded <?= (int) $batchLimit ?> at a time</span>
                    </span>
                </div>

                <div class="summary-list">
                    <div class="summary-row"><span>Photos</span><strong data-summary="photos">0 of <?= (int) MAX_PHOTOS_PER_ORDER ?></strong></div>
                    <div class="summary-row"><span>Print size</span><strong data-summary="paper"><?= e(paper_size_label($paper)) ?></strong></div>
                    <div class="summary-row"><span>Border</span><strong data-summary="borderless"><?= $borderless === 1 ? 'Borderless' : 'White border' ?></strong></div>
                    <div class="summary-row"><span>Colour</span><strong data-summary="color"><?= e($color === 'mono' ? 'Black & white' : 'Colour') ?></strong></div>
                    <div class="summary-row"><span>Quality</span><strong data-summary="quality"><?= e(ucfirst($quality)) ?></strong></div>
                </div>

                <div class="free-callout"><?= icon('sparkles') ?> Total cost: £0.00 — always free</div>

                <p class="help" data-upload-progress hidden aria-live="polite"></p>

                <button class="btn btn-primary btn-lg btn-block mt-3" type="submit" <?= $accepting ? '' : 'disabled' ?>>
                    <?= icon('printer') ?> Send photos to the printer
                </button>
                <p class="summary-note">Each photo prints once. You can follow the print from your dashboard and cancel it any time while it is still in the queue.</p>
            </div>
        </section>

        <section class="panel">
            <div class="panel-body pad-sm">
                <ul class="check-list" style="gap:11px">
                    <li><?= icon('shield') ?><span>Only you and the administrators can open your photos.</span></li>
                    <li><?= icon('layers') ?><span>Prints are made in the order they arrive, one copy of each photo.</span></li>
                    <li><?= icon('sparkles') ?><span>Printed on Epson photo paper on the ET-2926.</span></li>
                </ul>
            </div>
        </section>
    </aside>
</form>

<?php app_end(); ?>
