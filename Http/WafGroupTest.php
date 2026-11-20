<?php

declare(strict_types=1);

use Characterization\WafCrudTestCase;

/** Characterization of adm_waf_group (CI3). Group-role assignment is covered by WafGroupRoleTest. */
final class WafGroupTest extends WafCrudTestCase
{
    protected static function spec(): array
    {
        return [
            'module' => 'adm_waf_group', 'path' => '/adm_waf_group', 'table' => 'sys_tmst_group', 'title' => 'WAF - Groups',
            'guid' => 'group_guid', 'code' => 'group_code', 'name' => 'group_name', 'active' => 'group_is_active',
            'codeLength' => 50, 'maxLength' => true,
            'unique' => 'Group Code "%s" is Allready Used. Please try a nother one.',
            'form' => [
                'group_parent_id' => '0', 'group_code' => '{CODE}', 'group_name' => '{NAME}',
                'group_description' => 'Characterization description', 'group_is_core' => '0', 'group_is_active' => '1',
            ],
            'required' => [
                'group_parent_id' => 'Parent', 'group_code' => 'Group Code', 'group_name' => 'Group Name',
                'group_description' => 'Description', 'group_is_core' => 'Group Is Core', 'group_is_active' => 'Record Status',
            ],
            'seed' => ['group_app_id' => 2, 'group_description' => 'Characterization fixture'],
            'createSets' => ['group_app_id' => 2],
            'list' => ['cells' => 7, 'codeCell' => 1, 'filter' => 'filter_name', 'sort' => ['group_code_sort', 'group_name_sort']],
        ];
    }
}
