<?php

namespace LynHuang\LaravelModelUtil\Traits;

/**
 * 字段加解密存取 Trait
 *
 * 通过模型事件自动对指定字段加密存储、解密读取，
 * 并提供精确 / 模糊查询作用域。
 *
 * 使用方式：在模型里声明
 *   use EncryptsAttributes;
 *   protected $encryptable = ['mobile', 'id_card'];
 */
trait EncryptsAttributes
{
    /**
     * 需要加解密的字段列表，子类必须声明
     *
     * @var array
     */
    protected $encryptable = [];

    /**
     * 注册模型事件
     */
    public static function bootEncryptsAttributes()
    {
        static::saving(function ($model) {
            foreach ($model->encryptable as $field) {
                $value = $model->getAttribute($field);
                if ($value !== null && $value !== '') {
                    $model->setAttribute($field, encrypt($value));
                }
            }
        });

        static::retrieved(function ($model) {
            foreach ($model->encryptable as $field) {
                $value = $model->getRawOriginal($field);
                if ($value !== null && $value !== '') {
                    try {
                        $model->setAttribute($field, decrypt($value));
                    } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                        // 无法解密的旧数据保留原值
                    }
                }
            }
        });
    }

    /**
     * 按加密后的密文精确查询
     *
     * @param $query
     * @param string $field
     * @param $value
     * @return mixed
     */
    public function scopeWhereEncrypted($query, string $field, $value)
    {
        return $query->where($field, encrypt($value));
    }

    /**
     * 按加密后的密文模糊查询（like）
     *
     * @param $query
     * @param string $field
     * @param $value
     * @return mixed
     */
    public function scopeWhereEncryptedLike($query, string $field, $value)
    {
        return $query->where($field, 'like', '%' . encrypt($value) . '%');
    }
}
