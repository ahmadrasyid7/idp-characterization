<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_configs/waf_ref_menu_type (CI3). */
final class WafRefMenuTypeTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return self::referenceSpec('waf_ref_menu_type', 'sys_tref_menu_type', 'menu_type', 'Menu Type');
    }
}
