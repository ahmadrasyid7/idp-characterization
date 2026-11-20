<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\HttpTestCase;
use Characterization\WafCrudTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Characterization of adm_waf_role/adm_waf_role_permission (CI3): granting and revoking module actions
 * (sys_tmst_role_module_action) and menus (sys_tmst_role_menu) for a role.
 */
final class WafRolePermissionTest extends HttpTestCase
{
    private const PATH = '/adm_waf_role/adm_waf_role_permission/';

    private array $role;
    private array $modact;
    private array $menu;

    protected function modules(): array
    {
        return ['adm_waf_role_permission'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $prefix = $this->fixtures->prefix;
        $this->role = $this->fixtures->waf('sys_tmst_role', [
            'role_app_code' => $prefix.'_R1', 'role_app_name' => $prefix.'_R1', 'role_app_description' => 'Characterization role', 'role_app_id' => 2,
        ]);
        $module = $this->fixtures->waf('sys_tmst_module', [
            'module_code' => $prefix.'_MOD', 'module_name' => $prefix.'_MOD', 'module_app_id' => 2, 'module_version' => '1.0',
            'module_description' => 'Characterization module', 'module_access_type_id' => 7, 'module_privilege_id' => 3,
        ]);
        $this->modact = $this->fixtures->waf('sys_tmst_module_action', [
            'modact_code' => $prefix.'_ACT', 'modact_module_id' => $module['module_id'], 'modact_name' => $prefix.'_ACT',
            'modact_version' => '1.0', 'modact_description' => 'Characterization action', 'modact_action_type_id' => 16, 'modact_privilege_id' => 3,
        ]);
        // MEMBER menu: the ADMIN menu view can fail on unrelated local menus with non-JSON params (see README).
        $this->menu = $this->fixtures->waf('sys_tmst_menu', [
            'menu_code' => $prefix.'_MN', 'menu_title' => $prefix.'_MN', 'menu_subtitle' => 'Characterization', 'menu_app_id' => 2,
            'menu_type_id' => 5, 'menu_open_type_id' => 4, 'menu_position_id' => 7, 'menu_params' => '[{"level":"1"}]', 'menu_is_hidden' => 1,
        ]);
    }

    private function view(string $view): string
    {
        $query = $view === 'module' ? '&filter_view=module&filter_access_type=ADMIN' : '';

        return self::PATH.'index?filter_key='.rawurlencode($this->role['role_guid']).$query;
    }

    private function assign(string $kind, string $granted, ?string $role = null, ?string $resource = null, ?array $token = null): array
    {
        $token ??= $this->browser->csrf($this->view($kind));
        $this->browser->post(self::PATH.($kind === 'module' ? 'do_assign_modact' : 'do_assign_menu'), $token + [
            'p_role_key' => $role ?? $this->role['role_guid'],
            ($kind === 'module' ? 'p_modact_key' : 'p_menu_key') => $resource ?? ($kind === 'module' ? $this->modact['modact_guid'] : $this->menu['menu_guid']),
            'p_granted_type' => $granted,
        ]);

        return $token;
    }

    private function relation(string $kind): array
    {
        return $kind === 'module'
            ? Database::rows($this->fixtures->db->waf, 'SELECT * FROM sys_tmst_role_module_action WHERE role_id = ? AND modact_id = ?', [$this->role['role_id'], $this->modact['modact_id']])
            : Database::rows($this->fixtures->db->waf, 'SELECT * FROM sys_tmst_role_menu WHERE role_id = ? AND menu_id = ?', [$this->role['role_id'], $this->menu['menu_id']]);
    }

    private function audit(string $kind, string $user = 'allowed'): array
    {
        return $this->fixtures->auditRows($user, $kind === 'module' ? 'sys_tmst_role_module_action' : 'sys_tmst_role_menu');
    }

    private function granted(string $kind): string
    {
        $page = $this->browser->get($this->view($kind));
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        $guid = $kind === 'module' ? $this->modact['modact_guid'] : $this->menu['menu_guid'];
        $select = $page->filter($kind === 'module' ? 'select[name="modact_is_granted"]' : 'select[name="menu_is_granted"]')
            ->reduce(static fn (Crawler $s) => str_contains((string) $s->attr('onchange'), "'".$guid."'"));
        self::assertCount(1, $select);
        $prefix = $this->fixtures->prefix;
        self::assertSame($kind === 'module' ? 'MODACT_CODE_'.$prefix.'_MOD_'.$prefix.'_ACT' : 'MENU_CODE_'.$prefix.'_MN', $select->attr('id'));

        return $select->filter('option[selected]')->attr('value');
    }

    public function testViewsDefaultToMemberMenusAndFilterModulesByAccessType(): void
    {
        $this->signIn();
        $this->browser->get(self::PATH.'index?filter_key='.rawurlencode($this->role['role_guid']));
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertStringContainsString('WAF - Role Permissions (menu)', $this->text());
        self::assertStringContainsString('Role Code: {FIXTURE}_R1 Role Name: {FIXTURE}_R1 Description: Characterization role', $this->text());
        self::assertSame('0', $this->granted('menu'));
        $this->browser->get($this->view('module'));
        self::assertStringContainsString('WAF - Role Permissions (module)', $this->text());
        self::assertStringContainsString('Module {FIXTURE}_MOD->{FIXTURE}_ACT', $this->text());
        self::assertSame('0', $this->granted('module'));
        $this->browser->get(self::PATH.'index?filter_key='.rawurlencode($this->role['role_guid']).'&filter_view=module&filter_access_type=MEMBER');
        self::assertStringNotContainsString($this->modact['modact_guid'], $this->browser->body(), 'ADMIN modules are hidden under MEMBER.');
    }

    /** @dataProvider kinds */
    public function testGrantAndRevokeWritesRowsAuditAndJsonContract(string $kind): void
    {
        $this->signIn();
        $table = $kind === 'module' ? 'sys_tmst_role_module_action' : 'sys_tmst_role_menu';
        $resource = $kind === 'module' ? 'modact_id' : 'menu_id';
        $token = $this->assign($kind, '1');
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        $json = $this->json();
        self::assertSame(['success' => true, 'csrfTokenName' => array_key_first($token)], array_slice($json, 0, 2, true));
        $rows = $this->relation($kind);
        self::assertCount(1, $rows);
        self::assertSame($this->fixtures->username(), $rows[0]['sys_entry_user']);
        self::assertSame($this->fixtures->username(), $rows[0]['sys_update_user']);
        self::assertNotNull($rows[0]['sys_entry_date']);
        $audit = $this->audit($kind);
        self::assertCount(1, $audit);
        self::assertSame('C', $audit[0]['table_action']);
        self::assertSame($table, $audit[0]['table_name']);
        self::assertSame(['role_id' => (string) $this->role['role_id'], $resource => (string) ($kind === 'module' ? $this->modact['modact_id'] : $this->menu['menu_id'])],
            json_decode($audit[0]['sql_data'], true));
        self::assertSame('1', $this->granted($kind));

        $this->assign($kind, '1');
        self::assertSame(400, $this->browser->status(), 'Granting twice is rejected.');
        self::assertFalse($this->json()['success']);
        self::assertCount(1, $this->audit($kind));

        $this->assign($kind, '0');
        self::assertSame(200, $this->browser->status());
        self::assertTrue($this->json()['success']);
        self::assertSame([], $this->relation($kind));
        $audit = $this->audit($kind);
        self::assertCount(2, $audit);
        self::assertSame('D', $audit[1]['table_action']);
        self::assertSame($this->fixtures->username(), json_decode($audit[1]['sql_data'], true)[0]['sys_entry_user']);
        self::assertSame('0', $this->granted($kind));

        $this->assign($kind, '0');
        self::assertSame(400, $this->browser->status(), 'Revoking a missing grant is rejected.');
        self::assertCount(2, $this->audit($kind));
    }

    /** @dataProvider unknownKeys */
    public function testUnknownRoleOrResourceFailsWithServerError(string $kind, string $which): void
    {
        $this->signIn();
        $missing = $this->fixtures->prefix.'-missing';
        $this->assign($kind, '1', $which === 'role' ? $missing : null, $which === 'resource' ? $missing : null);
        self::assertSame(500, $this->browser->status(), 'count(null) TypeError in the legacy controller.');
        self::assertSame(WafCrudTestCase::DENIED_PAGE, $this->text());
        self::assertSame([], $this->relation($kind));
        self::assertSame([], $this->audit($kind));
    }

    public static function unknownKeys(): array
    {
        return [
            'module role' => ['module', 'role'], 'module action' => ['module', 'resource'],
            'menu role' => ['menu', 'role'], 'menu' => ['menu', 'resource'],
        ];
    }

    public static function kinds(): array
    {
        return ['module action' => ['module'], 'menu' => ['menu']];
    }

    public function testCancelClosesThePopup(): void
    {
        $this->signIn();
        $this->browser->post($this->view('menu'), $this->browser->csrf($this->view('menu')) + ['btn_action' => 'CANCEL']);
        self::assertSame("<script type='text/javascript'>window.close();window.opener.top.location.reload();</script>", trim($this->browser->body()));
    }

    /** @dataProvider actions */
    public function testDeniedAdminGetsGenericError(string $action): void
    {
        $this->signIn('denied');
        $this->send($action);
        self::assertSame(500, $this->browser->status());
        self::assertSame(WafCrudTestCase::DENIED_PAGE, $this->text());
        foreach (['module', 'menu'] as $kind) {
            self::assertSame([], $this->relation($kind));
            self::assertSame([], $this->audit($kind, 'denied'));
        }
    }

    /** @dataProvider actions */
    public function testSignedOutRequestRedirectsToLogin(string $action): void
    {
        $this->send($action);
        self::assertSame($action === 'index' ? 307 : 303, $this->browser->status());
        self::assertSame('/admin/', $this->browser->redirect());
        self::assertSame([], $this->relation('module'));
        self::assertSame([], $this->relation('menu'));
    }

    private function send(string $action): void
    {
        if ($action === 'index') {
            $this->browser->get($this->view('menu'));
        } else {
            $this->assign($action === 'do_assign_modact' ? 'module' : 'menu', '1', null, null, $this->anonymousToken());
        }
    }

    public static function actions(): array
    {
        return ['index' => ['index'], 'do_assign_menu' => ['do_assign_menu'], 'do_assign_modact' => ['do_assign_modact']];
    }
}
