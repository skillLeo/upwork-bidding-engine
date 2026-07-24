<?php

namespace App\Services\Auth;

/**
 * Turns a user-agent string into "Chrome on macOS".
 *
 * Deliberately a small local parser rather than a dependency. A full UA
 * database (jenssegers/agent and friends) ships tens of thousands of regexes
 * to answer questions this screen never asks; all that is needed here is
 * enough for a person to recognise their own device in a list and revoke the
 * one they don't recognise.
 *
 * Order matters in both tables below — Edge advertises itself as Chrome, and
 * Chrome advertises itself as Safari, so the most specific token has to be
 * tested first.
 */
class DeviceLabeller
{
    /** @var array<string, string> needle => browser name, most specific first */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'Firefox' => 'Firefox',
        'CriOS' => 'Chrome',      // Chrome on iOS
        'FxiOS' => 'Firefox',     // Firefox on iOS
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
        'PostmanRuntime' => 'Postman',
        'curl' => 'curl',
    ];

    /** @var array<string, string> needle => platform name, most specific first */
    private const PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows NT' => 'Windows',
        'Mac OS X' => 'macOS',
        'Macintosh' => 'macOS',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public function label(?string $userAgent): ?string
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return null;
        }

        $browser = $this->firstMatch($userAgent, self::BROWSERS);
        $platform = $this->firstMatch($userAgent, self::PLATFORMS);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            // Something unrecognised: show a short prefix rather than
            // "Unknown device", so an odd client is still identifiable.
            default => mb_substr($userAgent, 0, 40),
        };
    }

    /**
     * @param  array<string, string>  $table
     */
    private function firstMatch(string $haystack, array $table): ?string
    {
        foreach ($table as $needle => $name) {
            if (str_contains($haystack, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
