<?php

namespace LynHuang\LaravelModelUtil\Traits;

trait UseFilter
{
    public function scopeFilter($query, QueryFilter $filter)
    {
        return $filter->apply($query);
    }
}