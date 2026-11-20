<?php

declare(strict_types=1);

namespace Characterization;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Shared characterization contract for the legacy WAF CRUD screens that follow the CI3 "reference table" template:
 * index (active/history tabs), read_detail, read_input/create_data, read_update/update_data, read_delete/delete_data,
 * read_restore_purge/restore_data/purge_data. Each concrete class describes its screen in spec().
 */
abstract class WafCrudTestCase extends HttpTestCase
{
    public const GET_ACTIONS = ['index', 'read_detail', 'read_input', 'read_update', 'read_delete', 'read_restore_purge'];
    public const POST_ACTIONS = ['create_data', 'update_data', 'delete_data', 'restore_data', 'purge_data'];
    public const DENIED_PAGE = '500 Something went wrong An unexpected error occurred while processing your request. Please try again in a few minutes. If the problem continues, contact support and include the reference below. Reference: {REFERENCE} Go back Home';
    public const RELOAD = "<script type='text/javascript'>window.top.location.reload();</script>";
    public const REFERENCED_PAGE = '500 This action cannot be completed The data might still be referenced by other data, therefore you cannot continue this action. Reference: {REFERENCE} Go back Home';

    /**
     * Keys: module, path, table, title, guid, code, name, active, codeLength, codeLabel, unique (sprintf format),
     * maxLength (bool), form (valid create fields), required (field => label), seed (extra seed columns),
     * deleteSets (extra columns set by soft delete), list (cells, codeCell, filter, sort, sortHist, pageSize, sortQuirk),
     * query (extra index/read_input query string), schema (audit table_schema variable, default CHAR_DB_WAF).
     * Templates may contain {CODE}, {NAME} and the placeholders returned by placeholders().
     */
    abstract protected static function spec(): array;

    /**
     * Spec for the ip_data adm_ref_* screens (CI3 reference-table template on a tref_* table).
     *
     * @param list<string> $sort active-tab sort parameters
     */
    protected static function ipReferenceSpec(
        string $module, string $table, string $stem, string $label, string $title, array $sort, array $overrides = []
    ): array {
        return array_replace_recursive([
            'module' => $module, 'path' => '/'.$module, 'table' => $table, 'title' => 'References - '.$title,
            'guid' => $stem.'_guid', 'code' => $stem.'_code', 'name' => $stem.'_name', 'active' => $stem.'_is_active',
            'codeLength' => 50, 'maxLength' => true, 'schema' => 'CHAR_DB_IP',
            'unique' => $label.' Code "%s" is Allready Used. Please try a nother one.',
            'form' => [$stem.'_code' => '{CODE}', $stem.'_name' => '{NAME}', $stem.'_description' => 'Characterization description', $stem.'_is_active' => '1'],
            'required' => [$stem.'_code' => $label.' Code', $stem.'_name' => $label.' Name', $stem.'_description' => 'Description', $stem.'_is_active' => 'Record Status'],
            'seed' => [$stem.'_description' => 'Characterization fixture'],
            'list' => ['cells' => 8, 'codeCell' => 2, 'filter' => 'filter_ref_name', 'sort' => $sort],
        ], $overrides);
    }

    /** Spec for the six adm_waf_configs reference sub-controllers (identical CI3 template). */
    protected static function referenceSpec(string $module, string $table, string $stem, string $label, array $overrides = []): array
    {
        $name = $overrides['name'] ?? $stem.'_name';
        $code = $overrides['code'] ?? $stem.'_code';
        unset($overrides['name'], $overrides['code']);

        $spec = array_replace_recursive([
            'module' => $module, 'path' => '/adm_waf_configs/'.$module, 'table' => $table,
            'title' => 'WAF Configurations - '.$label.'s',
            'guid' => $stem.'_guid', 'code' => $code, 'name' => $name, 'active' => $stem.'_is_active',
            'codeLength' => 20, 'maxLength' => true,
            'unique' => $label.' Code "%s" is Allready Used. Please try a nother one.',
            'form' => [$code => '{CODE}', $name => '{NAME}', $stem.'_description' => 'Characterization description', $stem.'_is_active' => '1'],
            'required' => [$code => $label.' Code', $name => $label.' Name', $stem.'_description' => 'Description', $stem.'_is_active' => 'Record Status'],
            'seed' => [$stem.'_description' => 'Characterization fixture', $stem.'_app_id' => 2],
            'createSets' => [$stem.'_app_id' => 2],
            'list' => ['cells' => 7, 'codeCell' => 2, 'filter' => 'filter_ref_name', 'sort' => [$code.'_sort', $name.'_sort']],
        ], $overrides);
        foreach (['seed', 'createSets'] as $key) {
            $spec[$key] = array_filter($spec[$key], static fn ($value) => $value !== null);
        }

        return $spec;
    }

    protected function modules(): array
    {
        return [static::spec()['module']];
    }

    /** @return array<string, string> extra template placeholders (e.g. a seeded parent row) */
    protected function placeholders(): array
    {
        return [];
    }

    protected function code(string $suffix): string
    {
        $code = $this->fixtures->prefix.'_'.$suffix;
        if (strlen($code) > static::spec()['codeLength']) {
            throw new \RuntimeException('Fixture code exceeds the column length: '.$code);
        }

        return $code;
    }

    /** Valid create/update form payload (without CSRF or buttons) for a code suffix. */
    protected function form(string $suffix, ?string $name = null): array
    {
        return $this->fill(static::spec()['form'], $this->code($suffix), $name ?? $this->code($suffix));
    }

    /** Seeds an owned row directly (stable GUID) for read/update/delete/restore/purge cases. */
    protected function seed(string $suffix, bool $deleted = false, array $values = []): array
    {
        $spec = static::spec();
        $row = $values + $this->fill($spec['seed'], $this->code($suffix), $values[$spec['name']] ?? $this->code($suffix)) + [
            $spec['code'] => $this->code($suffix), $spec['name'] => $this->code($suffix),
            $spec['active'] => $deleted ? 0 : 1, 'sys_soft_delete' => $deleted ? 1 : 0,
        ];

        return $this->fixtures->waf($spec['table'], $row);
    }

    protected function row(string $code): ?array
    {
        return $this->fixtures->wafRow(static::spec()['table'], $code);
    }

    protected function audit(): array
    {
        return $this->fixtures->auditRows('allowed', static::spec()['table']);
    }

    protected function url(string $action, string $query = ''): string
    {
        $extra = $this->fill(['q' => static::spec()['query'] ?? ''], '', '')['q'];
        $parts = array_filter([$query, $action === 'index' || $action === 'read_input' ? $extra : ''], static fn ($p) => $p !== '');

        return static::spec()['path'].'/'.$action.($parts === [] ? '' : '?'.implode('&', $parts));
    }

    protected function fill(array $template, string $code, string $name): array
    {
        $replace = ['{CODE}' => $code, '{NAME}' => $name] + $this->placeholders();
        $filled = [];
        foreach ($template as $key => $value) {
            $filled[$key] = is_string($value) ? strtr($value, $replace) : $value;
        }

        return $filled;
    }

    /** Submits a form exactly like the browser: CSRF from the form page, then the button value. */
    protected function submit(string $action, ?array $row, array $fields = [], ?string $button = null): void
    {
        $guid = $row === null ? null : $row[static::spec()['guid']];
        $forms = [
            'create_data' => ['read_input', 'SAVE_AND_EXIT'], 'update_data' => ['read_update', 'SAVE_AND_EXIT'],
            'delete_data' => ['read_delete', 'DELETE_DATA'], 'restore_data' => ['read_restore_purge', 'RESTORE_DATA'],
            'purge_data' => ['read_restore_purge', 'PURGE_DATA'],
        ];
        [$form, $default] = $forms[$action];
        $token = $this->browser->csrf($this->url($form, $guid === null ? '' : 'key='.rawurlencode($guid)));
        $key = $guid === null ? [] : ['key' => $guid];
        $this->browser->post($this->url($action), $token + $fields + $key + ['btn_action' => $button ?? $default]);
    }

    /**
     * Screens insert without a GUID. Without the production GUID trigger the row gets '', so when another row
     * already has an empty GUID (e.g. menu 45 in the local dump) the INSERT hits the unique key.
     */
    protected function createCollides(): bool
    {
        return $this->fixtures->foreignEmptyGuid(static::spec()['table']);
    }

    protected function assertCreateCollided(string $code): void
    {
        self::assertSame(500, $this->browser->status());
        self::assertSame(static::DENIED_PAGE, $this->text());
        self::assertNull($this->row($code));
        self::assertSame([], $this->audit());
    }

    protected function assertReloaded(): void
    {
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertSame(static::RELOAD, trim($this->browser->body()));
    }

    protected function assertAuditRow(array $audit, string $action, string $identifier): void
    {
        self::assertSame($action, $audit['table_action']);
        self::assertSame(static::spec()['table'], $audit['table_name']);
        self::assertSame(Config::required(static::spec()['schema'] ?? 'CHAR_DB_WAF'), $audit['table_schema']);
        self::assertSame($this->fixtures->username(), $audit['user_application']);
        self::assertSame('2', (string) $audit['app_id']);
        self::assertStringContainsString($identifier, $audit['sql_action']);
        self::assertNotNull($audit['date_audit']);
        self::assertIsArray(json_decode((string) $audit['sql_data'], true, 512, JSON_THROW_ON_ERROR));
    }

    /** @dataProvider actions */
    public function testAllowedAdminCanUseEveryAction(string $action): void
    {
        $spec = static::spec();
        $this->signIn();
        $row = $this->seed('R', in_array($action, ['read_restore_purge', 'restore_data', 'purge_data'], true));
        if ($action === 'create_data') {
            $this->submit('create_data', null, $this->form('C'));
            if ($this->createCollides()) {
                $this->assertCreateCollided($this->code('C'));
                // The action is still reachable: an invalid form renders validation instead of Access Denied.
                $this->submit('create_data', null, [$spec['code'] => ''] + $this->form('C'));
                self::assertSame(200, $this->browser->status());
                self::assertStringContainsString('Editor [new]', $this->text());
            } else {
                $this->assertReloaded();
                self::assertNotNull($this->row($this->code('C')));
            }
        } elseif (in_array($action, static::POST_ACTIONS, true)) {
            $this->submit($action, $row, $action === 'update_data' ? $this->form('R', $this->code('R').'_U') : []);
            $this->assertReloaded();
            $after = $this->row($row[$spec['code']]);
            if ($action === 'purge_data') {
                self::assertNull($after);
            } else {
                self::assertSame($action === 'delete_data' ? '1' : '0', (string) $after['sys_soft_delete']);
                if ($action === 'update_data') {
                    self::assertSame($this->code('R').'_U', $after[$spec['name']]);
                }
            }
        } else {
            $this->browser->get($this->url($action, $action === 'index' || $action === 'read_input' ? '' : 'key='.rawurlencode($row[$spec['guid']])));
            self::assertSame(200, $this->browser->status(), $this->browser->body());
            self::assertNull($this->browser->redirect());
            self::assertStringNotContainsString('Access Denied!', $this->text());
            $modes = ['read_detail' => 'view_detail', 'read_input' => 'new', 'read_update' => 'edit',
                'read_delete' => 'confirm_delete', 'read_restore_purge' => 'restore_or_purge'];
            if ($action === 'index') {
                self::assertStringContainsString($spec['title'], $this->text());
            } else {
                self::assertStringContainsString('Editor ['.$modes[$action].']', $this->text());
            }
            if (in_array($action, ['read_input', 'read_update'], true)) {
                self::assertStringContainsString('name="'.$spec['code'].'"', $this->browser->body());
            }
            if (in_array($action, ['read_detail', 'read_update', 'read_delete', 'read_restore_purge'], true)) {
                self::assertStringContainsString($row[$spec['code']], $this->browser->body());
            }
        }
    }

    /** @dataProvider actions */
    public function testDeniedAdminGetsGenericErrorAndNothingChanges(string $action): void
    {
        $this->signIn('denied');
        $row = $this->seed('R', in_array($action, ['restore_data', 'purge_data'], true));
        $this->sendWithoutPermission($action, $row);
        self::assertSame(500, $this->browser->status(), $this->browser->body());
        self::assertNull($this->browser->redirect());
        self::assertSame(static::DENIED_PAGE, $this->text());
        self::assertSame($row, $this->row($row[static::spec()['code']]));
        self::assertNull($this->row($this->code('C')));
        self::assertSame([], $this->fixtures->auditRows('denied', static::spec()['table']));
    }

    /** @dataProvider actions */
    public function testSignedOutRequestRedirectsToLogin(string $action): void
    {
        $row = $this->seed('R');
        $this->sendWithoutPermission($action, $row);
        self::assertSame(in_array($action, static::POST_ACTIONS, true) ? 303 : 307, $this->browser->status());
        self::assertSame('/admin/', $this->browser->redirect());
        self::assertSame($row, $this->row($row[static::spec()['code']]));
        self::assertNull($this->row($this->code('C')));
    }

    private function sendWithoutPermission(string $action, array $row): void
    {
        $guid = $row[static::spec()['guid']];
        if (!in_array($action, static::POST_ACTIONS, true)) {
            $this->browser->get($this->url($action, $action === 'index' || $action === 'read_input' ? '' : 'key='.rawurlencode($guid)));

            return;
        }
        $token = $this->anonymousToken();
        $buttons = ['delete_data' => 'DELETE_DATA', 'restore_data' => 'RESTORE_DATA', 'purge_data' => 'PURGE_DATA'];
        $this->browser->post($this->url($action), $token + $this->form($action === 'create_data' ? 'C' : 'R')
            + ['key' => $guid, 'btn_action' => $buttons[$action] ?? 'SAVE_AND_EXIT']);
    }

    public static function actions(): array
    {
        $cases = [];
        foreach (Fixtures::ACTIONS as $action) {
            $cases[$action] = [$action];
        }

        return $cases;
    }

    public function testCreateUpdateDeleteRestorePurgeRowsAndAuditTrail(): void
    {
        $spec = static::spec();
        $this->signIn();
        $form = $this->form('C');
        $this->submit('create_data', null, $form);
        $base = 0;
        if ($this->createCollides()) {
            $this->assertCreateCollided($this->code('C'));
        } else {
            $this->assertCreated($form);
            $base = 1;
        }

        $row = $this->seed('L');
        $update = $this->form('L', $this->code('L').'_U');
        $this->submit('update_data', $row, $update);
        $this->assertReloaded();
        $updated = $this->row($this->code('L'));
        self::assertSame($this->code('L').'_U', $updated[$spec['name']]);
        self::assertSame($row['sys_entry_user'], $updated['sys_entry_user']);
        self::assertSame($row['sys_entry_date'], $updated['sys_entry_date']);
        self::assertSame($this->fixtures->username(), $updated['sys_update_user']);
        self::assertNotSame($row['sys_update_date'], $updated['sys_update_date']);
        $audit = $this->audit();
        self::assertCount($base + 1, $audit);
        $this->assertAuditRow($audit[$base], 'U', $row[$spec['guid']]);
        self::assertSame((string) $audit[$base]['id_log'], (string) $updated['sys_id_log_audit']);

        $this->submit('delete_data', $updated);
        $this->assertReloaded();
        $deleted = $this->row($this->code('L'));
        self::assertSame('1', (string) $deleted['sys_soft_delete']);
        self::assertSame('0', (string) $deleted[$spec['active']]);
        foreach ($spec['deleteSets'] ?? [] as $field => $value) {
            self::assertSame((string) $value, (string) $deleted[$field], $field);
        }
        self::assertSame($this->fixtures->username(), $deleted['sys_delete_user']);
        self::assertNotNull($deleted['sys_delete_date']);
        foreach (['sys_entry_user', 'sys_entry_date', 'sys_update_user', 'sys_update_date'] as $field) {
            self::assertSame($updated[$field], $deleted[$field], $field);
        }
        $audit = $this->audit();
        $this->assertAuditRow($audit[$base + 1], 'S', $row[$spec['guid']]);
        self::assertSame((string) $audit[$base + 1]['id_log'], (string) $deleted['sys_id_log_audit']);

        $this->submit('restore_data', $deleted);
        $this->assertReloaded();
        $restored = $this->row($this->code('L'));
        self::assertSame('0', (string) $restored['sys_soft_delete']);
        self::assertSame('0', (string) $restored[$spec['active']], 'Legacy restore does not reactivate.');
        foreach (['sys_entry_user', 'sys_entry_date', 'sys_delete_user', 'sys_delete_date'] as $field) {
            self::assertSame($deleted[$field], $restored[$field], $field);
        }
        self::assertSame($this->fixtures->username(), $restored['sys_update_user']);
        $audit = $this->audit();
        $this->assertAuditRow($audit[$base + 2], 'R', $row[$spec['guid']]);
        self::assertSame((string) $audit[$base + 2]['id_log'], (string) $restored['sys_id_log_audit']);

        $this->submit('purge_data', $restored);
        $this->assertReloaded();
        self::assertNull($this->row($this->code('L')));
        $audit = $this->audit();
        self::assertCount($base + 4, $audit);
        $this->assertAuditRow($audit[$base + 3], 'D', $row[$spec['guid']]);
        $snapshot = json_decode($audit[$base + 3]['sql_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->code('L'), $snapshot[0][$spec['code']]);
    }

    private function assertCreated(array $form): void
    {
        $spec = static::spec();
        $this->assertReloaded();
        $created = $this->row($this->code('C'));
        self::assertNotNull($created);
        foreach ($form as $field => $value) {
            if (array_key_exists($field, $created) && $value !== '') {
                self::assertSame((string) $value, (string) $created[$field], $field);
            }
        }
        foreach ($spec['createSets'] ?? [] as $field => $value) {
            self::assertSame((string) $value, (string) $created[$field], $field);
        }
        self::assertSame('0', (string) $created['sys_soft_delete']);
        self::assertSame($this->fixtures->username(), $created['sys_entry_user']);
        self::assertSame($this->fixtures->username(), $created['sys_update_user']);
        self::assertNotNull($created['sys_entry_date']);
        self::assertNull($created['sys_delete_user']);
        $audit = $this->audit();
        self::assertCount(1, $audit);
        $this->assertAuditRow($audit[0], 'C', $this->code('C'));
        self::assertSame($this->code('C'), json_decode($audit[0]['sql_data'], true)[$spec['code']]);
    }

    public function testCancelButtonsOnlyReloadThePage(): void
    {
        $this->signIn();
        $row = $this->seed('R');
        $before = $this->row($this->code('R'));
        foreach (['create_data' => null, 'update_data' => $row] as $action => $target) {
            $this->submit($action, $target, $this->form('X'), 'CANCEL');
            $this->assertReloaded();
        }
        foreach (['delete_data', 'restore_data', 'purge_data'] as $action) {
            $this->submit($action, $row, [], 'CANCEL');
            $this->assertReloaded();
        }
        self::assertSame($before, $this->row($this->code('R')));
        self::assertNull($this->row($this->code('X')));
        self::assertSame([], $this->audit());
    }

    /** @dataProvider invalidValues */
    public function testCreateAndUpdateValidationMessages(string $field, string $value, string $message, bool $update): void
    {
        $spec = static::spec();
        $this->signIn();
        $existing = $this->seed('E');
        $target = $this->seed('T');
        $form = $this->form($update ? 'T' : 'V');
        $form[$field] = strtr($value, ['{EXISTING}' => $existing[$spec['code']], '{PREFIX}' => $this->fixtures->prefix]);
        $this->submit($update ? 'update_data' : 'create_data', $update ? $target : null, $form);
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertStringContainsString(strtr($message, ['{EXISTING}' => $existing[$spec['code']]]), $this->browser->body());
        self::assertStringContainsString($update ? 'Editor [edit]' : 'Editor [new]', $this->text());
        self::assertSame($target, $this->row($target[$spec['code']]));
        self::assertSame($existing, $this->row($existing[$spec['code']]));
        self::assertNull($this->row($this->code('V')));
        self::assertSame([], $this->audit());
    }

    public static function invalidValues(): array
    {
        $spec = static::spec();
        $values = [];
        foreach ($spec['required'] as $field => $label) {
            $values[$field.' required'] = [$field, '', 'The '.$label.' field is required.'];
        }
        $values['code unique'] = [$spec['code'], '{EXISTING}', sprintf($spec['unique'], '{EXISTING}')];
        if ($spec['maxLength']) {
            $values['code max 50'] = [$spec['code'], '{PREFIX}_'.str_repeat('X', 51 - 16),
                'The '.$spec['required'][$spec['code']].' field cannot exceed 50 characters in length.'];
        }
        foreach ($spec['invalid'] ?? [] as $label => $case) {
            $values[$label] = $case;
        }
        $cases = [];
        foreach ($values as $label => $value) {
            $cases['create '.$label] = array_merge($value, [false]);
            $cases['update '.$label] = array_merge($value, [true]);
        }

        return $cases;
    }

    /**
     * The validation limit (50, or none for module actions) is longer than several code columns. CI3 runs a
     * non-strict MySQL session, so an over-long code passes validation and is silently truncated to the column.
     */
    public function testLongestAcceptedCodeAndInactiveStatus(): void
    {
        $spec = static::spec();
        $this->signIn();
        $form = $this->form('C');
        $code = str_pad($this->fixtures->prefix.'_', $spec['maxLength'] ? 50 : 60, 'X');
        $form[$spec['code']] = $code;
        $form[$spec['active']] = '0';
        $this->submit('create_data', null, $form);
        $stored = substr($code, 0, $spec['codeLength']);
        if ($this->createCollides()) {
            $this->assertCreateCollided($stored);

            return;
        }
        $this->assertReloaded();
        $row = $this->row($stored);
        self::assertNotNull($row, 'Stored code is truncated to '.$spec['codeLength'].' characters.');
        self::assertSame('0', (string) $row[$spec['active']]);
        $audit = $this->audit();
        self::assertCount(1, $audit);
        $this->assertAuditRow($audit[0], 'C', $code);
    }

    public function testListTabsFilterSortAndPagination(): void
    {
        $spec = static::spec();
        $list = $spec['list'];
        $this->signIn();
        $size = $list['pageSize'] ?? 25;
        $active = [];
        for ($i = 1; $i <= $size + 3; ++$i) {
            $active[] = $this->seed(sprintf('A%02d', $i), false, $this->listValues($i, 'A'))[$spec['code']];
        }
        $history = [];
        for ($i = 1; $i <= 3; ++$i) {
            $history[] = $this->seed(sprintf('H%02d', $i), true, $this->listValues($i, 'H'))[$spec['code']];
        }
        $newest = array_reverse($active);
        $filter = $list['filter'] === null ? '' : $list['filter'].'='.$this->fixtures->prefix.'_L';

        $page = $this->browser->get($this->url('index', $filter));
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertSame(array_slice($newest, 0, $size), $this->codes($page, 'tab_1'), 'Default order is newest entry first.');
        self::assertCount(1, $page->filter('#tab_1.active'));
        $page = $this->browser->get($this->url('index', trim($filter.'&per_page=2', '&')));
        self::assertSame(array_slice($newest, $size), $this->codes($page, 'tab_1'));
        $page = $this->browser->get($this->url('index', trim($filter.'&per_page_hist=2&active_tabs=tab_2', '&')));
        self::assertSame(array_slice($newest, 0, $size), $this->codes($page, 'tab_1'), 'per_page_hist pages only the history tab.');
        self::assertCount(1, $page->filter('#tab_2.active'));
        if ($list['filter'] === null) {
            self::assertSame(array_reverse($history), $this->codes($this->browser->get($this->url('index')), 'tab_2'));

            return;
        }
        $unfiltered = $this->browser->get($this->url('index'));
        $page = $this->browser->get($this->url('index', $filter.'&btn_search=FILTER_HIST'));
        self::assertSame(array_reverse($history), $this->codes($page, 'tab_2'), 'FILTER_HIST filters only the history tab.');
        self::assertSame($this->codes($unfiltered, 'tab_1'), $this->codes($page, 'tab_1'), 'FILTER_HIST leaves the active tab unfiltered.');
        $page = $this->browser->get($this->url('index', $filter.'&btn_search=FILTER'));
        self::assertSame(array_slice($newest, 0, $size), $this->codes($page, 'tab_1'));
        self::assertSame($this->codes($unfiltered, 'tab_2'), $this->codes($page, 'tab_2'), 'FILTER leaves the history tab unfiltered.');
        $page = $this->browser->get($this->url('index', $list['filter'].'='.$this->fixtures->prefix.'_MISSING'));
        self::assertSame([], $this->codes($page, 'tab_1'));

        foreach ($list['sort'] as $sort) {
            $histSort = $list['sortHist'][$sort] ?? $sort.'_hist';
            foreach (['ASC' => $active, 'DESC' => array_reverse($active)] as $direction => $expected) {
                $page = $this->browser->get($this->url('index', $filter.'&'.$sort.'='.$direction));
                $quirk = $list['sortQuirk'][$sort] ?? null;
                self::assertSame(array_slice($quirk === 'ignored' ? $newest : $expected, 0, $size), $this->codes($page, 'tab_1'), $sort.' '.$direction);
                $page = $this->browser->get($this->url('index', $filter.'&btn_search=FILTER_HIST&'.$histSort.'='.$direction));
                $quirk = $list['sortQuirk'][$histSort] ?? null;
                $hist = $direction === 'ASC' ? $history : array_reverse($history);
                self::assertSame($quirk === 'ignored' ? array_reverse($history) : $hist, $this->codes($page, 'tab_2'), $histSort.' '.$direction);
            }
        }
    }

    /** Extra seed values for list rows; names sort in the same order as codes. */
    protected function listValues(int $i, string $kind): array
    {
        return [
            static::spec()['name'] => $this->fixtures->prefix.'_L'.$kind.sprintf('%02d', $i),
            'sys_entry_date' => sprintf('2000-01-01 00:%02d:%02d', $kind === 'A' ? 1 : 2, $i),
        ];
    }

    /** Fixture codes in a list tab (non-fixture rows become {OTHER}). */
    protected function codes(Crawler $page, string $tab): array
    {
        $list = static::spec()['list'];
        $codes = $page->filter('#'.$tab.' tbody tr')->each(function (Crawler $row) use ($list): ?string {
            $cells = $row->filter('td');
            if ($cells->count() !== $list['cells']) {
                return null;
            }
            $text = trim($cells->eq($list['codeCell'])->text());
            if (preg_match('/^('.preg_quote($this->fixtures->prefix, '/').'_\S+?)(?: \(\d+\))?$/', $text, $match)) {
                return $match[1];
            }

            return '{OTHER}';
        });

        return array_values(array_filter($codes, static fn (?string $code): bool => $code !== null));
    }
}
