<?php

declare(strict_types=1);

use Characterization\Fixtures;
use Characterization\HttpTestCase;

final class RbacTest extends HttpTestCase
{
    /** @dataProvider actions */
    public function testAllowedAction(string $action): void
    {
        $this->signIn();
        $row = $this->fixtures->status('RBAC', in_array($action, ['read_restore_purge', 'restore_data', 'purge_data'], true));
        if ($action === 'create_data') {
            $token = $this->browser->csrf('/adm_ref_cp_status/read_input');
            $payload = $this->payload();
            $this->browser->post('/adm_ref_cp_status/create_data', $token + $payload);
            $this->assertReload();
            self::assertNotNull($this->fixtures->row($payload['cp_status_code']));
        } elseif (in_array($action, ['update_data', 'delete_data', 'restore_data', 'purge_data'], true)) {
            $payload = $action === 'update_data' ? $this->payload('RBAC') : [];
            $this->mutate($action, $row, $payload);
            $this->assertReload();
            $after = $this->fixtures->row($row['cp_status_code']);
            if ($action === 'purge_data') {
                self::assertNull($after);
            } else {
                self::assertNotNull($after);
                self::assertSame($action === 'delete_data' ? '1' : '0', (string) $after['sys_soft_delete']);
            }
        } else {
            $this->browser->get('/adm_ref_cp_status/'.$action.'?key='.rawurlencode($row['cp_status_guid']));
            self::assertSame(200, $this->browser->status(), $this->browser->body());
            self::assertNull($this->browser->redirect());
            self::assertStringNotContainsString('Access Denied!', $this->text());
            if ($action === 'index') {
                self::assertStringContainsString('References - Client-Product Status', $this->text());
            } elseif (in_array($action, ['read_input', 'read_update'], true)) {
                self::assertStringContainsString('name="cp_status_code"', $this->browser->body());
            } else {
                self::assertStringContainsString('{FIXTURE}_RBAC', $this->text());
            }
        }
    }

    /** @dataProvider actions */
    public function testDeniedActionDoesNotChangeRowsOrAudit(string $action): void
    {
        $this->signIn('denied');
        $row = $this->fixtures->status('RBAC');
        $audit = $this->fixtures->auditRows('denied');
        $this->sendAction($action, $row);
        self::assertSame(500, $this->browser->status(), $this->browser->body());
        self::assertNull($this->browser->redirect());
        self::assertSame(
            '500 Something went wrong An unexpected error occurred while processing your request. Please try again in a few minutes. If the problem continues, contact support and include the reference below. Reference: {REFERENCE} Go back Home',
            $this->text()
        );
        self::assertSame($row, $this->fixtures->row($row['cp_status_code']));
        self::assertNull($this->fixtures->row($this->fixtures->prefix.'_CREATED'));
        self::assertSame($audit, $this->fixtures->auditRows('denied'));
    }

    /** @dataProvider actions */
    public function testSignedOutActionRedirectsToLogin(string $action): void
    {
        $row = $this->fixtures->status('RBAC');
        $this->sendAction($action, $row);
        $post = in_array($action, ['create_data', 'update_data', 'delete_data', 'restore_data', 'purge_data'], true);
        self::assertSame($post ? 303 : 307, $this->browser->status());
        self::assertSame('/admin/', $this->browser->redirect());
        self::assertSame($row, $this->fixtures->row($row['cp_status_code']));
        self::assertSame([], $this->fixtures->auditRows());
    }

    private function sendAction(string $action, array $row, array $token = []): void
    {
        if (in_array($action, ['create_data', 'update_data', 'delete_data', 'restore_data', 'purge_data'], true)) {
            $buttons = ['delete_data' => 'DELETE_DATA', 'restore_data' => 'RESTORE_DATA', 'purge_data' => 'PURGE_DATA'];
            // Login is public, so it supplies a valid CSRF token even when every module action is denied.
            if ($token === []) {
                $anonymous = $this->newBrowser();
                $token = $anonymous->csrf('/admin');
                $csrfCookie = $anonymous->client->getCookieJar()->get('csrf_cookie_idp');
                if ($csrfCookie !== null) {
                    $this->browser->client->getCookieJar()->set($csrfCookie);
                }
            }
            $this->browser->post('/adm_ref_cp_status/'.$action, $token + [
                'key' => $row['cp_status_guid'],
                'btn_action' => $buttons[$action] ?? 'SAVE_AND_EXIT',
            ] + $this->payload());
        } else {
            $this->browser->get('/adm_ref_cp_status/'.$action.'?key='.rawurlencode($row['cp_status_guid']));
        }
    }

    public static function actions(): array
    {
        $cases = [];
        foreach (Fixtures::ACTIONS as $action) {
            $cases[$action] = [$action];
        }

        return $cases;
    }
}
