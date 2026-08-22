<?php

namespace LynHuang\LaravelModelUtil\Traits;

/**
 * 多语言字段辅助 Trait
 *
 * 将翻译内容以 JSON 形式存储在一个字段中，提供按语言读取/写入的能力。
 * 使用前建议把对应字段加入模型的 $casts = ['name' => 'array']。
 */
trait Translatable
{
    /**
     * 读取指定语言的翻译值
     *
     * @param string $field 存储翻译 JSON 的字段
     * @param string|null $locale 语言标识，默认当前应用语言
     * @return mixed|null
     */
    public function translate(string $field, ?string $locale = null)
    {
        $locale = $locale ?: app()->getLocale();
        $value  = $this->getAttribute($field);

        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return null;
        }

        return $value[$locale]
            ?? $value[config('app.fallback_locale')]
            ?? $value[array_key_first($value)]
            ?? null;
    }

    /**
     * 写入指定语言的翻译值
     *
     * @param string $field 存储翻译 JSON 的字段
     * @param string $locale 语言标识
     * @param mixed $value 翻译内容
     * @return $this
     */
    public function setTranslation(string $field, string $locale, $value)
    {
        $data = $this->getAttribute($field);
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            $data = [];
        }

        $data[$locale] = $value;
        $this->setAttribute($field, json_encode($data, JSON_UNESCAPED_UNICODE));

        return $this;
    }

    /**
     * 指定语言是否已有翻译值
     *
     * @param string $field
     * @param string $locale
     * @return bool
     */
    public function hasTranslation(string $field, string $locale): bool
    {
        return $this->translate($field, $locale) !== null;
    }
}
