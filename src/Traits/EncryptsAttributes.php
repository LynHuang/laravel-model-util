<?php

namespace LynHuang\LaravelModelUtil\Traits;

use Illuminate\Contracts\Encryption\DecryptException;
use LogicException;

/**
 * 字段加解密存取 Trait
 *
 * 通过模型事件自动对指定字段加密存储、解密读取，并提供精确查询作用域。
 *
 * 使用方式：在模型里声明
 *   use EncryptsAttributes;
 *   protected $encryptable = ['mobile', 'id_card'];
 *
 * 精确查询基于盲索引列（HMAC-SHA256，列名为 <字段>_hash），需为每个加密字段
 * 增加对应的盲索引列并建议加索引：
 *   $table->string('mobile_hash', 64)->nullable()->index();
 * 未创建盲索引列时不影响加解密存取，只是 whereEncrypted 不可用；
 * 历史数据可通过 Model::backfillEncryptHashes() 回填。
 *
 * 注意：
 * - 加密字段无法做模糊查询：encrypt() 每次产生不同密文（随机 IV），LIKE 永远匹配不上，
 *   whereEncryptedLike 调用时会抛出异常说明原因；
 * - 通过 Query Builder 的批量 update（Model::query()->update() 等）不触发模型事件，
 *   不会自动加解密，请走 Eloquent 的 save / update 路径。
 */
trait EncryptsAttributes
{
    // 注意：加密字段列表 $encryptable 与盲索引后缀 $encryptHashSuffix 由使用方模型声明。
    // Trait 与模型重复定义同名属性时，PHP 会对不同默认值报致命错误，故这里不声明，
    // 统一通过 encryptableFields() / encryptHashColumnSuffix() 读取。

    /**
     * 需要加解密的字段列表，模型中声明：protected $encryptable = ['mobile'];
     *
     * @return array
     */
    protected function encryptableFields()
    {
        return property_exists($this, 'encryptable') ? $this->encryptable : [];
    }

    /**
     * 盲索引列名后缀，模型可声明 $encryptHashSuffix 覆盖，默认 '_hash'
     *
     * @return string
     */
    protected function encryptHashColumnSuffix()
    {
        return property_exists($this, 'encryptHashSuffix') ? $this->encryptHashSuffix : '_hash';
    }

    /**
     * 注册模型事件
     */
    public static function bootEncryptsAttributes()
    {
        // 保存前：把脏的明文字段加密，并同步维护盲索引列
        static::saving(function ($model) {
            $model->encryptDirtyAttributes();
        });

        // 取出后：把密文还原为明文（不产生脏状态，避免未修改的保存重复加密）
        static::retrieved(function ($model) {
            $model->decryptAttributesInMemory();
        });

        // 保存后：把内存中的密文还原为明文，保证保存后继续读取模型拿到的是明文
        static::saved(function ($model) {
            $model->decryptAttributesInMemory();
        });
    }

    /**
     * 加密脏的明文字段，并同步写入盲索引列
     */
    protected function encryptDirtyAttributes()
    {
        foreach ($this->encryptableFields() as $field) {
            if (!$this->isDirty($field)) {
                continue;
            }

            $plaintext  = $this->getAttribute($field);
            $hashColumn = $field . $this->encryptHashColumnSuffix();
            $hasHash    = $this->hasEncryptHashColumn($hashColumn);

            // 值被清空：字段按原值存入（null / ''），盲索引列同步置空
            if ($plaintext === null || $plaintext === '') {
                if ($hasHash) {
                    $this->setAttribute($hashColumn, null);
                }
                continue;
            }

            if ($hasHash) {
                $this->setAttribute($hashColumn, $this->encryptSearchHash($plaintext));
            }
            $this->setAttribute($field, encrypt($plaintext));
        }
    }

    /**
     * 把内存中的密文解密为明文（attributes 与 original 同步更新，不产生脏状态）
     *
     * retrieved 时 attributes / original 均为库中密文；
     * saved 时 attributes 为刚加密写入的密文（saved 事件先于 finishSave 的 syncOriginal 触发）。
     */
    protected function decryptAttributesInMemory()
    {
        foreach ($this->encryptableFields() as $field) {
            $cipher = $this->attributes[$field] ?? null;
            if ($cipher === null || $cipher === '' || !is_string($cipher)) {
                continue;
            }
            try {
                $plaintext = decrypt($cipher);
            } catch (DecryptException $e) {
                // 无法解密的旧数据保留原值
                continue;
            }
            $this->attributes[$field] = $plaintext;
            if (array_key_exists($field, $this->original)) {
                $this->original[$field] = $plaintext;
            }
        }
    }

    /**
     * 按明文精确查询（基于盲索引列，非密文比对）
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $field 加密字段名
     * @param mixed $value 明文值
     * @return mixed
     */
    public function scopeWhereEncrypted($query, string $field, $value)
    {
        $this->assertEncryptSearchable($field);

        $hashColumn = $field . $this->encryptHashColumnSuffix();
        if ($value === null || $value === '') {
            return $query->whereNull($hashColumn);
        }

        return $query->where($hashColumn, $this->encryptSearchHash($value));
    }

    /**
     * 加密字段无法做模糊查询（密文每次都不同，LIKE 永远匹配不上），
     * 保留方法名并给出明确报错，避免使用者误以为查询结果正确。
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $field 加密字段名
     * @param mixed $value 明文值
     * @throws LogicException
     */
    public function scopeWhereEncryptedLike($query, string $field, $value)
    {
        throw new LogicException(
            '加密字段无法进行模糊查询：encrypt() 每次产生不同密文，LIKE 无法匹配密文。'
            . '精确查询请使用 whereEncrypted（基于 ' . $field . $this->encryptHashColumnSuffix() . ' 盲索引列）；'
            . '如需模糊检索，请额外维护一个明文或脱敏的可搜索字段。'
        );
    }

    /**
     * 把变更明细中加密字段的密文转换回明文（供审计等场景集成使用）
     *
     * @param array $changes 字段 => 密文
     * @return array 字段 => 明文（无法解密的保留密文）
     */
    public function revealChanges(array $changes)
    {
        foreach ($this->encryptableFields() as $field) {
            if (isset($changes[$field]) && is_string($changes[$field]) && $changes[$field] !== '') {
                try {
                    $changes[$field] = decrypt($changes[$field]);
                } catch (DecryptException $e) {
                    // 无法解密的保留密文
                }
            }
        }

        return $changes;
    }

    /**
     * 回填历史数据的盲索引列（新增盲索引列后执行一次即可）
     *
     * 走连接级 Query Builder 读取原始行，绕开模型事件，避免取出时的解密干扰。
     *
     * @param int $chunkSize 分块大小
     * @return int 实际更新的行数
     * @throws LogicException 当存在未创建盲索引列的加密字段时
     */
    public static function backfillEncryptHashes(int $chunkSize = 500)
    {
        $instance = new static;
        foreach ($instance->encryptableFields() as $field) {
            $instance->assertEncryptSearchable($field);
        }

        $keyName = $instance->getKeyName();
        $table   = $instance->getTable();
        $conn    = $instance->getConnection();
        $updated = 0;

        $conn->table($table)->chunkById($chunkSize, function ($rows) use ($instance, $conn, $keyName, $table, &$updated) {
            foreach ($rows as $row) {
                $updates = [];
                foreach ($instance->encryptableFields() as $field) {
                    $cipher = $row->{$field} ?? null;
                    if ($cipher === null || $cipher === '' || !is_string($cipher)) {
                        continue;
                    }
                    try {
                        $plaintext = decrypt($cipher);
                    } catch (DecryptException $e) {
                        // 无法解密的旧数据，跳过
                        continue;
                    }
                    $hash       = $instance->encryptSearchHash($plaintext);
                    $hashColumn = $field . $instance->encryptHashColumnSuffix();
                    if (($row->{$hashColumn} ?? null) === $hash) {
                        continue;
                    }
                    $updates[$hashColumn] = $hash;
                }
                if ($updates) {
                    $conn->table($table)->where($keyName, $row->{$keyName})->update($updates);
                    $updated++;
                }
            }
        });

        return $updated;
    }

    /**
     * 计算明文的盲索引哈希
     *
     * @param string $value 明文
     * @return string 64 位十六进制哈希
     */
    protected function encryptSearchHash($value)
    {
        return hash_hmac('sha256', (string)$value, $this->encryptSearchKey());
    }

    /**
     * 盲索引密钥：由 APP_KEY 派生（HMAC），与字段加密本身解耦
     */
    protected function encryptSearchKey()
    {
        return hash_hmac('sha256', 'laravel-model-util.encrypts-attributes', (string)config('app.key'));
    }

    /**
     * 盲索引列是否存在（按 连接+表 缓存，避免每次保存都查表结构）
     *
     * @param string $column
     * @return bool
     */
    protected function hasEncryptHashColumn($column)
    {
        static $cache = [];

        $key = $this->getConnectionName() . '|' . $this->getTable();
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = [];
        }
        if (!array_key_exists($column, $cache[$key])) {
            $cache[$key][$column] = $this->getConnection()
                ->getSchemaBuilder()
                ->hasColumn($this->getTable(), $column);
        }

        return $cache[$key][$column];
    }

    /**
     * 校验字段可用于加密精确查询：已在 $encryptable 声明且盲索引列存在
     *
     * @param string $field
     * @throws LogicException
     */
    protected function assertEncryptSearchable($field)
    {
        if (!in_array($field, $this->encryptableFields(), true)) {
            throw new LogicException("字段 {$field} 未加入 \$encryptable，无法使用 whereEncrypted 查询");
        }

        $hashColumn = $field . $this->encryptHashColumnSuffix();
        if (!$this->hasEncryptHashColumn($hashColumn)) {
            throw new LogicException(
                "表 {$this->getTable()} 缺少盲索引列 {$hashColumn}，请先添加并建索引"
                . "（如 \$table->string('{$hashColumn}', 64)->nullable()->index()），"
                . '历史数据可调用 ' . static::class . '::backfillEncryptHashes() 回填'
            );
        }
    }
}
