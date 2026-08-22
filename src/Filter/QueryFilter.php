<?php

namespace LynHuang\LaravelModelUtil\Filter;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LynHuang\LaravelModelUtil\Exceptions\InvalidParamException;

abstract class QueryFilter
{
    const OPTION_GREAT          = 'gt'; // 大于
    const OPTION_GREAT_OR_EQUAL = 'ge'; // 大于等于
    const OPTION_EQUAL          = 'eq'; // 等于
    const OPTION_NOT_EQUAL      = 'ne'; // 不等于
    const OPTION_LESS           = 'lt'; // 小于
    const OPTION_LESS_OR_EQUAL  = 'le'; // 小于等于
    const OPTION_BETWEEN        = 'bt'; // 在...之间
    const OPTION_NOT_BETWEEN    = 'nb'; // 不在...之间
    const OPTION_IN             = 'in'; // 在...之内
    const OPTION_NOT_IN         = 'ni'; // 不在...之内
    const OPTION_LIKE           = 'lk'; // 模糊查询
    const OPTION_NULL           = 'nl'; // 为空
    const OPTION_NOT_NULL       = 'nn'; // 不为空

    protected $request;
    protected $builder;
    protected $table;
    protected $input = [];

    /**
     * 允许排序的字段白名单，子类按需配置
     * 例：protected $sortable = ['id', 'created_at', 'name'];
     * @var array
     */
    protected $sortable = [];

    /**
     * 默认每页条数，可通过 ?per_page= 参数覆盖
     * @var int
     */
    protected $perPage = 15;

    /**
     * 创建时间字段名，子类可按需覆盖
     * @var string
     */
    protected $createdAtColumn = 'created_at';

    /**
     * 多态关联在本表中的关联字段名，子类可按需覆盖
     * @var string
     */
    protected $morphIdColumn = 'object_id';

    public function __construct($input = null)
    {
        if ($input instanceof Request) {
            $this->request = $input;
        } else {
            $this->request = new Request();
            if (is_array($input) && !empty($input)) {
                $this->request->merge($input);
            }
        }
        $this->filters();
    }

    public function apply(Builder $builder)
    {
        $this->builder = $builder;
        $this->table   = $builder->getModel()->getTable();

        foreach ($this->input as $name => $value) {
            if (is_null($value)) continue;
            if (!method_exists($this, $name)) continue;
            if ($this->isInternalMethod($name)) continue;
            call_user_func_array([$this, $name], [$value]);
        }

        $this->applyAfter();

        return $this->builder;
    }

    /**
     * 判断方法是否为 QueryFilter 基类的内部方法
     *
     * 防止请求参数与基类内部方法同名时被意外调用，
     * 例如 ?throwError=xxx 触发异常、?composeBuilder=xxx 报错等。
     * 仅当子类复写了同名方法时，该名称才会被允许自动调用。
     *
     * @param string $name
     * @return bool
     */
    private function isInternalMethod($name)
    {
        static $internal = null;

        if ($internal === null) {
            $internal = array_diff(
                array_map(function (\ReflectionMethod $method) {
                    return $method->getName();
                }, (new \ReflectionClass(self::class))->getMethods()),
                // 基类中允许通过请求参数直接触发的通用过滤方法
                ['id', 'created_at', 'sort', 'per_page', 'random']
            );
        }

        return in_array($name, $internal, true);
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
        // 快捷时间范围：?created_at=today / yesterday / week / month / year
        if ($bounds = $this->timeRangeBounds($created_at)) {
            $this->builder->whereBetween($this->createdAtColumn, $bounds);
            return;
        }
        $this->analyzeParam($this->createdAtColumn, $created_at);
    }

    /**
     * 快捷时间范围解析
     *
     * @param $value
     * @return array|null 返回 [起, 止] 时间范围，非预置关键字时返回 null
     */
    protected function timeRangeBounds($value)
    {
        $now = Carbon::now();
        switch ($value) {
            case 'today':
                return [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];
            case 'yesterday':
                return [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay()];
            case 'week':
                return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()];
            case 'month':
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
            case 'year':
                return [$now->copy()->startOfYear(), $now->copy()->endOfYear()];
            default:
                return null;
        }
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
        $input = [];
        foreach ($search_fields as $i => $field) {
            $input[$field] = $search_fields_value[$i];
        }
        $filter  = new $relateFilter($input);
        // 返回查询构造器，由调用方以子查询形式使用，避免在应用层物化中间结果
        return $relate_model::query()->filter($filter)->select($relate_local_id);
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
        // 使用当前模型主键字段，避免硬编码
        $this->builder->whereIn($this->builder->getModel()->getKeyName(), $ids);
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
            $model = $this->getModelByType($object_type);
            if ($model) {
                $model    = new $model();
                $keyName  = $model->getKeyName();
                if (is_array($value)) {
                    $ids = $model->whereIn($field, $value)->get()->pluck($keyName);
                } else {
                    $ids = $model->where($field, $value)->get()->pluck($keyName);
                }
                // 使用可配置的本表多态关联字段，避免硬编码
                $this->builder->whereIn($this->morphIdColumn, $ids);
            }
        }
    }

    /**
     * 根据 object_type 解析对应的模型类名
     *
     * 默认从 config('model_util.object_types') 读取类型与模型的映射，
     * 例如：['houses' => \App\Models\House::class]
     * 子类可按需复写此方法，以满足自定义映射规则。
     *
     * @param string $type 多态类型
     * @return string|null 模型类名，未配置时返回 null
     */
    protected function getModelByType($type)
    {
        $map = config('model_util.object_types', []);
        return is_array($map) && isset($map[$type]) ? $map[$type] : null;
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
        // 非标量值（如数组、对象）无法参与字符串运算，直接忽略
        if (!is_scalar($value)) {
            return;
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
                    // in / ni / bt / nb / nn 无法用普通 where 表达，或条件下不支持
                    if (in_array($op, [
                        self::OPTION_IN,
                        self::OPTION_NOT_IN,
                        self::OPTION_BETWEEN,
                        self::OPTION_NOT_BETWEEN,
                        self::OPTION_NOT_NULL,
                    ], true)) {
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
        // 内联子查询，避免把中间结果物化到应用层
        $query = $model::valid()->where($other_condition)->select($model_field);
        if ($value) {
            $this->builder->whereIn($field, $query);
        } else {
            $this->builder->whereNotIn($field, $query);
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
        if (empty($params)) return;

        // 将请求参数名映射为过滤器字段名
        $mapped = [];
        foreach ($params as $k => $v) {
            if (is_null($v)) continue;
            $mapped[$relation[$k]] = $v;
        }
        $this->searchIdsByFilter($mapped, $model, $model_field, $filter, $src_field, true);
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
        $mapped = [];
        foreach ($params as $k => $v) {
            if (is_null($v)) continue;
            $mapped[$k] = $v;
        }
        $this->searchIdsByFilter($mapped, $model, $model_field, $filter, $src_field, false);
    }

    /**
     * searchIdsWithFilter 与 searchIdsWithAttrsTable 的公共实现
     *
     * @param array $params 过滤器参数字段 => 值
     * @param $model
     * @param $model_field
     * @param $filter
     * @param string $src_field
     * @param bool $with_valid 是否应用模型的 valid() 作用域
     */
    private function searchIdsByFilter($params, $model, $model_field, $filter, $src_field, $with_valid)
    {
        if (empty($params)) return;

        $query = $model::filter(new $filter($params))->select($model_field)->distinct();
        if ($with_valid) {
            $query = $query->valid();
        }
        // 内联子查询，避免在应用层物化中间结果
        $this->builder->whereIn($src_field, $query);
    }

    //通用主键过滤器，可重构
    protected function id($param)
    {
        // 使用模型主键字段，避免硬编码
        $this->analyzeParam($this->builder->getModel()->getKeyName(), $param);
    }

    /**
     * 排序过滤：?sort=-created_at,name
     * 负号前缀表示倒序，多个排序字段用英文逗号分隔。
     * 字段必须在 $sortable 白名单内，未配置白名单时忽略排序。
     *
     * @param $value
     */
    protected function sort($value)
    {
        if (!is_scalar($value)) return;
        if (empty($this->sortable)) {
            return;
        }
        foreach (explode(',', (string)$value) as $item) {
            $item = trim($item);
            if ($item === '') continue;

            $desc  = Str::startsWith($item, '-');
            $field = ltrim($item, '-');
            if (!in_array($field, $this->sortable, true)) {
                $this->throwError($field);
            }
            if ($desc) {
                $this->builder->orderByDesc($field);
            } else {
                $this->builder->orderBy($field);
            }
        }
    }

    /**
     * 每页条数：?per_page=20
     * 配合 getPerPage() 使用：User::query()->filter($filter)->paginate($filter->getPerPage());
     *
     * @param $value
     */
    protected function per_page($value)
    {
        if (!is_scalar($value)) return;
        $this->perPage = max(1, (int)$value);
    }

    /**
     * 获取每页条数（受 per_page 参数影响）
     *
     * @return int
     */
    public function getPerPage()
    {
        return $this->perPage;
    }

    /**
     * 随机取 N 条：?random=10
     *
     * 避免 orderBy random() 的全表排序：先取主键 min/max 再随机起点顺序取，
     * 仅适用于主键分布较均匀的自增主键场景（近似随机）。
     *
     * @param $value
     */
    protected function random($value)
    {
        if (!is_scalar($value)) return;
        $n = max(1, (int)$value);
        $keyName = $this->builder->getModel()->getKeyName();
        $base    = $this->builder->toBase();
        $max     = (int)$base->max($keyName);
        $min     = (int)$base->min($keyName);

        if ($max <= $min) {
            $this->builder->inRandomOrder()->limit($n);
            return;
        }
        $start = random_int($min, $max);
        $this->builder->where($keyName, '>=', $start)->orderBy($keyName)->limit($n);
    }

    //分离操作符和搜索值
    protected function splitOptionAndValue($v): array
    {
        if (!is_scalar($v)) {
            return [self::OPTION_EQUAL, null]; //非标量值不参与字符串运算
        }
        $v = (string)$v;
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
            case self::OPTION_NOT_BETWEEN:
                $params = explode(',', $value);
                if (count($params) != 2) {
                    $this->throwError($field);
                }
                $builder->whereNotBetween($field, $params);
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
            case self::OPTION_NOT_NULL:
                $builder->whereNotNull($field);
                break;
            default:
                if (!isset($ops[$op])) {
                    $this->throwError($field);
                }
                $builder->where($field, $ops[$op], $value);
        }
    }

    //抛出参数错误
    protected function throwError($field)
    {
        throw new InvalidParamException($field . '参数格式错误');
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
            self::OPTION_NOT_BETWEEN . ":",
            self::OPTION_NULL . ":",
            self::OPTION_NOT_NULL . ":",
        ];
    }
}
