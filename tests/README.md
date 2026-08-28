# Tests

- `Unit/` contains isolated checks with no database dependency.
- `Integration/` contains checks that use sessions, the database, or the complete application bootstrap.
- Existing `scripts/verify-*.php` commands are compatibility launchers, so documented commands and developer habits keep working.

Run a check with the XAMPP PHP executable, for example:

```powershell
C:\xampp\php\php.exe scripts\verify-customer-auth.php
```
