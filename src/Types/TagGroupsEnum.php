<?php

namespace markhuot\CraftQL\Types;

use markhuot\CraftQL\Builders\EnumObject;
use markhuot\CraftQL\CraftQL;

class TagGroupsEnum extends EnumObject {

    function getValues() {
        return array_map(function ($group) {
            return $group['handle'];
        }, CraftQL::$plugin->tagGroups->all());
    }

}