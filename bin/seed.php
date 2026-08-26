<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Db;

// Catalog and key pool copied verbatim from the assignment.
$products = [
    ['STEAM-TOPUP-500',  'Пополнение Steam 500 ₽',        'topup',        500,  'RUB', 'assets/steam.png'],
    ['STEAM-TOPUP-1000', 'Пополнение Steam 1000 ₽',       'topup',        1000, 'RUB', 'assets/steam.png'],
    ['STEAM-TOPUP-2500', 'Пополнение Steam 2500 ₽',       'topup',        2500, 'RUB', 'assets/steam.png'],
    ['KEY-CS2-PRIME',    'CS2 Prime Status ключ',         'key',          1290, 'RUB', 'assets/cs2.png'],
    ['KEY-GTA5',         'GTA V ключ активации',          'key',          1990, 'RUB', 'assets/gta5.png'],
    ['KEY-EFT',          'Escape from Tarkov ключ',       'key',          3490, 'RUB', 'assets/eft.png'],
    ['SUB-DISCORD-1M',   'Discord Nitro 1 месяц',         'subscription', 399,  'RUB', 'assets/discord.png'],
    ['SUB-YT-3M',        'YouTube Premium 3 месяца',      'subscription', 1490, 'RUB', 'assets/youtube.png'],
    ['SUB-SPOTIFY-1M',   'Spotify Premium 1 месяц',       'subscription', 299,  'RUB', 'assets/spotify.png'],
    ['GIFT-PSN-1000',    'PlayStation Store карта 1000 ₽', 'giftcard',    1000, 'RUB', 'assets/psn.png'],
    ['GIFT-XBOX-1500',   'Xbox Gift Card 1500 ₽',         'giftcard',     1500, 'RUB', 'assets/xbox.png'],
    ['GIFT-ROBLOX-800',  'Roblox 800 Robux',              'giftcard',     890,  'RUB', 'assets/roblox.png'],
];

$keys = [
    'LFXC-TNCS-BPCD', 'P3EI-W8UO-9B4K', 'FEL3-GUXN-TCCH', 'YPLV-QK2Z-IUS5', '0K9E-P1FR-BY1U',
    '5LZV-UQ48-RXCZ', 'X93K-NYAQ-GEC1', 'EIO5-CQT5-35KO', 'M58F-GIIR-VJAP', 'NU8Y-SWYB-6252',
    'OODW-CCHF-MBAF', 'DNA5-WFJM-NE49', 'QRDD-MJ3F-A8TF', 'TAT9-5ZJN-G1T2', 'LI39-4330-ISMB',
    'BKJY-8Q79-8NHI', 'HHW6-4RX2-DX62', '1RG2-L28O-O80G', 'EF63-F39X-MTEA', '8XS7-P53H-JKIV',
    'JPE6-MQV6-P7ST', 'SAPG-A2GR-0ULS', 'T2DU-IJ1S-U16P', 'WSSY-QTR7-Z57J', 'U74E-EPCI-CY26',
    'FZXF-58H8-OR93', 'FPSM-HLZA-TPAL', 'WSC9-28DJ-B2JE', 'P63J-F7UZ-DCYP', 'C7W2-D4C5-QMT7',
    'JESI-DFBH-LK1K', 'SGMA-JA0T-GR7D', '3PR4-OSY9-M3ZW', 'OMBE-C0JF-D45Y', 'KIKQ-FQJ8-9TI8',
    'LMAN-RSHS-AJDO', 'BAKI-VT1X-Z5OL', '9F0X-B46W-03FS', 'S423-V6YY-IBEM', 'D4UW-WYRA-20ST',
    'XC0J-CJ0H-09RN', 'RY1W-XCFJ-0KUA', 'CJYY-YKSQ-QE6H', '97AQ-38QJ-H8HU', 'FS8E-3S5Z-I6RA',
    'ARQK-FML4-A14E', '7Z6K-NO9V-MPJB', 'D4K7-IJSG-N853', 'W67T-ZB0Q-1XKB', '7EQM-K09J-XKUO',
];

$pdo = Db::pdo();
$pdo->beginTransaction();

foreach ($products as [$sku, $name, $type, $price, $currency, $image]) {
    Db::run(
        'INSERT INTO products (sku, name, type, price, currency, image) VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT (sku) DO UPDATE SET name = excluded.name, type = excluded.type,
             price = excluded.price, currency = excluded.currency, image = excluded.image',
        [$sku, $name, $type, $price, $currency, $image],
    );
}

// re-seeding must never un-reserve a key that already went out to an order
foreach ($keys as $code) {
    Db::run('INSERT INTO key_pool (code) VALUES (?) ON CONFLICT (code) DO NOTHING', [$code]);
}

$pdo->commit();

$available = Db::one('SELECT count(*) AS n FROM key_pool WHERE order_id IS NULL');
printf("seeded %d products, %d keys in pool (%d available)\n", count($products), count($keys), (int) $available['n']);
