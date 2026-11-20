# Legacy HTTP characterization (RAPIS-521, RAPIS-522, RAPIS-524)

This independent PHPUnit 9.6 / Symfony 6.4 BrowserKit project records the **current CI3 behavior**, not the desired Symfony behavior.
No application bootstrap or CI3/Symfony application classes are loaded. Requests use HTTP/1.1 with redirects disabled; MySQLi is used
only to seed/clean fixtures, simulate session age, and assert persisted rows. No browser, root Composer changes, or production dependencies are needed.

## One-command local run

Start the existing `sso-docker/idp` stack first, with its normal local databases and WAF metadata restored:

```sh
docker compose -f ../sso-docker/idp/compose.yaml up -d
sh tests/characterization/run-local.sh
```

Run the second command from the `idp` checkout. It installs this directory's locked dependencies in the existing PHP container, uses
the container's `DB_*` settings (including its MySQL CA), generates a temporary test password, and runs all tests against the local nginx.
It does not build images, import a dump, change schemas, change the app environment, or alter the sibling repository.
`SSO_DOCKER_COMPOSE` overrides the compose-file location. The existing stack must mount this checkout at `/srv/idp`, and its PHP must have
MySQLi, DOM, XML, XMLWriter, mbstring and Composer (the current PHP 8.2 local image does). PHP 8.1+ is supported.

Target a subset with PHPUnit arguments:

```sh
sh tests/characterization/run-local.sh --filter RbacTest
sh tests/characterization/run-local.sh --filter 'Waf'          # all adm_waf_* characterization (RAPIS-522)
sh tests/characterization/run-local.sh --filter WafRoleTest    # one screen
sh tests/characterization/run-local.sh --filter 'Ref'          # the seven adm_ref_* reference screens (RAPIS-524)
```

The full suite takes several minutes (roughly 1,000 tests): every module action is exercised as allowed, denied and signed-out.

Tests fail, rather than silently skip, if configuration, schema, action registration, HTTP or database access is missing.
The three databases must already contain the real legacy schema. `admins.index` and every action a test class grants
(`Fixtures::MODULE_ACTIONS`, e.g. the eleven `adm_ref_cp_status` actions or `adm_waf_role_permission.do_assign_modact`) must be
registered, active, and attached to active action types under application 2. Fixtures never register or change shared module/action metadata,
and tests only request registered actions: in development mode an unregistered action would be auto-registered into the shared metadata.

## Direct execution / environment

For a PHP installation outside the local wrapper:

```sh
cd tests/characterization
composer install --no-interaction
# Export the variables below for your local stack.
composer test
```

| Variable | Meaning |
|---|---|
| `CHAR_BASE_URL` | Local origin only, no path or credentials (see safety gates) |
| `CHAR_ALLOW_FIXTURES` | Must be `yes`; explicit acknowledgement of fixture writes/deletes |
| `CHAR_TEST_PASSWORD` | Temporary fixture password, at least 16 characters |
| `CHAR_DB_HOST`, `CHAR_DB_PORT` | MySQL host and optional port (default 3306) |
| `CHAR_DB_USER`, `CHAR_DB_PASSWORD` | Local fixture DB account |
| `CHAR_DB_WAF`, `CHAR_DB_IP`, `CHAR_DB_AUDIT` | Existing WAF, ip_data and audit database names |
| `CHAR_DB_SSL_CA` | Optional readable MySQL CA file; enables verified TLS (the local stack requires it) |
| `CHAR_HTTP_IP` | Optional IP override for the base-URL hostname; wrapper resolves `nginx` |

The base URL is the only HTTP routing setting. Database variables must identify the same installation's three databases;
never point the HTTP client at one installation and the DB assertions at another.
There are no fixed user IDs or existing admin credentials: the script seeds three isolated identities using `CHAR_TEST_PASSWORD`.

### Hard safety gates

- **Local only.** Only `http(s)://localhost`, `http(s)://127.0.0.1` or `http(s)://idp-local.rschooltoday.test` (optional port) can be used.
  Redirects are inspected, never followed, so a response cannot send credentials to a different host.
- Database names must contain a complete `_`-separated `local` or `test` component, and the DB host must be the local Docker
  database (`localhost`, `127.0.0.1`, `db` or the local MySQL certificate name).
- Each test gets a random `ZZTEST_<8 hex>` namespace. All users, groups, roles, status codes and WAF rows (modules, module actions, menus,
  roles, groups, `sys_tref_*` references, mail templates) are owned by that namespace. Relations only connect these rows to existing
  application/action/menu metadata. Development-only auto-grants therefore affect only a fixture role. Real RBAC rows and real admins' permissions
  are never edited or deleted; mail tests use random unused template types and only read the real templates.
- Ownership is the exact `sys_entry_user`: the prefix for seeded rows, or a fixture username for rows created through the screens.
  Every owned code (subject for mail templates) must start with `ZZTEST_<8 hex>_`; cleanup refuses otherwise.
- Cleanup uses exact ownership and primary-key matches, not `LIKE`, broad prefix deletes, truncation or schema changes.
  Relation rows (`sys_tmst_role_menu`, `sys_tmst_role_module_action`, `sys_tmst_group_role`, `sys_tmst_group_app`, `sys_tmst_user_group`)
  are deleted only by owned role/group/menu/module-action IDs, before the owned rows (dependants first).
  Audit cleanup is restricted by fixture user ID **and** username, application 2, and the characterized tables (`Fixtures::AUDIT_TABLES`).
  Session cleanup touches only fixture user IDs or session cookies obtained by this test client.
- Never run this suite against staging or production; it is not supported and the gates refuse those targets.

## Fixtures and recovery

Each test seeds in `setUp()` and cleans in `tearDown()`, including when assertions fail. User/role/group seeding is transactional.
The prefix is locked with MySQL `GET_LOCK` while in use. Seeding the same prefix replaces **only its own** fixtures, so repeated seed/cleanup is safe.
A separate process-lifetime MySQL lock serializes HTTP suites against the same data database (wait up to 120 seconds, then fail).
This also prevents create collisions on local dumps with missing GUID-generation triggers; random prefixes alone cannot isolate an empty generated GUID.
Connections and prefix locks are closed after each test; the suite lock is released when PHP exits.
Tests explicitly verify repeatability and preservation of a second fixture namespace (`CpStatusTest`, `WafFixtureCleanupTest`).

For manual inspection, export the direct-execution variables and use a known prefix:

```sh
php fixtures.php seed ZZTEST_1234abcd
php fixtures.php cleanup ZZTEST_1234abcd
```

The seed command creates active allowed, active denied and inactive users, their own groups/roles/grants, and one status row.
The script prints the prefix, never the password. Re-run cleanup after inspection. If PHP is forcibly killed or the DB becomes unavailable,
normal teardown cannot finish: find the exact `ZZTEST_<8 hex>` ownership marker in the fixture rows and run the same cleanup command.
Do not delete all `ZZTEST_` rows: another suite may be running.

## Coverage and observed behavior

- **Login:** form, required fields separately/together, unknown user, bad password, inactive account, success/cookie/session data,
  already-authenticated redirect, logout including replay of the old cookie, and 7200-second cookie/DB session lifetime.
  Session age is changed only for the test-owned session. Another client triggers the legacy expired-session cleanup before checking rejection.
- **RBAC:** allowed, denied and signed-out requests for every one of the eleven actions (including real valid mutation requests, not cancel branches).
  Denied/signed-out requests assert unchanged status and audit data. HTTP/1.1 GET redirects are **307**, POST redirects **303**.
  Successful login and logout use **HTTP 200 + `Refresh`**, not a 302. A denied action is **500** with the generic error page, not 403.
- **CRUD:** 25-row pagination, active/history tabs, both name sort directions and name filtering; required/unique/50-character code boundaries,
  required name/description/status for both create and update, inactive status `0`, create/update/delete/restore/purge persisted fields and audit actions
  `C/U/S/R/D`. Updating a row with its unchanged code is accepted. Soft delete writes delete audit columns without changing update columns;
  restore keeps the delete columns and leaves the row inactive. Purge audit contains the deleted row snapshot.
- **Normalization:** visible text ignores scripts/styles/hidden CSRF values and replaces base URL, fixture namespace, timestamps, GUIDs and random
  error-reference IDs. Tests compare explicit response contracts, not recorded pages containing credentials or database dumps.
  Raw mutation reload scripts and redirect targets are asserted separately.
- **WAF admin modules (RAPIS-522):** one class per controller (`Http/Waf*Test.php`).
  | Screen (route) | Class | Coverage |
  |---|---|---|
  | `adm_waf_configs` | `WafConfigsTest` | application info, configuration menu filtered by the role's menu grants |
  | `adm_waf_configs/waf_ref_{access_type,action_type,menu_type,menu_open_type,menu_position,privileges}` | `WafRef*Test` | full CRUD contract below |
  | `adm_waf_module` | `WafModuleTest` | CRUD contract, access/privilege filters, attached actions on the detail view |
  | `adm_waf_modact` | `WafModactTest` | CRUD contract inside a module's popup, per-module code uniqueness |
  | `adm_waf_menu` | `WafMenuTest` | CRUD contract, type/position filters, module-action link, purge blocked by a role grant |
  | `adm_waf_group` | `WafGroupTest` | CRUD contract |
  | `adm_waf_role` | `WafRoleTest` | CRUD contract |
  | `adm_waf_group/adm_waf_group_role` | `WafGroupRoleTest` | listing, add/remove role in a group, JSON/HTTP contract, rows and audit |
  | `adm_waf_role/adm_waf_role_permission` | `WafRolePermissionTest` | menu/module views, grant/revoke module actions and menus, rows and audit |
  | `adm_waf_mail` | `WafMailTest` | template per type, create/update, validation, identity-server actions |
  | (end to end) | `WafRbacEffectTest` | grants/revokes made through the screens change what another `ZZTEST_` admin can open |
  | (end to end) | `WafModuleRbacReadTest` | module/action rows created, renamed, deactivated and deleted through `/adm_waf_module` and `/adm_waf_modact` change CI3's role-permission screen and grant endpoint and Symfony's `LegacyRbac` (`/_next/whoami`) alike |

- **Reference modules (RAPIS-524):** the remaining `adm_ref_*` screens use the same CRUD contract on their `ip_data` `tref_*` tables
  (`WafCrudTestCase::ipReferenceSpec()`, audit `table_schema` = `CHAR_DB_IP`). `adm_ref_cp_status` keeps its RAPIS-521 `CpStatusTest`.
  | Screen | Table | Class | Extra coverage |
  |---|---|---|---|
  | `adm_ref_app_status` | `tref_app_status` | `RefAppStatusTest` | code/name sorts |
  | `adm_ref_product_type` | `tref_product_type` | `RefProductTypeTest` | name sort |
  | `adm_ref_product_status` | `tref_product_status` | `RefProductStatusTest` | name sort |
  | `adm_ref_client_type` | `tref_client_type` | `RefClientTypeTest` | name sort |
  | `adm_ref_client_status` | `tref_client_status` | `RefClientStatusTest` | code/name sorts |
  | `adm_ref_cp_stage` | `tref_cp_app_stage` | `RefCpStageTest` | code/name sorts |
  | `adm_ref_client_cat` | `tref_client_category` | `RefClientCatTest` | required parent, parent options (ROOT + every category, deleted ones too), list parent label, direct self-parent rejection, deeper cycles/unknown parents accepted, parent in the create audit |

  The shared **CRUD contract** (`Support/WafCrudTestCase.php`, configured per screen by `spec()`): allowed, denied and signed-out requests for
  all eleven actions; list default order, both tabs, 25-row pagination (50 for module actions), name filter per tab, code/name sort in both
  directions on both tabs; required fields, uniqueness, the 50-character limit and the longest accepted code for create and update;
  create/update/soft delete/restore/purge rows with audit actions `C/U/S/R/D` in `sys_log_audit_table`; cancel buttons.
  Relationship endpoints return **200** `{success: true, csrfTokenName, csrfHash}` when they change a row and **400** `success: false`
  for a duplicate grant, a missing revoke or any `p_granted_type` other than `1`/`0`.
- **Harness-only unit suite:** allowed/refused targets and normalization; no HTTP/database configuration required (`composer test:unit`).

### Preserve these legacy quirks

These are intentional characterization expectations, **not fixes or approved future UX**:

1. The active list uses the **history** filter/pagination parameters: `FILTER` ignores its name filter; `FILTER_HIST` filters both tabs;
   `per_page` does not page the active list, but `per_page_hist` pages both. Tests pin those behaviors.
2. Development mode returns an access-denied error and then grants the denied action to the user's role. Each test owns a fresh role and
   sends just one denied action; no existing roles are used or modified.
3. Development disables CSRF and renders an empty `ci_csrf_token`, not `csrf_token_idp`. The helper reads the actual form field.
   If CSRF is enabled (e.g. `CI_ENV=testing` over HTTPS) the form renders `csrf_token_idp` and a tokenless submission gets 403; the test covers both.
4. Some local schema dumps omit GUID-generation triggers. Create is tested with exactly the browser's form fields; tests do not inject a GUID
   to hide this. The remaining lifecycle uses a seeded stable GUID, so an empty generated key cannot target an unrelated row.
   An existing empty-GUID row or strict schema can make create fail; restore the authentic environment/schema, do not change the app or test payload to bypass it.
5. Legacy expired-session cleanup occurs after PHP reads the current session, so replaying an expired row before another request cleans it can
   survive one request. The expiry test triggers cleanup through a separate client rather than asserting Symfony's stricter read-time behavior.

6. WAF screens insert without a GUID and the local dump has no GUID triggers, so a screen-created row gets an empty GUID.
   A table then accepts only **one** screen create until that row is removed (later creates hit the unique key, generic 500).
   Lifecycle cases therefore create once and continue with seeded rows. The local dump already contains a real menu with an empty GUID
   (`IP_ADMINS_WAF_MAIL_TEMPLATE`, id 45), so creating a menu through the screen currently always fails; the CRUD contract detects a
   foreign empty GUID and then asserts the 500 with no row or audit (the create action is still shown to be reachable via validation).
7. Validation allows 50-character codes, but several columns are shorter (`sys_tref_*` except menu position: 20, role code: 20, menu code: 30).
   CI3 uses a non-strict MySQL session, so longer codes are **silently truncated**; the audit row keeps the full submitted code.
   Module actions have no length rule at all (60 characters are truncated to 50).
8. Unlike `adm_ref_cp_status`, WAF lists apply `FILTER` only to the active tab and `FILTER_HIST` only to the history tab; the other tab is unfiltered.
   `waf_ref_menu_open_type` ignores both sorts on the active tab; `waf_ref_privileges` ignores the history name sort (misspelled parameter)
   and its name field is `privlege_name`.
9. Updating a menu with an empty module action keeps the existing link (the empty value is dropped from the UPDATE).
   Purging a menu still granted to a role fails with the **500 "data might still be referenced"** page; the `D` audit row is written first.
10. Unknown role/group/menu/module-action keys on the assignment endpoints are a **500** (PHP 8 `count(null)` TypeError), not a 400.
11. A granted menu linked to a module action is shown when **any** role holds that action, not necessarily the user's (the menu query does not
    join the action grant to the user's role). Page access itself still requires the user's own grant.
12. Mail templates: create/update answer **200 + `Refresh`** back to the type; validation errors are discarded by that redirect.
    `create_data_is`/`update_data_is` save the template and then fail with a generic 500 because the model method `update_data_is` does not exist.
13. The ADMIN tab of the role menu-permission view fails with a 500 when any active ADMIN menu has non-JSON `menu_params`
    (the local dump has `NewMenu`, id 89, with `asdfvv`). Tests use MEMBER menus; restore the data rather than adapting the test.
14. Module index rows show the **Actions** link only to admins who also hold `adm_waf_modact.index`.
15. The RAPIS-524 `adm_ref_*` lists behave like the WAF lists in quirk 8 (`FILTER` filters only the active tab, `FILTER_HIST` only the
    history tab), unlike `adm_ref_cp_status` (quirk 1). Sort parameter names are not uniform: `adm_ref_app_status` uses `status_code_sort`/`status_name_sort`,
    and `adm_ref_client_status`/`adm_ref_client_cat` name their history sorts `*_code_hist`/`*_name_hist`.
16. `adm_ref_client_cat` orders the **active** tab by the history sort parameters, so `client_category_*_sort` is ignored there.
    Its `check_parent_is_looping` only rejects a category that is its **own direct parent** (update only; creates have no ID yet);
    deeper cycles and parent IDs that do not exist are stored. The parent list (`ROOT` = `0`) includes soft-deleted categories, and the
    list shows `ROOT (0)` for a root or unknown parent.

No separate bug tickets were filed for these observations.

## Scope: local only

The suite is run locally only. It is not part of `bitbucket-pipelines.yml` and has no staging run.
The non-destructive harness unit tests need no HTTP/DB configuration (`composer test:unit`).

## Adding the next module / eventual cutover

Add a test class under `Http/` extending `HttpTestCase` and return the modules it needs from `modules()` (registered in
`Fixtures::MODULE_ACTIONS`); only the allowed identity receives them. Screens that follow the CI3 reference-table template extend
`WafCrudTestCase` and describe columns, form, labels and list layout in `spec()`. Use only public HTTP routes via `Browser`, read CSRF from the
actual form before each POST, and assert exact response, row and audit contracts. Seed rows with `Fixtures::waf()` (WAF tables in
`Fixtures::WAF_ENTITIES`, `ip_data` tables in `Fixtures::IP_ENTITIES`; both use the same ownership and cleanup rules) (or extend `Fixtures` with
explicitly owned records and reverse-order cleanup); never grant fixture users access to unrelated modules or change existing application
metadata. Add test coverage for cleanup/isolation.
Normalize only volatile values, not permission errors, validation messages, statuses or business values.

Record surprising behavior as an explicit expectation and discuss a separate bug fix; do not change legacy behavior in this suite.
At cutover, run the **unchanged** cases after nginx routes the original URLs to Symfony, then remove CI3 code only after equivalence is proven.
SAML, OAuth2, REST `services` and `/api/*` remain separate cards.
