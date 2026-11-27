<?php
/**
 * Service Settings: the print defaults customers get and whether the queue is
 * accepting new orders.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_admin();

if (is_post()) {
    verify_csrf();

    if (input('action') === 'service') {
        $paper   = input('default_paper_size');
        $quality = input('default_print_quality');

        set_setting('default_paper_size', array_key_exists($paper, paper_size_options()) ? $paper : 'A4');
        set_setting('default_print_quality', array_key_exists($quality, quality_options()) ? $quality : 'normal');
        set_setting('accepting_orders', isset($_POST['accepting_orders']) ? '1' : '0');

        flash('success', 'Print service settings saved.');
        redirect('admin/service-settings.php');
    }
}

app_start('admin', 'service-settings', 'Service Settings', 'The print defaults customers get and whether the queue accepts new orders.');
?>

<div class="stack">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Print Services</h3>
                <p>Defaults for new orders and whether the queue is open</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="service">

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

                <button class="btn btn-primary mt-3" type="submit"><?= icon('check') ?> Save print services</button>
            </form>
        </div>
    </section>
</div>

<?php app_end(); ?>
