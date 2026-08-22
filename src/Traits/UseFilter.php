<?php

namespace LynHuang\LaravelModelUtil\Traits;
use LynHuang\LaravelModelUtil\Filter\QueryFilter;
trait UseFilter
{
    public function scopeFilter($query, QueryFilter $filter)
    {
        return $filter->apply($query);
    }
}