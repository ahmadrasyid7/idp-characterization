<?php

declare(strict_types=1);

namespace Characterization;

use PHPUnit\Framework\TestCase;

abstract class HttpTestCase extends TestCase
{
    protected Config $config;
    protected Fixtures $fixtures;
    protected Browser $browser;

    protected function setUp(): void
    {
        $this->config = new Config();
        $this->fixtures = new Fixtures($this->config, null, $this->modules());
        try {
            $this->fixtures->seed();
            $this->browser = $this->newBrowser();
        } catch (\Throwable $error) {
            $this->finishFixtures();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtures)) {
            $this->finishFixtures();
        }
    }

    private function finishFixtures(): void
    {
        try {
            $this->fixtures->cleanup();
        } finally {
            $this->fixtures->close();
            unset($this->fixtures);
        }
    }

    /** @return list<string> modules granted to the allowed fixture identity (see Fixtures::MODULE_ACTIONS) */
    protected function modules(): array
    {
        return ['adm_ref_cp_status'];
    }

    protected function newBrowser(): Browser
    {
        return new Browser($this->config, $this->fixtures);
    }

    /** A CSRF token from the public login form, usable when every module action is denied or signed out. */
    protected function anonymousToken(): array
    {
        $anonymous = $this->newBrowser();
        $token = $anonymous->csrf('/admin');
        $cookie = $anonymous->client->getCookieJar()->get('csrf_cookie_idp');
        if ($cookie !== null) {
            $this->browser->client->getCookieJar()->set($cookie);
        }

        return $token;
    }

    /** Decodes a legacy AJAX assignment response ({success, csrfTokenName, csrfHash}). */
    protected function json(): array
    {
        self::assertStringStartsWith('application/json', (string) $this->browser->client->getResponse()->getHeader('Content-Type'));

        return json_decode($this->browser->body(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function signIn(string $kind = 'allowed'): void
    {
        $this->browser->login($kind);
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertSame('/admins/index', $this->browser->redirect());
    }

    protected function text(): string
    {
        return Normalizer::text($this->browser->body(), $this->config->baseUrl, $this->fixtures->prefix);
    }

    protected function payload(string $suffix = 'CREATED'): array
    {
        return [
            'cp_status_code' => $this->fixtures->prefix.'_'.$suffix,
            'cp_status_name' => $this->fixtures->prefix.'_'.$suffix,
            'cp_status_description' => 'Characterization description',
            'cp_status_is_active' => '1', 'btn_action' => 'SAVE_AND_EXIT',
        ];
    }

    protected function mutate(string $action, array $row, array $payload = []): void
    {
        $forms = [
            'update_data' => ['read_update', 'SAVE_AND_EXIT'],
            'delete_data' => ['read_delete', 'DELETE_DATA'],
            'restore_data' => ['read_restore_purge', 'RESTORE_DATA'],
            'purge_data' => ['read_restore_purge', 'PURGE_DATA'],
        ];
        [$form, $button] = $forms[$action];
        $token = $this->browser->csrf('/adm_ref_cp_status/'.$form.'?key='.rawurlencode($row['cp_status_guid']));
        $this->browser->post('/adm_ref_cp_status/'.$action, $token + $payload + [
            'key' => $row['cp_status_guid'], 'btn_action' => $button,
        ]);
    }

    protected function assertReload(): void
    {
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertSame("<script type='text/javascript'>window.top.location.reload();</script>", trim($this->browser->body()));
    }

    protected function assertAudit(string $action, string $identifier, int $before): array
    {
        $rows = $this->fixtures->auditRows();
        self::assertCount($before + 1, $rows);
        $audit = $rows[$before];
        self::assertSame($action, $audit['table_action']);
        self::assertSame('tref_cp_status', $audit['table_name']);
        self::assertSame(Config::required('CHAR_DB_IP'), $audit['table_schema']);
        self::assertSame($this->fixtures->username(), $audit['user_application']);
        self::assertSame('2', (string) $audit['app_id']);
        self::assertStringContainsString($identifier, $audit['sql_action']);
        self::assertNotNull($audit['date_audit']);
        self::assertIsArray(json_decode($audit['sql_data'], true, 512, JSON_THROW_ON_ERROR));

        return $audit;
    }
}
