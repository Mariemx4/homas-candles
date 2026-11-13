<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminLifecycleRepository;
use App\Database\TransactionManager;
use DomainException;
use PDOException;

/** Completes missing deletion/confirmation behavior and delegates existing activation rules. */
final class AdminLifecycleService
{
    public function __construct(
        private readonly AdminLifecycleRepository $records,
        private readonly TransactionManager $transactions,
        private readonly AdminProductService $products,
        private readonly MaterialService $materials,
        private readonly TaxonomyService $taxonomy
    ) {
    }
    public function detail(string $kind, int $id, int $actor): array
    {
        if (!$this->records->authorize($actor)) {
            throw new DomainException('Active administrator access is required.');
        }
        $row = $this->records->find($kind, $id) ?? throw new DomainException('The record could not be found.');
        $dependencies = $this->records->dependencies($kind, $id);
        $protected = $kind === 'requests' || ($kind === 'administrators' && ($id === $actor || ($row['account_status'] === 'ACTIVE' && $this->records->activeAdministrators() <= 1)));
        return ['record' => $row,'dependencies' => $dependencies,'can_delete' => !$protected && !$dependencies,'can_deactivate' => !($kind === 'administrators' && $id === $actor),
            'active' => isset($row['is_active']) ? (bool)$row['is_active'] : (isset($row['account_status']) ? $row['account_status'] === 'ACTIVE' : empty($row['archived_at']))];
    }
    public function mutate(string $kind, int $id, int $actor, string $action): array
    {
        try {
            return $this->transactions->run(function () use ($kind, $id, $actor, $action): array {
                // Serialize the last-active-admin decision before locking any individual account.
                if ($kind === 'administrators') {
                    $this->records->lockAdministrators();
                }
                if (!$this->records->authorize($actor)) {
                    throw new DomainException('Active administrator access is required.');
                }
                $row = $this->records->find($kind, $id, true) ?? throw new DomainException('The record could not be found.');
                if (!in_array($action, ['delete','activate','deactivate','archive','restore'], true)) {
                    throw new DomainException('Choose a valid lifecycle action.');
                }
                if ($kind === 'administrators' && in_array($action, ['delete','deactivate'], true)) {
                    if ($id === $actor) {
                        throw new DomainException('You cannot delete or deactivate your current administrator account.');
                    }
                    if ($row['account_status'] === 'ACTIVE' && $this->records->activeAdministrators() <= 1) {
                        throw new DomainException('The last active administrator must remain active.');
                    }
                }
                if ($kind === 'requests') {
                    if (!in_array($action, ['archive','restore'], true)) {
                        throw new DomainException('Custom requests retain their history. Archive the request instead.');
                    }
                    $this->records->archiveRequest($id, $actor, $action === 'archive');
                } elseif ($action === 'delete') {
                    $references = $this->records->dependencies($kind, $id);
                    if ($references) {
                        throw new DomainException('This record is referenced by ' . implode(', ', array_map(static fn ($table, $count) => $count . ' ' . str_replace('_', ' ', $table), array_keys($references), $references)) . '. Deactivate it instead.');
                    }
                    if (in_array($kind, ['customers','administrators'], true)) {
                        $this->records->recordAccountChange($kind, $id, $actor, false, 'DELETED', $this->records->accountSnapshot($kind, $id), null);
                    }
                    $this->records->delete($kind, $id);
                } elseif (in_array($action, ['activate','deactivate'], true)) {
                    $active = $action === 'activate';
                    $result = match($kind) {
                        'products' => $this->products->toggleActive($id, $active),
                        'materials' => $this->materials->toggleActive($id, $active),
                        'categories','product_types','colors','scents' => $this->taxonomy->toggleActive($kind, $id, $active),
                        'customers','administrators' => $this->accountStatus($kind, $id, $active, $actor),
                        'promotions' => $this->promotionStatus($id, $active),
                        default => throw new DomainException('This action is unavailable.'),
                    };
                    if (!$result['success']) {
                        throw new DomainException(implode(' ', $result['errors']));
                    }
                    return ['success' => true,'errors' => [],'notice' => $result['notice'] ?? 'Status updated.'];
                } else {
                    throw new DomainException('This action is unavailable for this record.');
                }
                return ['success' => true,'errors' => [],'notice' => $action === 'delete' ? 'Unused record permanently deleted.' : 'Request history preserved; archive status updated.'];
            });
        } catch (DomainException $e) {
            return ['success' => false,'errors' => ['form' => $e->getMessage()]];
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['23503','40001','40P01'], true)) {
                return ['success' => false,'errors' => ['form' => 'The record has dependencies or changed while you were reviewing it. Reload and deactivate or archive it instead.']];
            }throw $e;
        }
    }
    private function accountStatus(string $kind, int $id, bool $active, int $actor): array
    {
        $before = $this->records->accountSnapshot($kind, $id);
        $this->records->accountStatus($kind, $id, $active);
        $this->records->recordAccountChange($kind, $id, $actor, false, $active ? 'ACTIVATED' : 'DEACTIVATED', $before, $this->records->accountSnapshot($kind, $id));
        return ['success' => true];
    }
    private function promotionStatus(int $id, bool $active): array
    {
        $this->records->promotionStatus($id, $active);
        return ['success' => true];
    }
}
