<?php

namespace App\Services;

use RuntimeException;

class GksTransport
{
    public function handshake(string $body): array
    {
        return $this->request('https://embed.st/fetch', 16384, $body);
    }

    public function playlist(string $url, int $limit): string
    {
        if (! preg_match('~^https://lb[0-9]+\.strmd\.st/secure/[A-Za-z0-9_-]+/(?:rtmp|hotel|golf|ingest)/stream/[A-Za-z0-9_-]+/[1-9][0-9]*/(?:playlist|(?:high|low)/mono)\.m3u8$~D', $url)) {
            throw new RuntimeException('Unsupported playlist URL.');
        }

        return $this->request($url, $limit)['body'];
    }

    public function page(string $url, int $limit): string
    {
        if (! preg_match('~^https://embed\.st/embed/golf/[1-9][0-9]{0,8}/[12]$~D', $url)
            && ! preg_match('~^https://rockylive\.st/source/streamed1\.php\?hd=[1-9][0-9]{0,8}&id=[1-9][0-9]{0,8}&no=[12]$~D', $url)) {
            throw new RuntimeException('Unsupported GOLF page URL.');
        }

        return $this->request($url, min($limit, 1048576))['body'];
    }

    public function redirects(array $urls): array
    {
        if (count($urls) > 8) {
            throw new RuntimeException('Too many media redirects.');
        }
        $multi = curl_multi_init();
        curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, 3);
        $handles = $locations = [];
        try {
            foreach ($urls as $url) {
                if (! preg_match('~^https://lb[0-9]+\.strmd\.st/m/[A-Za-z0-9_-]{100,1500}\.ts$~D', $url)) {
                    throw new RuntimeException('Unsupported media redirect URL.');
                }
                $handle = curl_init($url);
                curl_setopt_array($handle, [
                    CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
                    CURLOPT_HTTPHEADER => ['Origin: https://embed.st', 'Referer: https://embed.st/'],
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$locations, $url): int {
                        if (str_starts_with(strtolower($line), 'location:')) {
                            $locations[$url] = trim(substr($line, 9));
                        }

                        return strlen($line);
                    },
                    // Fail closed if an upstream incorrectly sends a body to HEAD.
                    CURLOPT_WRITEFUNCTION => static fn () => 0,
                ]);
                $handles[$url] = $handle;
                curl_multi_add_handle($multi, $handle);
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running && $status === CURLM_OK) {
                    curl_multi_select($multi, 0.2);
                }
            } while ($running && $status === CURLM_OK);
            foreach ($handles as $url => $handle) {
                if (curl_errno($handle) || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 302 || ! isset($locations[$url])) {
                    // Cache successful metadata even if a newly published segment
                    // is not ready yet. The service rejects an incomplete playlist.
                    unset($locations[$url]);
                }
            }

            return $locations;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }

    private function request(string $url, int $limit, ?string $post = null): array
    {
        $body = '';
        $goat = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Referer: https://embed.st/',
                'Origin: https://embed.st',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36',
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$goat): int {
                if (str_starts_with(strtolower($line), 'goat:')) {
                    $goat = trim(substr($line, 5));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($post !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
        }
        try {
            if (! curl_exec($curl) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException('GKS source unavailable.');
            }

            return ['body' => $body, 'key' => $goat];
        } finally {
            curl_close($curl);
        }
    }
}
