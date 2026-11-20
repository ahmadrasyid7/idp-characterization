<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\HttpTestCase;
use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_configs/index (CI3): application info and the role-filtered configuration menu. */
final class WafConfigsTest extends HttpTestCase
{
    protected function modules(): array
    {
        return ['adm_waf_configs'];
    }

    public function testShowsApplicationInfoAndOnlyGrantedConfigurationMenus(): void
    {
        $this->signIn();
        $page = $this->browser->get('/adm_waf_configs');
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        $text = $this->text();
        self::assertStringContainsString('WAF - Configurations', $text);
        self::assertStringContainsString('Application Name : rST Identity Provider Application Code : dwc_rst_ip', $text);
        self::assertCount(0, $page->filter('a.btn-menucontent'), 'No configuration menu is granted to the fixture role.');

        $menu = Database::rows($this->fixtures->db->waf, "SELECT menu_id, menu_title FROM sys_tmst_menu
            WHERE menu_app_id = 2 AND menu_code = 'IP_ADMINS_WAF_CFG_ACCESS'")[0];
        Database::insert($this->fixtures->db->waf, 'sys_tmst_role_menu', [
            'role_id' => $this->fixtures->identity()['role_id'], 'menu_id' => $menu['menu_id'], 'sys_entry_user' => $this->fixtures->prefix,
        ]);
        $page = $this->browser->get('/adm_waf_configs/index?sidebar_right=IP_ADMINS_WAF_CFG_ACCESS');
        $links = $page->filter('a.btn-menucontent');
        self::assertCount(1, $links);
        self::assertSame($menu['menu_title'], trim($links->text()));
        self::assertSame($this->config->baseUrl.'/adm_waf_configs/waf_ref_access_type/index?sys_menu_active=IP_ADMINS_IP_WAF_H&sys_sub_menu_active=IP_ADMINS_IP_WAF_CONFIG&sidebar_right=IP_ADMINS_WAF_CFG_ACCESS',
            $links->attr('href'));
        self::assertStringContainsString('active', (string) $links->attr('class'));
    }

    public function testDeniedAdminGetsGenericError(): void
    {
        $this->signIn('denied');
        $this->browser->get('/adm_waf_configs/index');
        self::assertSame(500, $this->browser->status());
        self::assertSame(WafCrudTestCase::DENIED_PAGE, $this->text());
    }

    public function testSignedOutRequestRedirectsToLogin(): void
    {
        $this->browser->get('/adm_waf_configs/index');
        self::assertSame(307, $this->browser->status());
        self::assertSame('/admin/', $this->browser->redirect());
    }
}
