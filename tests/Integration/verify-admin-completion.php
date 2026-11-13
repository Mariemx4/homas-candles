<?php

declare(strict_types=1);
ob_start();
putenv('LOGIN_THROTTLE_KEY=' . bin2hex(random_bytes(32)));
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Database\TransactionManager;
use App\Repositories\{AdminLifecycleRepository, OrderRepository, AdminReportRepository};
use App\Services\{AccountProfileService, BusinessClock, AdminReportService};

[$db, $schema] = create_throwaway_auth_schema(true);
$checks = 0;
$check = TestCheck::printing($checks);
try {
    $check(BusinessClock::today(new DateTimeImmutable('2026-12-31T22:59:59Z'))->format('Y-m-d') === '2026-12-31' && BusinessClock::today(new DateTimeImmutable('2026-12-31T23:00:00Z'))->format('Y-m-d') === '2027-01-01', 'Tunisia midnight crosses the correct business date and year while UTC is still the previous day.');
    $bounds = BusinessClock::utcBounds('2027-01-01', '2027-01-01');
    $check($bounds === ['start' => '2026-12-31 23:00:00', 'end' => '2027-01-01 23:00:00'], 'An inclusive Tunisian report date maps to exact half-open UTC boundaries.');
    $check($db->query("SELECT homas_business_day_start('2026-12-31 23:00:00+00'::timestamptz)::text")->fetchColumn() === $bounds['start'], 'The database quota trigger and PHP calendar use the same midnight boundary.');
    $check(BusinessClock::displayTimestamp('2026-12-31 23:15:00', 'Y-m-d H:i') === '2027-01-01 00:15', 'Stored UTC timestamps display in Tunisia time without rewriting history.');
    $db->exec("INSERT INTO administrators_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Completion','Owner','completion-owner@example.test','98117741','secret-admin-hash');
        INSERT INTO customers_accounts(first_name,last_name,email,phone_number,password_hash) VALUES('Original','Customer','completion-customer@example.test','98117742','secret-customer-hash');
        INSERT INTO customer_addresses(customer_id,street_address,delegation,governorate,postal_code,is_primary) VALUES(1,'1 Test street','Tunis','Tunis','1000',1)");
    $records = new AdminLifecycleRepository($db);
    $profiles = new AccountProfileService($records, new TransactionManager($db));
    $input = ['first_name' => 'Edited', 'last_name' => 'Customer', 'email' => 'completion-customer@example.test', 'phone_number' => '98117742', 'second_phone_number' => ''];
    $result = $profiles->save('customers', 1, $input, 1);
    $history = $records->accountHistory('customers', 1);
    $check($result['success'] && count($history) === 1 && $history[0]['actor_administrator_id'] == 1 && $history[0]['action'] === 'UPDATED', 'A successful administrator profile edit records its actor and one durable history entry.');
    $check(json_decode($history[0]['before_state'], true)['first_name'] === 'Original' && json_decode($history[0]['after_state'], true)['first_name'] === 'Edited' && !str_contains(json_encode($history), 'secret-customer-hash'), 'History retains meaningful before/after values while excluding password hashes.');
    $profiles->save('customers', 1, $input, 1);
    $check($records->accountHistoryCount('customers', 1) === 1, 'Saving identical profile data creates no misleading change record.');
    $staleVersion = AccountProfileService::version($records->find('customers', 1), $records->addresses(1));
    $profiles->save('customers', 1, array_replace($input, ['first_name' => 'Changed elsewhere']), 1);
    $stale = $profiles->save('customers', 1, $input + ['profile_version' => $staleVersion], 1, true);
    $check(!$stale['success'] && $records->find('customers', 1)['first_name'] === 'Changed elsewhere', 'A stale customer self-service form cannot overwrite a newer administrator edit.');
    $fresh = $profiles->save('customers', 1, array_replace($input, ['profile_version' => AccountProfileService::version($records->find('customers', 1), $records->addresses(1))]), 1, true);
    $check($fresh['success'] && $records->accountHistory('customers', 1)[0]['actor_customer_id'] == 1, 'Customer self-service edits record the customer actor through the same history transaction.');
    foreach (['UPDATE account_change_history SET action=\'DELETED\' WHERE id=1', 'DELETE FROM account_change_history WHERE id=1'] as $sql) {
        try {
            $db->exec($sql);
            $blocked = false;
        } catch (PDOException) {
            $blocked = true;
        }
        $check($blocked, 'Existing account history rejects mutation: ' . strtok($sql, ' '));
    }
    $db->exec("CREATE FUNCTION fail_completion_history() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture history failure'; END $$; CREATE TRIGGER completion_history_failure BEFORE INSERT ON account_change_history FOR EACH ROW EXECUTE FUNCTION fail_completion_history()");
    try {
        $profiles->save('customers', 1, array_replace($input, ['first_name' => 'Must roll back']), 1);
        $failed = false;
    } catch (PDOException) {
        $failed = true;
    }
    $check($failed && $records->find('customers', 1)['first_name'] === 'Edited', 'If durable history fails, the profile change rolls back atomically.');
    $db->exec('DROP TRIGGER completion_history_failure ON account_change_history');
    $order = $db->prepare("INSERT INTO orders(order_number,language_code,order_source,order_status,delivery_method,required_date,customer_id,customer_name_snapshot,customer_phone_snapshot,subtotal,delivery_fee,total_amount,required_deposit_amount,created_at) VALUES(:number,'en','MANUAL','REQUESTED','PICKUP','2027-01-10',1,'Edited Customer','98117742',40,0,40,0,:created)");
    foreach (['2026-12-31 22:59:59', '2026-12-31 23:00:00', '2027-01-01 00:00:00', '2027-01-01 22:59:59'] as $i => $time) {
        $order->execute(['number' => 'BOUNDARY-' . $i, 'created' => $time]);
    }
    try {
        (new OrderRepository($db))->assertDailyOrderLimit(1, new DateTimeImmutable('2026-12-31T23:30:00Z'));
        $blocked = false;
    } catch (DomainException) {
        $blocked = true;
    }
    $check($blocked, 'Daily quotas count the three orders inside the Tunisia day across UTC midnight.');
    $report = (new AdminReportService(new AdminReportRepository($db)))->report(['start' => '2027-01-01', 'end' => '2027-01-01'], new DateTimeImmutable('2027-01-01'));
    $check((int)$report['summary']['orders']['order_count'] === 3, 'A real report includes the local-day boundary order and excludes the previous Tunisian day.');
    require_once APP_PATH . '/Bootstrap/admin/admin-lifecycle.php';
    $deactivated = $lifecycleService->mutate('customers', 1, 1, 'deactivate');
    $check($deactivated['success'] && $records->accountHistory('customers', 1)[0]['action'] === 'DEACTIVATED', 'Suspending an account records the responsible administrator without losing prior edits.');
    $check($records->accountHistoryCount('customers', 1) > count($records->accountHistory('customers', 1, 1)) && count($records->accountHistory('customers', 1, 1, 1)) === 1, 'History is bounded per page and older changes remain accessible.');
    echo 'Completed ' . $checks . ' admin completion assertions.' . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    drop_throwaway_auth_schema($db, $schema);
    ob_end_flush();
}
