<?php

declare(strict_types=1);

namespace Characterization;

use RuntimeException;

final class Fixtures
{
    public const ACTIONS = [
        'index', 'read_detail', 'read_input', 'create_data', 'read_update', 'update_data',
        'read_delete', 'delete_data', 'read_restore_purge', 'restore_data', 'purge_data',
    ];

    /** Registered actions per module that a test class may grant to the allowed identity. */
    public const MODULE_ACTIONS = [
        'adm_ref_cp_status' => self::ACTIONS,
        'adm_waf_mail' => ['index', 'create_data', 'update_data', 'create_data_is', 'update_data_is'],
        'adm_waf_configs' => ['index'],
        'waf_ref_access_type' => self::ACTIONS,
        'waf_ref_action_type' => self::ACTIONS,
        'waf_ref_menu_type' => self::ACTIONS,
        'waf_ref_menu_open_type' => self::ACTIONS,
        'waf_ref_menu_position' => self::ACTIONS,
        'waf_ref_privileges' => self::ACTIONS,
        'adm_waf_module' => self::ACTIONS,
        'adm_waf_modact' => self::ACTIONS,
        'adm_waf_menu' => self::ACTIONS,
        'adm_waf_group' => self::ACTIONS,
        'adm_waf_group_role' => ['index', 'do_assign_role'],
        'adm_waf_role' => self::ACTIONS,
        'adm_waf_role_permission' => ['index', 'do_assign_menu', 'do_assign_modact'],
        'adm_ref_app_status' => self::ACTIONS,
        'adm_ref_client_status' => self::ACTIONS,
        'adm_ref_client_type' => self::ACTIONS,
        'adm_ref_cp_stage' => self::ACTIONS,
        'adm_ref_product_status' => self::ACTIONS,
        'adm_ref_product_type' => self::ACTIONS,
        'adm_ref_client_cat' => self::ACTIONS,
    ];

    /**
     * WAF rows that tests may seed or create through the screens. Ownership is the exact sys_entry_user
     * (fixture prefix or a fixture username) and every owned "code" must start with the fixture prefix.
     * Listed in safe deletion order (dependants first).
     */
    public const WAF_ENTITIES = [
        'sys_tmst_menu' => ['id' => 'menu_id', 'guid' => 'menu_guid', 'code' => 'menu_code'],
        'sys_tmst_module_action' => ['id' => 'modact_id', 'guid' => 'modact_guid', 'code' => 'modact_code'],
        'sys_tmst_module' => ['id' => 'module_id', 'guid' => 'module_guid', 'code' => 'module_code'],
        'sys_tmst_role' => ['id' => 'role_id', 'guid' => 'role_guid', 'code' => 'role_app_code'],
        'sys_tmst_group' => ['id' => 'group_id', 'guid' => 'group_guid', 'code' => 'group_code'],
        'sys_tref_access_type' => ['id' => 'access_type_id', 'guid' => 'access_type_guid', 'code' => 'access_type_code'],
        'sys_tref_action_type' => ['id' => 'action_type_id', 'guid' => 'action_type_guid', 'code' => 'action_type_code'],
        'sys_tref_menu_type' => ['id' => 'menu_type_id', 'guid' => 'menu_type_guid', 'code' => 'menu_type_code'],
        'sys_tref_menu_open_type' => ['id' => 'menu_open_type_id', 'guid' => 'menu_open_type_guid', 'code' => 'menu_open_type_code'],
        'sys_tref_menu_position' => ['id' => 'menu_position_id', 'guid' => 'menu_position_guid', 'code' => 'menu_position_code'],
        'sys_tref_privilege_type' => ['id' => 'privilege_id', 'guid' => 'privilege_guid', 'code' => 'privilege_code'],
        'sys_tref_mail_template' => ['id' => 'mail_template_id', 'guid' => 'mail_template_guid', 'code' => 'mail_template_subject'],
    ];

    /** ip_data reference tables (adm_ref_* screens) with the same ownership rules as WAF_ENTITIES. */
    public const IP_ENTITIES = [
        'tref_app_status' => ['id' => 'app_status_id', 'guid' => 'app_status_guid', 'code' => 'app_status_code'],
        'tref_client_status' => ['id' => 'client_status_id', 'guid' => 'client_status_guid', 'code' => 'client_status_code'],
        'tref_client_type' => ['id' => 'client_type_id', 'guid' => 'client_type_guid', 'code' => 'client_type_code'],
        'tref_cp_app_stage' => ['id' => 'cp_app_stage_id', 'guid' => 'cp_app_stage_guid', 'code' => 'cp_app_stage_code'],
        'tref_product_status' => ['id' => 'product_status_id', 'guid' => 'product_status_guid', 'code' => 'product_status_code'],
        'tref_product_type' => ['id' => 'product_type_id', 'guid' => 'product_type_guid', 'code' => 'product_type_code'],
        'tref_client_category' => ['id' => 'client_category_id', 'guid' => 'client_category_guid', 'code' => 'client_category_code'],
    ];

    /** Audit table names written by the characterized screens (cleanup is still limited to fixture users). */
    public const AUDIT_TABLES = [
        'tref_cp_status', 'sys_tmst_role_menu', 'sys_tmst_role_module_action', 'sys_tmst_group_role',
        'sys_tmst_menu', 'sys_tmst_module_action', 'sys_tmst_module', 'sys_tmst_role', 'sys_tmst_group',
        'sys_tref_access_type', 'sys_tref_action_type', 'sys_tref_menu_type', 'sys_tref_menu_open_type',
        'sys_tref_menu_position', 'sys_tref_privilege_type', 'sys_tref_mail_template',
        'tref_app_status', 'tref_client_status', 'tref_client_type', 'tref_cp_app_stage',
        'tref_product_status', 'tref_product_type', 'tref_client_category',
    ];

    public string $prefix;
    public Database $db;
    private static ?Database $suiteLock = null;
    private Config $config;
    private array $sessionIds = [];
    /** @var list<string> */
    private array $modules;

    /** @param list<string> $modules modules whose registered actions are granted to the allowed identity */
    public function __construct(Config $config, ?string $prefix = null, array $modules = ['adm_ref_cp_status'])
    {
        $this->prefix = $prefix ?? 'ZZTEST_'.bin2hex(random_bytes(4));
        if (!preg_match('/^ZZTEST_[a-f0-9]{8}$/D', $this->prefix)) {
            throw new RuntimeException('Fixture prefix must be ZZTEST_ followed by exactly eight lowercase hex characters.');
        }
        foreach ($modules as $module) {
            if (!array_key_exists($module, self::MODULE_ACTIONS)) {
                throw new RuntimeException('Unknown characterized module: '.$module);
            }
        }
        $this->modules = array_values($modules);
        $this->config = $config;
        self::lockSuite($config);
        $this->db = new Database($config);
        $lock = Database::rows($this->db->waf, 'SELECT GET_LOCK(?, 0) AS acquired', [$this->prefix]);
        if ((int) $lock[0]['acquired'] !== 1) {
            throw new RuntimeException('Fixture prefix is already in use: '.$this->prefix);
        }
    }

    private static function lockSuite(Config $config): void
    {
        if (self::$suiteLock !== null) {
            return;
        }
        $database = new Database($config);
        $name = 'idp-characterization:'.substr(hash('sha256', Config::required('CHAR_DB_IP')), 0, 32);
        try {
            $lock = Database::rows($database->waf, 'SELECT GET_LOCK(?, 120) AS acquired', [$name]);
            if ((int) $lock[0]['acquired'] !== 1) {
                throw new RuntimeException('Another characterization suite is using this database; timed out after 120 seconds.');
            }
        } catch (\Throwable $error) {
            $database->close();
            throw $error;
        }
        self::$suiteLock = $database;
        register_shutdown_function(static function () use ($database): void {
            $database->close();
        });
    }

    public function username(string $kind = 'allowed'): string
    {
        if (!in_array($kind, ['allowed', 'denied', 'inactive', 'unknown'], true)) {
            throw new RuntimeException('Unknown fixture identity.');
        }

        return $this->prefix.'_'.$kind;
    }

    public function seed(): void
    {
        $this->cleanup();
        $modules = array_values(array_unique(array_merge($this->modules, ['admins'])));
        $actions = Database::rows($this->db->waf, 'SELECT m.module_code, a.modact_code, a.modact_id
            FROM sys_tmst_module m
            JOIN sys_tmst_module_action a ON a.modact_module_id = m.module_id
            JOIN sys_tref_action_type t ON t.action_type_id = a.modact_action_type_id AND t.action_type_is_active = 1
            WHERE m.module_app_id = 2 AND m.module_is_active = 1 AND a.modact_is_active = 1
            AND m.module_code IN ('.implode(', ', array_fill(0, count($modules), '?')).')', $modules);
        $granted = ['admins.index'];
        foreach ($this->modules as $module) {
            foreach (self::MODULE_ACTIONS[$module] as $action) {
                $granted[] = $module.'.'.$action;
            }
        }
        foreach ($granted as $required) {
            $matches = array_filter($actions, static fn ($a) => $a['module_code'].'.'.$a['modact_code'] === $required);
            if (count($matches) !== 1) {
                throw new RuntimeException('Expected exactly one registered active action: '.$required.'. Restore the legacy WAF metadata first.');
            }
        }
        $this->db->waf->begin_transaction();
        try {
            foreach (['allowed' => 1, 'denied' => 1, 'inactive' => 0] as $kind => $active) {
                $short = $this->prefix.'_'.substr($kind, 0, 1);
                $role = Database::insert($this->db->waf, 'sys_tmst_role', [
                    'role_guid' => $short, 'role_app_id' => 2, 'role_app_code' => $short,
                    'role_app_name' => $short, 'role_is_active' => 1, 'role_parent_id' => 0,
                    'sys_entry_user' => $this->prefix,
                ]);
                $group = Database::insert($this->db->waf, 'sys_tmst_group', [
                    'group_guid' => $short, 'group_code' => $short, 'group_name' => $short,
                    'group_app_id' => 2, 'group_is_active' => 1, 'group_parent_id' => 0,
                    'sys_entry_user' => $this->prefix,
                ]);
                $user = Database::insert($this->db->waf, 'sys_tmst_user', [
                    'user_guid' => $short, 'user_name' => $this->username($kind),
                    'user_password' => password_hash($this->config->password, PASSWORD_BCRYPT),
                    'user_is_active' => $active, 'sys_entry_user' => $this->prefix,
                ]);
                foreach ([
                    'sys_tmst_user_group' => ['user_id' => $user, 'group_id' => $group, 'user_group_is_active' => 1, 'user_group_is_default' => 1],
                    'sys_tmst_group_app' => ['group_id' => $group, 'app_id' => 2, 'group_app_is_active' => 1],
                    'sys_tmst_group_role' => ['group_id' => $group, 'role_id' => $role, 'group_role_is_active' => 1],
                ] as $table => $values) {
                    Database::insert($this->db->waf, $table, $values + ['sys_entry_user' => $this->prefix]);
                }
                foreach ($actions as $action) {
                    $code = $action['module_code'].'.'.$action['modact_code'];
                    if ($code === 'admins.index' || ($kind === 'allowed' && in_array($code, $granted, true))) {
                        Database::insert($this->db->waf, 'sys_tmst_role_module_action', [
                            'role_id' => $role, 'modact_id' => $action['modact_id'],
                            'sys_entry_user' => $this->prefix,
                        ]);
                    }
                }
            }
            $this->db->waf->commit();
        } catch (\Throwable $error) {
            $this->db->waf->rollback();
            throw $error;
        }
    }

    public function status(string $suffix = 'ROW', bool $deleted = false): array
    {
        if (!preg_match('/^[A-Z0-9_]{1,30}$/D', $suffix)) {
            throw new RuntimeException('Unsafe fixture status suffix.');
        }
        $code = $this->prefix.'_'.$suffix;
        Database::insert($this->db->ip, 'tref_cp_status', [
            'cp_status_guid' => $code, 'cp_status_code' => $code, 'cp_status_name' => $code,
            'cp_status_description' => 'Characterization fixture '.$code,
            'cp_status_is_active' => $deleted ? 0 : 1, 'sys_soft_delete' => $deleted ? 1 : 0,
            'sys_entry_user' => $this->prefix, 'sys_entry_date' => '2000-01-01 00:00:00',
            'sys_update_user' => $this->prefix, 'sys_update_date' => '2000-01-01 00:00:00',
        ]);

        return $this->row($code);
    }

    public function row(string $code): ?array
    {
        $this->assertOwnedCode($code);
        $rows = Database::rows($this->db->ip, 'SELECT * FROM tref_cp_status WHERE cp_status_code = ?', [$code]);

        return $rows[0] ?? null;
    }

    public function auditRows(string $kind = 'allowed', string $table = 'tref_cp_status'): array
    {
        return Database::rows($this->db->audit, 'SELECT * FROM sys_log_audit_table
            WHERE user_application = ? AND table_name = ? ORDER BY id_log', [$this->username($kind), $table]);
    }

    public function rememberSession(string $id): void
    {
        $this->sessionIds[$id] = $id;
    }

    public function close(): void
    {
        try {
            Database::execute($this->db->waf, 'SELECT RELEASE_LOCK(?)', [$this->prefix]);
        } finally {
            $this->db->close();
        }
    }

    public function cleanup(): void
    {
        $users = Database::rows($this->db->waf, 'SELECT user_id, user_name FROM sys_tmst_user WHERE sys_entry_user = ?', [$this->prefix]);
        foreach ($users as $user) {
            if (!in_array($user['user_name'], [$this->username(), $this->username('denied'), $this->username('inactive')], true)) {
                throw new RuntimeException('Unexpected fixture ownership; refusing cleanup.');
            }
        }
        // Exact actor/owner matches only; never use LIKE (the prefix contains SQL wildcards).
        foreach ([$this->prefix, $this->username(), $this->username('denied'), $this->username('inactive')] as $actor) {
            $rows = Database::rows($this->db->ip, 'SELECT cp_status_code FROM tref_cp_status WHERE sys_entry_user = ?', [$actor]);
            foreach ($rows as $row) {
                $this->assertOwnedCode($row['cp_status_code']);
                Database::execute($this->db->ip, 'DELETE FROM tref_cp_status WHERE cp_status_code = ? AND sys_entry_user = ?', [$row['cp_status_code'], $actor]);
            }
        }
        foreach ($users as $user) {
            Database::execute($this->db->audit, 'DELETE FROM sys_log_audit_table
                WHERE user_id = ? AND user_application = ? AND app_id = 2 AND table_name IN ('
                .implode(', ', array_fill(0, count(self::AUDIT_TABLES), '?')).')',
                array_merge([$user['user_id'], $user['user_name']], self::AUDIT_TABLES));
            Database::execute($this->db->waf, 'DELETE FROM sys_ttrx_ci3_sessions WHERE user_id = ?', [$user['user_id']]);
            Database::execute($this->db->waf, 'DELETE FROM sys_tmst_user_group WHERE user_id = ?', [$user['user_id']]);
            Database::execute($this->db->waf, 'DELETE FROM sys_tmst_user WHERE user_id = ? AND sys_entry_user = ?', [$user['user_id'], $this->prefix]);
        }
        foreach ($this->sessionIds as $id) {
            Database::execute($this->db->waf, 'DELETE FROM sys_ttrx_ci3_sessions WHERE id = ?', [$id]);
        }
        $this->cleanupWaf();
        $this->cleanupIp();
    }

    /** Deletes ip_data reference rows owned by this namespace (exact owner and code match). */
    private function cleanupIp(): void
    {
        $actors = $this->actors();
        $in = implode(', ', array_fill(0, count($actors), '?'));
        foreach (self::IP_ENTITIES as $table => $meta) {
            $rows = Database::rows($this->db->ip, 'SELECT '.$meta['id'].' AS id, '.$meta['code'].' AS code, sys_entry_user
                FROM '.$table.' WHERE sys_entry_user IN ('.$in.')', $actors);
            foreach ($rows as $row) {
                $this->assertOwnedCode((string) $row['code']);
                Database::execute($this->db->ip, 'DELETE FROM '.$table.' WHERE '.$meta['id'].' = ? AND sys_entry_user = ?',
                    [$row['id'], $row['sys_entry_user']]);
            }
        }
    }

    /**
     * Deletes WAF rows owned by this namespace (seeded by the prefix or created through the screens by a fixture
     * identity), then their relation rows. Never touches rows owned by anyone else.
     */
    private function cleanupWaf(): void
    {
        $actors = $this->actors();
        $in = implode(', ', array_fill(0, count($actors), '?'));
        $owned = [];
        foreach (self::WAF_ENTITIES as $table => $meta) {
            $rows = Database::rows($this->db->waf, 'SELECT '.$meta['id'].' AS id, '.$meta['code'].' AS code, sys_entry_user
                FROM '.$table.' WHERE sys_entry_user IN ('.$in.') ORDER BY '.$meta['id'].' DESC', $actors);
            foreach ($rows as $row) {
                $this->assertOwnedCode((string) $row['code']);
            }
            $owned[$table] = $rows;
        }
        $ids = static fn (string $table): array => array_map(static fn ($row) => (string) $row['id'], $owned[$table]);
        $relations = [
            'sys_tmst_role_menu' => ['role_id' => $ids('sys_tmst_role'), 'menu_id' => $ids('sys_tmst_menu')],
            'sys_tmst_role_module_action' => ['role_id' => $ids('sys_tmst_role'), 'modact_id' => $ids('sys_tmst_module_action')],
            'sys_tmst_group_role' => ['group_id' => $ids('sys_tmst_group'), 'role_id' => $ids('sys_tmst_role')],
            'sys_tmst_group_app' => ['group_id' => $ids('sys_tmst_group')],
            'sys_tmst_user_group' => ['group_id' => $ids('sys_tmst_group')],
        ];
        foreach ($relations as $table => $columns) {
            foreach ($columns as $column => $values) {
                if ($values !== []) {
                    Database::execute($this->db->waf, 'DELETE FROM '.$table.' WHERE '.$column.' IN ('
                        .implode(', ', array_fill(0, count($values), '?')).')', $values);
                }
            }
        }
        foreach (self::WAF_ENTITIES as $table => $meta) {
            foreach ($owned[$table] as $row) {
                Database::execute($this->db->waf, 'DELETE FROM '.$table.' WHERE '.$meta['id'].' = ? AND sys_entry_user = ?',
                    [$row['id'], $row['sys_entry_user']]);
            }
        }
    }

    /** @return list<string> exact sys_entry_user values that mark rows as owned by this namespace */
    private function actors(): array
    {
        return [$this->prefix, $this->username(), $this->username('denied'), $this->username('inactive')];
    }

    /** Seeds an owned WAF or ip_data reference row; the code column must start with the fixture prefix. */
    public function waf(string $table, array $values): array
    {
        $meta = self::entity($table);
        $code = (string) $values[$meta['code']];
        $this->assertOwnedCode($code);
        $values += [
            $meta['guid'] => $this->prefix.'-'.bin2hex(random_bytes(8)),
            'sys_entry_user' => $this->prefix, 'sys_entry_date' => '2000-01-01 00:00:00',
            'sys_update_user' => $this->prefix, 'sys_update_date' => '2000-01-01 00:00:00',
        ];
        Database::insert($this->connection($table), $table, $values);

        return $this->wafRow($table, $code);
    }

    public function wafRow(string $table, string $code): ?array
    {
        $meta = self::entity($table);
        $this->assertOwnedCode($code);
        $rows = Database::rows($this->connection($table), 'SELECT * FROM '.$table.' WHERE '.$meta['code'].' = ?', [$code]);
        if (count($rows) > 1) {
            throw new RuntimeException('Ambiguous fixture code '.$code.' in '.$table.'.');
        }

        return $rows[0] ?? null;
    }

    /** True when a row outside this namespace already has an empty GUID (screen creates then collide on the unique key). */
    public function foreignEmptyGuid(string $table): bool
    {
        $meta = self::entity($table);
        $actors = $this->actors();

        return Database::rows($this->connection($table), 'SELECT COUNT(*) AS n FROM '.$table.' WHERE '.$meta['guid'].' = ? AND (sys_entry_user IS NULL OR sys_entry_user NOT IN ('
            .implode(', ', array_fill(0, count($actors), '?')).'))', array_merge([''], $actors))[0]['n'] > 0;
    }

    /** @return array{user_id: int, role_id: int, role_guid: string, group_id: int, group_guid: string} */
    public function identity(string $kind = 'allowed'): array
    {
        $rows = Database::rows($this->db->waf, 'SELECT u.user_id, r.role_id, r.role_guid, g.group_id, g.group_guid
            FROM sys_tmst_user u
            JOIN sys_tmst_user_group ug ON ug.user_id = u.user_id
            JOIN sys_tmst_group g ON g.group_id = ug.group_id
            JOIN sys_tmst_group_role gr ON gr.group_id = g.group_id
            JOIN sys_tmst_role r ON r.role_id = gr.role_id
            WHERE u.user_name = ? AND u.sys_entry_user = ? AND g.sys_entry_user = ? AND r.sys_entry_user = ?',
            [$this->username($kind), $this->prefix, $this->prefix, $this->prefix]);
        if (count($rows) !== 1) {
            throw new RuntimeException('Fixture identity is not seeded: '.$kind);
        }

        return $rows[0];
    }

    private static function entity(string $table): array
    {
        $meta = self::WAF_ENTITIES[$table] ?? self::IP_ENTITIES[$table] ?? null;
        if ($meta === null) {
            throw new RuntimeException('Unknown fixture table: '.$table);
        }

        return $meta;
    }

    private function connection(string $table): \mysqli
    {
        return isset(self::IP_ENTITIES[$table]) ? $this->db->ip : $this->db->waf;
    }

    private function assertOwnedCode(string $code): void
    {
        if (strpos($code, $this->prefix.'_') !== 0) {
            throw new RuntimeException('Refusing access to a non-fixture code.');
        }
    }
}
