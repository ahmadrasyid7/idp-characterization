<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_ref_cp_stage (CI3, ip_data table tref_cp_app_stage). */
final class RefCpStageTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return self::ipReferenceSpec('adm_ref_cp_stage', 'tref_cp_app_stage', 'cp_app_stage', 'Client Product Stage',
            'Client-Product Stage', ['cp_app_stage_code_sort', 'cp_app_stage_name_sort']);
    }
}
