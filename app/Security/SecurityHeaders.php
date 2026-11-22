<?php
// app/Security/SecurityHeaders.php — HTTP Security Headers & Cache Controls
namespace App\Security;

class SecurityHeaders {
    private static bool $sent = false;

    public static function apply(): void {
        if (self::$sent || headers_sent()) return;

        // Clickjacking protection
        header('X-Frame-Options: SAMEORIGIN');

        // MIME sniffing prevention
        header('X-Content-Type-Options: nosniff');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissions policy (allows geolocation only for same origin)
        header('Permissions-Policy: geolocation=(self)');

        // Anti-crawler header for all private / authenticated endpoints
        header('X-Robots-Tag: noindex, nofollow, noarchive');

        // Content Security Policy
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com https://cdn.datatables.net; " .
               "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdn.datatables.net; " .
               "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; " .
               "img-src 'self' data: https: blob:; " .
               "connect-src 'self'; " .
               "frame-ancestors 'self';";
        header("Content-Security-Policy: $csp");

        self::applyNoCache();

        self::$sent = true;
    }

    /**
     * Disable client and proxy caching for sensitive / personal records (Salary, Attendance, Reports)
     */
    public static function applyNoCache(): void {
        if (headers_sent()) return;
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    }
}
