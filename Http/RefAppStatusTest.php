<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_ref_app_status (CI3, ip_data table tref_app_status). */
final class RefAppStatusTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        // Legacy quirk: the sort parameters drop the "app_" prefix of the columns.
        return self::ipReferenceSpec('adm_ref_app_status', 'tref_app_status', 'app_status', 'Status',
            'Application Deployment Status', ['status_code_sort', 'status_name_sort']);
    }
}
