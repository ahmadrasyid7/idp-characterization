<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\HttpTestCase;

final class AdminLoginTest extends HttpTestCase
{
    public function testLoginFormAndSuccessfulLogin(): void
    {
        $page = $this->browser->get('/admin');
        self::assertSame(200, $this->browser->status());
        self::assertCount(1, $page->filter('form[action$="/pbc_login/do_login_admin"]'));
        self::assertCount(1, $page->filter('input[name="email_admin"]'));
        self::assertCount(1, $page->filter('input[name="password_admin"]'));
        self::assertCount(1, $page->filter('input[name="csrf_token_idp"], input[name="ci_csrf_token"]'));
        $this->signIn();
        $cookie = $this->browser->client->getCookieJar()->get('waf_session_idp');
        self::assertNotNull($cookie);
        self::assertNotSame('', $cookie->getValue());
        $session = Database::rows($this->fixtures->db->waf,
            'SELECT * FROM sys_ttrx_ci3_sessions WHERE id = ?', [$cookie->getValue()]);
        self::assertCount(1, $session);
        self::assertStringContainsString('waf_user_is_loged|i:1;', $session[0]['data']);
        self::assertStringContainsString($this->fixtures->username(), $session[0]['data']);
        $this->browser->get('/admin');
        self::assertSame(307, $this->browser->status());
        self::assertSame('/admins', $this->browser->redirect());
        $this->browser->get('/adm_ref_cp_status');
        self::assertSame(200, $this->browser->status());
        self::assertStringContainsString('References - Client-Product Status', $this->text());
    }

    /** @dataProvider invalidLogins */
    public function testLoginErrors(string $kind, ?string $password, string $message): void
    {
        $this->browser->login($kind, $password);
        self::assertSame(200, $this->browser->status());
        self::assertNull($this->browser->redirect());
        self::assertStringContainsString($message, $this->text());
        $this->browser->get('/adm_ref_cp_status');
        self::assertSame(307, $this->browser->status());
        self::assertSame('/admin/', $this->browser->redirect());
    }

    public static function invalidLogins(): array
    {
        return [
            'unknown user' => ['unknown', null, 'User does not exist'],
            'wrong password' => ['allowed', 'not-the-fixture-password', 'Wrong Credentials'],
            'inactive user' => ['inactive', null, 'Your Administrator account was not active, please contact administrator'],
        ];
    }

    /** @dataProvider missingFields */
    public function testRequiredLoginFields(array $fields, array $messages): void
    {
        $this->browser->post('/pbc_login/do_login_admin', $this->browser->csrf('/admin') + $fields);
        self::assertSame(200, $this->browser->status());
        foreach ($messages as $message) {
            self::assertStringContainsString($message, $this->text());
        }
        self::assertNull($this->browser->redirect());
    }

    public static function missingFields(): array
    {
        return [
            'both' => [[], ['The Email field is required.', 'The Password field is required.']],
            'email' => [['password_admin' => 'present'], ['The Email field is required.']],
            'password' => [['email_admin' => 'present'], ['The Password field is required.']],
        ];
    }

    public function testLogoutInvalidatesEvenReplayedSessionCookie(): void
    {
        $this->signIn();
        $cookie = $this->browser->client->getCookieJar()->get('waf_session_idp');
        self::assertNotNull($cookie);
        $this->browser->get('/logout');
        self::assertSame(200, $this->browser->status());
        self::assertSame('/', $this->browser->redirect());
        self::assertSame([], Database::rows($this->fixtures->db->waf,
            'SELECT id FROM sys_ttrx_ci3_sessions WHERE id = ?', [$cookie->getValue()]));
        $this->browser = $this->newBrowser();
        $this->browser->client->getCookieJar()->set($cookie);
        $this->browser->get('/adm_ref_cp_status');
        self::assertSame(307, $this->browser->status());
        self::assertSame('/admin/', $this->browser->redirect());
    }

    public function testSessionExpiresAfter7200Seconds(): void
    {
        $this->signIn();
        $cookie = $this->browser->client->getCookieJar()->get('waf_session_idp');
        self::assertNotNull($cookie);
        self::assertGreaterThanOrEqual(time() + 7190, $cookie->getExpiresTime());
        self::assertLessThanOrEqual(time() + 7200, $cookie->getExpiresTime());
        Database::execute($this->fixtures->db->waf, 'UPDATE sys_ttrx_ci3_sessions SET timestamp = ? WHERE id = ?',
            [time() - 7100, $cookie->getValue()]);
        $this->newBrowser()->get('/admin');
        $this->browser->get('/adm_ref_cp_status');
        self::assertSame(200, $this->browser->status());
        Database::execute($this->fixtures->db->waf, 'UPDATE sys_ttrx_ci3_sessions SET timestamp = ? WHERE id = ?',
            [time() - 7201, $cookie->getValue()]);
        // WAF's cleanup happens after PHP reads the current session; trigger it with another client.
        $this->newBrowser()->get('/admin');
        $this->browser->get('/adm_ref_cp_status');
        self::assertSame(307, $this->browser->status());
        self::assertSame('/admin/', $this->browser->redirect());
    }

    public function testCsrfEnforcementMatchesEnvironment(): void
    {
        $this->browser->csrf('/admin');
        $enforced = $this->browser->client->getCookieJar()->get('csrf_cookie_idp') !== null;
        $this->browser->post('/pbc_login/do_login_admin', [
            'email_admin' => $this->fixtures->username(), 'password_admin' => $this->config->password,
        ]);
        if ($enforced) {
            self::assertSame(403, $this->browser->status());
            self::assertStringContainsString('403 Something went wrong', $this->text());
        } else {
            self::assertSame(200, $this->browser->status());
            self::assertSame('/admins/index', $this->browser->redirect());
        }
    }
}
