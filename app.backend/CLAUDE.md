# Acadly API

The rules for this code base are in the repository root: `../CLAUDE.md` and `../docs/architecture/`.

- `kernel/` is `Acadly\Kernel`. `core/` is `Acadly\Core`. `modules/` is `Acadly\Modules`. `app/` is the Laravel shell and holds no domain code.
- Every module is listed in `config/acadly.php`.
- Run before a pull request: `composer lint` and `php artisan test`.
- After a change to a route or a response: `composer contract`, then commit `../contracts/openapi.json`.
