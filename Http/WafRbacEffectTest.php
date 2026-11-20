<?php

declare(strict_types=1);

use Characterization\Browser;
use Characterization\Database;
use Characterization\HttpTestCase;
use Characterization\Normalizer;
use Characterization\WafCrudTestCase;

/**
 * End-to-end RBAC effect: permissions changed through the WAF screens by one ZZTEST_ admin immediately change what
 * another ZZTEST_ admin (the "denied" identity) can open. This protects the legacy RBAC voter during the migration.
 */
final class WafRbacEffectTest extends HttpTestCase
{
    private const TARGET = '/adm_ref_cp_status';
    private const TARGET_TITLE = 'References - Client-Product Status';

    private Browser $user;
    private array $target;

    protected function modules(): array
    {
        return ['adm_waf_role_permission', 'adm_waf_group_role'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->target = Database::rows($this->fixtures->db->waf, "SELECT a.modact_id, a.modact_guid FROM sys_tmst_module_action a
            JOIN sys_tmst_module m ON m.module_id = a.modact_module_id
            WHERE m.module_app_id = 2 AND m.module_code = 'adm_ref_cp_status' AND a.modact_code = 'index'")[0];
        $this->signIn();
        // Never open the target before granting: development mode auto-grants a denied action to the user's role.
        $this->user = $this->newBrowser();
        $this->user->login('denied');
        self::assertSame('/admins/index', $this->user->redirect());
    }

    private function post(string $path, string $formPath, array $fields): void
    {
        $this->browser->post($path, $this->browser->csrf($formPath) + $fields);
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertTrue(json_decode($this->browser->body(), true)['success']);
    }

    private function grantModact(string $roleGuid, string $granted): void
    {
        $this->post('/adm_waf_role/adm_waf_role_permission/do_assign_modact',
            '/adm_waf_role/adm_waf_role_permission/index?filter_key='.rawurlencode($roleGuid),
            ['p_role_key' => $roleGuid, 'p_modact_key' => $this->target['modact_guid'], 'p_granted_type' => $granted]);
    }

    private function grantMenu(string $roleGuid, string $menuGuid, string $granted): void
    {
        $this->post('/adm_waf_role/adm_waf_role_permission/do_assign_menu',
            '/adm_waf_role/adm_waf_role_permission/index?filter_key='.rawurlencode($roleGuid),
            ['p_role_key' => $roleGuid, 'p_menu_key' => $menuGuid, 'p_granted_type' => $granted]);
    }

    private function assertUserCanOpenTarget(): void
    {
        $this->user->get(self::TARGET);
        self::assertSame(200, $this->user->status(), $this->user->body());
        self::assertStringContainsString(self::TARGET_TITLE, $this->userText());
    }

    private function assertUserIsDeniedTarget(): void
    {
        $this->user->get(self::TARGET);
        self::assertSame(500, $this->user->status());
        self::assertSame(WafCrudTestCase::DENIED_PAGE, $this->userText());
    }

    private function userText(): string
    {
        return Normalizer::text($this->user->body(), $this->config->baseUrl, $this->fixtures->prefix);
    }

    public function testGrantingAndRevokingAModuleActionChangesAccess(): void
    {
        $role = $this->fixtures->identity('denied')['role_guid'];
        $this->grantModact($role, '1');
        $this->assertUserCanOpenTarget();
        $this->grantModact($role, '0');
        $this->assertUserIsDeniedTarget();
    }

    public function testMenuVisibilityFollowsTheMenuGrantAndPageAccessFollowsTheActionGrant(): void
    {
        $role = $this->fixtures->identity('denied')['role_guid'];
        $type = Database::rows($this->fixtures->db->waf, "SELECT menu_type_id FROM sys_tref_menu_type WHERE menu_type_code = 'ADMIN' AND menu_type_app_id = 2")[0]['menu_type_id'];
        $position = Database::rows($this->fixtures->db->waf, "SELECT menu_position_id FROM sys_tref_menu_position WHERE menu_position_code = 'SIDE_BAR_LEFT' AND menu_position_app_id = 2")[0]['menu_position_id'];
        $menu = $this->fixtures->waf('sys_tmst_menu', [
            'menu_code' => $this->fixtures->prefix.'_MN', 'menu_title' => $this->fixtures->prefix.'_MENU', 'menu_subtitle' => 'Characterization',
            'menu_app_id' => 2, 'menu_type_id' => $type, 'menu_position_id' => $position, 'menu_open_type_id' => 4,
            'menu_params' => '[{"level":"1"}]', 'menu_is_hidden' => 0, 'menu_module_action_id' => $this->target['modact_id'],
        ]);
        // The admin home (sidebar host) needs welcome_admin.index; grant it to the fixture role only.
        Database::insert($this->fixtures->db->waf, 'sys_tmst_role_module_action', [
            'role_id' => $this->fixtures->identity('denied')['role_id'], 'sys_entry_user' => $this->fixtures->prefix,
            'modact_id' => Database::rows($this->fixtures->db->waf, "SELECT a.modact_id FROM sys_tmst_module_action a
                JOIN sys_tmst_module m ON m.module_id = a.modact_module_id
                WHERE m.module_app_id = 2 AND m.module_code = 'welcome_admin' AND a.modact_code = 'index'")[0]['modact_id'],
        ]);
        $visible = function (): bool {
            $this->user->get('/admins/welcome_admin/index');
            self::assertSame(200, $this->user->status(), $this->user->body());

            return str_contains($this->userText(), '{FIXTURE}_MENU');
        };
        self::assertFalse($visible());
        $this->grantMenu($role, $menu['menu_guid'], '1');
        // Legacy quirk: get_user_menus joins sys_tmst_role_module_action without the user's role, so the linked
        // action being granted to ANY role (real admin roles hold cp_status.index) is enough to show the menu.
        self::assertTrue($visible(), 'Menu is shown before the user\'s role holds the linked action.');
        $this->grantModact($role, '1');
        $this->assertUserCanOpenTarget();
        $this->grantMenu($role, $menu['menu_guid'], '0');
        self::assertFalse($visible());
        $this->assertUserCanOpenTarget();
        $this->grantModact($role, '0');
        $this->assertUserIsDeniedTarget();
    }

    public function testAddingAndRemovingARoleInTheUsersGroupChangesAccess(): void
    {
        $role = $this->fixtures->waf('sys_tmst_role', [
            'role_app_code' => $this->fixtures->prefix.'_RX', 'role_app_name' => $this->fixtures->prefix.'_RX', 'role_app_id' => 2,
        ]);
        Database::insert($this->fixtures->db->waf, 'sys_tmst_role_module_action', [
            'role_id' => $role['role_id'], 'modact_id' => $this->target['modact_id'], 'sys_entry_user' => $this->fixtures->prefix,
        ]);
        $group = $this->fixtures->identity('denied')['group_guid'];
        $assign = function (string $granted) use ($role, $group): void {
            $this->post('/adm_waf_group/adm_waf_group_role/do_assign_role',
                '/adm_waf_group/adm_waf_group_role/index?filter_key='.rawurlencode($group),
                ['p_group_key' => $group, 'p_role_key' => $role['role_guid'], 'p_granted_type' => $granted]);
        };
        $assign('1');
        $this->assertUserCanOpenTarget();
        $assign('0');
        $this->assertUserIsDeniedTarget();
    }
}
