<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_configs/waf_ref_access_type (CI3). */
final class WafRefAccessTypeTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return self::referenceSpec('waf_ref_access_type', 'sys_tref_access_type', 'access_type', 'Access Type');
    }
}
