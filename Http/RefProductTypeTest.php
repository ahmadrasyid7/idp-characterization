<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_ref_product_type (CI3, ip_data table tref_product_type). */
final class RefProductTypeTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return self::ipReferenceSpec('adm_ref_product_type', 'tref_product_type', 'product_type', 'Product Type',
            'Product Type', ['product_type_name_sort']);
    }
}
