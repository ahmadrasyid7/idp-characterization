<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\HttpTestCase;
use Characterization\WafCrudTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Characterization of adm_waf_group/adm_waf_group_role (CI3): adding and removing roles in a group. */
final class WafGroupRoleTest extends HttpTestCase
{
    private const PATH = '/adm_waf_group/adm_waf_group_role/';

    private array $group;
    private array $role;

    protected function modules(): array
    {
        return ['adm_waf_group_role'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $prefix = $this->fixtures->prefix;
        $this->group = $this->fixtures->waf('sys_tmst_group', [
            'group_code' => $prefix.'_G1', 'group_name' => $prefix.'_G1', 'group_description' => 'Characterization group', 'group_app_id' => 2,
        ]);
        $this->role = $this->fixtures->waf('sys_tmst_role', [
            'role_app_code' => $prefix.'_R1', 'role_app_name' => $prefix.'_R1', 'role_app_description' => 'Characterization role', 'role_app_id' => 2,
        ]);
    }

    private function assign(string $granted, ?string $group = null, ?string $role = null, ?array $token = null): array
    {
        $token ??= $this->browser->csrf(self::PATH.'index?filter_key='.rawurlencode($this->group['group_guid']));
        $this->browser->post(self::PATH.'do_assign_role', $token + [
            'p_group_key' => $group ?? $this->group['group_guid'], 'p_role_key' => $role ?? $this->role['role_guid'],
            'p_granted_type' => $granted,
        ]);

        return $token;
    }

    private function relation(): array
    {
        return Database::rows($this->fixtures->db->waf, 'SELECT * FROM sys_tmst_group_role WHERE group_id = ? AND role_id = ?',
            [$this->group['group_id'], $this->role['role_id']]);
    }

    private function audit(): array
    {
        return $this->fixtures->auditRows('allowed', 'sys_tmst_group_role');
    }

    /** Selected value of the role's Yes/No select on the group-role screen. */
    private function granted(): string
    {
        $page = $this->browser->get(self::PATH.'index?filter_key='.rawurlencode($this->group['group_guid']));
        self::assertSame(200, $this->browser->status());
        $select = $page->filter('select[name="role_is_granted"]')->reduce(
            fn (Crawler $s) => str_contains((string) $s->attr('onchange'), "'".$this->role['role_guid']."'")
        );
        self::assertCount(1, $select);
        self::assertSame('ROLE_CODE_'.$this->role['role_app_code'], $select->attr('id'));

        return $select->filter('option[selected]')->attr('value');
    }

    public function testAllowedAdminSeesGroupAndEveryRoleWithTheirGrantState(): void
    {
        $this->signIn();
        $this->browser->get(self::PATH.'index?filter_key='.rawurlencode($this->group['group_guid']));
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        $text = $this->text();
        self::assertStringContainsString('WAF - Group Roles', $text);
        self::assertStringContainsString('Group Code: {FIXTURE}_G1 Group Name: {FIXTURE}_G1 Description: Characterization group', $text);
        self::assertSame('0', $this->granted());
        foreach (['a', 'd', 'i'] as $identity) {
            self::assertStringContainsString('{FIXTURE}_'.$identity.' {FIXTURE}_'.$identity, $text, 'Every application role is listed.');
        }
    }

    public function testAssignAndRemoveRoleWritesRowsAuditAndJsonContract(): void
    {
        $this->signIn();
        $token = $this->assign('1');
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        $json = $this->json();
        self::assertTrue($json['success']);
        self::assertSame(['success', 'csrfTokenName', 'csrfHash'], array_keys($json));
        self::assertSame(array_key_first($token), $json['csrfTokenName'], 'The response echoes the active CSRF field name.');
        $rows = $this->relation();
        self::assertCount(1, $rows);
        self::assertSame('1', (string) $rows[0]['group_role_is_active']);
        self::assertSame($this->fixtures->username(), $rows[0]['sys_entry_user']);
        self::assertSame($this->fixtures->username(), $rows[0]['sys_update_user']);
        self::assertSame('0', (string) $rows[0]['sys_soft_delete']);
        $audit = $this->audit();
        self::assertCount(1, $audit);
        self::assertSame('C', $audit[0]['table_action']);
        self::assertSame(['group_id' => (string) $this->group['group_id'], 'role_id' => (string) $this->role['role_id'], 'group_role_is_active' => '1'],
            json_decode($audit[0]['sql_data'], true));
        self::assertSame('1', $this->granted());

        $this->assign('1');
        self::assertSame(400, $this->browser->status(), 'Assigning an existing role is rejected.');
        self::assertFalse($this->json()['success']);
        self::assertCount(1, $this->relation());
        self::assertCount(1, $this->audit());

        $this->assign('0');
        self::assertSame(200, $this->browser->status());
        self::assertTrue($this->json()['success']);
        self::assertSame([], $this->relation());
        $audit = $this->audit();
        self::assertCount(2, $audit);
        self::assertSame('D', $audit[1]['table_action']);
        self::assertSame("DELETE FROM sys_tmst_group_role WHERE group_id = '".$this->group['group_id']."' AND role_id = '".$this->role['role_id']."'",
            $audit[1]['sql_action']);
        $snapshot = json_decode($audit[1]['sql_data'], true);
        self::assertSame($this->fixtures->username(), $snapshot[0]['sys_entry_user'], 'Audit keeps the removed row.');
        self::assertSame('0', $this->granted());

        $this->assign('0');
        self::assertSame(400, $this->browser->status(), 'Removing a missing role is rejected.');
        $this->assign('2');
        self::assertSame(400, $this->browser->status(), 'Only 1 (grant) and 0 (revoke) are actions.');
        self::assertSame([], $this->relation());
        self::assertCount(2, $this->audit());
    }

    /** @dataProvider unknownKeys */
    public function testUnknownGroupOrRoleFailsWithServerError(string $which): void
    {
        $this->signIn();
        $missing = $this->fixtures->prefix.'-missing';
        $this->assign('1', $which === 'group' ? $missing : null, $which === 'role' ? $missing : null);
        self::assertSame(500, $this->browser->status(), 'count(null) TypeError in the legacy controller.');
        self::assertSame(WafCrudTestCase::DENIED_PAGE, $this->text());
        self::assertSame([], $this->relation());
        self::assertSame([], $this->audit());
    }

    public static function unknownKeys(): array
    {
        return ['group' => ['group'], 'role' => ['role']];
    }

    public function testCancelClosesThePopup(): void
    {
        $this->signIn();
        $path = self::PATH.'index?filter_key='.rawurlencode($this->group['group_guid']);
        $this->browser->post($path, $this->browser->csrf($path) + ['btn_action' => 'CANCEL']);
        self::assertSame("<script type='text/javascript'>window.close();window.opener.top.location.reload();</script>", trim($this->browser->body()));
    }

    /** @dataProvider actions */
    public function testDeniedAdminGetsGenericError(string $action): void
    {
        $this->signIn('denied');
        $this->send($action, $this->anonymousToken());
        self::assertSame(500, $this->browser->status());
        self::assertSame(WafCrudTestCase::DENIED_PAGE, $this->text());
        self::assertSame([], $this->relation());
        self::assertSame([], $this->fixtures->auditRows('denied', 'sys_tmst_group_role'));
    }

    /** @dataProvider actions */
    public function testSignedOutRequestRedirectsToLogin(string $action): void
    {
        $this->send($action, $this->anonymousToken());
        self::assertSame($action === 'index' ? 307 : 303, $this->browser->status());
        self::assertSame('/admin/', $this->browser->redirect());
        self::assertSame([], $this->relation());
    }

    private function send(string $action, array $token): void
    {
        if ($action === 'index') {
            $this->browser->get(self::PATH.'index?filter_key='.rawurlencode($this->group['group_guid']));
        } else {
            $this->assign('1', null, null, $token);
        }
    }

    public static function actions(): array
    {
        return ['index' => ['index'], 'do_assign_role' => ['do_assign_role']];
    }
}
