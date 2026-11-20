<?php

declare(strict_types=1);

use Characterization\Browser;
use Characterization\HttpTestCase;

/**
 * Module and action rows written through /adm_waf_module and /adm_waf_modact (CI3 or the Symfony replacement) are
 * read the same way by CI3's role-permission screen and grant endpoint and by the Symfony LegacyRbac voter.
 * CI3's page-access check can only be exercised for real controllers, so this uses fixture codes with the
 * permission screen (same app, access-type and active filters) and /_next/whoami instead.
 */
final class WafModuleRbacReadTest extends HttpTestCase
{
    private Browser $user;
    private string $roleGuid;

    protected function modules(): array
    {
        return ['adm_waf_module', 'adm_waf_modact', 'adm_waf_role_permission'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        $this->roleGuid = $this->fixtures->identity('denied')['role_guid'];
        $this->user = $this->newBrowser();
        $this->user->login('denied');
        self::assertSame('/admins/index', $this->user->redirect());
    }

    public function testWrittenModuleRowsChangeCi3PermissionsAndLegacyRbac(): void
    {
        $moduleCode = $this->fixtures->prefix.'_MOD';
        $moduleForm = [
            'module_parent_id' => '0', 'module_code' => $moduleCode, 'module_name' => $moduleCode,
            'module_description' => 'Characterization fixture', 'module_version' => '1.0', 'module_path' => '',
            'module_is_core' => '0', 'module_privilege_id' => '3', 'module_access_type_id' => '7', 'module_is_active' => '1',
        ];
        $this->save('adm_waf_module', 'create_data', '/adm_waf_module/read_input', $moduleForm);
        $module = $this->fixtures->wafRow('sys_tmst_module', $moduleCode);
        self::assertNotNull($module);
        $action = [
            'filter_key' => $module['module_guid'], 'modact_module_id' => (string) $module['module_id'],
            'modact_code' => $this->fixtures->prefix.'_ACT', 'modact_name' => 'Characterization action',
            'modact_version' => '1.0', 'modact_description' => 'Characterization fixture',
            'modact_action_type_id' => '16', 'modact_privilege_id' => '3', 'modact_is_active' => '1',
        ];
        $this->save('adm_waf_modact', 'create_data', '/adm_waf_modact/read_input?filter_key='.rawurlencode($module['module_guid']), $action);
        $actionRow = $this->fixtures->wafRow('sys_tmst_module_action', $action['modact_code']);
        self::assertNotNull($actionRow);

        self::assertTrue($this->listed($moduleCode, $action['modact_code']));
        self::assertFalse($this->userCan($moduleCode, $action['modact_code']));

        $this->grant($actionRow['modact_guid'], '1');
        self::assertTrue($this->userCan($moduleCode, $action['modact_code']));

        $renamed = $action;
        $renamed['modact_code'] = $this->fixtures->prefix.'_ACT2';
        $this->update('adm_waf_modact', $actionRow['modact_guid'], $renamed);
        self::assertFalse($this->listed($moduleCode, $action['modact_code']));
        self::assertTrue($this->listed($moduleCode, $renamed['modact_code']));
        self::assertFalse($this->userCan($moduleCode, $action['modact_code']));
        self::assertTrue($this->userCan($moduleCode, $renamed['modact_code']));

        $moduleForm['module_is_active'] = '0';
        $this->update('adm_waf_module', $module['module_guid'], $moduleForm);
        self::assertFalse($this->listed($moduleCode, $renamed['modact_code']));
        self::assertFalse($this->userCan($moduleCode, $renamed['modact_code']));
        $moduleForm['module_is_active'] = '1';
        $this->update('adm_waf_module', $module['module_guid'], $moduleForm);
        self::assertTrue($this->listed($moduleCode, $renamed['modact_code']));
        self::assertTrue($this->userCan($moduleCode, $renamed['modact_code']));

        $token = $this->browser->csrf('/adm_waf_modact/read_delete?key='.rawurlencode($actionRow['modact_guid']));
        $this->browser->post('/adm_waf_modact/delete_data', $token + ['key' => $actionRow['modact_guid'], 'btn_action' => 'DELETE_DATA']);
        $this->assertReload();
        self::assertFalse($this->listed($moduleCode, $renamed['modact_code']));
        self::assertFalse($this->userCan($moduleCode, $renamed['modact_code']));

        $this->grant($actionRow['modact_guid'], '0');
    }

    private function save(string $module, string $action, string $formPath, array $fields): void
    {
        $token = $this->browser->csrf($formPath);
        $this->browser->post('/'.$module.'/'.$action, $token + $fields + ['btn_action' => 'SAVE_AND_EXIT']);
        $this->assertReload();
    }

    private function update(string $module, string $guid, array $fields): void
    {
        $this->save($module, 'update_data', '/'.$module.'/read_update?key='.rawurlencode($guid), $fields + ['key' => $guid]);
    }

    private function listed(string $moduleCode, string $actionCode): bool
    {
        $this->browser->get('/adm_waf_role/adm_waf_role_permission/index?filter_key='.rawurlencode($this->roleGuid)
            .'&filter_view=module&filter_access_type=ADMIN');
        self::assertSame(200, $this->browser->status(), $this->browser->body());

        return str_contains($this->browser->body(), 'Module '.$moduleCode.'->'.$actionCode.' ');
    }

    private function grant(string $actionGuid, string $granted): void
    {
        $path = '/adm_waf_role/adm_waf_role_permission/index?filter_key='.rawurlencode($this->roleGuid);
        $this->browser->post('/adm_waf_role/adm_waf_role_permission/do_assign_modact', $this->browser->csrf($path)
            + ['p_role_key' => $this->roleGuid, 'p_modact_key' => $actionGuid, 'p_granted_type' => $granted]);
        self::assertTrue($this->json()['success']);
    }

    private function userCan(string $moduleCode, string $actionCode): bool
    {
        $permission = $moduleCode.'.'.$actionCode;
        $this->user->get('/_next/whoami?can='.rawurlencode($permission));
        self::assertSame(200, $this->user->status(), $this->user->body());

        return json_decode($this->user->body(), true, 512, JSON_THROW_ON_ERROR)['can'][$permission];
    }
}
