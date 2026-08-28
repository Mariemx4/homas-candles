<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\AdministratorAccountRepository;

final class AdministratorAuthorization
{
    public function __construct(
        private readonly AdministratorAccountRepository $administrators,
        private readonly AdministratorSession $session,
        private readonly string $loginUrl
    ) {
    }

    /** @return array{id: mixed, first_name: mixed, last_name: mixed, email: mixed, account_status: mixed} */
    public function requireActiveAdministrator(): array
    {
        $administratorId = $this->session->requireAdministrator();
        $administrator = $this->activeAdministrator($administratorId);

        if ($administrator !== null) {
            return $administrator;
        }

        header('Location: ' . $this->loginUrl . '?access_revoked=1', true, 303);
        exit;
    }

    /** @return array{id: mixed, first_name: mixed, last_name: mixed, email: mixed, account_status: mixed}|null */
    public function activeAdministrator(?int $administratorId = null): ?array
    {
        $administratorId ??= $this->session->administratorId();
        if ($administratorId === null) {
            return null;
        }

        $administrator = $this->administrators->findForAuthorization($administratorId);

        // Re-check database state on every protected request so suspension or
        // deletion revokes access immediately, not only after session expiry.
        if ($administrator !== null && (string) $administrator['account_status'] === 'ACTIVE') {
            return $administrator;
        }

        $this->session->clearAuthentication();
        return null;
    }
}
