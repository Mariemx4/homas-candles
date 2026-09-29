<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Authentication/session component for Administrator Authorization.
 *
 * DATA FLOW: Authentication entry points and guards use this layer to validate identities, manage session state, and pass authenticated account data into protected routes.
 */

namespace App\Auth;

use App\Repositories\AdministratorAccountRepository;

final class AdministratorAuthorization
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly AdministratorAccountRepository $administrators,
        private readonly AdministratorSession $session,
        private readonly string $loginUrl
    ) {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `requireActiveAdministrator` (require active administrator) and return its result.
     *
     * @return array{id: mixed, first_name: mixed, last_name: mixed, email: mixed, account_status: mixed}
     */
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

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `activeAdministrator` (active administrator) and return its result.
     *
     * @return array{id: mixed, first_name: mixed, last_name: mixed, email: mixed, account_status: mixed}|null
     */
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
