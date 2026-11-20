<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_configs/waf_ref_menu_open_type (CI3). */
final class WafRefMenuOpenTypeTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        // Legacy quirk: the active tab ignores both sort parameters (read_data is called without the order).
        return self::referenceSpec('waf_ref_menu_open_type', 'sys_tref_menu_open_type', 'menu_open_type', 'Menu Open Type', [
            'list' => ['sortQuirk' => ['menu_open_type_code_sort' => 'ignored', 'menu_open_type_name_sort' => 'ignored']],
        ]);
    }
}
