<?php
/**
 * The signed-in administrator's own account: name, email and password.
 *
 * Reached from the avatar in the top bar, so it is one click from anywhere in
 * the admin area rather than buried in Settings.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();

if (is_post()) {
    verify_csrf();

    if (input('action') === 'account') {
        $name    = input('name');
        $email   = mb_strtolower(input('email'));
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

        redirect('admin/account.php');
    }
}

app_start('admin', 'account', 'Your account', 'Your name, email and password.');
?>

<div class="settings-layout">
    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Your admin account</h3>
                    <p>How you sign in to the admin area</p>
                </div>
            </div>
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

                    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save account</button>
                </form>
            </div>
        </section>
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h3>Signed in as</h3></div>
            <div class="panel-body">
                <div class="profile-facts">
                    <div class="profile-fact"><span>Role</span><strong>Administrator</strong></div>
                    <div class="profile-fact"><span>Email</span><strong><?= e($admin['email']) ?></strong></div>
                </div>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>
