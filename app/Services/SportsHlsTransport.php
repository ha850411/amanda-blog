<?php

namespace App\Services;

use RuntimeException;

class SportsHlsTransport
{
    public function get(string $url, int $limit): string
    {
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (! $ok || $status !== 200) {
                throw new RuntimeException('Upstream unavailable.');
            }

            return $body;
        } finally {
            curl_close($curl);
        }
    }
}
