<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_configs/waf_ref_action_type (CI3). */
final class WafRefActionTypeTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return self::referenceSpec('waf_ref_action_type', 'sys_tref_action_type', 'action_type', 'Action Type');
    }
}
