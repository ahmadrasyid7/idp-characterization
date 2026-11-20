<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_ref_client_type (CI3, ip_data table tref_client_type). */
final class RefClientTypeTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return self::ipReferenceSpec('adm_ref_client_type', 'tref_client_type', 'client_type', 'Client Type',
            'Client Type', ['client_type_name_sort']);
    }
}
