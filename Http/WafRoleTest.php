<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_role (CI3). Permission assignment is covered by WafRolePermissionTest. */
final class WafRoleTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return [
            'module' => 'adm_waf_role', 'path' => '/adm_waf_role', 'table' => 'sys_tmst_role', 'title' => 'WAF - Roles',
            'guid' => 'role_guid', 'code' => 'role_app_code', 'name' => 'role_app_name', 'active' => 'role_is_active',
            'codeLength' => 20, 'maxLength' => true,
            'unique' => 'Role Code "%s" is Allready Used. Please try a nother one.',
            'form' => [
                'role_parent_id' => '0', 'role_app_code' => '{CODE}', 'role_app_name' => '{NAME}',
                'role_app_description' => 'Characterization description', 'role_is_active' => '1',
            ],
            'required' => [
                'role_parent_id' => 'Parent', 'role_app_code' => 'Role Code', 'role_app_name' => 'Role Name',
                'role_app_description' => 'Description', 'role_is_active' => 'Record Status',
            ],
            'seed' => ['role_app_id' => 2, 'role_app_description' => 'Characterization fixture'],
            'createSets' => ['role_app_id' => 2],
            'list' => ['cells' => 7, 'codeCell' => 1, 'filter' => 'filter_name', 'sort' => ['role_app_code_sort', 'role_app_name_sort']],
        ];
    }
}
