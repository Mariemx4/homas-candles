<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/HttpTestClient.php';
require_once __DIR__ . '/../Support/TestCheck.php';
require_once __DIR__ . '/../Support/TestPhpServer.php';

use App\Auth\{CustomerRegistrationValidator, GoogleCustomerService, GoogleIdentity, PasswordPolicy};
use App\Database\TransactionManager;
use App\Repositories\{CustomerAccountRepository, GoogleCustomerRepository, AdminLifecycleRepository};
use Firebase\JWT\JWT;

[$db, $schema] = create_throwaway_auth_schema(true);
$count = 0;
$server = null;
$directory = sys_get_temp_dir() . '/homas-google-http-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$check = TestCheck::printing($count);
$reject = static function (callable $operation, string $label) use ($check): void {
    try {
        $operation();
    } catch (DomainException | PDOException) {
        $check(true, $label);
        return;
    }
    $check(false, $label);
};
$governorates = require APP_PATH . '/config/governorates.php';
$details = ['first_name' => 'Leila', 'last_name' => 'Google', 'phone_number' => '22110101',
    'street_address' => '18 Test street', 'governorate' => 'Tunis', 'delegation' => 'Tunis', 'postal_code' => '1000'];

try {
    $transactions = new TransactionManager($db);
    $accounts = new CustomerAccountRepository($db, $transactions);
    $identities = new GoogleCustomerRepository($db);
    $service = new GoogleCustomerService($identities, $accounts, $transactions, new CustomerRegistrationValidator($governorates));
    $identity = new GoogleIdentity('service-customer', 'service-google@example.test', 'Leila', 'Google');
    $check($service->resolve($identity)['customer_id'] === null && (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn() === 0, 'A verified new identity does not create an addressless customer.');
    $invalid = $details;
    unset($invalid['street_address'], $invalid['phone_number']);
    $result = $service->register($identity, $invalid);
    $check(isset($result['errors']['street_address'], $result['errors']['phone_number']) && (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn() === 0, 'Required phone and address checks run before creating account data.');
    $created = $service->register($identity, $details + ['email' => 'forged@example.test', 'subject' => 'forged-subject']);
    $customer = (int) $created['customer_id'];
    $check($created['errors'] === [] && (int) $identities->findBySubject('service-customer')['id'] === $customer, 'A completed signup atomically connects the verified subject.');
    $check($accounts->findByEmail('service-google@example.test') !== null && $accounts->findByEmail('forged@example.test') === null, 'Posted email and provider identifiers cannot override verified identity data.');
    $check((int) $db->query("SELECT COUNT(*) FROM customer_addresses WHERE customer_id=$customer AND is_primary=1")->fetchColumn() === 1, 'New Google accounts have exactly one required primary address.');
    $check($service->register($identity, $details)['customer_id'] === $customer && (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn() === 1, 'Repeated completion does not duplicate a linked account.');
    $db->prepare('UPDATE customers_accounts SET email=:email WHERE id=:id')->execute(['email' => 'changed-contact@example.test', 'id' => $customer]);
    $check($service->resolve($identity)['customer_id'] === $customer, 'A changed contact email does not break the stable Google-subject login.');
    $records = new AdminLifecycleRepository($db);
    $transactions->run(static fn () => $records->accountStatus('customers', $customer, false));
    $reject(static fn () => $service->resolve($identity), 'Suspended customers cannot sign in through Google.');
    $transactions->run(static fn () => $records->accountStatus('customers', $customer, true));

    $localPassword = 'FixtureLocalOnly1!';
    $local = $accounts->createWithPrimaryAddress(
        ['first_name' => 'Existing', 'last_name' => 'Customer',
        'email' => 'existing-google@example.test', 'phone_number' => '22110102', 'password_hash' => PasswordPolicy::hashNew($localPassword)],
        ['street_address' => '20 Test street', 'governorate' => 'Tunis', 'delegation' => 'Tunis', 'postal_code' => '1000']
    );
    $linkIdentity = new GoogleIdentity('existing-google-subject', 'existing-google@example.test', 'Existing', 'Customer');
    $resolved = $service->resolve($linkIdentity);
    $check($resolved['customer_id'] === null && $resolved['link_customer_id'] === $local && $identities->findBySubject($linkIdentity->subject) === null, 'Third-party emails without Google-hosted ownership still require explicit linking.');
    $reject(static fn () => $service->link($customer, $linkIdentity), 'A Google identity cannot be connected to a different customer.');
    $hashBefore = $accounts->findByEmail($linkIdentity->email)['password_hash'];
    $service->link($local, $linkIdentity);
    $check($service->resolve($linkIdentity)['customer_id'] === $local && $accounts->findByEmail($linkIdentity->email)['password_hash'] === $hashBefore, 'Explicit linking preserves the original account and local password.');
    $reject(static fn () => $service->link($local, new GoogleIdentity('different-google-subject', $linkIdentity->email, '', '')), 'A different Google identity cannot silently replace an existing connection.');
    $existingCustomer = static function (string $email, string $phone) use ($accounts, $localPassword): int {
        return $accounts->createWithPrimaryAddress(
            ['first_name' => 'Existing', 'last_name' => 'Owner', 'email' => $email,
                'phone_number' => $phone, 'password_hash' => PasswordPolicy::hashNew($localPassword)],
            ['street_address' => '28 Saved street', 'governorate' => 'Tunis', 'delegation' => 'Tunis', 'postal_code' => '1000']
        );
    };
    $gmail = $existingCustomer('existing-service-fixture@gmail.com', '22110106');
    $gmailIdentity = new GoogleIdentity('existing-gmail-subject', 'existing-service-fixture@gmail.com', 'Provider', 'Name');
    $db->exec("INSERT INTO orders(order_number,customer_id,delivery_method,required_date,customer_name_snapshot,customer_phone_snapshot,subtotal,delivery_fee,total_amount)
        VALUES('GOOGLE-KEEP-ORDER',$gmail,'PICKUP',CURRENT_DATE+7,'Existing Owner','22110106',48,0,48)");
    $snapshot = static fn (): array => [
        $db->query("SELECT (to_jsonb(c)-'last_login_at'-'updated_at')::text FROM customers_accounts c WHERE id=$gmail")->fetchColumn(),
        $db->query("SELECT to_jsonb(a)::text FROM customer_addresses a WHERE customer_id=$gmail ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
        $db->query("SELECT to_jsonb(o)::text FROM orders o WHERE customer_id=$gmail ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
    ];
    $savedGmail = $snapshot();
    $accountCount = (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn();
    $check($service->resolve($gmailIdentity)['customer_id'] === $gmail, 'An existing Gmail customer signs in directly after verified Google ownership.');
    $check((int) $identities->findBySubject($gmailIdentity->subject)['id'] === $gmail, 'Direct Google sign-in connects the original customer ID.');
    $check($snapshot() === $savedGmail && (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn() === $accountCount, 'Direct sign-in preserves password, profile, addresses and order history without duplicate accounts.');
    $check($service->resolve($gmailIdentity)['customer_id'] === $gmail && $service->register($gmailIdentity, $details)['customer_id'] === $gmail, 'Repeat Google sign-in and signup completion reuse the same existing account.');
    $reject(static fn () => $service->resolve(new GoogleIdentity('replacement-gmail-subject', $gmailIdentity->email, '', '')), 'Email matching cannot replace an already linked Google identity.');
    $workspace = $existingCustomer('existing-workspace@company.test', '22110107');
    $workspaceIdentity = new GoogleIdentity('workspace-existing-subject', 'existing-workspace@company.test', '', '', 'company.test');
    $check($service->resolve($workspaceIdentity)['customer_id'] === $workspace, 'Verified Workspace domain ownership also signs in to the existing account directly.');
    $suspended = $existingCustomer('suspended-fixture@gmail.com', '22110108');
    $transactions->run(static fn () => $records->accountStatus('customers', $suspended, false));
    $suspendedIdentity = new GoogleIdentity('suspended-existing-subject', 'suspended-fixture@gmail.com', '', '');
    $reject(static fn () => $service->resolve($suspendedIdentity), 'Verified Gmail ownership never bypasses account suspension.');
    $check($identities->findBySubject($suspendedIdentity->subject) === null, 'A rejected suspended account receives no Google connection.');
    $httpGmail = $existingCustomer('existing-http-fixture@gmail.com', '22110109');
    $httpWorkspace = $existingCustomer('existing-http@company.test', '22110110');
    $rollback = new GoogleIdentity('rollback-subject', 'rollback-google@example.test', 'Rollback', 'Customer');
    $db->exec("CREATE FUNCTION fail_google_link_fixture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.google_subject='rollback-subject' THEN RAISE EXCEPTION 'fixture rejection'; END IF; RETURN NEW; END $$;
        CREATE TRIGGER reject_fixture_google_link BEFORE INSERT ON customer_google_identities FOR EACH ROW EXECUTE FUNCTION fail_google_link_fixture()");
    $beforeAccounts = (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn();
    $beforeAddresses = (int) $db->query('SELECT COUNT(*) FROM customer_addresses')->fetchColumn();
    $reject(static fn () => $service->register($rollback, array_replace($details, ['phone_number' => '22110103'])), 'A provider-link failure rolls back registration.');
    $check((int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn() === $beforeAccounts && (int) $db->query('SELECT COUNT(*) FROM customer_addresses')->fetchColumn() === $beforeAddresses, 'Rollback leaves no orphan account or address.');
    $httpLocal = $accounts->createWithPrimaryAddress(
        ['first_name' => 'Existing', 'last_name' => 'HTTP',
        'email' => 'http-existing-google@example.test', 'phone_number' => '22110105', 'password_hash' => PasswordPolicy::hashNew($localPassword)],
        ['street_address' => '24 Test street', 'governorate' => 'Tunis', 'delegation' => 'Tunis', 'postal_code' => '1000']
    );

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $public = openssl_pkey_get_details($key);
    $base64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $jwks = ['keys' => [['kid' => 'http-fixture-key', 'alg' => 'RS256', 'kty' => 'RSA', 'use' => 'sig',
        'n' => $base64($public['rsa']['n']), 'e' => $base64($public['rsa']['e'])]]];
    $fixtureFile = $directory . '/provider.json';
    $router = $directory . '/router.php';
    // This router is passed only to the disposable PHP CLI server. Production
    // routes have no environment switch for bypassing Google's HTTPS transport.
    $routerSource = '<?php if(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH)==="/__auth-fixture/expire-customer") {'
        . 'session_start();$_SESSION["customer_auth"]=["customer_id"=>1,"authenticated_at"=>time()-10000,"last_activity_at"=>time()-10000];session_write_close();echo "fixture ready";exit;}'
        . 'if(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH)==="/__auth-fixture/forget-flow") {'
        . 'session_start();unset($_SESSION["google_oauth_flows"]);session_write_close();echo "fixture ready";exit;}'
        . 'require_once ' . var_export(APP_PATH . '/Bootstrap/google-auth.php', true) . ';'
        . 'if(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH)==="/auth/google-callback.php") {'
        . '$fake=new class(' . var_export($fixtureFile, true) . ') implements App\\Http\\JsonHttpClient {'
        . 'public function __construct(private string $file){} public function request(string $method,string $url,array $form=[]):array {'
        . '$data=json_decode(file_get_contents($this->file),true,512,JSON_THROW_ON_ERROR);'
        . 'if($method==="POST"&&$url===App\\Auth\\GoogleOidcClient::TOKEN_URL)return ["body"=>["id_token"=>$data["token"]],"max_age"=>null];'
        . 'if($method==="GET"&&$url===App\\Auth\\GoogleOidcClient::KEYS_URL)return ["body"=>$data["jwks"],"max_age"=>300];'
        . 'throw new RuntimeException("Unexpected test request");}};'
        . '$googleSignInController=new App\\Auth\\GoogleSignInController(new App\\Auth\\GoogleOidcClient($googleConfig,$fake,' . var_export($directory . '/keys.json', true) . '),$googleOAuthSession,$googleCustomerService,$customerSession,$csrf,$authFlash,$loginThrottle,BASE_URL);'
        . 'require ' . var_export(APP_PATH . '/Bootstrap/admin/google-auth.php', true) . ';'
        . '$administratorGoogleController=new App\\Auth\\AdministratorGoogleController(new App\\Auth\\GoogleOidcClient($googleConfig,$fake,' . var_export($directory . '/keys.json', true) . '),$googleOAuthSession,$administratorAuth,$csrf,$authFlash,$loginThrottle,BASE_URL);'
        . 'require ' . var_export(PUBLIC_PATH . '/auth/google-callback.php', true) . ';exit;}return false;';
    file_put_contents($router, $routerSource);
    $address = TestPhpServer::reserveAddress();
    // Shared with the LoginThrottle this suite inspects directly below.
    $throttleKey = bin2hex(random_bytes(32));
    $server = TestPhpServer::start($address, $directory, $directory . '/server.log', [
        'PGOPTIONS' => '-c search_path=' . $schema,
        'APP_BASE_URL' => '/',
        'GOOGLE_CLIENT_ID' => 'http-fixture.apps.googleusercontent.com',
        'GOOGLE_CLIENT_SECRET' => 'http-fixture-secret',
        'GOOGLE_REDIRECT_URI' => 'http://' . $address . '/auth/google-callback.php',
        'LOGIN_THROTTLE_KEY' => $throttleKey,
        'HOMAS_VERIFICATION_DATABASE' => null,
    ], [], $router);
    $start = static function (HttpTestClient $browser, string $subject, string $email, string $origin = 'login.php', ?string $hostedDomain = null) use ($fixtureFile, $privateKey, $jwks, $check): array {
        $page = $browser->request($origin);
        $check($page['status'] === 200 && str_contains($page['body'], 'class="google-sign-in"'), 'Configured Google sign-in is rendered on ' . $origin . '.');
        $response = $browser->request($origin === 'admin/login.php' ? 'admin/google-start.php' : 'auth/google-start.php', ['csrf_token' => HttpTestClient::field($page['body'], 'csrf_token'), 'origin' => $origin]);
        $check($response['status'] === 303 && str_starts_with((string) $response['location'], 'https://accounts.google.com/o/oauth2/v2/auth?'), 'Authorization redirects only to Google.');
        parse_str((string) parse_url($response['location'], PHP_URL_QUERY), $parameters);
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'http-fixture.apps.googleusercontent.com',
            'sub' => $subject, 'email' => $email, 'email_verified' => true, 'given_name' => '<Leila>', 'family_name' => 'Google',
            'nonce' => $parameters['nonce'], 'iat' => time() - 5, 'exp' => time() + 600];
        if ($hostedDomain !== null) {
            $claims['hd'] = $hostedDomain;
        }
        $token = JWT::encode($claims, $privateKey, 'RS256', 'http-fixture-key');
        file_put_contents($fixtureFile, json_encode(['token' => $token, 'jwks' => $jwks], JSON_THROW_ON_ERROR));
        return $parameters;
    };
    $base = 'http://' . $address;
    // Real alternate Host headers reproduce the local homas-candles.test ->
    // localhost mismatch. Canonicalization must happen before session_start().
    foreach (['login.php', 'signup.php', 'admin/login.php'] as $route) {
        $context = stream_context_create(['http' => ['header' => "Host: homas-candles.test\r\n", 'follow_location' => 0, 'ignore_errors' => true]]);
        file_get_contents($base . '/' . $route, false, $context);
        $headers = $http_response_header ?? [];
        $check(str_contains($headers[0] ?? '', '302') && in_array('Location: ' . $base . '/' . $route, $headers, true)
            && !preg_grep('/^Set-Cookie:/i', $headers), 'Alternate-host ' . $route . ' is canonicalized before creating a second session.');
    }
    $browser = new HttpTestClient($base);
    $check($browser->request('auth/google-start.php')['status'] === 405, 'Google authorization cannot be started by a GET link.');
    $check($browser->request('auth/google-start.php', ['csrf_token' => 'invalid'])['location'] === '/login.php', 'Invalid CSRF cannot start Google authorization.');
    $flow = $start($browser, 'http-new-customer', 'http-new-google@example.test', 'signup.php');
    $callback = 'auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']);
    $check($browser->request($callback)['location'] === '/auth/google-complete.php', 'A new Google customer is sent to required-details completion.');
    $check($browser->request('account.php')['location'] === '/login.php', 'Pending Google signup cannot access an authenticated account.');
    $completion = $browser->request('auth/google-complete.php');
    $check($completion['status'] === 200 && !str_contains($completion['body'], 'type="password"') && str_contains($completion['body'], '&lt;Leila&gt;'), 'Completion omits local passwords and escapes provider-supplied names.');
    $countBefore = (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn();
    $browser->request('auth/google-complete.php', ['csrf_token' => HttpTestClient::field($completion['body'], 'csrf_token'), 'first_name' => 'HTTP', 'last_name' => 'Customer', 'phone_number' => '22110104']);
    $completion = $browser->request('auth/google-complete.php');
    $check((int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn() === $countBefore && str_contains($completion['body'], 'aria-invalid="true"') && str_contains($completion['body'], '22110104'), 'Incomplete address submission retains entries without creating an account.');
    $posted = array_replace($details, ['first_name' => 'HTTP', 'phone_number' => '22110104', 'email' => 'forged-http@example.test', 'subject' => 'forged-http',
        'csrf_token' => HttpTestClient::field($completion['body'], 'csrf_token')]);
    $check($browser->request('auth/google-complete.php', $posted)['location'] === '/account.php', 'A valid completion signs the customer in only after saving the address.');
    $check($browser->request('account.php')['status'] === 200 && $accounts->findByEmail('forged-http@example.test') === null, 'The authenticated account retains the verified email despite forged POST values.');
    $httpCustomer = (int) $identities->findBySubject('http-new-customer')['id'];
    $check((int) $db->query("SELECT COUNT(*) FROM customer_addresses WHERE customer_id=$httpCustomer AND is_primary=1")->fetchColumn() === 1, 'HTTP registration persists one primary address with its customer identity.');
    $returning = new HttpTestClient($base);
    $flow = $start($returning, 'http-new-customer', 'http-new-google@example.test');
    $check($returning->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']))['location'] === '/account.php', 'Returning linked customers sign in directly without registration.');
    $attacker = new HttpTestClient($base);
    $check($attacker->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']))['location'] === '/login.php' && $attacker->request('account.php')['status'] === 303, 'Authorization state from another browser cannot authenticate a session.');
    $gmailAccountsBefore = (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn();
    $gmailPasswordBefore = $accounts->findByEmail('existing-http-fixture@gmail.com')['password_hash'];
    foreach (['login.php', 'signup.php'] as $origin) {
        $gmailBrowser = new HttpTestClient($base);
        $flow = $start($gmailBrowser, 'http-gmail-existing-subject', 'existing-http-fixture@gmail.com', $origin);
        $response = $gmailBrowser->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']));
        $check($response['location'] === '/account.php' && $gmailBrowser->request('account.php')['status'] === 200, 'Existing Gmail customer reaches the account from ' . $origin . ' without an old-password prompt.');
        $check((int) $identities->findBySubject('http-gmail-existing-subject')['id'] === $httpGmail, 'The Gmail callback authenticates the existing customer ID.');
    }
    $check((int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn() === $gmailAccountsBefore
        && $accounts->findByEmail('existing-http-fixture@gmail.com')['password_hash'] === $gmailPasswordBefore, 'The HTTP Gmail flow preserves local password and creates no duplicate customer.');
    $workspaceBrowser = new HttpTestClient($base);
    $flow = $start($workspaceBrowser, 'http-workspace-existing-subject', 'existing-http@company.test', 'login.php', 'company.test');
    $check($workspaceBrowser->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']))['location'] === '/account.php'
        && (int) $identities->findBySubject('http-workspace-existing-subject')['id'] === $httpWorkspace, 'The HTTP callback uses the verified Workspace claim to sign in to the existing account.');
    $linking = new HttpTestClient($base);
    $flow = $start($linking, 'http-existing-subject', 'http-existing-google@example.test');
    $check($linking->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']))['location'] === '/login.php' && $identities->findBySubject('http-existing-subject') === null, 'An existing email remains unlinked until local authentication and explicit confirmation.');
    $login = $linking->request('login.php');
    $check($linking->request('login.php', ['csrf_token' => HttpTestClient::field($login['body'], 'csrf_token'), 'login_identifier' => 'http-existing-google@example.test', 'password' => $localPassword])['location'] === '/auth/google-link.php', 'Password authentication resumes the pending Google connection.');
    $link = $linking->request('auth/google-link.php');
    $check($link['status'] === 200 && str_contains($link['body'], 'name="confirm_link"'), 'The Google connection has an explicit confirmation screen.');
    $check($linking->request('auth/google-link.php', ['csrf_token' => 'invalid', 'confirm_link' => '1'])['location'] === '/login.php' && $identities->findBySubject('http-existing-subject') === null, 'An invalid CSRF token cannot connect Google to an authenticated account.');
    $link = $linking->request('auth/google-link.php');
    $check($linking->request('auth/google-link.php', ['csrf_token' => HttpTestClient::field($link['body'], 'csrf_token'), 'confirm_link' => '1'])['location'] === '/profile.php' && (int) $identities->findBySubject('http-existing-subject')['id'] === $httpLocal, 'Explicit HTTP confirmation connects Google to the same existing customer.');
    $check(str_contains($linking->request('profile.php')['body'], 'Google is now connected'), 'A successful connection displays confirmation in the customer profile.');
    $check($accounts->findByEmail('http-existing-google@example.test')['password_hash'] !== '' && $linking->request('account.php')['status'] === 200, 'Link confirmation preserves normal customer-account access.');
    $cancelled = new HttpTestClient($base);
    $flow = $start($cancelled, 'cancelled-subject', 'cancelled-google@example.test', 'signup.php');
    $check($cancelled->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'error' => 'access_denied']))['location'] === '/signup.php', 'Cancelled Google consent returns to the originating signup screen.');
    $check($accounts->findByEmail('cancelled-google@example.test') === null, 'Cancelling consent never creates customer data.');
    // Staff access is an allowlist of existing ACTIVE primary emails. There is
    // deliberately no administrator registration route, insert or role conversion.
    $staffInsert = $db->prepare("INSERT INTO administrators_accounts(first_name,last_name,email,phone_number,password_hash,account_status)
        VALUES('Google','Staff',:email,:phone,:hash,:status) RETURNING id");
    $staffIds = [];
    foreach ([['staff-fixture@gmail.com', '22110201', 'ACTIVE'],
        ['staff@company.test', '22110202', 'ACTIVE'],
        ['suspended-staff@gmail.com', '22110203', 'SUSPENDED'],
        ['pending-staff@gmail.com', '22110204', 'PENDING_APPROVAL'],
        ['staff@external.test', '22110205', 'ACTIVE']] as [$email, $phone, $status]) {
        $staffInsert->execute(['email' => $email, 'phone' => $phone, 'hash' => PasswordPolicy::hashNew($localPassword), 'status' => $status]);
        $staffIds[$email] = (int) $staffInsert->fetchColumn();
    }
    $sameEmailCustomer = $existingCustomer('staff-fixture@gmail.com', '22110111');
    $staffCount = (int) $db->query('SELECT COUNT(*) FROM administrators_accounts')->fetchColumn();
    $customerCount = (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn();
    $staffSnapshot = $db->query("SELECT (to_jsonb(a)-'last_login_at'-'updated_at')::text FROM administrators_accounts a ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $admin = new HttpTestClient($base);
    $check($admin->request('admin/google-start.php')['status'] === 405, 'Administrator Google authorization requires POST.');
    $check($admin->request('admin/google-start.php', ['csrf_token' => 'invalid'])['location'] === '/admin/login.php', 'Invalid CSRF cannot start administrator Google sign-in.');
    $flow = $start($admin, 'staff-google-subject', 'staff-fixture@gmail.com', 'admin/login.php');
    $admin->request('__auth-fixture/expire-customer');
    $adminResult = $admin->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']));
    $check($adminResult['location'] === '/admin/index.php' && $admin->request('admin/index.php')['status'] === 200, 'An administrator Google callback survives an expired customer session and reaches the dashboard.');
    $check((bool) preg_grep('/Set-Cookie:.*HttpOnly/i', $adminResult['headers']), 'Administrator Google sign-in rotates a protected session cookie.');
    $check($admin->request('account.php')['location'] === '/login.php', 'Administrator Google sign-in does not authenticate a same-email customer account.');
    $replay = new HttpTestClient($base);
    $replay->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code', 'purpose' => 'administrator']));
    $check($replay->request('admin/index.php')['location'] === '/admin/login.php', 'A query parameter or replay from another browser cannot grant admin access.');
    $workspaceAdmin = new HttpTestClient($base);
    $flow = $start($workspaceAdmin, 'staff-workspace-subject', 'staff@company.test', 'admin/login.php', 'company.test');
    $check($workspaceAdmin->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']))['location'] === '/admin/index.php'
        && $workspaceAdmin->request('admin/index.php')['status'] === 200, 'An existing active Workspace administrator can use the same registered callback.');
    foreach (['existing-http-fixture@gmail.com', 'unknown-staff@gmail.com', 'suspended-staff@gmail.com', 'pending-staff@gmail.com', 'staff@external.test'] as $email) {
        $denied = new HttpTestClient($base);
        $flow = $start($denied, 'denied-' . hash('sha256', $email), $email, 'admin/login.php');
        $check($denied->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']))['location'] === '/admin/login.php'
            && $denied->request('admin/index.php')['location'] === '/admin/login.php', 'Customer-only, unknown, inactive and non-authoritative identities cannot enter the dashboard: ' . $email);
        $db->exec("DELETE FROM login_throttle WHERE realm='ADMIN'");
    }
    $customerOnly = new HttpTestClient($base);
    $flow = $start($customerOnly, 'staff-customer-subject', 'staff-fixture@gmail.com');
    $check($customerOnly->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code', 'purpose' => 'administrator']))['location'] === '/account.php'
        && $customerOnly->request('admin/index.php')['location'] === '/admin/login.php', 'A customer flow never creates an administrator session even when the same email is approved staff.');
    $bothRealms = $start($admin, 'staff-customer-subject', 'staff-fixture@gmail.com');
    $admin->request('auth/google-callback.php?' . http_build_query(['state' => $bothRealms['state'], 'code' => 'fixture-code']));
    $customerPage = $admin->request('account.php');
    $check($customerPage['status'] === 200 && $admin->request('admin/index.php')['status'] === 200, 'Independent customer and staff identities coexist in the same browser.');
    $admin->request('logout.php', ['csrf_token' => HttpTestClient::field($customerPage['body'], 'csrf_token')]);
    $check($admin->request('account.php')['location'] === '/login.php' && $admin->request('admin/index.php')['status'] === 200, 'Customer logout signs out only the customer and preserves dashboard access.');
    $lostStaff = new HttpTestClient($base);
    $flow = $start($lostStaff, 'lost-staff-subject', 'staff-fixture@gmail.com', 'admin/login.php');
    $lostStaff->request('__auth-fixture/forget-flow');
    $check($lostStaff->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']))['location'] === '/admin/login.php'
        && $lostStaff->request('admin/index.php')['location'] === '/admin/login.php', 'Lost admin state returns to staff login without granting access or falling back to customer login.');
    $db->exec("DELETE FROM login_throttle WHERE realm='ADMIN'");
    $cancelledAdmin = new HttpTestClient($base);
    $flow = $start($cancelledAdmin, 'cancelled-staff-subject', 'staff-fixture@gmail.com', 'admin/login.php');
    $check($cancelledAdmin->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'error' => 'access_denied']))['location'] === '/admin/login.php', 'Cancelled staff consent returns to the admin login.');
    $staffId = $staffIds['staff-fixture@gmail.com'];
    $throttle = new App\Auth\LoginThrottle($db, $throttleKey);
    for ($attempt = 0; $attempt < 5; ++$attempt) {
        $throttle->failed('ADMIN', $throttle->accountBucket($staffId, 'staff-fixture@gmail.com'), $throttle->ipBucket('192.0.2.90'));
    }
    $blockedAdmin = new HttpTestClient($base);
    $flow = $start($blockedAdmin, 'blocked-staff-subject', 'staff-fixture@gmail.com', 'admin/login.php');
    $check($blockedAdmin->request('auth/google-callback.php?' . http_build_query(['state' => $flow['state'], 'code' => 'fixture-code']))['location'] === '/admin/login.php', 'Google cannot bypass an existing administrator account throttle.');
    $db->exec("DELETE FROM login_throttle WHERE realm='ADMIN'");
    $check((int) $db->query('SELECT COUNT(*) FROM administrators_accounts')->fetchColumn() === $staffCount
        && (int) $db->query('SELECT COUNT(*) FROM customers_accounts')->fetchColumn() === $customerCount, 'All Google staff journeys create no administrator or customer records.');
    $check($db->query("SELECT (to_jsonb(a)-'last_login_at'-'updated_at')::text FROM administrators_accounts a ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) === $staffSnapshot, 'Google staff sign-in preserves passwords, approval status and profile fields.');
    $passwordCustomer = new HttpTestClient($base);
    $oldPasswordPage = $passwordCustomer->request('login.php');
    $oldPasswordToken = HttpTestClient::field($oldPasswordPage['body'], 'csrf_token');
    $start($passwordCustomer, 'password-back-customer', 'http-existing-google@example.test');
    $check($passwordCustomer->request('login.php', ['csrf_token' => $oldPasswordToken, 'login_identifier' => 'http-existing-google@example.test', 'password' => $localPassword])['location'] === '/index.php'
        && $passwordCustomer->request('account.php')['status'] === 200, 'Returning from Google leaves the original customer password form usable.');
    $passwordAdmin = new HttpTestClient($base);
    $oldStaffPage = $passwordAdmin->request('admin/login.php');
    preg_match('~<form class="admin-form"[^>]*>(.*?)</form>~s', $oldStaffPage['body'], $passwordForm);
    $staffPasswordToken = HttpTestClient::field($passwordForm[1], 'csrf_token');
    $check($staffPasswordToken !== HttpTestClient::field($oldStaffPage['body'], 'csrf_token'), 'Google and password administrator forms use independent CSRF tokens.');
    $start($passwordAdmin, 'password-back-staff', 'staff-fixture@gmail.com', 'admin/login.php');
    $check($passwordAdmin->request('admin/login.php', ['csrf_token' => $staffPasswordToken, 'login_identifier' => 'staff-fixture@gmail.com', 'password' => $localPassword])['location'] === '/admin/index.php'
        && $passwordAdmin->request('admin/index.php')['status'] === 200, 'Returning from Google leaves the original administrator password form usable.');
    $check(!preg_match('/PHP (Warning|Fatal|Notice)|Fatal error/', (string) file_get_contents($directory . '/server.log')), 'Google HTTP journeys finish without PHP warnings or fatal errors.');
    echo 'Google customer authentication: ' . $count . ' checks passed.' . PHP_EOL;
} finally {
    $server?->stop();
    drop_throwaway_auth_schema($db, $schema);
    if (!str_starts_with($directory, sys_get_temp_dir() . '/homas-google-http-')) {
        throw new RuntimeException('Unexpected test cleanup path.');
    }
    foreach (new DirectoryIterator($directory) as $file) {
        if ($file->isFile()) {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}
