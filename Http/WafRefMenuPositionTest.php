<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_configs/waf_ref_menu_position (CI3). */
final class WafRefMenuPositionTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return self::referenceSpec('waf_ref_menu_position', 'sys_tref_menu_position', 'menu_position', 'Menu Position', ['codeLength' => 50]);
    }
}
