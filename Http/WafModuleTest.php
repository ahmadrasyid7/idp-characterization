<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\WafCrudTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Characterization of adm_waf_module (CI3): registered modules and the actions attached to them. */
final class WafModuleTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return [
            'module' => 'adm_waf_module', 'path' => '/adm_waf_module', 'table' => 'sys_tmst_module', 'title' => 'WAF - Modules',
            'guid' => 'module_guid', 'code' => 'module_code', 'name' => 'module_name', 'active' => 'module_is_active',
            'codeLength' => 50, 'maxLength' => true,
            'unique' => 'Module Code "%s" is Allready Used. Please try a nother one.',
            'form' => [
                'module_parent_id' => '0', 'module_code' => '{CODE}', 'module_name' => '{NAME}',
                'module_description' => 'Characterization description', 'module_version' => '1.0', 'module_path' => '',
                'module_is_core' => '0', 'module_privilege_id' => '3', 'module_access_type_id' => '7', 'module_is_active' => '1',
            ],
            'required' => [
                'module_parent_id' => 'Parent', 'module_code' => 'module Code', 'module_name' => 'Module Name',
                'module_description' => 'Description', 'module_version' => 'Version', 'module_is_core' => 'Is Core Module',
                'module_privilege_id' => 'Privilege Type', 'module_access_type_id' => 'Access Type', 'module_is_active' => 'Record Status',
            ],
            'seed' => [
                'module_app_id' => 2, 'module_version' => '1.0', 'module_description' => 'Characterization fixture',
                'module_access_type_id' => 7, 'module_privilege_id' => 3,
            ],
            'createSets' => ['module_app_id' => 2],
            'list' => ['cells' => 7, 'codeCell' => 1, 'filter' => 'filter_name', 'sort' => ['module_code_sort', 'module_name_sort']],
        ];
    }

    public function testAccessAndPrivilegeTypeFiltersApplyToTheSelectedTab(): void
    {
        $this->signIn();
        $admin = $this->seed('ADMIN', false, ['module_name' => $this->code('L1')]);
        $member = $this->seed('MEMBER', false, ['module_name' => $this->code('L2'), 'module_access_type_id' => 6]);
        $public = $this->seed('PUBLIC', false, ['module_name' => $this->code('L3'), 'module_privilege_id' => 1]);
        $deleted = $this->seed('DELETED', true, ['module_name' => $this->code('L4'), 'module_access_type_id' => 6]);
        $filter = 'filter_name='.$this->fixtures->prefix.'_L';
        $page = $this->browser->get($this->url('index', $filter.'&filter_access_type_id=6'));
        self::assertSame([$member['module_code']], $this->codes($page, 'tab_1'));
        $page = $this->browser->get($this->url('index', $filter.'&filter_privilege_type_id=1'));
        self::assertSame([$public['module_code']], $this->codes($page, 'tab_1'));
        $page = $this->browser->get($this->url('index', $filter.'&filter_access_type_id=7&filter_privilege_type_id=3'));
        self::assertSame([$admin['module_code']], $this->codes($page, 'tab_1'));
        $page = $this->browser->get($this->url('index', $filter.'&filter_access_type_id=6&btn_search=FILTER_HIST'));
        self::assertSame([$deleted['module_code']], $this->codes($page, 'tab_2'));
    }

    public function testDetailListsAttachedActionsAndActionsLinkNeedsModactPermission(): void
    {
        $this->signIn();
        $module = $this->seed('M', false, ['module_name' => $this->code('L1')]);
        foreach (['INDEX', 'EDIT'] as $action) {
            $this->fixtures->waf('sys_tmst_module_action', [
                'modact_code' => $this->code($action), 'modact_module_id' => $module['module_id'], 'modact_name' => $this->code($action),
                'modact_version' => '1.0', 'modact_description' => 'Characterization fixture', 'modact_action_type_id' => 16,
                'modact_privilege_id' => 3,
            ]);
        }
        $this->browser->get($this->url('read_detail', 'key='.rawurlencode($module['module_guid'])));
        self::assertSame(200, $this->browser->status());
        foreach (['INDEX', 'EDIT'] as $action) {
            self::assertStringContainsString($this->code($action), $this->browser->body());
        }
        $page = $this->browser->get($this->url('index', 'filter_name='.$this->fixtures->prefix.'_L'));
        $row = $page->filter('#tab_1 tbody tr')->reduce(static fn (Crawler $tr) => $tr->filter('td')->count() === 7)->first();
        self::assertSame('Edit Delete', trim(preg_replace('/\s+/', ' ', $row->filter('td')->eq(6)->text())));
        self::assertCount(0, $page->filter('a[href*="adm_waf_modact/index"]'), 'Actions link requires adm_waf_modact.index.');
    }

    public function testCodeComparisonRejectsCaseVariantsIncludingACaseOnlyUpdate(): void
    {
        $this->signIn();
        $row = $this->seed('CASE');
        $form = $this->form('NEW');
        $form['module_code'] = $this->fixtures->prefix.'_case';

        foreach (['create_data' => null, 'update_data' => $row] as $action => $target) {
            $this->submit($action, $target, $form);
            self::assertSame(200, $this->browser->status(), $this->browser->body());
            self::assertStringContainsString(sprintf(static::spec()['unique'], $form['module_code']), $this->browser->body());
            self::assertSame($row, $this->row($this->code('CASE')));
            self::assertSame([], $this->audit());
        }
    }

    public function testModuleWithActionsCanBeSoftDeletedButNotPurged(): void
    {
        $this->signIn();
        $module = $this->seed('USED');
        $action = $this->fixtures->waf('sys_tmst_module_action', [
            'modact_code' => $this->code('ACTION'), 'modact_module_id' => $module['module_id'],
            'modact_name' => $this->code('ACTION'), 'modact_version' => '1.0',
            'modact_description' => 'Characterization fixture', 'modact_action_type_id' => 16,
            'modact_privilege_id' => 3,
        ]);

        $this->submit('delete_data', $module);
        $this->assertReloaded();
        $deleted = $this->row($this->code('USED'));
        self::assertSame('1', (string) $deleted['sys_soft_delete']);
        self::assertSame('0', (string) $deleted['module_is_active']);
        self::assertSame($action, Database::rows(
            $this->fixtures->db->waf,
            'SELECT * FROM sys_tmst_module_action WHERE modact_id = ?',
            [$action['modact_id']],
        )[0]);

        $this->submit('purge_data', $deleted);
        self::assertSame(500, $this->browser->status());
        self::assertSame(self::REFERENCED_PAGE, $this->text());
        self::assertSame($deleted, $this->row($this->code('USED')));
        $audit = $this->audit();
        self::assertCount(2, $audit);
        $this->assertAuditRow($audit[0], 'S', $module['module_guid']);
        $this->assertAuditRow($audit[1], 'D', $module['module_guid']);
    }
}
