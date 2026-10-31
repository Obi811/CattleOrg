<?php
/**
 * functions.php
 * -----------------------------------------------------------------------------
 * Allgemeine Hilfsfunktionen ohne Abhängigkeit von Session/Mandant.
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

if (!function_exists('formatDate')) {
    /**
     * Datum fuer die Anzeige im deutschen Format (Standard: TT.MM.JJJJ, z. B. 31.12.2026).
     * Leere/ungueltige Werte liefern $empty. Nur fuer die Anzeige, nicht fuer Formularfelder oder API.
     */
    function formatDate(?string $value, string $format = 'd.m.Y', string $empty = ''): string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return $empty;
        }
        $ts = strtotime($value);
        return $ts === false ? $value : date($format, $ts);
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
     * Formatiert eine Ohrmarkennummer in lesbare Blöcke (z. B. "12 345 67890").
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

if (!function_exists('cattleLink')) {
    /**
     * Baut einen Link auf die Tier-Profilseite (cattle_show.php).
     * Zentrale Stelle, damit Ohrmarken/Tiernummern überall gleich
     * verlinkt und formatiert werden.
     *
     * @param int         $cattleId  ID des Tieres (cattle.id)
     * @param string|null $earTag    Ohrmarke (wird mit formatEarTag lesbar gemacht)
     * @param string      $cssClass  optionale CSS-Klassen für den <a>-Tag
     * @param string|null $label     optionaler Anzeigetext (statt formatierter Ohrmarke)
     */
    function cattleLink(int $cattleId, ?string $earTag, string $cssClass = '', ?string $label = null): string
    {
        $text = $label !== null ? $label : formatEarTag($earTag);
        if ($text === '') {
            $text = '–';
        }
        $href  = url('cattle_show.php') . '?id=' . $cattleId;
        $class = $cssClass !== '' ? ' class="' . e($cssClass) . '"' : '';
        return '<a href="' . e($href) . '"' . $class . '>' . e($text) . '</a>';
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

if (!function_exists('drySealMedicationPatterns')) {
    /**
     * Namensmuster (Kleinbuchstaben, SQL-LIKE) zur Erkennung von Trockenstellern
     * und Versieglern im Medikamentennamen. Zentrale, konfigurierbare Stelle.
     *
     * Hintergrund: Die Tabelle `medications` hat keine Kategorie-Spalte. Ein
     * Trockensteller/Versiegler wird daher ueber den Medikamentennamen ODER
     * ueber die Behandlungsart "Trockenstellen" erkannt (siehe
     * drySealTreatmentCondition()). Neue Produktnamen hier ergaenzen.
     */
    function drySealMedicationPatterns(): array
    {
        return [
            '%trockenstell%',   // Trockensteller
            '%versiegl%',       // Versiegler / Zitzenversiegler
            '%trocken%',        // generisch "trocken"
            '%orbeseal%',       // Orbeseal (Versiegler)
            '%cepravin%',       // Cepravin (Trockensteller)
            '%bovaclox%',       // Bovaclox DC (Trockensteller)
            '%ubro yellow%',    // Ubro Yellow (Trockensteller)
        ];
    }
}

if (!function_exists('drySealTreatmentCondition')) {
    /**
     * Liefert einen SQL-Boolean-Ausdruck, der wahr ist, wenn eine Behandlung
     * ein Trockensteller/Versiegler ist: Behandlungsart "Trockenstellen" ODER
     * Medikamentenname passt auf ein Muster aus drySealMedicationPatterns().
     *
     * Die Muster sind entwicklerseitige Konstanten (keine Nutzereingabe) und
     * werden direkt und escaped in die Query eingebettet. Dadurch werden KEINE
     * benannten Platzhalter verbraucht – wichtig wegen
     * PDO::ATTR_EMULATE_PREPARES=false (Platzhalter duerfen je Query nicht
     * mehrfach vorkommen).
     *
     * @param string $tAlias Alias/Spaltenpraefix der treatments-Zeile (z. B. "t")
     */
    function drySealTreatmentCondition(string $tAlias = 't'): string
    {
        $likes = [];
        foreach (drySealMedicationPatterns() as $p) {
            $esc = str_replace("'", "''", strtolower($p));
            $likes[] = "LOWER($tAlias.medication) LIKE '$esc'";
        }
        $likeSql = $likes ? ('(' . implode(' OR ', $likes) . ')') : '0';
        return "($tAlias.type = 'Trockenstellen' OR $likeSql)";
    }
}

if (!function_exists('trockensteherCattleCondition')) {
    /**
     * Liefert einen SQL-Boolean-Ausdruck fuer die Kennzahl/den Filter
     * "Trockensteher": Das Tier (Alias $cAlias) hat eine Behandlung mit einem
     * Trockensteller/Versiegler (siehe drySealTreatmentCondition) erhalten und
     * seit dem letzten solchen Behandlungsdatum KEINE Kalbung gehabt.
     *
     * Kalbung = lebendes Kalb (ueber mutter_id ODER mutter=Ohrmarke) ODER eine
     * erfasste Totgeburt, jeweils mit birth_date >= letztem Trockenstell-Datum.
     *
     * Verwendet ausschliesslich korrelierte Unterabfragen auf $cAlias.farm_id /
     * $cAlias.id / $cAlias.ear_tag und KEINE benannten Platzhalter – damit ist
     * der Ausdruck mit PDO::ATTR_EMULATE_PREPARES=false kompatibel und kann
     * mehrfach in einer Query stehen.
     *
     * @param string $cAlias Alias der cattle-Zeile (z. B. "c")
     */
    function trockensteherCattleCondition(string $cAlias = 'c'): string
    {
        $dry = drySealTreatmentCondition('t');
        // Spaetestes Trockenstell-/Versiegler-Datum dieses Tieres
        $lastDry = "(SELECT MAX(t.treatment_date) FROM treatments t
                     WHERE t.farm_id = $cAlias.farm_id AND t.cattle_id = $cAlias.id
                       AND t.treatment_date IS NOT NULL AND $dry)";
        return "(
            EXISTS (SELECT 1 FROM treatments t
                    WHERE t.farm_id = $cAlias.farm_id AND t.cattle_id = $cAlias.id
                      AND t.treatment_date IS NOT NULL AND $dry)
            AND NOT EXISTS (
                SELECT 1 FROM cattle k
                WHERE k.farm_id = $cAlias.farm_id AND k.id <> $cAlias.id
                  AND (k.mutter_id = $cAlias.id
                       OR (k.mutter IS NOT NULL AND k.mutter <> '' AND k.mutter = $cAlias.ear_tag))
                  AND k.birth_date IS NOT NULL AND k.birth_date >= $lastDry
            )
            AND NOT EXISTS (
                SELECT 1 FROM stillbirths s
                WHERE s.farm_id = $cAlias.farm_id AND s.mother_cattle_id = $cAlias.id
                  AND s.birth_date >= $lastDry
            )
        )";
    }
}

if (!function_exists('milchkuhCattleCondition')) {
    /**
     * Liefert einen SQL-Boolean-Ausdruck fuer die Kennzahl/den Filter
     * "Milchkuh": aktives, weibliches Tier mit mindestens einer Kalbung
     * (lebendes Kalb ueber mutter_id ODER mutter=Ohrmarke) ODER einer
     * erfassten Totgeburt (stillbirths). Nur korrelierte Unterabfragen auf
     * $cAlias, keine Platzhalter -> EMULATE_PREPARES=false-sicher und mehrfach
     * verwendbar.
     *
     * @param string $cAlias Alias der cattle-Zeile (z. B. "c")
     */
    function milchkuhCattleCondition(string $cAlias = 'c'): string
    {
        return "(
            EXISTS (
                SELECT 1 FROM cattle k
                WHERE k.farm_id = $cAlias.farm_id AND k.id <> $cAlias.id
                  AND (k.mutter_id = $cAlias.id
                       OR (k.mutter IS NOT NULL AND k.mutter <> '' AND k.mutter = $cAlias.ear_tag))
            )
            OR EXISTS (
                SELECT 1 FROM stillbirths s
                WHERE s.farm_id = $cAlias.farm_id AND s.mother_cattle_id = $cAlias.id
            )
        )";
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
     * Einfache Flash-Messages über die Session.
     * Aufruf ohne $message liest und löscht die gespeicherte Nachricht.
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
