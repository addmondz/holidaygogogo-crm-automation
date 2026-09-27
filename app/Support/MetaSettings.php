<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Meta app settings entered in the setup wizard. They are stored in the
 * database (secrets encrypted) and override the META_* values in .env, so
 * admins never need server access.
 */
class MetaSettings
{
    private const SECRET_KEYS = ['app_secret', 'system_token'];

    public static function get(string $key): ?string
    {
        $value = Setting::get("meta.{$key}");

        if (! is_string($value) || $value === '') {
            return null;
        }

        return in_array($key, self::SECRET_KEYS, true) ? rescue(fn () => Crypt::decryptString($value), null, false) : $value;
    }

    public static function set(string $key, ?string $value): void
    {
        $value = $value === null || $value === '' ? null : trim($value);

        if ($value !== null && in_array($key, self::SECRET_KEYS, true)) {
            $value = Crypt::encryptString($value);
        }

        Setting::set("meta.{$key}", $value);
        self::applyToConfig();
    }

    /**
     * Copy saved values over config('services.meta.*'). Called on boot.
     */
    public static function applyToConfig(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            foreach (['app_id', 'app_secret', 'verify_token' => 'webhook_verify_token'] as $key => $configKey) {
                $key = is_int($key) ? $configKey : $key;

                if ($value = self::get($key)) {
                    config(["services.meta.{$configKey}" => $value]);
                }
            }
        } catch (Throwable) {
            // No database yet (e.g. during installation).
        }
    }
}
