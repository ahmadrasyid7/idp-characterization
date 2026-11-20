<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_configs/waf_ref_privileges (CI3, table sys_tref_privilege_type). */
final class WafRefPrivilegesTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        // Legacy quirks: the name column is spelled privlege_name, the table has no app column,
        // and the history name sort reads a misspelled parameter so privlege_name_sort_hist is ignored.
        return self::referenceSpec('waf_ref_privileges', 'sys_tref_privilege_type', 'privilege', 'Privilege Type', [
            'name' => 'privlege_name',
            'seed' => ['privilege_app_id' => null],
            'createSets' => ['privilege_app_id' => null],
            'list' => ['sortQuirk' => ['privlege_name_sort_hist' => 'ignored']],
        ]);
    }
}
