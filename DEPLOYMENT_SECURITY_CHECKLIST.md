# Book System Deployment Security Checklist

This checklist is for the live Book System project in `C:\xampp\htdocs\book_system`.

## Already Implemented In Code

- Password hashing and verification are in place for admin and lecturer logins.
- CSRF protection is available and used across sensitive form actions.
- Prepared statements are used widely for login, request, and update flows.
- Role-based access checks are enforced for super admin, rep, and lecturer pages.
- Rep ownership scoping is used so reps should not access each other's records.
- Audit logging is available for important actions.
- Session handling is now hardened through `security_bootstrap.php`:
  - `HttpOnly` cookies
  - `SameSite=Lax`
  - secure cookies when HTTPS is active
  - strict session mode
  - cookie-only sessions
- Security response headers are now sent centrally:
  - `X-Frame-Options`
  - `X-Content-Type-Options`
  - `Referrer-Policy`
  - `Permissions-Policy`
  - `Cross-Origin-Resource-Policy`
- Session regeneration is now enforced after successful admin and lecturer login.
- Login throttling is now implemented through the `login_attempts` table.
- Database connection errors are now logged privately and shown safely in production.

## Must Do Before Public Deployment

- Force HTTPS for the whole site at the web server or reverse proxy level.
- Set production database credentials through environment variables instead of relying on defaults:
  - `BOOK_SYSTEM_DB_HOST`
  - `BOOK_SYSTEM_DB_PORT`
  - `BOOK_SYSTEM_DB_NAME`
  - `BOOK_SYSTEM_DB_USER`
  - `BOOK_SYSTEM_DB_PASS`
- Set `BOOK_SYSTEM_DEBUG=false` in production.
- Change or remove any default seeded super-admin credentials before the system goes live.
- Use a dedicated database user with only the permissions the application needs.
- Restrict access to phpMyAdmin, XAMPP utilities, and server admin tools.
- Disable directory listing on the web server.
- Protect uploaded advert images and application files with correct file permissions.
- Enable regular off-server backups for both database and uploaded files.
- Review error logs regularly after deployment.
- Keep PHP, MariaDB, Apache, and the server OS patched and updated.

## High-Risk Deployment Notes

- `database_setup.sql` currently seeds a predictable super-admin account:
  - username: `Roland`
  - role: `super_admin`
- That account must be changed, rotated, or removed before public deployment.
- If the seeded admin is kept unchanged on a public server, it becomes a major attack risk.

## Still Recommended Next

- Add MFA for the super admin account.
- Add stronger account lockout rules after repeated failed logins.
- Add centralized monitoring and alerting for repeated login failures.
- Tighten password policy requirements for new accounts and resets.
- Add a stricter Content Security Policy after reviewing inline scripts and styles.
- Add a production log review routine for suspicious admin, rep, and lecturer activity.
- Consider IP allow-listing for the most sensitive admin tools if the deployment model allows it.

## Deployment Sanity Checks

- Confirm login works over HTTPS only.
- Confirm session cookies are marked `HttpOnly`.
- Confirm the app works with `BOOK_SYSTEM_DEBUG=false`.
- Confirm admin, rep, and lecturer users can only access their own allowed pages.
- Confirm rate limiting blocks repeated failed login attempts.
- Confirm backups can actually be restored, not just created.
- Confirm uploaded advert images render correctly and cannot execute as scripts.

## Final Go-Live Reminder

This system is now better protected than the original XAMPP-ready build, but production safety still depends on server configuration, credential hygiene, backups, and ongoing monitoring. Application hardening alone is not enough without secure deployment practices.
