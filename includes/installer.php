<?php
/**
 * First-run installer: creates the tables, default settings, demo accounts
 * and a few sample photo orders so the dashboards are never empty.
 *
 * It only runs when the `users` table is missing. To start over, delete the
 * SQLite file (storage/database.sqlite) or drop the MySQL tables.
 */

declare(strict_types=1);

function install_database(PDO $pdo, string $driver): void
{
    $schemaFile = $driver === 'sqlite' ? 'schema.sqlite.sql' : 'schema.mysql.sql';
    $path       = __DIR__ . '/../database/' . $schemaFile;
    $sql        = file_get_contents($path);

    if ($sql === false) {
        throw new RuntimeException('Missing schema file: ' . $path);
    }

    // Strip comment lines first so the statement after a header comment is
    // never skipped, then run each statement.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    seed_demo_data($pdo);
}

function seed_demo_data(PDO $pdo): void
{
    $now      = date('Y-m-d H:i:s');
    $defaults = [
        'site_name'             => APP_NAME,
        'support_email'         => 'hello@printfree.test',
        'accepting_orders'      => '1',
        'default_paper_size'    => '4x6',
        'default_print_quality' => 'high',
        'epson_client_id'       => '',
        'epson_client_secret'   => '',
        'epson_api_key'         => '',
        'epson_redirect_uri'    => '',
        'epson_refresh_token'   => '',
        'epson_access_token'    => '',
        'epson_token_expires'   => '',
        'epson_device_name'     => '',
        'epson_device_serial'   => '',
        'epson_connected_at'    => '',
    ];

    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    $userStmt = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, phone, role, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $userStmt->execute([
        'PrintFree Customer',
        'customer@me.com',
        password_hash('123', PASSWORD_DEFAULT),
        '07700 900123',
        'customer',
        'active',
        $now,
    ]);
    $customerId = (int) $pdo->lastInsertId();

    $userStmt->execute([
        'PrintFree Admin',
        'admin@me.com',
        password_hash('123', PASSWORD_DEFAULT),
        null,
        'admin',
        'active',
        $now,
    ]);

    // Sample photo orders so the demo dashboards have something to show.
    $samples = [
        [
            'reference'  => 'PF-DEMO1',
            'paper'      => '4x6',
            'color'      => 'color',
            'borderless' => 1,
            'quality'    => 'high',
            'status'     => 'pending',
            'notes'      => 'Borderless on glossy photo paper, please.',
            'created'    => date('Y-m-d H:i:s', time() - 3600 * 4),
            'photos'     => [
                ['name' => 'Lake-at-sunrise.jpg', 'source' => 'photo-1.jpg'],
                ['name' => 'Sunrise-close-up.jpg', 'source' => 'photo-3.jpg'],
            ],
        ],
        [
            'reference'  => 'PF-DEMO2',
            'paper'      => '4x6',
            'color'      => 'color',
            'borderless' => 1,
            'quality'    => 'high',
            'status'     => 'completed',
            'notes'      => '',
            'created'    => date('Y-m-d H:i:s', time() - 3600 * 28),
            'photos'     => [
                ['name' => 'Garden-puppy.jpg', 'source' => 'photo-2.jpg'],
            ],
        ],
        [
            'reference'  => 'PF-DEMO3',
            'paper'      => '5x7',
            'color'      => 'color',
            'borderless' => 0,
            'quality'    => 'normal',
            'status'     => 'completed',
            'notes'      => '',
            'created'    => date('Y-m-d H:i:s', time() - 3600 * 72),
            'photos'     => [
                ['name' => 'City-at-dusk.jpg', 'source' => 'photo-3.jpg'],
            ],
        ],
    ];

    $orderStmt = $pdo->prepare(
        'INSERT INTO orders
            (reference, user_id, color_mode, borderless, paper_size, print_quality, notes,
             status, printed_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $photoStmt = $pdo->prepare(
        'INSERT INTO order_photos
            (order_id, file_name, mime_type, image_data, thumb_data, file_size,
             image_width, image_height, position, status, printed_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $eventStmt = $pdo->prepare(
        'INSERT INTO order_events (order_id, status, message, actor, created_at) VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($samples as $sample) {
        $orderStmt->execute([
            $sample['reference'],
            $customerId,
            $sample['color'],
            $sample['borderless'],
            $sample['paper'],
            $sample['quality'],
            $sample['notes'],
            $sample['status'],
            $sample['status'] === 'completed' ? $sample['created'] : null,
            $sample['created'],
            $sample['created'],
        ]);

        $orderId = (int) $pdo->lastInsertId();
        $count   = 0;

        foreach ($sample['photos'] as $photo) {
            $source = __DIR__ . '/../database/demo/' . $photo['source'];
            if (!is_file($source)) {
                continue;
            }

            $data = file_get_contents($source);
            if ($data === false || $data === '') {
                continue;
            }

            $dimensions = @getimagesize($source) ?: [0, 0];
            $thumb = make_photo_thumbnail($data);
            $count++;
            $completedAt = $sample['status'] === 'completed' ? $sample['created'] : null;

            $photoStmt->bindValue(1, $orderId, PDO::PARAM_INT);
            $photoStmt->bindValue(2, $photo['name'], PDO::PARAM_STR);
            $photoStmt->bindValue(3, 'image/jpeg', PDO::PARAM_STR);
            $photoStmt->bindValue(4, $data, PDO::PARAM_LOB);
            $photoStmt->bindValue(5, $thumb, $thumb === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $photoStmt->bindValue(6, strlen($data), PDO::PARAM_INT);
            $photoStmt->bindValue(7, (int) $dimensions[0], PDO::PARAM_INT);
            $photoStmt->bindValue(8, (int) $dimensions[1], PDO::PARAM_INT);
            $photoStmt->bindValue(9, $count, PDO::PARAM_INT);
            $photoStmt->bindValue(10, $sample['status'] === 'completed' ? 'completed' : 'pending', PDO::PARAM_STR);
            $photoStmt->bindValue(11, $completedAt, $completedAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $photoStmt->bindValue(12, $sample['created'], PDO::PARAM_STR);
            $photoStmt->execute();
            unset($data, $thumb);
        }

        $eventStmt->execute([
            $orderId,
            'pending',
            $count . ' photo' . ($count === 1 ? '' : 's') . ' submitted by the customer.',
            'PrintFree Customer',
            $sample['created'],
        ]);

        if ($sample['status'] === 'completed' && $count > 0) {
            $eventStmt->execute([$orderId, 'printing', 'Sent to the Epson ET-2926.', 'PrintFree Admin', $sample['created']]);
            $eventStmt->execute([$orderId, 'completed', 'Photos printed and marked complete.', 'PrintFree Admin', $sample['created']]);
        }
    }
}
