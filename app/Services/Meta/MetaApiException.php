<?php

namespace App\Services\Meta;

use RuntimeException;

/**
 * An error returned by Meta's Graph API, e.g. an expired token or a
 * WhatsApp message sent outside the 24-hour window.
 */
class MetaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $metaCode = null,
        public readonly ?string $details = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * A message suitable for showing to agents in the chat.
     */
    public function friendlyMessage(): string
    {
        $message = $this->getMessage();

        if ($this->details && ! str_contains($message, $this->details)) {
            $message .= ' — '.$this->details;
        }

        return $this->metaCode ? "{$message} (Meta error {$this->metaCode})" : $message;
    }
}
