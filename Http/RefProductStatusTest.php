<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_ref_product_status (CI3, ip_data table tref_product_status). */
final class RefProductStatusTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return self::ipReferenceSpec('adm_ref_product_status', 'tref_product_status', 'product_status', 'Product Status',
            'Product Status', ['product_status_name_sort']);
    }
}
