<?php

namespace App\Support;

class AdSense
{
    public function publisherId(): ?string
    {
        $value = trim((string) config('adsense.publisher_id', ''));

        if (! preg_match('/\A(?:ca-)?(pub-[0-9]{16})\z/', $value, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public function clientId(): ?string
    {
        $publisherId = $this->publisherId();

        return $publisherId ? 'ca-'.$publisherId : null;
    }

    public function enabled(): bool
    {
        return (bool) config('adsense.enabled', false) && $this->publisherId() !== null;
    }
}
