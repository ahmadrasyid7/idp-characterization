<?php

declare(strict_types=1);

use Characterization\Database;
use Characterization\HttpTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class CpStatusTest extends HttpTestCase
{
    public function testCreateUpdateDeleteRestoreAndPurgeAuditContract(): void
    {
        $this->signIn();
        $payload = $this->payload();
        $token = $this->browser->csrf('/adm_ref_cp_status/read_input');
        $this->browser->post('/adm_ref_cp_status/create_data', $token + $payload);
        $this->assertReload();
        $created = $this->fixtures->row($payload['cp_status_code']);
        self::assertNotNull($created);
        foreach (['cp_status_code', 'cp_status_name', 'cp_status_description', 'cp_status_is_active'] as $field) {
            self::assertSame($payload[$field], (string) $created[$field]);
        }
        self::assertSame('0', (string) $created['sys_soft_delete']);
        foreach (['sys_entry_user', 'sys_update_user'] as $field) {
            self::assertSame($this->fixtures->username(), $created[$field]);
        }
        self::assertNotNull($created['sys_entry_date']);
        self::assertNotNull($created['sys_update_date']);
        self::assertNull($created['sys_delete_user']);
        self::assertNull($created['sys_delete_date']);
        $this->assertAudit('C', $payload['cp_status_code'], 0);

        // Existing records have stable keys. Some local schema dumps omit the GUID-generating trigger.
        $row = $this->fixtures->status('LIFECYCLE');
        $update = $this->payload('LIFECYCLE');
        $update['cp_status_name'] .= '_UPDATED';
        $this->mutate('update_data', $row, $update);
        $this->assertReload();
        $updated = $this->fixtures->row($row['cp_status_code']);
        self::assertSame($update['cp_status_name'], $updated['cp_status_name']);
        self::assertSame($update['cp_status_description'], $updated['cp_status_description']);
        self::assertSame($row['sys_entry_user'], $updated['sys_entry_user']);
        self::assertSame($row['sys_entry_date'], $updated['sys_entry_date']);
        self::assertSame($this->fixtures->username(), $updated['sys_update_user']);
        self::assertNotSame($row['sys_update_date'], $updated['sys_update_date']);
        $audit = $this->assertAudit('U', $row['cp_status_guid'], 1);
        self::assertSame((string) $audit['id_log'], (string) $updated['sys_id_log_audit']);

        $this->mutate('delete_data', $updated);
        $this->assertReload();
        $deleted = $this->fixtures->row($row['cp_status_code']);
        self::assertSame('1', (string) $deleted['sys_soft_delete']);
        self::assertSame('0', (string) $deleted['cp_status_is_active']);
        self::assertSame($this->fixtures->username(), $deleted['sys_delete_user']);
        self::assertNotNull($deleted['sys_delete_date']);
        foreach (['sys_entry_user', 'sys_entry_date', 'sys_update_user', 'sys_update_date'] as $field) {
            self::assertSame($updated[$field], $deleted[$field]);
        }
        $audit = $this->assertAudit('S', $row['cp_status_guid'], 2);
        self::assertSame((string) $audit['id_log'], (string) $deleted['sys_id_log_audit']);

        $this->mutate('restore_data', $deleted);
        $this->assertReload();
        $restored = $this->fixtures->row($row['cp_status_code']);
        self::assertSame('0', (string) $restored['sys_soft_delete']);
        self::assertSame('0', (string) $restored['cp_status_is_active'], 'Legacy restore does not reactivate.');
        foreach (['sys_entry_user', 'sys_entry_date', 'sys_delete_user', 'sys_delete_date'] as $field) {
            self::assertSame($deleted[$field], $restored[$field]);
        }
        self::assertSame($this->fixtures->username(), $restored['sys_update_user']);
        self::assertNotNull($restored['sys_update_date']);
        $audit = $this->assertAudit('R', $row['cp_status_guid'], 3);
        self::assertSame((string) $audit['id_log'], (string) $restored['sys_id_log_audit']);
        $this->mutate('delete_data', $restored);
        $this->assertReload();
        $this->assertAudit('S', $row['cp_status_guid'], 4);
        $this->mutate('purge_data', $restored);
        $this->assertReload();
        self::assertNull($this->fixtures->row($row['cp_status_code']));
        $audit = $this->assertAudit('D', $row['cp_status_guid'], 5);
        $snapshot = json_decode($audit['sql_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($row['cp_status_code'], $snapshot[0]['cp_status_code']);
        self::assertSame(1, (int) $snapshot[0]['sys_soft_delete']);
    }

    /** @dataProvider invalidValues */
    public function testCreateAndUpdateValidation(string $field, string $value, string $message, bool $update): void
    {
        $this->signIn();
        $existing = $this->fixtures->status('EXISTING');
        $target = $this->fixtures->status('TARGET');
        $payload = $this->payload($update ? 'TARGET' : 'INVALID');
        $payload[$field] = $value === '{DUPLICATE}' ? $existing['cp_status_code'] : $value;
        if ($update) {
            $this->mutate('update_data', $target, $payload);
        } else {
            $token = $this->browser->csrf('/adm_ref_cp_status/read_input');
            $this->browser->post('/adm_ref_cp_status/create_data', $token + $payload);
        }
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertStringContainsString($message, $this->text());
        self::assertSame($target, $this->fixtures->row($target['cp_status_code']));
        self::assertSame($existing, $this->fixtures->row($existing['cp_status_code']));
        self::assertNull($this->fixtures->row($this->fixtures->prefix.'_INVALID'));
        self::assertSame([], $this->fixtures->auditRows());
    }

    public static function invalidValues(): array
    {
        $values = [
            'code required' => ['cp_status_code', '', 'The Client Product Status Code field is required.'],
            'code max 50' => ['cp_status_code', str_repeat('X', 51), 'The Client Product Status Code field cannot exceed 50 characters in length.'],
            'code unique' => ['cp_status_code', '{DUPLICATE}', 'is Allready Used. Please try a nother one.'],
            'name required' => ['cp_status_name', '', 'The Client Product Status Name field is required.'],
            'description required' => ['cp_status_description', '', 'The Description field is required.'],
            'status required' => ['cp_status_is_active', '', 'The Record Status field is required.'],
        ];
        $cases = [];
        foreach ($values as $label => $value) {
            $cases['create '.$label] = array_merge($value, [false]);
            $cases['update '.$label] = array_merge($value, [true]);
        }

        return $cases;
    }

    public function testFiftyCharacterCodeAndInactiveStatusAreAccepted(): void
    {
        $this->signIn();
        $payload = $this->payload();
        $payload['cp_status_code'] = str_pad($this->fixtures->prefix.'_', 50, 'X');
        $payload['cp_status_is_active'] = '0';
        $token = $this->browser->csrf('/adm_ref_cp_status/read_input');
        $this->browser->post('/adm_ref_cp_status/create_data', $token + $payload);
        $this->assertReload();
        $row = $this->fixtures->row($payload['cp_status_code']);
        self::assertNotNull($row);
        self::assertSame(50, strlen($row['cp_status_code']));
        self::assertSame('0', (string) $row['cp_status_is_active']);
        $this->assertAudit('C', $payload['cp_status_code'], 0);
    }

    public function testListPaginationNameSortFiltersAndTabsIncludingLegacyParameterMixup(): void
    {
        $this->signIn();
        $active = [];
        for ($i = 1; $i <= 28; ++$i) {
            $active[] = $this->fixtures->status(sprintf('LIST%02d', $i))['cp_status_code'];
        }
        $history = [];
        for ($i = 1; $i <= 3; ++$i) {
            $history[] = $this->fixtures->status(sprintf('HIST%02d', $i), true)['cp_status_code'];
        }
        $query = '/adm_ref_cp_status?btn_search=FILTER_HIST&filter_ref_name='.$this->fixtures->prefix
            .'&cp_status_name_sort=ASC&cp_status_name_sort_hist=ASC';
        $page = $this->browser->get($query);
        self::assertSame(200, $this->browser->status());
        self::assertSame(array_slice($active, 0, 25), $this->codes($page, 'tab_1'));
        self::assertSame($history, $this->codes($page, 'tab_2'));
        self::assertCount(1, $page->filter('#tab_1.active'));
        $page = $this->browser->get($query.'&per_page=2');
        self::assertSame(array_slice($active, 0, 25), $this->codes($page, 'tab_1'), 'Legacy ignores per_page for active rows.');
        $page = $this->browser->get($query.'&per_page_hist=2&active_tabs=tab_2');
        self::assertSame(array_slice($active, 25), $this->codes($page, 'tab_1'));
        self::assertSame([], $this->codes($page, 'tab_2'));
        self::assertCount(1, $page->filter('#tab_2.active'));
        $page = $this->browser->get(str_replace('=ASC', '=DESC', $query));
        self::assertSame(array_slice(array_reverse($active), 0, 25), $this->codes($page, 'tab_1'));
        self::assertSame(array_reverse($history), $this->codes($page, 'tab_2'));
        $page = $this->browser->get('/adm_ref_cp_status?btn_search=FILTER_HIST&filter_ref_name='.$this->fixtures->prefix.'_LIST01');
        self::assertSame([$active[0]], $this->codes($page, 'tab_1'));
        self::assertSame([], $this->codes($page, 'tab_2'));
        $page = $this->browser->get('/adm_ref_cp_status?btn_search=FILTER_HIST&filter_ref_name='.$this->fixtures->prefix.'_MISSING');
        self::assertSame([], $this->codes($page, 'tab_1'));
        self::assertSame([], $this->codes($page, 'tab_2'));
        $page = $this->browser->get('/adm_ref_cp_status?btn_search=FILTER&filter_ref_name='.$this->fixtures->prefix.'_MISSING&cp_status_name_sort=ASC');
        self::assertCount(25, $this->codes($page, 'tab_1'), 'Legacy ignores the active FILTER name even when it matches no fixture.');
    }

    public function testFixtureSeedingAndCleanupAreRepeatableAndIsolated(): void
    {
        $other = new Characterization\Fixtures($this->config);
        try {
            $sentinel = $other->status('SENTINEL');
            $this->fixtures->status();
            $this->fixtures->seed();
            self::assertNull($this->fixtures->row($this->fixtures->prefix.'_ROW'));
            self::assertCount(3, Database::rows($this->fixtures->db->waf,
                'SELECT user_id FROM sys_tmst_user WHERE sys_entry_user = ?', [$this->fixtures->prefix]));
            $this->fixtures->cleanup();
            $this->fixtures->cleanup();
            self::assertSame([], Database::rows($this->fixtures->db->waf,
                'SELECT user_id FROM sys_tmst_user WHERE sys_entry_user = ?', [$this->fixtures->prefix]));
            self::assertSame($sentinel, $other->row($sentinel['cp_status_code']));
        } finally {
            try {
                $other->cleanup();
            } finally {
                $other->close();
            }
        }
    }

    private function codes(Crawler $page, string $tab): array
    {
        $codes = $page->filter('#'.$tab.' tbody tr')->each(static function (Crawler $row): ?string {
            $cells = $row->filter('td');

            return $cells->count() === 8 ? trim($cells->eq(2)->text()) : null;
        });

        return array_values(array_filter($codes, static fn (?string $code): bool => $code !== null));
    }
}
