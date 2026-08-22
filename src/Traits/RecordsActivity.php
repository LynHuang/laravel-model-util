<?php

namespace LynHuang\LaravelModelUtil\Traits;

use Illuminate\Support\Facades\DB;

/**
 * 模型操作审计 Trait
 *
 * 模型创建 / 更新 / 删除时自动记录操作日志到 activity_logs 表。
 *
 * 表名可通过 config('model_util.activity_logs_table') 配置，默认 activity_logs。
 * 发布迁移：
 *   php artisan vendor:publish --tag=model-util-migrations
 */
trait RecordsActivity
{
    /**
     * 注册模型事件
     */
    public static function bootRecordsActivity()
    {
        static::created(function ($model) {
            $model->recordActivity('created');
        });
        static::updated(function ($model) {
            $model->recordActivity('updated');
        });
        static::deleted(function ($model) {
            $model->recordActivity('deleted');
        });
    }

    /**
     * 记录一条操作日志
     *
     * @param string $event 事件名（created / updated / deleted）
     * @param string|null $description 描述，为空时自动生成
     * @return int 写入的日志 id
     */
    public function recordActivity(string $event, ?string $description = null)
    {
        $properties = null;

        if ($event === 'updated' && $this->getChanges()) {
            $properties = [
                'before' => array_intersect_key($this->getOriginal(), $this->getChanges()),
                'after'  => $this->getChanges(),
            ];
        }

        return DB::table($this->activityLogTable())->insertGetId([
            'log_name'     => $event,
            'description'  => $description ?: $this->activityDescription($event),
            'subject_type' => get_class($this),
            'subject_id'   => $this->getKey(),
            'causer_type'  => $this->activityCauser() ? get_class($this->activityCauser()) : null,
            'causer_id'    => $this->activityCauser() ? $this->activityCauser()->getKey() : null,
            'properties'   => $properties ? json_encode($properties, JSON_UNESCAPED_UNICODE) : null,
            'created_at'   => now(),
        ]);
    }

    /**
     * 手动记录任意操作日志
     *
     * @param string $description
     * @param $subject 关联的业务模型
     * @param string $logName
     * @return int
     */
    public static function logActivity(string $description, $subject = null, string $logName = 'manual')
    {
        $instance = new static;

        return DB::table($instance->activityLogTable())->insertGetId([
            'log_name'     => $logName,
            'description'  => $description,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id'   => $subject ? $subject->getKey() : null,
            'causer_type'  => $instance->activityCauser() ? get_class($instance->activityCauser()) : null,
            'causer_id'    => $instance->activityCauser() ? $instance->activityCauser()->getKey() : null,
            'properties'   => null,
            'created_at'   => now(),
        ]);
    }

    /**
     * 操作人（当前登录用户），默认取 auth()->user()
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    protected function activityCauser()
    {
        return auth()->check() ? auth()->user() : null;
    }

    /**
     * 自动生成的事件描述
     */
    protected function activityDescription(string $event)
    {
        $modelName = class_basename($this);

        return ucfirst($modelName) . ' ' . $event . ' #' . $this->getKey();
    }

    /**
     * 日志表名
     */
    protected function activityLogTable()
    {
        return config('model_util.activity_logs_table', 'activity_logs');
    }
}
