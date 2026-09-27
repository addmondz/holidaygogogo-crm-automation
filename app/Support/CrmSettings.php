<?php

namespace App\Support;

use App\Enums\InboxVisibility;
use App\Models\Setting;

/**
 * Typed access to the settings admins can change from the Settings page.
 */
class CrmSettings
{
    public const DEFAULT_OPT_OUT_KEYWORDS = ['STOP', 'UNSUBSCRIBE'];

    public const DEFAULT_OPT_IN_KEYWORDS = ['START', 'SUBSCRIBE'];

    public static function visibility(): InboxVisibility
    {
        return InboxVisibility::tryFrom((string) Setting::get('inbox.visibility'))
            ?? InboxVisibility::OwnAndUnassigned;
    }

    public static function autoAssign(): bool
    {
        return (bool) Setting::get('assignment.auto_assign', false);
    }

    /**
     * @return list<string>
     */
    public static function optOutKeywords(): array
    {
        return self::keywords('broadcast.opt_out_keywords', self::DEFAULT_OPT_OUT_KEYWORDS);
    }

    /**
     * @return list<string>
     */
    public static function optInKeywords(): array
    {
        return self::keywords('broadcast.opt_in_keywords', self::DEFAULT_OPT_IN_KEYWORDS);
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function keywords(string $key, array $default): array
    {
        $value = Setting::get($key, $default);

        return array_values(array_filter(array_map(
            fn ($keyword) => mb_strtoupper(trim((string) $keyword)),
            is_array($value) ? $value : $default,
        )));
    }
}
