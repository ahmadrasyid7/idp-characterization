<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_modact (CI3): the module-action popup opened from a module row. */
final class WafModactTest extends WafCrudTestCase
{
    private ?array $module = null;

    protected static function spec(): array
    {
        return [
            'module' => 'adm_waf_modact', 'path' => '/adm_waf_modact', 'table' => 'sys_tmst_module_action', 'title' => 'WAF - Module Actions',
            'guid' => 'modact_guid', 'code' => 'modact_code', 'name' => 'modact_name', 'active' => 'modact_is_active',
            'codeLength' => 50, 'maxLength' => false, 'query' => 'filter_key={MODULE_GUID}',
            'unique' => 'Module Action Code "%s" is Allready Used. Please try a nother one.',
            'form' => [
                'filter_key' => '{MODULE_GUID}', 'modact_module_id' => '{MODULE_ID}', 'modact_code' => '{CODE}', 'modact_name' => '{NAME}',
                'modact_version' => '1.0', 'modact_description' => 'Characterization description',
                'modact_action_type_id' => '16', 'modact_privilege_id' => '3', 'modact_is_active' => '1',
            ],
            'required' => [
                'modact_code' => 'Module Action Code', 'modact_name' => 'Module Action Name', 'modact_version' => 'Version',
                'modact_description' => 'Description', 'modact_action_type_id' => 'Action Type',
                'modact_privilege_id' => 'Privilege Type', 'modact_is_active' => 'Record Status',
            ],
            'seed' => [
                'modact_module_id' => '{MODULE_ID}', 'modact_version' => '1.0', 'modact_description' => 'Characterization fixture',
                'modact_action_type_id' => 16, 'modact_privilege_id' => 3,
            ],
            // No filter or sort: the popup lists every action of filter_key's module, 50 per page.
            'list' => ['cells' => 6, 'codeCell' => 1, 'filter' => null, 'sort' => [], 'pageSize' => 50],
        ];
    }

    protected function placeholders(): array
    {
        $this->module ??= $this->parentModule('MOD');

        return ['{MODULE_ID}' => (string) $this->module['module_id'], '{MODULE_GUID}' => $this->module['module_guid']];
    }

    private function parentModule(string $suffix): array
    {
        return $this->fixtures->waf('sys_tmst_module', [
            'module_code' => $this->code($suffix), 'module_name' => $this->code($suffix), 'module_app_id' => 2,
            'module_version' => '1.0', 'module_description' => 'Characterization parent', 'module_access_type_id' => 7,
            'module_privilege_id' => 3,
        ]);
    }

    public function testPopupShowsOnlyTheSelectedModulesActions(): void
    {
        $this->signIn();
        $mine = $this->seed('MINE');
        $other = $this->parentModule('OTHER');
        $foreign = $this->fixtures->waf('sys_tmst_module_action', [
            'modact_code' => $this->code('FOREIGN'), 'modact_module_id' => $other['module_id'], 'modact_name' => 'x',
            'modact_version' => '1.0', 'modact_action_type_id' => 16, 'modact_privilege_id' => 3,
        ]);
        $page = $this->browser->get($this->url('index'));
        self::assertSame(200, $this->browser->status());
        self::assertStringContainsString('Module Code: {FIXTURE}_MOD', $this->text());
        self::assertSame([$mine['modact_code']], $this->codes($page, 'tab_1'));
        $page = $this->browser->get('/adm_waf_modact/index?filter_key='.rawurlencode($other['module_guid']));
        self::assertSame([$foreign['modact_code']], $this->codes($page, 'tab_1'));
    }

    public function testSameCodeIsAllowedOnAnotherModule(): void
    {
        $this->signIn();
        $other = $this->parentModule('OTHER');
        $this->fixtures->waf('sys_tmst_module_action', [
            'modact_code' => $this->code('C'), 'modact_module_id' => $other['module_id'], 'modact_name' => 'x',
            'modact_version' => '1.0', 'modact_action_type_id' => 16, 'modact_privilege_id' => 3,
        ]);
        $this->submit('create_data', null, $this->form('C'));
        // Uniqueness is per module, but wafRow() is by code only, so look the row up by module.
        $this->assertReloaded();
        $rows = Database::rows($this->fixtures->db->waf,
            'SELECT modact_module_id FROM sys_tmst_module_action WHERE modact_code = ? ORDER BY modact_module_id', [$this->code('C')]);
        self::assertSame([(string) $other['module_id'], (string) $this->module['module_id']],
            array_map(static fn ($r) => (string) $r['modact_module_id'], $rows));
    }

    public function testCodeComparisonRejectsCaseVariantsIncludingACaseOnlyUpdate(): void
    {
        $this->signIn();
        $row = $this->seed('CASE');
        $form = $this->form('NEW');
        $form['modact_code'] = $this->fixtures->prefix.'_case';

        foreach (['create_data' => null, 'update_data' => $row] as $action => $target) {
            $this->submit($action, $target, $form);
            self::assertSame(200, $this->browser->status(), $this->browser->body());
            self::assertStringContainsString(sprintf(static::spec()['unique'], $form['modact_code']), $this->browser->body());
            self::assertSame($row, $this->row($this->code('CASE')));
            self::assertSame([], $this->audit());
        }
    }

    /** @dataProvider actionReferences */
    public function testReferencedActionCanBeSoftDeletedButNotPurged(string $reference): void
    {
        $this->signIn();
        $action = $this->seed('USED');
        if ($reference === 'role') {
            $roleId = $this->fixtures->identity('denied')['role_id'];
            Database::insert($this->fixtures->db->waf, 'sys_tmst_role_module_action', [
                'role_id' => $roleId, 'modact_id' => $action['modact_id'], 'sys_entry_user' => $this->fixtures->prefix,
            ]);
            $sql = 'SELECT modact_id FROM sys_tmst_role_module_action WHERE role_id = ? AND modact_id = ?';
            $parameters = [$roleId, $action['modact_id']];
        } else {
            $menu = $this->fixtures->waf('sys_tmst_menu', [
                'menu_code' => $this->code('MENU'), 'menu_title' => $this->code('MENU'),
                'menu_subtitle' => 'Characterization', 'menu_app_id' => 2, 'menu_type_id' => 5,
                'menu_open_type_id' => 4, 'menu_position_id' => 7, 'menu_is_hidden' => 1,
                'menu_module_action_id' => $action['modact_id'],
            ]);
            $sql = 'SELECT menu_module_action_id FROM sys_tmst_menu WHERE menu_id = ? AND menu_module_action_id = ?';
            $parameters = [$menu['menu_id'], $action['modact_id']];
        }

        $this->submit('delete_data', $action);
        $this->assertReloaded();
        $deleted = $this->row($this->code('USED'));
        self::assertSame('1', (string) $deleted['sys_soft_delete']);
        self::assertSame('0', (string) $deleted['modact_is_active']);
        self::assertCount(1, Database::rows($this->fixtures->db->waf, $sql, $parameters));

        $this->submit('purge_data', $deleted);
        self::assertSame(500, $this->browser->status());
        self::assertSame(self::REFERENCED_PAGE, $this->text());
        self::assertSame($deleted, $this->row($this->code('USED')));
        self::assertCount(1, Database::rows($this->fixtures->db->waf, $sql, $parameters));
        $audit = $this->audit();
        self::assertCount(2, $audit);
        $this->assertAuditRow($audit[0], 'S', $action['modact_guid']);
        $this->assertAuditRow($audit[1], 'D', $action['modact_guid']);
    }

    public static function actionReferences(): array
    {
        return ['role grant' => ['role'], 'menu link' => ['menu']];
    }

    public function testCancelOnThePopupClosesTheWindow(): void
    {
        $this->signIn();
        $token = $this->browser->csrf($this->url('index'));
        $this->browser->post($this->url('index'), $token + ['btn_action' => 'CANCEL']);
        self::assertSame(200, $this->browser->status());
        self::assertSame("<script type='text/javascript'>window.close();window.opener.top.location.reload();</script>", trim($this->browser->body()));
    }
}
