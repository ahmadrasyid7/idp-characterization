<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Characterization of adm_ref_client_cat (CI3, hierarchical ip_data table tref_client_category). */
final class RefClientCatTest extends WafCrudTestCase
{
    private const LOOPING = 'Parent of Client Category "Parent=Self" is Looping. Please try a nother one.';

    protected static function spec(): array
    {
        // Legacy quirks: history sort parameters end in _hist, and the active tab is ordered by those history
        // parameters, so the active-tab sort parameters are ignored.
        return self::ipReferenceSpec('adm_ref_client_cat', 'tref_client_category', 'client_category', 'Client Category',
            'Client Category', ['client_category_code_sort', 'client_category_name_sort'], [
                'form' => ['parent_category_id' => '0'],
                'required' => ['parent_category_id' => 'Parent Client Category'],
                'list' => [
                    'codeCell' => 3,
                    'sortHist' => [
                        'client_category_code_sort' => 'client_category_code_hist',
                        'client_category_name_sort' => 'client_category_name_hist',
                    ],
                    'sortQuirk' => ['client_category_code_sort' => 'ignored', 'client_category_name_sort' => 'ignored'],
                ],
            ]);
    }

    public function testHistorySortParametersAlsoOrderTheActiveTab(): void
    {
        $this->signIn();
        $active = [];
        for ($i = 1; $i <= 3; ++$i) {
            $active[] = $this->seed(sprintf('A%02d', $i), false, $this->listValues($i, 'A'))['client_category_code'];
        }
        $filter = 'filter_ref_name='.$this->fixtures->prefix.'_L';
        foreach (['client_category_code_hist', 'client_category_name_hist'] as $sort) {
            $page = $this->browser->get($this->url('index', $filter.'&'.$sort.'=ASC'));
            self::assertSame($active, $this->codes($page, 'tab_1'), $sort);
            $page = $this->browser->get($this->url('index', $filter.'&'.$sort.'=DESC'));
            self::assertSame(array_reverse($active), $this->codes($page, 'tab_1'), $sort);
        }
    }

    public function testListShowsParentNameAndCodeOrRoot(): void
    {
        $this->signIn();
        $parent = $this->seed('P');
        $child = $this->seed('C1', false, ['parent_category_id' => $parent['client_category_id']]);
        $orphan = $this->seed('C2', false, ['parent_category_id' => 0]);
        $page = $this->browser->get($this->url('index', 'filter_ref_name='.$this->fixtures->prefix));
        $parents = [];
        $page->filter('#tab_1 tbody tr')->each(static function (Crawler $row) use (&$parents): void {
            $cells = $row->filter('td');
            if ($cells->count() === 8) {
                $parents[trim($cells->eq(3)->text())] = trim($cells->eq(1)->text());
            }
        });
        self::assertSame($parent['client_category_name'].' ('.$parent['client_category_code'].')', $parents[$child['client_category_code']]);
        self::assertSame('ROOT (0)', $parents[$orphan['client_category_code']]);
        self::assertSame('ROOT (0)', $parents[$parent['client_category_code']]);
    }

    public function testParentSelectorListsRootAndEveryCategoryIncludingDeleted(): void
    {
        $this->signIn();
        $active = $this->seed('P1');
        $deleted = $this->seed('P2', true);
        $page = $this->browser->get($this->url('read_input'));
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        $options = $page->filter('select[name="parent_category_id"] option');
        self::assertSame('0', $options->eq(0)->attr('value'));
        self::assertSame('ROOT', trim($options->eq(0)->text()));
        foreach ([$active, $deleted] as $row) {
            $option = $page->filter('select[name="parent_category_id"] option[value="'.$row['client_category_id'].'"]');
            self::assertCount(1, $option);
            self::assertSame($row['client_category_name'].' ('.$row['client_category_code'].')', trim($option->text()));
        }
    }

    public function testUpdateRejectsTheCategoryAsItsOwnDirectParent(): void
    {
        $this->signIn();
        $target = $this->seed('T');
        $form = ['parent_category_id' => (string) $target['client_category_id']] + $this->form('T');
        $this->submit('update_data', $target, $form);
        self::assertSame(200, $this->browser->status(), $this->browser->body());
        self::assertStringContainsString(self::LOOPING, $this->browser->body());
        self::assertStringContainsString('Editor [edit]', $this->text());
        self::assertSame($target, $this->row($target['client_category_code']));
        self::assertSame([], $this->audit());
    }

    /** Legacy only rejects a direct self-parent: deeper cycles and unknown parent IDs are stored as submitted. */
    public function testUpdateAcceptsDeeperCyclesAndUnknownParents(): void
    {
        $this->signIn();
        $grandparent = $this->seed('G');
        $parent = $this->seed('P', false, ['parent_category_id' => $grandparent['client_category_id']]);
        $this->submit('update_data', $grandparent, ['parent_category_id' => (string) $parent['client_category_id']] + $this->form('G'));
        $this->assertReloaded();
        self::assertSame((string) $parent['client_category_id'], (string) $this->row($grandparent['client_category_code'])['parent_category_id']);

        $unknown = (int) Characterization\Database::rows($this->fixtures->db->ip,
            'SELECT COALESCE(MAX(client_category_id), 0) + 1000 AS id FROM tref_client_category')[0]['id'];
        $this->submit('update_data', $parent, ['parent_category_id' => (string) $unknown] + $this->form('P'));
        $this->assertReloaded();
        self::assertSame((string) $unknown, (string) $this->row($parent['client_category_code'])['parent_category_id']);
        self::assertSame(['U', 'U'], array_column($this->audit(), 'table_action'));
    }

    public function testCreateStoresTheSelectedParent(): void
    {
        $this->signIn();
        $parent = $this->seed('P');
        $this->submit('create_data', null, ['parent_category_id' => (string) $parent['client_category_id']] + $this->form('C'));
        if ($this->createCollides()) {
            $this->assertCreateCollided($this->code('C'));

            return;
        }
        $this->assertReloaded();
        self::assertSame((string) $parent['client_category_id'], (string) $this->row($this->code('C'))['parent_category_id']);
        $audit = $this->audit();
        self::assertCount(1, $audit);
        self::assertSame((string) $parent['client_category_id'], (string) json_decode($audit[0]['sql_data'], true)['parent_category_id']);
    }
}
