<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\Fixtures;
use Characterization\HttpTestCase;

/** Harness safety for the WAF and ip_data reference fixtures: exact ownership cleanup, relation cleanup, isolation and refusal. */
final class WafFixtureCleanupTest extends HttpTestCase
{
    private function seedGraph(Fixtures $fixtures, string $owner): array
    {
        $p = $fixtures->prefix;
        $module = $fixtures->waf('sys_tmst_module', ['module_code' => $p.'_MOD', 'module_name' => 'x', 'module_app_id' => 2,
            'module_version' => '1', 'module_access_type_id' => 7, 'module_privilege_id' => 3, 'sys_entry_user' => $owner]);
        $modact = $fixtures->waf('sys_tmst_module_action', ['modact_code' => $p.'_ACT', 'modact_module_id' => $module['module_id'],
            'modact_name' => 'x', 'modact_version' => '1', 'modact_action_type_id' => 16, 'modact_privilege_id' => 3, 'sys_entry_user' => $owner]);
        $menu = $fixtures->waf('sys_tmst_menu', ['menu_code' => $p.'_MN', 'menu_title' => 'x', 'menu_subtitle' => 'x', 'menu_app_id' => 2,
            'menu_type_id' => 5, 'menu_position_id' => 7, 'menu_open_type_id' => 4, 'menu_module_action_id' => $modact['modact_id'],
            'menu_is_hidden' => 1, 'sys_entry_user' => $owner]);
        $role = $fixtures->waf('sys_tmst_role', ['role_app_code' => $p.'_R', 'role_app_name' => 'x', 'role_app_id' => 2, 'sys_entry_user' => $owner]);
        $group = $fixtures->waf('sys_tmst_group', ['group_code' => $p.'_G', 'group_name' => 'x', 'group_app_id' => 2, 'sys_entry_user' => $owner]);
        $access = $fixtures->waf('sys_tref_access_type', ['access_type_code' => $p.'_A', 'access_type_name' => 'x', 'access_type_app_id' => 2, 'sys_entry_user' => $owner]);
        $mail = $fixtures->waf('sys_tref_mail_template', ['mail_template_subject' => $p.'_MAIL', 'mail_template_type' => 1999999999,
            'mail_template_body' => 'x', 'sys_entry_user' => $owner]);
        foreach ([
            'sys_tmst_role_menu' => ['role_id' => $role['role_id'], 'menu_id' => $menu['menu_id']],
            'sys_tmst_role_module_action' => ['role_id' => $role['role_id'], 'modact_id' => $modact['modact_id']],
            'sys_tmst_group_role' => ['group_id' => $group['group_id'], 'role_id' => $role['role_id']],
        ] as $table => $values) {
            Database::insert($fixtures->db->waf, $table, $values);
        }

        return compact('module', 'modact', 'menu', 'role', 'group', 'access', 'mail');
    }

    private function exists(Fixtures $fixtures, array $graph): array
    {
        $found = [];
        foreach ([
            'module' => ['sys_tmst_module', 'module_id'], 'modact' => ['sys_tmst_module_action', 'modact_id'],
            'menu' => ['sys_tmst_menu', 'menu_id'], 'role' => ['sys_tmst_role', 'role_id'], 'group' => ['sys_tmst_group', 'group_id'],
            'access' => ['sys_tref_access_type', 'access_type_id'], 'mail' => ['sys_tref_mail_template', 'mail_template_id'],
        ] as $key => [$table, $id]) {
            $found[$key] = count(Database::rows($fixtures->db->waf, 'SELECT 1 FROM '.$table.' WHERE '.$id.' = ?', [$graph[$key][$id]]));
        }
        $found['relations'] = count(Database::rows($fixtures->db->waf, 'SELECT 1 FROM sys_tmst_role_menu WHERE role_id = ?', [$graph['role']['role_id']]))
            + count(Database::rows($fixtures->db->waf, 'SELECT 1 FROM sys_tmst_role_module_action WHERE role_id = ?', [$graph['role']['role_id']]))
            + count(Database::rows($fixtures->db->waf, 'SELECT 1 FROM sys_tmst_group_role WHERE group_id = ?', [$graph['group']['group_id']]));

        return $found;
    }

    /** @dataProvider owners */
    public function testCleanupRemovesOwnedWafRowsAndTheirRelationsButNotAnotherNamespace(string $owner): void
    {
        $other = new Fixtures($this->config);
        try {
            $sentinel = $this->seedGraph($other, $other->prefix);
            $mine = $this->seedGraph($this->fixtures, $owner === 'prefix' ? $this->fixtures->prefix : $this->fixtures->username());
            $all = ['module' => 1, 'modact' => 1, 'menu' => 1, 'role' => 1, 'group' => 1, 'access' => 1, 'mail' => 1, 'relations' => 3];
            self::assertSame($all, $this->exists($this->fixtures, $mine));
            $this->fixtures->cleanup();
            $this->fixtures->cleanup();
            self::assertSame(array_map(static fn () => 0, $all), $this->exists($this->fixtures, $mine));
            self::assertSame($all, $this->exists($other, $sentinel));
        } finally {
            try {
                $other->cleanup();
            } finally {
                $other->close();
            }
        }
    }

    public static function owners(): array
    {
        return ['seeded by the fixture prefix' => ['prefix'], 'created through a screen by a fixture user' => ['user']];
    }

    /** @dataProvider owners */
    public function testCleanupRemovesOwnedIpDataReferenceRowsButNotAnotherNamespace(string $owner): void
    {
        $other = new Fixtures($this->config);
        try {
            $seed = static function (Fixtures $fixtures, string $owner): array {
                $rows = [];
                foreach (Fixtures::IP_ENTITIES as $table => $meta) {
                    $stem = substr($meta['code'], 0, -strlen('_code'));
                    $rows[$table] = $fixtures->waf($table, [
                        $meta['code'] => $fixtures->prefix.'_IP', $stem.'_name' => 'x', $stem.'_is_active' => 1, 'sys_entry_user' => $owner,
                    ]);
                }

                return $rows;
            };
            $count = static function (Fixtures $fixtures, array $rows): int {
                $n = 0;
                foreach ($rows as $table => $row) {
                    $id = Fixtures::IP_ENTITIES[$table]['id'];
                    $n += count(Database::rows($fixtures->db->ip, 'SELECT 1 FROM '.$table.' WHERE '.$id.' = ?', [$row[$id]]));
                }

                return $n;
            };
            $sentinel = $seed($other, $other->prefix);
            $mine = $seed($this->fixtures, $owner === 'prefix' ? $this->fixtures->prefix : $this->fixtures->username());
            self::assertSame(count(Fixtures::IP_ENTITIES), $count($this->fixtures, $mine));
            $this->fixtures->cleanup();
            $this->fixtures->cleanup();
            self::assertSame(0, $count($this->fixtures, $mine));
            self::assertSame(count(Fixtures::IP_ENTITIES), $count($other, $sentinel));
        } finally {
            try {
                $other->cleanup();
            } finally {
                $other->close();
            }
        }
    }

    public function testCleanupRefusesAnOwnedRowWhoseCodeIsNotPrefixed(): void
    {
        $id = Database::insert($this->fixtures->db->waf, 'sys_tref_access_type', [
            'access_type_guid' => $this->fixtures->prefix.'-unprefixed', 'access_type_code' => 'ZZ'.substr($this->fixtures->prefix, 7),
            'access_type_name' => 'x', 'access_type_app_id' => 2, 'sys_entry_user' => $this->fixtures->username(),
        ]);
        try {
            $this->fixtures->cleanup();
            self::fail('Cleanup must refuse a fixture-owned row without the fixture prefix.');
        } catch (RuntimeException $error) {
            self::assertSame('Refusing access to a non-fixture code.', $error->getMessage());
        } finally {
            Database::execute($this->fixtures->db->waf, 'DELETE FROM sys_tref_access_type WHERE access_type_id = ? AND sys_entry_user = ?',
                [$id, $this->fixtures->username()]);
        }
    }

    public function testUnknownModuleGrantIsRejectedBeforeTouchingTheDatabase(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown characterized module: adm_waf_unknown');
        new Fixtures($this->config, null, ['adm_waf_unknown']);
    }
}
