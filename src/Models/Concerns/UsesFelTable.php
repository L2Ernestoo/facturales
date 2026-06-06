<?php

namespace Lc\Fel\Models\Concerns;

trait UsesFelTable
{
    public function getTable()
    {
        return config('fel.tables.' . static::$felTableConfigKey, parent::getTable());
    }
}
