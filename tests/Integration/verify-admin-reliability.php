<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
require_once __DIR__ . '/../Support/TestPhpServer.php';

use App\Database\TransactionManager;
use App\Repositories\{AdminProductRepository, CustomRequestRepository, MaterialRepository, OrderRepository, ProductRepository, ProductionRepository, TaxonomyRepository};
use App\Services\{ContactRequestService, ProductService, PublicAssetPathResolver};

// Build the current PostgreSQL schema independently of the missing reference seed.
[$db, $schema] = create_throwaway_auth_schema();
$server = null;
$client = null;
$sessionDirectory = sys_get_temp_dir() . '/' . $schema . '_reliability_sessions';
$serverLog = sys_get_temp_dir() . '/' . $schema . '_reliability.log';
$checks = 0;
$check = TestCheck::printing($checks);

try {
    foreach (glob(APP_PATH . '/database/migrations/*.sql') ?: [] as $migration) {
        if (!in_array(basename($migration), [
            '002_create_administrators_accounts_table.sql',
            '003_create_customers_accounts_table.sql',
            '004_create_customer_addresses_table.sql',
            '020_create_login_throttle.sql',
        ], true)) {
            $db->exec((string) file_get_contents($migration));
        }
    }
    $db->exec("INSERT INTO languages(code,name) VALUES('en','English'),('fr','Français'),('ar','العربية');
        INSERT INTO categories(id,slug,is_active) VALUES(1,'candles',1),(2,'accessories',1);
        INSERT INTO product_types(id,slug,is_active) VALUES(1,'flowers',1),(2,'other',1);
        INSERT INTO material_types(id,slug,is_consumable,is_recipe_input,is_active) VALUES(1,'raw-material',1,1,1)");
    $name = 'Audit Crème %_= شمعة';
    foreach (['categories' => 'category', 'product_types' => 'product_type'] as $table => $foreignKey) {
        $text = $db->prepare("INSERT INTO {$foreignKey}_translations({$foreignKey}_id,language_code,name) VALUES(:id,'en',:name)");
        $text->execute(['id' => 1, 'name' => $name]);
        $text->execute(['id' => 2, 'name' => 'Other record']);
    }
    foreach (['colors' => 'color', 'scents' => 'scent'] as $table => $foreignKey) {
        $db->exec("INSERT INTO {$table}(id,slug,is_active) VALUES(1,'audit-match',1),(2,'other-record',1)");
        $text = $db->prepare("INSERT INTO {$foreignKey}_translations({$foreignKey}_id,language_code,name) VALUES(:id,'en',:name)");
        $text->execute(['id' => 1, 'name' => $name]);
        $text->execute(['id' => 2, 'name' => 'Other record']);
    }
    $db->exec("INSERT INTO products(id,category_id,product_type_id,slug,is_active) VALUES(1,1,1,'audit-match',0),(2,2,2,'other-record',0)");
    $text = $db->prepare("INSERT INTO product_translations(product_id,language_code,name,description) VALUES(:id,'en',:name,'Regression fixture')");
    $text->execute(['id' => 1, 'name' => $name]);
    $text->execute(['id' => 2, 'name' => 'Other record']);
    $material = $db->prepare("INSERT INTO materials(id,material_type_id,sku,name,unit,stock_quantity,unit_cost,tracks_stock,minimum_stock_level,is_active) VALUES(:id,1,:sku,:name,'g',100,0.100,1,10,1)");
    $material->execute(['id' => 1, 'sku' => 'AUDIT-MATCH', 'name' => $name]);
    $material->execute(['id' => 2, 'sku' => 'OTHER-RECORD', 'name' => 'Other record']);

    $password = 'Reliability-' . bin2hex(random_bytes(16)) . '1a';
    $adminInsert = $db->prepare("INSERT INTO administrators_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Audit','Administrator','reliability@example.test','98110001',:hash) RETURNING id");
    $adminInsert->execute(['hash' => password_hash($password, PASSWORD_ARGON2ID)]);
    $admin = (int) $adminInsert->fetchColumn();
    $orderInsert = $db->prepare("INSERT INTO orders(order_number,language_code,order_source,order_status,delivery_method,required_date,customer_name_snapshot,customer_phone_snapshot,subtotal,delivery_fee,total_amount,required_deposit_amount) VALUES(:number,'en','MANUAL','CONFIRMED','PICKUP','2026-12-10',:name,'98110002',100,0,100,40)");
    $orderInsert->execute(['number' => 'AUDIT-RELIABILITY', 'name' => $name]);
    $orderInsert->execute(['number' => 'OTHER-RELIABILITY', 'name' => 'Other record']);
    $requestInsert = $db->prepare("INSERT INTO custom_product_requests(request_number,customer_name_snapshot,customer_phone_snapshot,category_id,requested_quantity,preferred_contact_method,request_status) VALUES(:number,:name,'98110002',1,1,'PHONE','SUBMITTED') RETURNING id");
    $requestInsert->execute(['number' => 'AUDIT-RELIABILITY', 'name' => $name]);
    $requestId = (int) $requestInsert->fetchColumn();
    $requestInsert->execute(['number' => 'OTHER-RELIABILITY', 'name' => 'Other record']);

    // Count and row queries must agree, with real nondeterministic ICU columns.
    $searches = [
        'Products' => static fn (array $criteria): array => [(new AdminProductRepository($db))->countForList($criteria), (new AdminProductRepository($db))->findForList($criteria, 0, 20)],
        'Materials' => static fn (array $criteria): array => [(new MaterialRepository($db))->countForList($criteria), (new MaterialRepository($db))->findForList($criteria, 'name', 'asc', 0, 20)],
        'Requests' => static fn (array $criteria): array => [(new CustomRequestRepository($db))->countForList($criteria), (new CustomRequestRepository($db))->findForList($criteria, 0, 20)],
        'Production' => static fn (array $criteria): array => [(new ProductionRepository($db))->countForQueue($criteria), (new ProductionRepository($db))->findForQueue($criteria, 'priority', 'asc', 0, 20, '2026-11-09')],
    ];
    foreach (['categories', 'product_types', 'colors', 'scents'] as $kind) {
        $searches[$kind] = static fn (array $criteria): array => [(new TaxonomyRepository($db))->countForList($kind, $criteria), (new TaxonomyRepository($db))->findForList($kind, $criteria, 0, 20)];
    }
    foreach ($searches as $label => $search) {
        foreach (['aUdIt cRÈmE', '%_=', 'شمعة'] as $term) {
            [$total, $rows] = $search(['search' => $term]);
            $check($total === 1 && count($rows) === 1, $label . ' search supports case, Unicode and literal wildcard text: ' . $term);
        }
        [$total, $rows] = $search(['search' => 'absent-record']);
        $check($total === 0 && $rows === [], $label . ' search returns an honest empty result.');
    }

    $requests = new CustomRequestRepository($db);
    $db->exec("INSERT INTO custom_product_requests(request_number,customer_name_snapshot,customer_phone_snapshot,category_id,preferred_contact_method,request_status,created_at) VALUES
        ('REQ-W-RECENT','Recent','98110003',1,'PHONE','SUBMITTED',CURRENT_TIMESTAMP - INTERVAL '30 seconds'),
        ('REQ-W-OLD','Old','98110003',1,'PHONE','SUBMITTED',CURRENT_TIMESTAMP - INTERVAL '120 seconds'),
        ('REQ-A-RECENT','Offline','98110003',1,'PHONE','SUBMITTED',CURRENT_TIMESTAMP)");
    $check($requests->countRecentWebRequests(60) === 1 && $requests->countRecentWebRequests(60, '98110003') === 1
        && $requests->countRecentWebRequests(60, '98110004') === 0, 'PostgreSQL rate windows include only recent website enquiries and the requested phone.');
    $settings = require APP_PATH . '/config/contact.php';
    $contact = new ContactRequestService(
        $requests,
        new OrderRepository($db),
        new ProductService(new ProductRepository($db), new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH)),
        new TransactionManager($db),
        $settings,
        static fn (): string => 'REQ-W-RELIABILITY'
    );
    $message = [
        'request_type' => 'MESSAGE',
        'customer_name' => 'Website Customer',
        'customer_phone' => '98110004',
        'customer_email' => '',
        'preferred_contact_method' => 'PHONE',
        'category_id' => '1',
        'message' => 'Please help me arrange an unscented candle order.'
    ];
    $submitted = $contact->submit($message, null);
    $check(
        $submitted['success'] && (int) $db->query("SELECT COUNT(*) FROM custom_product_requests WHERE request_number='REQ-W-RELIABILITY' AND request_status='SUBMITTED'")->fetchColumn() === 1,
        'A valid website enquiry reaches the PostgreSQL admin inbox.'
    );
    $limited = new ContactRequestService(
        $requests,
        new OrderRepository($db),
        new ProductService(new ProductRepository($db), new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH)),
        new TransactionManager($db),
        ['phone_limit' => 1] + $settings
    );
    $check(!$limited->submit($message, null)['success'], 'The repaired intake still enforces the phone rate limit.');

    mkdir($sessionDirectory, 0700);
    $address = TestPhpServer::reserveAddress();
    $server = TestPhpServer::start($address, $sessionDirectory, $serverLog, [
        'PGOPTIONS' => '-c search_path=' . $schema,
        'APP_BASE_URL' => '/',
        'LOGIN_THROTTLE_KEY' => bin2hex(random_bytes(32)),
        'HOMAS_VERIFICATION_DATABASE' => null,
    ]);
    $client = curl_init();
    curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
    $http = static function (string $path, ?array $post = null) use ($client, $address): array {
        $headers = [];
        curl_setopt_array($client, [
            CURLOPT_URL => 'http://' . $address . '/' . $path,
            CURLOPT_POSTFIELDS => $post === null ? null : http_build_query($post),
            CURLOPT_POST => $post !== null,
            CURLOPT_HTTPGET => $post === null,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$headers): int {
                if (str_contains($header, ':')) {
                    [$name, $value] = explode(':', $header, 2);
                    $headers[strtolower($name)] = trim($value);
                }
                return strlen($header);
            }
        ]);
        $body = curl_exec($client);
        if (!is_string($body)) {
            throw new RuntimeException('An isolated HTTP request failed.');
        }
        return [(int) curl_getinfo($client, CURLINFO_HTTP_CODE), $body, $headers];
    };
    $token = static function (string $html): string {
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match) !== 1) {
            throw new RuntimeException('A form is missing its CSRF token.');
        }
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    };
    $field = static function (string $html, string $id): string {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $element = $document->getElementById($id);
        if (!$element) {
            throw new RuntimeException('Expected form field missing: ' . $id);
        }
        if ($element->tagName === 'textarea') {
            return $element->textContent;
        }
        if ($element->tagName === 'select') {
            foreach ($element->getElementsByTagName('option') as $option) {
                if ($option->hasAttribute('selected')) {
                    return $option->getAttribute('value');
                }
            }
            return $element->getElementsByTagName('option')->item(0)?->getAttribute('value') ?? '';
        }
        return $element->getAttribute('value');
    };

    [, $login] = $http('admin/login.php');
    [$status,, $headers] = $http('admin/login.php', ['csrf_token' => $token($login), 'login_identifier' => 'reliability@example.test', 'password' => $password]);
    $check($status === 303 && ($headers['location'] ?? '') === '/admin/index.php', 'HTTP checks authenticate through the normal administrator sign-in.');
    [, $contactForm] = $http('contact.php');
    // Respect the real public form's minimum-fill protection instead of bypassing it.
    usleep(((int) $settings['minimum_fill_seconds'] + 1) * 1000000);
    $webMessage = array_replace($message, ['customer_name' => 'HTTP Website Customer', 'customer_phone' => '98110005']);
    [$status,, $headers] = $http('contact.php', $webMessage + ['csrf_token' => $token($contactForm), 'website' => '']);
    [, $inbox] = $http('admin/requests/index.php?' . http_build_query(['q' => 'HTTP Website Customer']));
    $check(
        $status === 303 && ($headers['location'] ?? '') === '/contact.php' && str_contains($inbox, 'HTTP Website Customer')
            && (int) $db->query("SELECT COUNT(*) FROM custom_product_requests WHERE customer_phone_snapshot='98110005' AND request_status='SUBMITTED'")->fetchColumn() === 1,
        'The real website contact POST passes abuse protection and appears in the authenticated admin inbox.'
    );
    foreach (
        [
            'catalogue/products.php',
            'catalogue/categories.php',
            'catalogue/product-types.php',
            'catalogue/colors.php',
            'catalogue/scents.php',
            'inventory/materials.php',
            'requests/index.php',
            'production/queue.php'
        ] as $route
    ) {
        [$status, $body] = $http('admin/' . $route . '?' . http_build_query(['q' => '%_=']));
        $check($status === 200 && str_contains($body, 'Audit Crème') && !str_contains($body, 'temporarily unavailable'), 'Authenticated populated search renders its matching record: ' . $route);
    }

    [, $body] = $http('admin/accounts.php?id=' . $admin);
    $profile = [
        'kind' => 'administrators',
        'id' => (string) $admin,
        'first_name' => 'Audit',
        'last_name' => 'Administrator',
        'email' => 'reliability@example.test',
        'phone_number' => '98110001',
        'second_phone_number' => ''
    ];
    [$status] = $http('admin/accounts.php', $profile + ['csrf_token' => $token($body), 'second_email' => 'backup@example.test']);
    [, $body] = $http('admin/accounts.php?id=' . $admin);
    $check($status === 303 && $field($body, 'profile-second_email') === 'backup@example.test', 'Administrator secondary email survives save and reload.');
    $http('admin/accounts.php', $profile + ['csrf_token' => $token($body)]);
    [, $body] = $http('admin/accounts.php?id=' . $admin);
    $check($field($body, 'profile-second_email') === 'backup@example.test', 'Older profile submissions that omit secondary email preserve it.');
    $http('admin/accounts.php', $profile + ['csrf_token' => $token($body), 'second_email' => 'reliability@example.test']);
    $check($db->query('SELECT second_email FROM administrators_accounts WHERE id=' . $admin)->fetchColumn() === 'backup@example.test', 'Invalid duplicate secondary email cannot overwrite the saved value.');

    $notes = 'Keep "quotes" & <script>test</script> as text';
    [, $body] = $http('admin/inventory/materials.php?id=1');
    $http('admin/inventory/materials.php', [
        'csrf_token' => $token($body),
        'id' => '1',
        'action' => 'record_movement',
        'movement_type' => 'ADJUSTMENT_OUT',
        'quantity_change' => '9999.123',
        'movement_note' => $notes
    ]);
    [, $body] = $http('admin/inventory/materials.php?id=1');
    $check($field($body, 'movement-type') === 'ADJUSTMENT_OUT' && $field($body, 'movement-quantity') === '9999.123'
        && $field($body, 'movement-note') === $notes && !str_contains($body, '<script>test</script>'), 'A rejected stock movement restores all entered values with safe escaping.');
    $check($db->query('SELECT stock_quantity FROM materials WHERE id=1')->fetchColumn() === '100.000'
        && (int) $db->query('SELECT COUNT(*) FROM material_stock_movements')->fetchColumn() === 0, 'Rejected movements leave stock and its ledger unchanged.');
    $http('admin/inventory/materials.php', [
        'csrf_token' => 'expired',
        'id' => '1',
        'action' => 'record_movement',
        'movement_type' => 'ADJUSTMENT_IN',
        'quantity_change' => '2.500',
        'movement_note' => $notes
    ]);
    [, $body] = $http('admin/inventory/materials.php?id=1');
    $check($field($body, 'movement-type') === 'ADJUSTMENT_IN' && $field($body, 'movement-note') === $notes, 'Expired stock forms preserve work without applying it.');

    $requestRoute = 'admin/requests/detail.php?id=' . $requestId;
    [, $body] = $http($requestRoute);
    $past = '2001-01-01T12:00';
    $http('admin/requests/action.php', [
        'csrf_token' => $token($body),
        'id' => (string) $requestId,
        'action' => 'contact',
        'contact_method' => 'WHATSAPP',
        'contact_outcome' => 'FOLLOW_UP_REQUIRED',
        'follow_up' => $past,
        'notes' => $notes
    ]);
    [, $body] = $http($requestRoute);
    $check($field($body, 'contact-method') === 'WHATSAPP' && $field($body, 'contact-outcome') === 'FOLLOW_UP_REQUIRED'
        && $field($body, 'contact-follow-up') === $past && $field($body, 'contact-notes') === $notes, 'Failed contact validation preserves method, outcome, follow-up and escaped notes.');
    $check($field($body, 'transition-notes') === '', 'Failed contact input does not populate the separate status form.');
    $http('admin/requests/action.php', [
        'csrf_token' => 'expired',
        'id' => (string) $requestId,
        'action' => 'transition',
        'new_status' => 'CONTACTED',
        'contact_method' => 'WHATSAPP',
        'notes' => $notes
    ]);
    [, $body] = $http($requestRoute);
    $check($field($body, 'transition-method') === 'WHATSAPP' && $field($body, 'transition-notes') === $notes
        && (int) $db->query('SELECT COUNT(*) FROM request_contact_attempts')->fetchColumn() === 0, 'Expired status forms retain input and do not create contact history.');
    $http('admin/requests/action.php', ['csrf_token' => $token($body), 'id' => (string) $requestId, 'action' => 'assign', 'administrator_id' => '999999']);
    [, $body] = $http($requestRoute);
    $check($field($body, 'assigned-admin') === '999999' && str_contains($body, 'Unavailable administrator'), 'An unavailable assignment remains visible instead of silently becoming Unassigned.');

    $db->exec('ALTER TABLE request_contact_attempts RENAME TO reliability_unavailable_contacts');
    try {
        $http('admin/requests/action.php', [
            'csrf_token' => $token($body),
            'id' => (string) $requestId,
            'action' => 'contact',
            'contact_method' => 'PHONE',
            'contact_outcome' => 'CONTACTED',
            'follow_up' => '',
            'notes' => $notes
        ]);
    } finally {
        $db->exec('ALTER TABLE reliability_unavailable_contacts RENAME TO request_contact_attempts');
    }
    [, $body] = $http($requestRoute);
    $check($field($body, 'contact-notes') === $notes && str_contains($body, 'could not be updated'), 'A contact persistence failure retains typing and gives a controlled recovery message.');

    // Genuine data failures must remain failures, including their HTTP status.
    $db->exec('ALTER TABLE custom_product_requests RENAME TO reliability_unavailable_requests');
    try {
        $query = ['q' => 'Audit & <test>', 'status' => 'OPEN', 'category' => '1', 'administrator' => (string) $admin, 'date_from' => '2026-12-01'];
        [$status, $body, $headers] = $http('admin/requests/index.php?' . http_build_query($query));
        $check($status === 503 && str_contains($headers['cache-control'] ?? '', 'no-store') && str_contains($body, 'Requests unavailable'), 'Inbox data failures return private HTTP 503 with an unavailable state.');
        $check($field($body, 'request-search') === $query['q'] && $field($body, 'request-status') === 'OPEN'
            && $field($body, 'request-category') === '1' && $field($body, 'request-admin') === (string) $admin
            && $field($body, 'request-date-from') === '2026-12-01', 'Unavailable inboxes preserve safe filters and offer retry without claiming zero matches.');
        $check(!str_contains($body, 'class="admin-result-summary"') && str_contains($body, 'Retry requests'), 'Unavailable request counts are not rendered as successful empty results.');
        [, $body] = $http('admin/requests/index.php?' . http_build_query(['q' => ['malformed'], 'category' => ['malformed']]));
        $check(!str_contains($body, 'Warning:') && $field($body, 'request-search') === '', 'Malformed filters remain controlled during an outage.');
        [, $body] = $http('admin/requests/index.php?' . http_build_query(['q' => str_repeat('شمعة', 40)]));
        $searchValue = $field($body, 'request-search');
        $check(mb_strlen($searchValue, 'UTF-8') === 100 && mb_check_encoding($searchValue, 'UTF-8'), 'Long Unicode filters remain valid UTF-8 when safely shortened during an outage.');
    } finally {
        $db->exec('ALTER TABLE reliability_unavailable_requests RENAME TO custom_product_requests');
    }

    $db->exec('ALTER TABLE materials RENAME COLUMN minimum_stock_level TO reliability_missing_minimum');
    try {
        [$status, $body, $headers] = $http('admin/index.php');
        $check($status === 503 && str_contains($headers['cache-control'] ?? '', 'no-store') && str_contains($body, 'Retry dashboard'), 'Dashboard data failures return private HTTP 503 with a retry action.');
        $check(substr_count($body, 'Data unavailable') === 5 && !str_contains($body, 'No urgent orders')
            && !str_contains($body, 'No payments to verify') && !preg_match('/admin-stat__value">0</', $body), 'Unavailable dashboard metrics never masquerade as zero or healthy empty sections.');
    } finally {
        $db->exec('ALTER TABLE materials RENAME COLUMN reliability_missing_minimum TO minimum_stock_level');
    }
    [$status, $body] = $http('admin/index.php');
    $check($status === 200 && !str_contains($body, 'Data unavailable'), 'A recovered dashboard returns to its normal data state.');
    $check(!str_contains((string) file_get_contents($serverLog), 'Warning:') && !str_contains((string) file_get_contents($serverLog), 'Fatal error:'), 'The HTTP journeys emit no PHP warnings or fatal errors.');
    echo 'Verified ' . $checks . ' admin reliability assertions.' . PHP_EOL;
} finally {
    $server?->stop();
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    drop_throwaway_auth_schema($db, $schema);
    foreach (glob($sessionDirectory . '/*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($sessionDirectory)) {
        rmdir($sessionDirectory);
    }
    if (is_file($serverLog)) {
        unlink($serverLog);
    }
}
