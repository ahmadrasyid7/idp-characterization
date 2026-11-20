<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_menu (CI3). */
final class WafMenuTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return [
            'module' => 'adm_waf_menu', 'path' => '/adm_waf_menu', 'table' => 'sys_tmst_menu', 'title' => 'WAF - Menus',
            'guid' => 'menu_guid', 'code' => 'menu_code', 'name' => 'menu_title', 'active' => 'menu_is_active',
            'codeLength' => 30, 'maxLength' => true,
            'unique' => 'Menu Code "%s" is Allready Used. Please try a nother one.',
            // MEMBER content-icon menus that stay hidden; no fixture role is granted them.
            'form' => [
                'menu_parent_id' => '0', 'menu_code' => '{CODE}', 'menu_title' => '{NAME}', 'menu_subtitle' => 'Characterization',
                'menu_description' => 'Characterization description', 'menu_params' => '[{"level":"1"}]', 'menu_style' => '',
                'menu_icon' => '', 'menu_fa_icon' => 'fa-home', 'menu_is_parent' => '0', 'menu_type_id' => '5',
                'menu_module_action_id' => '', 'menu_position_id' => '7', 'menu_order' => '1', 'menu_open_type_id' => '4',
                'menu_is_hidden' => '1', 'menu_is_active' => '1',
            ],
            'required' => [
                'menu_parent_id' => 'Parent', 'menu_code' => 'Menu Code', 'menu_title' => 'Menu Title',
                'menu_subtitle' => 'Menu Sub Title', 'menu_description' => 'Description', 'menu_params' => 'Params',
                'menu_is_parent' => 'Is Parent', 'menu_type_id' => 'Menu Type', 'menu_position_id' => 'Menu Position',
                'menu_order' => 'Menu Order', 'menu_open_type_id' => 'Open Type', 'menu_is_hidden' => 'Is Hidden',
                'menu_is_active' => 'Record Status',
            ],
            'invalid' => ['menu order numeric' => ['menu_order', 'first', 'The Menu Order field must contain only numbers.']],
            'seed' => [
                'menu_app_id' => 2, 'menu_subtitle' => 'Characterization', 'menu_type_id' => 5, 'menu_open_type_id' => 4,
                'menu_position_id' => 7, 'menu_params' => '[{"level":"1"}]', 'menu_is_hidden' => 1,
            ],
            'createSets' => ['menu_app_id' => 2],
            'deleteSets' => ['menu_is_hidden' => 1],
            'list' => ['cells' => 7, 'codeCell' => 1, 'filter' => 'filter_name', 'sort' => ['menu_code_sort', 'menu_title_sort']],
        ];
    }

    public function testTypeAndPositionFilters(): void
    {
        $this->signIn();
        $member = $this->seed('MEMBER', false, ['menu_title' => $this->code('L1')]);
        $admin = $this->seed('ADMIN', false, ['menu_title' => $this->code('L2'), 'menu_type_id' => 6, 'menu_position_id' => 6]);
        $filter = 'filter_name='.$this->fixtures->prefix.'_L';
        $page = $this->browser->get($this->url('index', $filter.'&filter_menu_type_id=6'));
        self::assertSame([$admin['menu_code']], $this->codes($page, 'tab_1'));
        $page = $this->browser->get($this->url('index', $filter.'&filter_menu_position_id=7'));
        self::assertSame([$member['menu_code']], $this->codes($page, 'tab_1'));
    }

    public function testModuleActionLinkIsStoredButAnEmptySelectionDoesNotClearIt(): void
    {
        $this->signIn();
        $index = Database::rows($this->fixtures->db->waf, "SELECT a.modact_id FROM sys_tmst_module_action a
            JOIN sys_tmst_module m ON m.module_id = a.modact_module_id
            WHERE m.module_app_id = 2 AND m.module_code = 'adm_ref_cp_status' AND a.modact_code = 'index'")[0]['modact_id'];
        $row = $this->seed('LINK');
        $form = $this->form('LINK');
        $form['menu_module_action_id'] = (string) $index;
        $this->submit('update_data', $row, $form);
        $this->assertReloaded();
        self::assertSame((string) $index, (string) $this->row($this->code('LINK'))['menu_module_action_id']);
        $this->submit('update_data', $row, $this->form('LINK'));
        $this->assertReloaded();
        self::assertSame((string) $index, (string) $this->row($this->code('LINK'))['menu_module_action_id'],
            'An empty module action is removed from the UPDATE, so the existing link is kept.');
    }

    public function testPurgingAMenuStillGrantedToARoleFailsAndKeepsTheRow(): void
    {
        $this->signIn();
        $row = $this->seed('USED', true);
        Database::insert($this->fixtures->db->waf, 'sys_tmst_role_menu', [
            'role_id' => $this->fixtures->identity('denied')['role_id'], 'menu_id' => $row['menu_id'],
            'sys_entry_user' => $this->fixtures->prefix,
        ]);
        $this->submit('purge_data', $row);
        self::assertSame(500, $this->browser->status());
        self::assertSame(self::REFERENCED_PAGE, $this->text());
        self::assertSame($row, $this->row($this->code('USED')));
        $audit = $this->audit();
        self::assertCount(1, $audit, 'The purge audit row is written before the failing DELETE.');
        $this->assertAuditRow($audit[0], 'D', $row['menu_guid']);
    }
}
