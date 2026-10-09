# External identity with Cloudflare Access

FileGator owns accounts and authorization. The Composer package `cf-access/auth` verifies Cloudflare application JWTs and returns identity only. It does not grant roles, permissions, or filesystem access. No manual source copy, sibling checkout, Cloudflare API token, or additional authentication service is needed.

## Install and configure

Run `composer install` and `CYPRESS_INSTALL_BINARY=0 npm ci --legacy-peer-deps --ignore-scripts`, then `NODE_OPTIONS=--openssl-legacy-provider npm run build`. PHP 8.1+ requires curl, OpenSSL, mbstring, PDO SQLite, and FileGator's existing zip extension. Node 22 is the supported build runtime.

Set these environment variables in the PHP worker/container environment:

```
FILEGATOR_AUTH_MODE=external
CF_TEAM_DOMAIN=https://yourteam.cloudflareaccess.com
CF_ACCESS_AUD=the-specific-filegator-application-aud
CF_ADMIN_EMAILS=owner@example.com
CF_CSRF_KEY=a-long-random-secret
```

Generate the CSRF secret once, for example with `openssl rand -hex 32`, and persist it in deployment configuration. Never use fixture values in production. Missing or invalid external settings fail closed. The tracked `configuration_external.php` enables external authentication; the Docker image selects it by default. `FILEGATOR_AUTH_MODE=local` retains the legacy configuration/adapter path for other installations. This working checkout's ignored configuration.php also selects external authentication.

Serve only `dist/`. Configure Cloudflare Access for every application/API path, use this application's AUD, and keep the origin reachable only through the intended tunnel. Do not configure a bypass for the origin. Preserve assertion headers and reject duplicate assertion headers upstream. Production sessions use Secure, HttpOnly, SameSite=Lax cookies; the public URL must be HTTPS.

Accounts default to `private/accounts.sqlite`, JWKS cache to `private/cf-access-cache`, and files to `repository`. Override the roots with `FILEGATOR_PRIVATE_ROOT` and `FILEGATOR_STORAGE_ROOT` if needed. Keep them outside the document root and writable only by the application OS user. Mount persistent private and repository volumes in Docker. Run `docker build -t filegator:access .` to build this fork with its locked module release; no upstream application is cloned.

On a fresh installation, the first approved configured admin email creates an administrator; other new approved identities receive ordinary users and separate `/users/<opaque-id>/` homes. New users get read/write/upload/download/batchdownload/zip, excluding chmod. Changing CF_ADMIN_EMAILS later never promotes an existing account.

## Migrate before opening access

Stop account writes and back up configuration, users.json, repository, and any existing SQLite database. Map every non-email username to its verified Cloudflare email:

```json
{"old-admin":"owner@example.com","old-user":"person@example.com"}
```

Run a dry-run, inspect the proposed links, then apply explicitly:

```sh
php bin/import-users --source=private/users.json --email-map=email-map.json --database=private/accounts.sqlite
php bin/import-users --source=private/users.json --email-map=email-map.json --database=private/accounts.sqlite --apply
```

Dry-run creates no persistent database and opens existing databases read-only. Import is all-or-nothing and rejects conflicts, missing email mappings, duplicate normalized emails, invalid roles/permissions, and unsafe homes. It preserves usernames, names, roles, permissions, and homes, drops passwords, and skips guests. Files are not moved. Existing shared homes remain shared. If upgrading an installation that already used the old module SQLite store, preserve that database as private/accounts.sqlite; its users/bindings schema is compatible, and it must not be replaced with an empty store. Imported accounts receive no bootstrap promotion; ensure at least one imported administrator can sign in before cutover.

Bindings use `(issuer, subject)`. Unique unbound email accounts can link on first login; a different subject cannot claim an already-bound account. Username edits do not change the email or binding. New manually created accounts require their verified email as username. External-mode home paths entered by admins are relative to the storage root, including when an administrator has a private home. Root/shared-home grants are deliberate administrator decisions.

## Account operations and logout

Admins can create, edit, and delete accounts without passwords. Delete disables access, retaining bindings, reserved email, and files. Last-active-admin deletion/demotion is rejected transactionally. Offline operations:

```sh
php bin/account --database=private/accounts.sqlite --username=person@example.com --disable
php bin/account --database=private/accounts.sqlite --username=person@example.com --enable
php bin/account --database=private/accounts.sqlite --username=person@example.com --relink --issuer=https://yourteam.cloudflareaccess.com --subject=verified-new-subject
```

Verify replacement subjects independently before relinking. Local disable and Cloudflare admission are separate controls. Remove identities from Access policies when edge admission should end. Logout clears FileGator's session before navigating to `/cdn-cgi/access/logout`; this may end sessions across Access apps, while the Google session can persist.

Every PHP request requires fresh JWT verification and current local authorization. Identity or authorization-scope changes regenerate the session and clear CSRF/archive ownership. Authentication failures return generic 401/403/503 responses with no-store. Logs contain diagnostic codes/status only for expected authentication failures. Warm cached keys work during outages until expiry; cold/expired caches fail closed without password fallback.

## Tests

```
vendor/bin/phpunit --no-coverage
php tests/external/integration.php
npm run lint
npm run build
```

`tests/external/integration.php` also runs account/migration/concurrency tests. For the browser suite, start a localhost-only fixture server with temporary private/storage roots and the fixture domain/AUD/admin/CSRF settings:

```sh
mkdir -p /tmp/filegator-test-storage
CF_TEAM_DOMAIN=https://fixture.cloudflareaccess.com CF_ACCESS_AUD=test-audience CF_ADMIN_EMAILS=alice@example.com CF_CSRF_KEY=test-only-csrf FILEGATOR_PRIVATE_ROOT=/tmp/filegator-test-private FILEGATOR_STORAGE_ROOT=/tmp/filegator-test-storage php -d display_errors=0 -S 127.0.0.1:8088 -t dist tests/external/server.php
npx playwright install chromium
node tests/external/browser.mjs
```

The fixture router replaces JWKS transport and relaxes secure cookies for localhost only. Never deploy it. Browser tests cover startup, password rejection, private-home isolation, uploads/downloads, admin creation, identity/session/CSRF changes, temporary artifacts, and simulated edge logout. Live acceptance separately requires real Access policies, signed assertions, and Google/edge logout behavior.

## Rollback

Stop writes, back up SQLite and newly created homes, then restore the previous application/configuration/users.json and rebuild or restore its frontend. Keep Cloudflare protection in place. Reconcile account changes made after migration before reverting; they are not copied back to users.json. Preserve uploaded files and the new account database.

## Dependency audit baseline

The new cf-access/auth dependency has no reported Composer advisories. FileGator retains four production Composer advisories in its existing Flysystem, Symfony HTTP Foundation, and intl-IDN polyfill dependencies, plus one development advisory. The existing npm tree reports 191 advisories. Resolving this legacy dependency baseline is separate from the identity integration; Access protection does not fix those packages. The dependency-resolution step retained the existing lock versions and used Composer's explicit --no-security-blocking flag; no global audit policy was disabled.
