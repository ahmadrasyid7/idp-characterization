<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\HttpTestCase;
use Characterization\WafCrudTestCase;

/**
 * Characterization of adm_waf_mail (CI3). Templates are keyed by mail_template_type, so fixtures use random
 * unused types far above the real ones (1-15); real templates are only read, never written.
 */
final class WafMailTest extends HttpTestCase
{
    private const INDEX = '/adm_waf_mail/index?sys_menu_active=IP_ADMINS_IP_WAF_H&sys_sub_menu_active=IP_ADMINS_WAF_MAIL_TEMPLATE';

    private int $type;
    private array $template;

    protected function modules(): array
    {
        return ['adm_waf_mail'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = random_int(1000000000, 1999999999);
        $this->template = $this->fixtures->waf('sys_tref_mail_template', [
            'mail_template_subject' => $this->fixtures->prefix.'_MAIL', 'mail_template_type' => $this->type,
            'mail_template_header' => 'Fixture header', 'mail_template_body' => 'Fixture body', 'mail_template_footer' => 'Fixture footer',
        ]);
    }

    private function unusedType(): int
    {
        do {
            $type = random_int(1000000000, 1999999999);
        } while ($type === $this->type || $this->byType($type) !== []);

        return $type;
    }

    private function byType(int $type): array
    {
        return Database::rows($this->fixtures->db->waf, 'SELECT * FROM sys_tref_mail_template WHERE mail_template_type = ?', [$type]);
    }

    private function audit(): array
    {
        return $this->fixtures->auditRows('allowed', 'sys_tref_mail_template');
    }

    private function fields(string $subject, int $type): array
    {
        return [
            'active_tabs' => 'tab_1', 'mail_template_type' => (string) $type, 'mail_template_subject' => $subject,
            'mail_template_header' => 'Characterization header', 'mail_template_body' => 'Characterization body',
            'mail_template_footer' => 'Characterization footer', 'btn_action' => 'SAVE_AND_EXIT',
        ];
    }

    private function post(string $action, array $fields, ?int $formType = null): void
    {
        $token = $this->browser->csrf('/adm_waf_mail/index?mail_template_type='.($formType ?? $this->type));
        $this->browser->post('/adm_waf_mail/'.$action, $token + $fields);
    }

    private function assertRefreshTo(string $target): void
    {
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertSame($target, $this->browser->redirect());
    }

    public function testIndexEditsTheTemplateOfTheRequestedType(): void
    {
        $this->signIn();
        $page = $this->browser->get('/adm_waf_mail/index?mail_template_type='.$this->type);
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertStringContainsString('WAF - Mail Template', $this->text());
        $form = $page->filter('#tab_1 form');
        self::assertSame($this->config->baseUrl.'/adm_waf_mail/update_data', $form->attr('action'));
        self::assertSame($this->template['mail_template_guid'], $form->filter('input[name="key"]')->attr('value'));
        self::assertSame($this->template['mail_template_subject'], $form->filter('input[name="mail_template_subject"]')->attr('value'));
        self::assertSame('Fixture header', $form->filter('input[name="mail_template_header"]')->attr('value'));
        self::assertSame('Fixture body', $form->filter('textarea[name="mail_template_body"]')->text());
        self::assertSame('Fixture footer', $form->filter('textarea[name="mail_template_footer"]')->text());
        self::assertSame(['11', '1', '2', '12', '3', '4', '5', '6', '7', '8', '9', '10', '13'],
            $form->filter('select[name="mail_template_type"] option')->each(static fn ($o) => $o->attr('value')));
    }

    public function testIndexDefaultsToType11AndShowsACreateFormForAnUnusedType(): void
    {
        $this->signIn();
        $page = $this->browser->get('/adm_waf_mail/index');
        self::assertSame('11', $page->filter('select[name="mail_template_type"] option[selected]')->attr('value'));
        $page = $this->browser->get('/adm_waf_mail/index?mail_template_type='.$this->unusedType());
        $form = $page->filter('#tab_1 form');
        self::assertSame($this->config->baseUrl.'/adm_waf_mail/create_data', $form->attr('action'));
        self::assertCount(0, $form->filter('input[name="key"]'));
        self::assertSame('', $form->filter('input[name="mail_template_subject"]')->attr('value'));
    }

    public function testUpdateSavesTheTemplateAndRefreshesBackToItsType(): void
    {
        $this->signIn();
        $fields = $this->fields($this->fixtures->prefix.'_MAIL_U', $this->type) + ['key' => $this->template['mail_template_guid']];
        $this->post('update_data', $fields);
        $this->assertRefreshTo(self::INDEX.'&mail_template_type='.$this->type);
        $row = $this->fixtures->wafRow('sys_tref_mail_template', $this->fixtures->prefix.'_MAIL_U');
        foreach (['mail_template_header', 'mail_template_body', 'mail_template_footer'] as $field) {
            self::assertSame($fields[$field], $row[$field]);
        }
        self::assertSame($this->fixtures->prefix, $row['sys_entry_user']);
        self::assertSame($this->fixtures->username(), $row['sys_update_user']);
        $audit = $this->audit();
        self::assertCount(1, $audit);
        self::assertSame('U', $audit[0]['table_action']);
        self::assertStringContainsString($this->template['mail_template_guid'], $audit[0]['sql_action']);
        self::assertSame((string) $audit[0]['id_log'], (string) $row['sys_id_log_audit']);
    }

    public function testCreateInsertsATemplateForTheSelectedType(): void
    {
        $this->signIn();
        $type = $this->unusedType();
        $this->post('create_data', $this->fields($this->fixtures->prefix.'_NEW', $type), $type);
        $this->assertRefreshTo(self::INDEX.'&mail_template_type='.$type);
        $row = $this->fixtures->wafRow('sys_tref_mail_template', $this->fixtures->prefix.'_NEW');
        self::assertNotNull($row);
        self::assertSame($type, (int) $row['mail_template_type']);
        self::assertSame('Characterization body', $row['mail_template_body']);
        self::assertSame('1', (string) $row['mail_template_is_active']);
        self::assertNull($row['sys_soft_delete'], 'Mail templates have no soft-delete default.');
        self::assertSame($this->fixtures->username(), $row['sys_entry_user']);
        $audit = $this->audit();
        self::assertCount(1, $audit);
        self::assertSame('C', $audit[0]['table_action']);
        self::assertSame($this->fixtures->prefix.'_NEW', json_decode($audit[0]['sql_data'], true)['mail_template_subject']);
    }

    /** @dataProvider requiredFields */
    public function testValidationFailureSilentlyRefreshesWithoutSaving(string $field, bool $update): void
    {
        $this->signIn();
        $type = $update ? $this->type : $this->unusedType();
        $fields = $this->fields($this->fixtures->prefix.'_BAD', $type);
        $fields[$field] = '';
        if ($update) {
            $fields['key'] = $this->template['mail_template_guid'];
        }
        $this->post($update ? 'update_data' : 'create_data', $fields, $type);
        $this->assertRefreshTo(self::INDEX.'&mail_template_type='.$type);
        self::assertSame($update ? [$this->template] : [], $this->byType($type));
        self::assertSame([], $this->audit());
        $this->browser->get('/adm_waf_mail/index?mail_template_type='.$type);
        self::assertStringNotContainsString('field is required', $this->text(), 'Validation errors are lost on the redirect.');
    }

    public static function requiredFields(): array
    {
        $cases = [];
        foreach (['mail_template_subject', 'mail_template_header', 'mail_template_body', 'mail_template_footer'] as $field) {
            $cases['create '.$field] = [$field, false];
            $cases['update '.$field] = [$field, true];
        }

        return $cases;
    }

    public function testCancelOnlyReloadsThePage(): void
    {
        $this->signIn();
        foreach (['create_data', 'update_data', 'create_data_is', 'update_data_is'] as $action) {
            $this->post($action, ['btn_action' => 'CANCEL', 'mail_template_type' => (string) $this->type, 'mail_template_type_is' => '14']);
            self::assertSame(WafCrudTestCase::RELOAD, trim($this->browser->body()), $action);
        }
        self::assertSame([$this->template], $this->byType($this->type));
        self::assertSame([], $this->audit());
    }

    public function testIdentityServerCreateSavesThenFailsOnMissingRegistryUpdate(): void
    {
        $this->signIn();
        $type = $this->unusedType();
        $this->post('create_data_is', [
            'mail_template_type_is' => (string) $type, 'mail_template_body_is' => 'IS body',
            'mail_template_subject' => $this->fixtures->prefix.'_IS', 'btn_action' => 'SAVE_AND_EXIT',
        ]);
        self::assertSame(500, $this->browser->status());
        self::assertSame(WafCrudTestCase::DENIED_PAGE, $this->text());
        $rows = $this->byType($type);
        self::assertCount(1, $rows, 'The template row is inserted before the controller calls the missing model method.');
        self::assertSame('IS body', $rows[0]['mail_template_body']);
        self::assertSame($this->fixtures->username(), $rows[0]['sys_entry_user']);
        self::assertSame('C', $this->audit()[0]['table_action']);
    }

    public function testIdentityServerCreateValidationRefreshesToTheSecondTab(): void
    {
        $this->signIn();
        $type = $this->unusedType();
        $this->post('create_data_is', [
            'mail_template_type_is' => (string) $type, 'mail_template_body_is' => '', 'btn_action' => 'SAVE_AND_EXIT',
        ]);
        $this->assertRefreshTo(self::INDEX.'&mail_template_is_type='.$type.'&active_tabs=tab_2');
        self::assertSame([], $this->byType($type));
        self::assertSame([], $this->audit());
    }

    public function testIdentityServerUpdateSavesThenFailsOnMissingRegistryUpdate(): void
    {
        $this->signIn();
        $this->post('update_data_is', [
            'key_is' => $this->template['mail_template_guid'], 'mail_template_type_is' => (string) $this->type,
            'mail_template_body_is' => 'IS updated body', 'btn_action' => 'SAVE_AND_EXIT',
        ]);
        self::assertSame(500, $this->browser->status());
        self::assertSame(WafCrudTestCase::DENIED_PAGE, $this->text());
        $row = $this->byType($this->type)[0];
        self::assertSame('IS updated body', $row['mail_template_body']);
        self::assertSame($this->fixtures->username(), $row['sys_update_user']);
        $audit = $this->audit();
        self::assertCount(1, $audit);
        self::assertSame('U', $audit[0]['table_action']);
    }

    /** @dataProvider actions */
    public function testDeniedAdminGetsGenericErrorAndNothingChanges(string $action): void
    {
        $this->signIn('denied');
        $this->send($action, $this->anonymousToken());
        self::assertSame(500, $this->browser->status());
        self::assertSame(WafCrudTestCase::DENIED_PAGE, $this->text());
        self::assertSame([$this->template], $this->byType($this->type));
        self::assertSame([], $this->fixtures->auditRows('denied', 'sys_tref_mail_template'));
    }

    /** @dataProvider actions */
    public function testSignedOutRequestRedirectsToLogin(string $action): void
    {
        $this->send($action, $this->anonymousToken());
        self::assertSame($action === 'index' ? 307 : 303, $this->browser->status());
        self::assertSame('/admin/', $this->browser->redirect());
        self::assertSame([$this->template], $this->byType($this->type));
    }

    private function send(string $action, array $token): void
    {
        if ($action === 'index') {
            $this->browser->get('/adm_waf_mail/index?mail_template_type='.$this->type);

            return;
        }
        $this->browser->post('/adm_waf_mail/'.$action, $token + $this->fields($this->fixtures->prefix.'_MAIL_X', $this->type) + [
            'key' => $this->template['mail_template_guid'], 'key_is' => $this->template['mail_template_guid'],
            'mail_template_type_is' => (string) $this->type, 'mail_template_body_is' => 'x',
        ]);
    }

    public static function actions(): array
    {
        $cases = [];
        foreach (['index', 'create_data', 'update_data', 'create_data_is', 'update_data_is'] as $action) {
            $cases[$action] = [$action];
        }

        return $cases;
    }
}
