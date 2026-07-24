<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;

/**
 * "Sargodha, PK" from an IP, resolved ENTIRELY ON THIS SERVER.
 *
 * A third-party HTTP lookup would mean every login IP of every customer
 * leaves the box and lands in some other company's logs — an unacceptable
 * trade for a cosmetic label on a sessions screen. MaxMind's GeoLite2 City
 * database is a local file, so nothing is transmitted.
 *
 * THE DATABASE FILE IS OPTIONAL. It is ~60MB, needs a (free) MaxMind
 * account, and is not in the repo. When it is absent this returns null and
 * the sessions screen simply shows no location. Nothing throws, no feature
 * breaks, and no request is ever made off-box.
 *
 * To enable: download GeoLite2-City.mmdb from MaxMind and place it at
 * storage/app/geoip/GeoLite2-City.mmdb (override with GEOIP_DATABASE).
 */
class IpLocator
{
    /**
     * Lookups are cached because a burst of requests from one device would
     * otherwise re-read the same 60MB file's index repeatedly, and an IP's
     * city does not change within an hour.
     */
    private const CACHE_TTL_SECONDS = 3600;

    public function locate(?string $ip): ?string
    {
        if (! $this->isPublicIp($ip)) {
            return null;
        }

        return Cache::remember('geoip:'.$ip, self::CACHE_TTL_SECONDS, fn () => $this->lookup($ip));
    }

    private function lookup(string $ip): ?string
    {
        $path = $this->databasePath();

        // No database, or the reader library was never installed. Both are
        // expected states, not errors.
        if ($path === null || ! class_exists(\GeoIp2\Database\Reader::class)) {
            return null;
        }

        try {
            $record = (new \GeoIp2\Database\Reader($path))->city($ip);

            $city = $record->city->name;
            $country = $record->country->isoCode;

            return match (true) {
                $city !== null && $country !== null => "{$city}, {$country}",
                $country !== null => $country,
                default => null,
            };
        } catch (\Throwable) {
            // AddressNotFoundException is routine (many IPs simply are not in
            // the database) and a corrupt file must not break signing in.
            // A missing location is cosmetic; a failed login is not.
            return null;
        }
    }

    private function databasePath(): ?string
    {
        $path = (string) config('services.geoip.database', storage_path('app/geoip/GeoLite2-City.mmdb'));

        return is_file($path) ? $path : null;
    }

    /**
     * Private, loopback and reserved ranges have no location, and looking
     * them up is pure waste — this is every request in local development.
     */
    private function isPublicIp(?string $ip): bool
    {
        if (! is_string($ip) || $ip === '') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
