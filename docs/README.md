# Project documentation

Start here:

- [Beginner handbook](beginner-handbook.md): follow a request and make a safe change.
- [Architecture and ownership](architecture.md): folders, classes and CSS responsibilities.
- [Routing reference](routing.md): public endpoints and compatibility URLs.
- [Running and operating the shop](operations.md): deployment checks, search indexing, sign-in limits, passwords, email, administrator access.
- [Docker operations](local-docker.md): save/sync, configuration, backups and recovery.
- [Testing](../tests/README.md): structural, integration and browser checks.
- [12–13 November refactor record](refactor-2026-11-12.md): what changed in the clean-up, how it was verified and what is still open.
- [Admin UI contracts](admin-dashboard/ui-maintenance.md): forms, tabs and responsive cards.
- [Customer account workspace](customer-account-workspace.md): customer dashboard, profile and address flows.
- [Google sign-in](google-sign-in.md): OAuth setup, required contact/address completion and existing-account linking.
- [Database design](database-design.md) and [requirements](database-requirements.md).

## Archive

`archive/` keeps dated plans, audits, screenshots and earlier clean-up records
(including the [10 November cleanup record](archive/code-cleanup-2026-11-10.md)
and the refactor brief). They are implementation history: counts, file names
and folder descriptions in them may no longer match the code. Use the guides
above for maintenance.

Older delivery evidence and source snapshots that used to sit in
`app/storage/backups/` were moved out of the project on 2026-11-13 (see the
refactor record). Database backups made by `scripts/backup-database.php` still
go to that folder.
