<?php
/**
 * functions.php
 * -----------------------------------------------------------------------------
 * Allgemeine Hilfsfunktionen ohne Abhaengigkeit von Session/Mandant.
 */

declare(strict_types=1);

if (!function_exists('e')) {
    /**
     * Sichere HTML-Ausgabe (XSS-Schutz).
     */
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    /**
     * Weiterleitung auf eine andere Seite und Skript beenden.
     */
    function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('url')) {
    /**
     * Baut eine URL relativ zur konfigurierten BASE_URL.
     */
    function url(string $path = ''): string
    {
        $base = defined('BASE_URL') ? BASE_URL : '/';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('formatEarTag')) {
    /**
     * Formatiert eine Ohrmarkennummer in lesbare Bloecke (z. B. "12 345 67890").
     */
    function formatEarTag(?string $earTag): string
    {
        $earTag = str_replace(' ', '', (string) $earTag);
        if ($earTag === '') {
            return '';
        }
        $part1 = substr($earTag, 0, 2);
        $part2 = substr($earTag, 2, 3);
        $part3 = substr($earTag, 5);

        $formatted = $part1;
        if ($part2 !== '') {
            $formatted .= ' ' . $part2;
        }
        if ($part3 !== '') {
            $formatted .= ' ' . $part3;
        }
        return $formatted;
    }
}

if (!function_exists('normalizeEarTag')) {
    /**
     * Reduziert eine Ohrmarke auf reine Ziffern (entfernt "DE", Leerzeichen etc.).
     */
    function normalizeEarTag(?string $earTag): string
    {
        return preg_replace('/[^0-9]/', '', (string) $earTag) ?? '';
    }
}

if (!function_exists('cattleRaces')) {
    /**
     * Erlaubte Rassen (entspricht dem ENUM in der DB).
     */
    function cattleRaces(): array
    {
        return [
            '1 SBT Holstein-Sbt',
            '2 RBT Holstein-Rbt',
            '9 RDN Doppelnutzung Rotbunt',
            '11 FL Fleckvieh',
            '18 BVA Braunvieh alter Zuchtrichtung',
            '90 SON Sonstige Rassen',
            '97 XFF Kreuzung Fleischrind x Fleischrind',
            '98 XFM Kreuzung Fleischrind x Milchrind',
            '99 XMM Kreuzung Milchrind x Milchrind',
        ];
    }
}

if (!function_exists('treatmentTypes')) {
    /**
     * Erlaubte Behandlungsarten (entspricht dem ENUM in der DB).
     */
    function treatmentTypes(): array
    {
        return ['Injektion', 'drenchen', 'Eutertube', 'Trockenstellen', 'Hemmstoff Probe', 'Klauenpflege', 'Sonstige'];
    }
}

if (!function_exists('calcAge')) {
    /**
     * Berechnet das Alter als lesbaren String "x J, y M" aus einem Geburtsdatum.
     */
    function calcAge(?string $birthDate): string
    {
        if (empty($birthDate)) {
            return '-';
        }
        try {
            $birth = new DateTime($birthDate);
            $now   = new DateTime();
            $diff  = $birth->diff($now);
            return "{$diff->y} J, {$diff->m} M";
        } catch (Exception $e) {
            return '-';
        }
    }
}

if (!function_exists('flash')) {
    /**
     * Einfache Flash-Messages ueber die Session.
     * Aufruf ohne $message liest und loescht die gespeicherte Nachricht.
     */
    function flash(string $key, ?string $message = null, string $type = 'info')
    {
        if ($message !== null) {
            $_SESSION['_flash'][$key] = ['message' => $message, 'type' => $type];
            return null;
        }
        if (isset($_SESSION['_flash'][$key])) {
            $data = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            return $data;
        }
        return null;
    }
}
