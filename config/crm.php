<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default country code
    |--------------------------------------------------------------------------
    |
    | Used to turn local phone numbers into international format when
    | contacts are imported or typed in, e.g. 012-345 6789 -> 60123456789.
    |
    */

    'default_country_code' => env('CRM_DEFAULT_COUNTRY_CODE', '60'),

    /*
    |--------------------------------------------------------------------------
    | Blast sending speed
    |--------------------------------------------------------------------------
    |
    | Maximum broadcast messages sent per minute (per channel). Meta's own
    | throughput is much higher; this keeps blasts gentle on your number's
    | quality rating and leaves room for agents' live replies.
    |
    */

    'broadcast_per_minute' => (int) env('CRM_BROADCAST_PER_MINUTE', 300),

    /*
    |--------------------------------------------------------------------------
    | Media storage
    |--------------------------------------------------------------------------
    |
    | Disk used for customer photos/files and quick-reply attachments.
    | Files are served only to logged-in agents.
    |
    */

    'media_disk' => env('CRM_MEDIA_DISK', 'local'),

    'max_upload_kb' => (int) env('CRM_MAX_UPLOAD_KB', 16384),

];
