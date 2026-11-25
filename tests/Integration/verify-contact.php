<?php

declare(strict_types=1);



// WHAT: drives ContactAbuseGuard and ContactRequestService against an
//       in-memory SQLite copy of the tables they touch.
// WHY:  proves that submissions become custom requests with product context,
//       that callbacks/social replies/email replies enforce their extra
//       fields, and that every abuse control rejects what it should —
//       without needing the MySQL server.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Database\TransactionManager;
use App\Repositories\CustomRequestRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Security\ContactAbuseGuard;
use App\Services\ContactRequestService;
use App\Services\ProductService;
use App\Services\PublicAssetPathResolver;

// DATABASE SETUP: Build only the catalogue, customer, and request tables this workflow reads and writes.
$db = new PDO('sqlite::memory:');
// ISOLATION: every service below receives this in-memory database. The suite
// never calls database(), so it cannot reach the shop's PostgreSQL tables.
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || function_exists('database')) {
    throw new RuntimeException('Refusing to run: verify-contact must use only its in-memory SQLite database.');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
// CONCAT is used by catalogue queries; SQLite's CURRENT_TIMESTAMP is UTC.
$db->sqliteCreateFunction('CONCAT', static fn (?string ...$parts): string => implode('', $parts));
$db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE category_translations (category_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INTEGER, slug TEXT, selling_price REAL, stock_quantity INTEGER, minimum_quantity INTEGER, purchase_mode TEXT, is_made_to_order INTEGER, can_purchase_alone INTEGER, main_image_path TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE product_translations (product_id INTEGER, language_code TEXT, name TEXT, description TEXT)');
$db->exec('CREATE TABLE product_colors (product_id INTEGER, color_id INTEGER, image_path TEXT)');
$db->exec('CREATE TABLE product_photos (id INTEGER PRIMARY KEY, product_id INTEGER, file_path TEXT, sort_order INTEGER)');
$db->exec('CREATE TABLE colors (id INTEGER PRIMARY KEY, hex_code TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE color_translations (color_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE product_scents (product_id INTEGER, scent_id INTEGER)');
$db->exec('CREATE TABLE scents (id INTEGER PRIMARY KEY, is_active INTEGER)');
$db->exec('CREATE TABLE scent_translations (scent_id INTEGER, language_code TEXT, name TEXT)');
$db->exec("CREATE TABLE customers_accounts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone_number TEXT, account_status TEXT NOT NULL DEFAULT 'ACTIVE')");
$db->exec('CREATE TABLE customer_addresses (id INTEGER PRIMARY KEY, customer_id INTEGER, street_address TEXT, delegation TEXT, governorate TEXT, postal_code TEXT, is_primary INTEGER)');
$db->exec('CREATE TABLE custom_product_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, request_number TEXT UNIQUE, customer_id INTEGER,
    category_id INTEGER NOT NULL, product_id INTEGER, customer_name_snapshot TEXT, customer_email_snapshot TEXT,
    customer_phone_snapshot TEXT, preferred_contact_method TEXT, budget TEXT, requested_quantity INTEGER, required_date TEXT,
    color_preferences TEXT, scent_preferences TEXT, packaging_preferences TEXT, customer_message TEXT,
    request_status TEXT, assigned_administrator_id INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec("INSERT INTO categories VALUES (1, 'candles', 1), (3, 'bouquets', 1), (9, 'retired', 0)");
$db->exec("INSERT INTO category_translations VALUES (1, 'en', 'Candles'), (3, 'en', 'Bouquets'), (9, 'en', 'Retired')");
$db->exec("INSERT INTO products VALUES (5, 3, 'test-bouquet', 60.0, NULL, 10, 'CART', 1, 1, 'assets/products/.gitkeep', 1)");
$db->exec("INSERT INTO product_translations VALUES (5, 'en', 'Test Bouquet', 'Bouquet')");
$db->exec("INSERT INTO customers_accounts (id,first_name,last_name,email,phone_number) VALUES (7, 'Amira', 'Ben Salah', 'amira@example.com', '22123456')");

$settings = require APP_PATH . '/config/contact.php';
$sequence = 0;
$makeService = static function (array $overrides = []) use ($db, $settings, &$sequence): ContactRequestService {
    return new ContactRequestService(
        new CustomRequestRepository($db),
        new OrderRepository($db),
        new ProductService(new ProductRepository($db), new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH)),
        new TransactionManager($db),
        $overrides + $settings,
        static function () use (&$sequence): string {
            return 'REQ-W-TEST-' . str_pad((string) ++$sequence, 4, '0', STR_PAD_LEFT);
        },
        static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-29')
    );
};
$service = $makeService();
$check = TestCheck::quiet();
// TEST INPUT: A valid general message; individual checks override one field at a time.
$valid = static fn (array $overrides = []): array => $overrides + [
    'request_type' => 'MESSAGE',
    'customer_name' => ' Amira  Ben Salah ',
    'customer_phone' => '+216 22 123 456',
    'customer_email' => '',
    'preferred_contact_method' => 'PHONE',
    'category_id' => '1',
    'message' => 'Do you make unscented candles for a hospital room?',
];
$latest = static fn (): array => $db->query('SELECT * FROM custom_product_requests ORDER BY id DESC LIMIT 1')->fetch();

// 1. Abuse guard: honeypot, minimum fill time, and per-session limit.
$_SESSION = [];
$clock = 1000;
$guard = new ContactAbuseGuard($settings, static function () use (&$clock): int {
    return $clock;
});
$guard->stampForm();
$check($guard->inspect(['website' => 'http://spam.example']) === ContactAbuseGuard::BOT, 'A filled honeypot is treated as a bot.');
$check($guard->inspect([]) === ContactAbuseGuard::TOO_FAST, 'An instant submission is rejected.');
$clock += 5;
$check($guard->inspect(['website' => '']) === ContactAbuseGuard::PASS, 'A human-paced submission passes.');
$guard->recordSubmission();
$check($guard->inspect([]) === ContactAbuseGuard::TOO_FAST, 'A replayed POST without a fresh form render is rejected.');
for ($i = 0; $i < 2; $i++) {
    $guard->stampForm();
    $clock += 5;
    $guard->recordSubmission();
}
$guard->stampForm();
$clock += 5;
$check($guard->inspect([]) === ContactAbuseGuard::RATE_LIMITED, 'The session limit stops a fourth message within ten minutes.');
$clock += 601;
$check($guard->inspect([]) === ContactAbuseGuard::PASS, 'The session limit resets after the window.');

// 2. Server validation.
$errors = static fn (array $input): array => $service->submit($input, null)['errors'];
$check(isset($errors($valid(['category_id' => '']))['category_id']), 'A general enquiry needs a topic.');
$check(isset($errors($valid(['category_id' => '9']))['category_id']), 'Inactive categories cannot be chosen.');
$check(isset($errors($valid(['message' => 'Hi']))['message']), 'A message needs at least 10 characters.');
$check(isset($errors($valid(['customer_phone' => '1234']))['customer_phone']), 'Invalid phone numbers are refused.');
$check(isset($errors($valid(['preferred_contact_method' => 'EMAIL']))['customer_email']), 'Email replies need an email address.');
$check(isset($errors($valid(['preferred_contact_method' => 'INSTAGRAM']))['social_handle']), 'Social replies need a handle.');
$check(isset($errors($valid(['preferred_contact_method' => 'FAX']))['preferred_contact_method']), 'Unknown contact methods are refused.');
$check(isset($errors($valid(['request_type' => 'CALLBACK', 'preferred_contact_method' => 'EMAIL', 'customer_email' => 'a@b.tn']))['preferred_contact_method']), 'Callbacks must be by phone or WhatsApp.');
$check(isset($errors($valid(['message' => 'See http://a.tn http://b.tn http://c.tn now']))['message']), 'Link-stuffed messages are refused.');
$check((int) $db->query('SELECT COUNT(*) FROM custom_product_requests')->fetchColumn() === 0, 'Rejected submissions write nothing.');

// 3. A general message becomes a SUBMITTED web request.
$result = $service->submit($valid(['preferred_contact_method' => 'INSTAGRAM', 'social_handle' => '@amira.bs']), 7);
$row = $latest();
$check($result['success'] && $result['requestNumber'] === 'REQ-W-TEST-0001', 'A valid message is saved.');
$check($row['request_status'] === 'SUBMITTED' && $row['assigned_administrator_id'] === null, 'Web requests start unassigned in the admin queue.');
$check((int) $row['category_id'] === 1 && $row['product_id'] === null && (int) $row['customer_id'] === 7, 'Topic and signed-in customer are stored.');
$check($row['customer_name_snapshot'] === 'Amira Ben Salah' && $row['customer_phone_snapshot'] === '22123456', 'Contact details are normalized.');
$check(str_starts_with($row['customer_message'], "[Website message]\nInstagram handle: @amira.bs"), 'The request type and handle are visible to administrators.');

// 4. Callback with product context: category/product come from the server, details are validated.
$product = $service->productContext('test-bouquet');
$check($product !== null && $product['category_id'] === 3 && $product['minimum_quantity'] === 10, 'Product context is resolved from the slug.');
$check($service->productContext('../etc/passwd') === null && $service->productContext('missing') === null, 'Unknown or malformed slugs fall back to a general enquiry.');
$callback = ['request_type' => 'CALLBACK', 'message' => '', 'category_id' => '1', 'product' => 'test-bouquet', 'customer_phone' => '55 111 222'];
$check(isset($errors($valid($callback + ['requested_quantity' => '5']))['requested_quantity']), 'The product minimum quantity applies.');
$check(isset($errors($valid($callback + ['required_date' => '2026-09-29']))['required_date']), 'The date must be after today.');
$check(isset($errors($valid($callback + ['budget' => '-3']))['budget']), 'Budgets must be positive amounts.');
$result = $service->submit($valid($callback + [
    'preferred_contact_method' => 'WHATSAPP',
    'requested_quantity' => '25',
    'required_date' => '2026-10-20',
    'budget' => '150,5',
    'color_preferences' => 'Ivory',
    'packaging_preferences' => 'Gift box',
]), null);
$row = $latest();
$check($result['success'] && (int) $row['product_id'] === 5 && (int) $row['category_id'] === 3, 'The product and its category are stored, not the submitted topic.');
$check((int) $row['requested_quantity'] === 25 && $row['required_date'] === '2026-10-20' && $row['budget'] === '150.500', 'Request details are stored exactly.');
$check($row['preferred_contact_method'] === 'WHATSAPP' && str_contains($row['customer_message'], '[Website callback request]')
    && str_contains($row['customer_message'], 'Product: Test Bouquet (test-bouquet)'), 'A callback needs no message and keeps the product name.');

// 5. Database-wide limits: per phone number, then site-wide.
$service->submit($valid(['customer_phone' => '99888777']), null);
$service->submit($valid(['customer_phone' => '99888777']), null);
$service->submit($valid(['customer_phone' => '99888777']), null);
$result = $service->submit($valid(['customer_phone' => '+216 99 888 777']), null);
$check(!$result['success'] && isset($result['errors']['form']), 'The per-phone daily limit applies regardless of formatting.');
$tight = $makeService(['global_limit' => 5]);
$result = $tight->submit($valid(['customer_phone' => '44555666']), null);
$check(!$result['success'] && isset($result['errors']['form']), 'The site-wide flood limit applies.');

// RESULT STEP: Report success only after every assertion above has passed.
echo "Contact checks passed.\n";
