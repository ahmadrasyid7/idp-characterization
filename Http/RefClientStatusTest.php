<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_ref_client_status (CI3, ip_data table tref_client_status). */
final class RefClientStatusTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        // Legacy quirk: the history sort parameters end in _hist, not _sort_hist.
        return self::ipReferenceSpec('adm_ref_client_status', 'tref_client_status', 'client_status', 'Client Status',
            'Client Status', ['client_status_code_sort', 'client_status_name_sort'], [
                'list' => ['sortHist' => [
                    'client_status_code_sort' => 'client_status_code_hist',
                    'client_status_name_sort' => 'client_status_name_hist',
                ]],
            ]);
    }
}
