<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'group', 'type', 'label', 'description'];

    public static function get(string $key, $default = null)
    {
        return Cache::rememberForever('setting.'.$key, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();
            if (! $setting) return $default;
            return match ($setting->type) {
                'int' => (int) $setting->value,
                'float' => (float) $setting->value,
                'bool' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
                'json' => json_decode($setting->value, true),
                default => $setting->value,
            };
        });
    }

    public static function put(string $key, $value, string $group = 'general', string $type = 'string'): self
    {
        if ($type === 'json' && ! is_string($value)) {
            $value = json_encode($value);
        } elseif ($type === 'bool') {
            $value = $value ? '1' : '0';
        }
        $setting = self::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group, 'type' => $type]);
        Cache::forget('setting.'.$key);
        return $setting;
    }
}
