<?php

namespace LynHuang\LaravelModelUtil\Filter;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class QueryFilter
{
    const OPTION_GREAT          = 'gt'; // 大于
    const OPTION_GREAT_OR_EQUAL = 'ge'; // 大于等于
    const OPTION_EQUAL          = 'eq'; // 等于
    const OPTION_NOT_EQUAL      = 'ne'; // 不等于
    const OPTION_LESS           = 'lt'; // 小于
    const OPTION_LESS_OR_EQUAL  = 'le'; // 小于等于
    const OPTION_BETWEEN        = 'bt'; // 在...之间
    const OPTION_IN             = 'in'; // 在...之内
    const OPTION_NOT_IN         = 'ni'; // 不在...之内
    const OPTION_LIKE           = 'lk'; // 模糊查询
    const OPTION_NULL           = 'nl'; // 为空

    protected $request;
    protected $builder;
    protected $table;
    protected $input = [];

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->filters();
    }

    public function apply(Builder $builder)
    {
        $this->builder = $builder;
        $this->table   = $builder->getModel()->getTable();

        foreach ($this->input as $name => $value) {
            if (is_null($value)) continue;
            if (method_exists($this, $name)) {
                call_user_func_array([$this, $name], [$value]);
            }
        }

        $this->applyAfter();

        return $this->builder;
    }

    /**
     * apply 方法执行后的一些操作，有需要的复写此方法即可
     */
    public function applyAfter() {}

    public function filters()
    {
        $this->input = $this->request->all();
    }

    /**
     * 添加过滤条件
     *
     * @param $name
     * @param $value
     */
    public function addInput($name, $value)
    {
        $this->input[$name] = $value;
    }

    public function addInputs($inputs)
    {
        $this->input = array_merge($this->input, $inputs);
    }

    public function addInputArray($name, $array, $oprator = self::OPTION_IN)
    {
        if ($array instanceof Collection) {
            $array = $array->toArray();
        }
        if (empty($array)) return;
        $this->input[$name] = $oprator . ':' . implode(',', $array);
    }

    /**
     * 去掉过滤条件
     *
     * @param $name
     */
    public function removeInput($name)
    {
        unset($this->input[$name]);
    }

    /**
     * 清空过滤条件
     */
    public function clearInput()
    {
        $this->input = [];
    }

    /**
     * @return array
     */
    public function getInput()
    {
        return $this->input;
    }

    protected function created_at($created_at)
    {
        $this->analyzeParam('created_at', $created_at);
    }

    /**
     * 关联查询
     *
     * @param $relate_model string 关联模型
     * @param $relateFilter string 关联模型过滤器
     * @param $foreign_key string 当前模型关联外键字段名
     * @param $relate_search_field array|string 关联模型搜索字段,多个字段使用英文逗号隔开（指关联filter里的字段）
     * @param $search_field_value array|string 搜索字段相对应的值
     * @param $relate_local_id string 需要从关联模型中提取回来的字段
     */
    protected function relateParam(
        string $relate_model,
        string $relateFilter,
        string $foreign_key,
        $relate_search_field,
        $search_field_value,
        string $relate_local_id = 'id'
    )
    {
        $relate_search_field = is_array($relate_search_field) ? $relate_search_field : [$relate_search_field];
        $search_field_value  = is_array($search_field_value) ? $search_field_value : [$search_field_value];
        $idQuery             = $this->relateIdSearch($relate_model, $relateFilter, $relate_search_field, $search_field_value, $relate_local_id);
        $this->builder->whereIn($foreign_key, $idQuery);

    }

    /**
     * 查找关联表的字段
     *
     * 仅能适用于一对一或多对一的关联
     *
     * @param $relate_model string 关联模型
     * @param $relateFilter string 类名
     * @param $search_fields array 搜索字段
     * @param $search_fields_value array 与搜索字段对应的值
     * @param string $relate_local_id
     * @return null
     */
    protected function relateIdSearch(string $relate_model, string $relateFilter, array $search_fields, array $search_fields_value, string $relate_local_id = 'id')
    {
        $request = new Request();
        foreach ($search_fields as $i => $field) {
            $request->offsetSet($field, $search_fields_value[$i]);
        }
        $filter  = new $relateFilter($request);
        $idQuery = $relate_model::query()->filter($filter)->select($relate_local_id);
        return $idQuery;
    }


    /**
     * 多对多中间表查询
     *
     * @param $table string 中间表
     * @param $relate_id string 中间表与关联表的关联字段
     * @param $param string 查询参数
     * @param $local_id  string 中间表与本表的关联字段
     * @param array $where 中间表额外查询参数
     */
    public function pivotParam($table, $relate_id, $param, $local_id, $where = [])
    {
        $query = DB::table($table);
        if (!empty($where)) {
            $query = $query->where($where);
        }
        [$op, $value] = $this->splitOptionAndValue($param);
        $this->composeBuilder($query, $relate_id, $value, $op);

        $ids = $query->get()->pluck($local_id)->unique();
        $this->builder->whereIn('id', $ids);
    }

    /**
     * 多字段查询 （只能查本表，且不支持like）
     * 查询间用or连接，不支持in，not in，between操作
     *
     * @param $fields array 多个字段
     * @param $value
     */
    protected function orParam($fields, $value)
    {
        $this->builder->where(function ($query) use ($fields, $value) {
            foreach ($fields as $k => $field) {
                if ($k == 0) {
                    $query->where($field, '=', $value);
                } else {
                    $query->orWhere($field, '=', $value);
                }
            }
            return $query;
        });
    }

    /**
     * 多字段搜索
     * 查询间用or连接，不支持in，not in，between操作
     *
     * @param $fields array 多个字段
     * @param $value
     * @param array $relates [model, relate, relate_id]
     */
    protected function searchParam($fields, $value, $relates = [])
    {
        $this->builder->where(function ($query) use ($fields, $value, $relates) {
            foreach ($fields as $k => $field) {
                if ($k == 0) {
                    $query->where($field, 'like', "%{$value}%");
                } else {
                    $query->orWhere($field, 'like', "%{$value}%");
                }
            }

            //关联查询
            foreach ($relates as $r) {
                $ids = $this->relateIdSearch($r[0], $r[1], ['search'], [$value]);
                if (!is_null($ids)) {
                    $query->orWhere(function ($query) use ($r, $ids) {
                        $query->whereIn($r[2], $ids);
                    });
                }
            }

            return $query;
        });
    }

    /**
     * 专门用于多态关联查询对应字段
     *
     * @param $object
     * @param $localField
     * @param $foreignField
     */
    protected function morphParam($field, $value)
    {
        $object_type = $this->input['object_type'] ?? null;
        if ($object_type) {
            $model = getModelByType($object_type);
            if ($model) {
                $model = new $model();
                if (is_array($value)) {
                    $ids = $model->whereIn($field, $value)->get()->pluck('id');
                } else {
                    $ids = $model->where($field, $value)->get()->pluck('id');
                }
                $this->builder->whereIn('object_id', $ids);
            }
        }
    }

    /**
     * 有多个关联表的查询， CommentFilter 有使用到，如comment表与多个表
     * 查询间用or连接，不支持in，not in，between操作
     *
     * @param $fields array 多个字段
     * @param $value
     * @param array $relates [model, relate, relate_id, array other_condition]
     *          other_condition 如： ['object_type'=>'houses'] 这个主要是用来区分这
     */
    protected function searchParamMany($fields, $value, $relates = [])
    {
        $this->builder->where(function ($query) use ($fields, $value, $relates) {
            foreach ($fields as $k => $field) {
                if ($k == 0) {
                    $query->where($field, 'like', "%{$value}%");
                } else {
                    $query->orWhere($field, 'like', "%{$value}%");
                }
            }

            //关联查询
            $query->orWhere(function ($query) use ($relates, $value) {
                foreach ($relates as $r) {
                    $ids = $this->relateIdSearch($r[0], $r[1], ['search'], [$value]);
                    if ($ids) {
                        $query->orWhereIn($r[2], $ids);
                        if (isset($r[3]) && is_array($r[3])) {
                            foreach ($r[3] as $k => $v) {
                                if (is_array($v)) {
                                    $query->whereIn($k, $v);
                                } else {
                                    $query->where($k, $v);
                                }
                            }
                        }
                    }
                }
            });

            return $query;
        });
    }

    /**
     * 分析参数构建查询
     *
     * @param string $field 数据库字段
     * @param string $value 传递过来的查询值（可能带操作符的）
     * @param boolean $table_prefix 是否加上表名前线
     * @throws InvalidParamException
     */
    protected function analyzeParam(string $field, $value, bool $table_prefix = false)
    {
        if ($table_prefix) {
            $field = $this->table . '.' . $field;
        }
        //在和的情况下，需要遍历条件
        if (Str::contains($value, '&&')) {
            $vs = explode('&&', $value);
            foreach ($vs as $v) {
                [$op, $p] = $this->splitOptionAndValue($v);
                $this->composeBuilder($this->builder, $field, $p, $op);
            }
            return;
        }

        //在或的情况下，需要使用闭包实现
        if (Str::contains($value, '||')) {
            $vs = explode('||', $value);
            $this->builder->where(function ($query) use ($field, $vs) {
                foreach ($vs as $k => $v) {
                    [$op, $p] = $this->splitOptionAndValue($v);
                    if ($op == self::OPTION_IN || $op == self::OPTION_NOT_IN || $op == self::OPTION_BETWEEN) {
                        $this->throwError($field);
                    }
                    if ($k == 0) {
                        if ($op == self::OPTION_NULL) {
                            $query->whereNull($field);
                            continue;
                        }

                        $query->where(...$this->buildQueryParams($field, $op, $p));
                    } else {
                        if ($op == self::OPTION_NULL) {
                            $query->orWhereNull($field);
                            continue;
                        }
                        $query->orWhere(...$this->buildQueryParams($field, $op, $p));
                    }
                }
                return $query;
            });
            return;
        }

        [$op, $p] = $this->splitOptionAndValue($value);
        $this->composeBuilder($this->builder, $field, $p, $op);
    }

    //格式化用在where语句中的参数
    protected function buildQueryParams($field, $op, $p): array
    {
        $ops = $this->options();
        switch ($op) {
            case self::OPTION_LIKE:
                return [$field, $ops[$op], "%{$p}%"];
            case self::OPTION_BETWEEN:
                $params = explode(',', $p);
                if (count($params) != 2) {
                    $this->throwError($field);
                }
                return [$field, explode(',', $p)];
            case self::OPTION_IN:
            case self::OPTION_NOT_IN:
                return [$field, explode(',', $p)];
            default:
                if (!isset($ops[$op])) {
                    $this->throwError($field);
                }
                return [$field, $ops[$op], $p];
        }
    }

    /**
     * 加上指定模型的条件
     * @param $field
     * @param $value
     * @param $model
     * @param $model_field
     * @param $other_condition
     */
    protected function searchIds($field, $value, $model, $model_field, $other_condition = [])
    {
        $ids = $model::valid()->where($other_condition)->get()->pluck($model_field);
        if ($value) {
            $this->builder->whereIn($field, $ids);
        } else {
            $this->builder->whereNotIn($field, $ids);
        }
    }

    /**
     * 通过过滤器加上指定模型的条件
     * @param array $relation ['参数名' => '数据表里的字段名', ...]
     * @param string $model 关联模型
     * @param string $model_field 关联模型关联字段
     * @param string $filter 过滤器
     * @param string $src_field $model_field所对应的主模型的字段名
     */
    public function searchIdsWithFilter(array $relation, $model, $model_field, $filter, $src_field = 'id')
    {
        $params = $this->request->only(array_keys($relation));
        if ($params) {
            $request = new Request();
            foreach ($params as $k => $v) {
                if (is_null($v)) continue;

                $request->offsetSet($relation[$k], $v);
            }
            $ids = $model::filter(new $filter($request))->valid()->select($model_field)->distinct()->get()->pluck($model_field);
            $this->builder->whereIn($src_field, $ids);
        }
    }

    /**
     * 获取属性表指定条件的id
     * @param $params
     * @param $model
     * @param $model_field
     * @param $filter
     * @param string $src_field
     */
    public function searchIdsWithAttrsTable($params, $model, $model_field, $filter, $src_field = 'id')
    {
        $request = new Request();
        foreach ($params as $k => $v) {
            if (is_null($v)) continue;

            $request->offsetSet($k, $v);
        }
        $ids = $model::filter(new $filter($request))->select($model_field)->distinct()->get()->pluck($model_field);
        $this->builder->whereIn($src_field, $ids);
    }

    //通用id过滤器，可重构
    protected function id($param)
    {
        $this->analyzeParam('id', $param);
    }

    //分离操作符和搜索值
    protected function splitOptionAndValue($v): array
    {
        if (Str::startsWith($v, $this->ops())) {
            $op = explode(':', $v)[0];
            $v  = substr($v, 3);
            return [$op, $v];
        }
        return [self::OPTION_EQUAL, $v]; //不含操作符，默认为=
    }

    //创建查询
    protected function composeBuilder(&$builder, $field, $value, $op)
    {
        $ops = $this->options();
        switch ($op) {
            case self::OPTION_LIKE:
                $builder->where($field, $ops[$op], "%{$value}%");
                break;
            case self::OPTION_BETWEEN:
                $params = explode(',', $value);
                if (count($params) != 2) {
                    $this->throwError($field);
                }
                $builder->whereBetween($field, $params);
                break;
            case self::OPTION_IN:
                $builder->whereIn($field, explode(',', $value));
                break;
            case self::OPTION_NOT_IN:
                $builder->whereNotIn($field, explode(',', $value));
                break;
            case self::OPTION_NULL:
                $builder->whereNull($field);
                break;
            default:
                if (!isset($ops[$op])) {
                    $this->throwError($field);
                }
                $builder->where($field, $ops[$op], $value);
        }
    }

    private function _getArr($str, $delimiter = ',', $count = 2)
    {
        $arr = explode($delimiter, $str);
        if (count($arr) != $count)
            $this->throwError('');
        return $arr;
    }

    //抛出参数错误的响应错误
    protected function throwError($field)
    {
        throw new HttpResponseException(response()->json([
            'message' => $field . '参数格式错误',
            'code'    => -1,
            'status'  => 'error',
        ]));
    }

    //将传递过来的操作符转换成sql操作符
    protected function options(): array
    {
        return [
            self::OPTION_GREAT          => '>',
            self::OPTION_EQUAL          => '=',
            self::OPTION_LESS           => '<',
            self::OPTION_GREAT_OR_EQUAL => '>=',
            self::OPTION_LESS_OR_EQUAL  => '<=',
            self::OPTION_NOT_EQUAL      => '<>',
            self::OPTION_LIKE           => 'like',
            self::OPTION_NULL           => 'nl',
        ];
    }

    protected function ops(): array
    {
        return [
            self::OPTION_GREAT . ":",
            self::OPTION_EQUAL . ":",
            self::OPTION_LESS . ":",
            self::OPTION_GREAT_OR_EQUAL . ":",
            self::OPTION_LESS_OR_EQUAL . ":",
            self::OPTION_NOT_EQUAL . ":",
            self::OPTION_LIKE . ":",
            self::OPTION_IN . ":",
            self::OPTION_NOT_IN . ":",
            self::OPTION_BETWEEN . ":",
            self::OPTION_NULL . ":",
        ];
    }
}
