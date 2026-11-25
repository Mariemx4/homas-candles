<?php

declare(strict_types=1);

// Demo catalogue and administrator for the local `docker compose up` demo.
//
// Safety: it refuses to run unless HOMAS_DEMO=1 and the connected database's
// name starts with "homas_demo", so it can never write to the real shop.
// It runs once: when products already exist it does nothing.
//
// The products are inserted as drafts, priced by the app's own PricingService,
// and switched on only if ProductReadinessService says they meet the shop's
// activation requirements, exactly as an administrator would have to.

require_once __DIR__ . '/../../app/config/database.php';

use App\Auth\PasswordPolicy;
use App\Database\TransactionManager;
use App\Repositories\AdminProductRepository;
use App\Repositories\PricingRepository;
use App\Services\PricingService;
use App\Services\ProductReadinessService;

/**
 * Makes sure every demo photo exists under public/assets/products/demo.
 *
 * The database keeps its rows when the container is rebuilt, but the files do not.
 * Without the photos, the catalogue hides those products, so the photos are restored
 * on every start. Each product photo and colour photo is copied from the brand photo
 * its product uses.
 */
function ensureDemoImages(PDO $db): void
{
    $directory = PRODUCT_IMAGE_PATH . '/demo';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the demo image folder.');
    }
    $rows = $db->query(
        'SELECT p.main_image_path, pc.image_path
         FROM products AS p
         LEFT JOIN product_colors AS pc ON pc.product_id = p.id'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $source = PUBLIC_PATH . '/assets/brand/' . basename((string) $row['main_image_path']);
        if (!is_file($source)) {
            continue;
        }
        foreach (['main_image_path', 'image_path'] as $column) {
            if (!is_string($row[$column] ?? null)) {
                continue;
            }
            $target = PUBLIC_PATH . '/' . $row[$column];
            if (!is_file($target)) {
                copy($source, $target);
                @chown($target, 'www-data');
            }
        }
    }
}
const DEMO_ADMIN_EMAIL = 'admin@demo.test';
const DEMO_ADMIN_PASSWORD = 'DemoAdmin-2026!';

$db = database();
$databaseName = (string) $db->query('SELECT current_database()')->fetchColumn();
if (getenv('HOMAS_DEMO') !== '1' || !str_starts_with($databaseName, 'homas_demo')) {
    fwrite(STDERR, "Refusing to load demo data into '{$databaseName}': this is not the demo database.\n");
    exit(1);
}
// Photos are repaired on every start, before the loaded check below.
ensureDemoImages($db);
if ((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0) {
    echo "Demo data already loaded.\n";
    exit(0);
}

// Reference data (languages, categories, types). reference.sql has its own
// BEGIN/COMMIT, so it runs before, not inside, the demo transaction below.
$db->exec((string) file_get_contents(APP_PATH . '/database/reference.sql'));

$transactions = new TransactionManager($db);
$transactions->run(static function () use ($db): void {

    $insert = static function (string $table, array $row) use ($db): int {
        $columns = array_keys($row);
        $statement = $db->prepare(
            "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ') RETURNING id'
        );
        $statement->execute($row);

        return (int) $statement->fetchColumn();
    };
    $insertLink = static function (string $table, array $row) use ($db): void {
        $columns = array_keys($row);
        $db->prepare("INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')
            ->execute($row);
    };
    $translate = static function (string $table, string $key, int $id, array $names) use ($insertLink): void {
        foreach ($names as $language => $name) {
            $insertLink($table, [$key => $id, 'language_code' => $language, 'name' => $name]);
        }
    };

    // Colours and their names in the three shop languages.
    $colors = [];
    foreach ([
        'ivory' => ['#F4EFE6', ['en' => 'Ivory', 'fr' => 'Ivoire', 'ar' => 'عاجي']],
        'sage' => ['#9CAF88', ['en' => 'Sage', 'fr' => 'Sauge', 'ar' => 'مريمية']],
        'blush' => ['#E8B4B8', ['en' => 'Blush', 'fr' => 'Rose poudré', 'ar' => 'وردي']],
    ] as $slug => [$hex, $names]) {
        $colors[$slug] = $insert('colors', ['slug' => $slug, 'hex_code' => $hex, 'is_active' => 1]);
        $translate('color_translations', 'color_id', $colors[$slug], $names);
    }

    // Materials (type 1 = raw material). Costs are in TND per unit.
    $material = static fn (string $sku, string $name, string $unit, string $cost, string $stock): int => $insert('materials', [
        'material_type_id' => 1, 'sku' => $sku, 'name' => $name, 'unit' => $unit,
        'unit_cost' => $cost, 'stock_quantity' => $stock, 'tracks_stock' => 1, 'is_active' => 1,
    ]);
    $wax = $material('DEMO-WAX', 'Soy wax', 'g', '0.030', '20000.000');
    $wick = $material('DEMO-WICK', 'Cotton wick', 'pc', '0.200', '500.000');
    $jar = $material('DEMO-JAR', 'Glass jar', 'pc', '2.500', '200.000');
    $fragrances = [
        'vanilla' => $material('DEMO-FR-VANILLA', 'Vanilla fragrance oil', 'ml', '0.150', '3000.000'),
        'lavender' => $material('DEMO-FR-LAVENDER', 'Lavender fragrance oil', 'ml', '0.140', '3000.000'),
        'amber' => $material('DEMO-FR-AMBER', 'Amber fragrance oil', 'ml', '0.180', '3000.000'),
    ];

    // Scents, each backed by its fragrance oil.
    $scents = [];
    foreach ([
        'vanilla' => ['en' => 'Vanilla', 'fr' => 'Vanille', 'ar' => 'فانيليا'],
        'lavender' => ['en' => 'Lavender', 'fr' => 'Lavande', 'ar' => 'خزامى'],
        'amber' => ['en' => 'Amber', 'fr' => 'Ambre', 'ar' => 'عنبر'],
    ] as $slug => $names) {
        $scents[$slug] = $insert('scents', ['fragrance_material_id' => $fragrances[$slug], 'slug' => $slug, 'is_active' => 1]);
        $translate('scent_translations', 'scent_id', $scents[$slug], $names);
    }

    // Candles (category 1). Product types come from reference.sql:
    // 1 flowers, 2 bubble, 3 in-container.
    $products = [
        ['peony-flower-candle', 1, 'candle.jpg', 120, false, ['ivory', 'blush'], ['vanilla', 'lavender'], [
            'en' => ['Peony flower candle', 'A hand-poured soy candle shaped like a peony in full bloom.'],
            'fr' => ['Bougie fleur de pivoine', 'Une bougie de soja coulée à la main en forme de pivoine épanouie.'],
            'ar' => ['شمعة زهرة الفاوانيا', 'شمعة صويا مصبوبة يدوياً على شكل زهرة فاوانيا متفتحة.'],
        ]],
        ['amber-jar-candle', 3, 'bestSeller.jpg', 200, true, ['ivory', 'sage'], ['amber', 'vanilla'], [
            'en' => ['Amber jar candle', 'A warm amber candle in a reusable glass jar.'],
            'fr' => ['Bougie ambre en pot', 'Une bougie chaleureuse à l’ambre dans un pot en verre réutilisable.'],
            'ar' => ['شمعة العنبر في برطمان', 'شمعة دافئة برائحة العنبر في برطمان زجاجي قابل لإعادة الاستخدام.'],
        ]],
        ['bubble-cube-candle', 2, 'candleSet.jpg', 90, false, ['blush', 'sage'], ['lavender', 'amber'], [
            'en' => ['Bubble cube candle', 'A playful bubble-cube candle that brightens any shelf.'],
            'fr' => ['Bougie cube bulles', 'Une bougie cube à bulles qui égaie chaque étagère.'],
            'ar' => ['شمعة مكعب الفقاعات', 'شمعة مكعب الفقاعات المرحة التي تضيء أي رف.'],
        ]],
        ['rose-bouquet-candle', 1, 'bouquet.jpg', 150, false, ['blush', 'ivory'], ['vanilla', 'amber'], [
            'en' => ['Rose bouquet candle', 'A small bouquet of wax roses, made to order for gifts.'],
            'fr' => ['Bougie bouquet de roses', 'Un petit bouquet de roses en cire, fait sur commande pour offrir.'],
            'ar' => ['شمعة باقة الورود', 'باقة صغيرة من ورود الشمع تُصنع حسب الطلب للهدايا.'],
        ]],
    ];
    foreach ($products as [$slug, $type, $image, $waxGrams, $inJar, $productColors, $productScents, $texts]) {
        $imagePath = 'assets/products/demo/' . $image;
        $productId = $insert('products', [
            'category_id' => 1, 'product_type_id' => $type, 'slug' => $slug,
            'main_image_path' => $imagePath, 'is_active' => 0,
        ]);
        foreach ($texts as $language => [$name, $description]) {
            $insertLink('product_translations', [
                'product_id' => $productId, 'language_code' => $language, 'name' => $name, 'description' => $description,
            ]);
        }
        // Each colour needs its own photo file (image paths are unique per colour).
        foreach ($productColors as $color) {
            $colorImage = 'assets/products/demo/' . $slug . '-' . $color . '.jpg';
            $insertLink('product_colors', ['product_id' => $productId, 'color_id' => $colors[$color], 'image_path' => $colorImage]);
        }
        foreach ($productScents as $scent) {
            $insertLink('product_scents', ['product_id' => $productId, 'scent_id' => $scents[$scent]]);
        }
        // The recipe's fragrance line is replaced by the scent the customer chooses.
        $recipe = [$wax => (string) $waxGrams, $wick => '1', $fragrances[$productScents[0]] => '12'];
        if ($inJar) {
            $recipe[$jar] = '1';
        }
        foreach ($recipe as $materialId => $quantity) {
            $insertLink('product_materials', [
                'product_id' => $productId, 'material_id' => $materialId, 'recipe_type' => 'ACTUAL', 'quantity_required' => $quantity,
            ]);
        }
    }

    $insertLink('administrators_accounts', [
        'first_name' => 'Demo', 'last_name' => 'Administrator', 'email' => DEMO_ADMIN_EMAIL,
        'phone_number' => '20000000', 'password_hash' => PasswordPolicy::hashVerified(DEMO_ADMIN_PASSWORD),
    ]);
});

// Price the catalogue with the real rules, then activate what is ready.
ensureDemoImages($db);
$pricing = new PricingService(new PricingRepository($db), $transactions);
$pricing->recalculateAll();
$readiness = new ProductReadinessService(
    new AdminProductRepository($db),
    $pricing,
    $transactions,
    new \App\Repositories\ProductOptionRepository($db),
    new \App\Repositories\ProductTranslationRepository($db)
);
$catalogue = new AdminProductRepository($db);
$active = 0;
foreach ($db->query('SELECT id FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $productId) {
    $result = $readiness->evaluate((int) $productId);
    if ($result !== null && $result['ready']) {
        $catalogue->setActive((int) $productId, true);
        ++$active;
    } else {
        echo 'Not activated: ' . ($result['name'] ?? $productId) . ' (' . implode('; ', $result['missing'] ?? []) . ")\n";
    }
}

echo "Demo data loaded: {$active} active products.\n";
echo 'Demo administrator: ' . DEMO_ADMIN_EMAIL . ' / ' . DEMO_ADMIN_PASSWORD . " (local demo only)\n";
