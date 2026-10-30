<?php
/**
 * /api/v1/cattle
 * -----------------------------------------------------------------------------
 * GET  -> Tierliste des Betriebs (paginiert, Filter: status, q, geschlecht,
 *         stall, wartezeit, traechtig, rasse, min_age, max_age)
 * POST -> neues Tier anlegen
 * Einzelnes Tier: GET /api/v1/cattle?id=123
 *
 * Erfordert Bearer-Token + Betriebskontext (X-Farm-Id) + passende Berechtigung.
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET', 'POST']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

// SQL-Ausdruck fuer die Wartezeit-Dauer (Tage) eines Behandlungs-Joins.
// wz_milk/wz_fleisch sind im Katalog VARCHAR -> explizit auf SIGNED casten,
// damit GREATEST numerisch (nicht alphabetisch) vergleicht.
$WZ_DAYS_EXPR = "GREATEST(CAST(COALESCE(m.wz_milk,'0') AS SIGNED), CAST(COALESCE(m.wz_fleisch,'0') AS SIGNED))";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apiRequirePermission($farm, 'cattle.read');

    // -----------------------------------------------------------------------
    // Einzelnes Tier (Detailansicht mit Zusatzinformationen)
    // -----------------------------------------------------------------------
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare('SELECT * FROM cattle WHERE id = :id AND farm_id = :f');
        $stmt->execute([':id' => $id, ':f' => $farmId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonError('NOT_FOUND', 'Tier nicht gefunden.', 404);
        }

        // Aktueller Stall (offener Stallaufenthalt)
        $cs = $db->prepare(
            'SELECT stall_name FROM stall_history
             WHERE cattle_id = :id AND farm_id = :f AND end_date IS NULL
             ORDER BY start_date DESC, id DESC LIMIT 1'
        );
        $cs->execute([':id' => $id, ':f' => $farmId]);
        $currentStall = $cs->fetchColumn() ?: null;

        // Wartezeit aus Behandlungen + globalem Medikamentenkatalog
        $tr = $db->prepare(
            'SELECT t.treatment_date, m.wz_milk, m.wz_fleisch
             FROM treatments t
             LEFT JOIN medications m ON m.name = t.medication
             WHERE t.cattle_id = :id AND t.farm_id = :f'
        );
        $tr->execute([':id' => $id, ':f' => $farmId]);
        $today = new DateTime('today');
        $milchEnde = null; $fleischEnde = null; $ende = null;
        foreach ($tr->fetchAll() as $t) {
            if (empty($t['treatment_date'])) { continue; }
            try { $bd = new DateTime($t['treatment_date']); } catch (Exception $e) { continue; }
            $wzm = (int) ($t['wz_milk'] ?? 0);
            $wzf = (int) ($t['wz_fleisch'] ?? 0);
            if ($wzm > 0) {
                $e1 = (clone $bd)->modify("+{$wzm} days");
                if ($milchEnde === null || $e1 > new DateTime($milchEnde)) { $milchEnde = $e1->format('Y-m-d'); }
            }
            if ($wzf > 0) {
                $e2 = (clone $bd)->modify("+{$wzf} days");
                if ($fleischEnde === null || $e2 > new DateTime($fleischEnde)) { $fleischEnde = $e2->format('Y-m-d'); }
            }
            $maxWz = max($wzm, $wzf);
            if ($maxWz > 0) {
                $e3 = (clone $bd)->modify("+{$maxWz} days");
                if ($ende === null || $e3 > new DateTime($ende)) { $ende = $e3->format('Y-m-d'); }
            }
        }
        $wartezeit = [
            'aktiv'       => $ende !== null && new DateTime($ende) >= $today,
            'ende'        => $ende,
            'milch_ende'  => $milchEnde,
            'fleisch_ende'=> $fleischEnde,
        ];

        // Traechtigkeit: letzte Besamung + letzte Kontrolle
        $li = $db->prepare(
            'SELECT MAX(date) FROM inseminations WHERE cattle_id = :id AND farm_id = :f'
        );
        $li->execute([':id' => $id, ':f' => $farmId]);
        $lastInsem = $li->fetchColumn() ?: null;

        $lc = $db->prepare(
            'SELECT check_date, result FROM pregnancy_checks
             WHERE cattle_id = :id AND farm_id = :f
             ORDER BY check_date DESC, id DESC LIMIT 1'
        );
        $lc->execute([':id' => $id, ':f' => $farmId]);
        $lastCheck = $lc->fetch();

        $expectedCalving = null;
        if ($lastInsem !== null) {
            try {
                $expectedCalving = (new DateTime($lastInsem))->modify('+280 days')->format('Y-m-d');
            } catch (Exception $e) { $expectedCalving = null; }
        }
        $pregnancy = [
            'status'            => $lastCheck['result'] ?? null,
            'last_insemination' => $lastInsem,
            'last_check'        => $lastCheck['check_date'] ?? null,
            'expected_calving'  => $expectedCalving,
        ];

        // Hemmstoffproben dieses Tieres
        $hp = $db->prepare(
            'SELECT id, probe_date, nummer, status, created_at
             FROM hemmstoff_probes WHERE cattle_id = :id AND farm_id = :f
             ORDER BY probe_date DESC, id DESC'
        );
        $hp->execute([':id' => $id, ':f' => $farmId]);

        // Totgeburten dieser Kuh (additiv zu den Kalbungen)
        $sb = $db->prepare(
            'SELECT id, birth_date, sex, comment, created_at
               FROM stillbirths WHERE mother_cattle_id = :id AND farm_id = :f
              ORDER BY birth_date DESC, id DESC'
        );
        $sb->execute([':id' => $id, ':f' => $farmId]);

        $row['current_stall']    = $currentStall;
        $row['wartezeit']        = $wartezeit;
        $row['pregnancy']        = $pregnancy;
        $row['hemmstoff_probes'] = $hp->fetchAll();
        $row['stillbirths']      = $sb->fetchAll();

        jsonSuccess($row);
    }

    // -----------------------------------------------------------------------
    // Liste (paginiert, mit Filtern)
    // -----------------------------------------------------------------------
    [$page, $perPage, $offset] = apiPagination();
    $status = $_GET['status'] ?? null;
    $q = trim((string) ($_GET['q'] ?? ''));
    $geschlecht = strtoupper(trim((string) ($_GET['geschlecht'] ?? '')));
    $stallFilter = trim((string) ($_GET['stall'] ?? ''));
    $wartezeitFilter = (string) ($_GET['wartezeit'] ?? '') === '1';
    $traechtigFilter = (string) ($_GET['traechtig'] ?? '') === '1';
    $trockensteherFilter = (string) ($_GET['trockensteher'] ?? '') === '1';
    $milchkuhFilter      = (string) ($_GET['milchkuh'] ?? '') === '1';
    // Filter analog zur Web-Tieruebersicht (public/cattle_list.php):
    // Rasse (exakter Match) sowie Alter in Monaten (min./max.) ueber birth_date.
    $rasse  = trim((string) ($_GET['rasse'] ?? ''));
    $minAge = trim((string) ($_GET['min_age'] ?? ''));
    $maxAge = trim((string) ($_GET['max_age'] ?? ''));

    $where = ['c.farm_id = :f'];
    $params = [':f' => $farmId];
    if ($status === 'Aktiv' || $status === 'Inaktiv') { $where[] = 'c.status = :s'; $params[':s'] = $status; }
    if ($q !== '') { $where[] = 'c.ear_tag LIKE :q'; $params[':q'] = '%' . $q . '%'; }
    if ($geschlecht === 'M' || $geschlecht === 'W') { $where[] = 'c.geschlecht = :g'; $params[':g'] = $geschlecht; }
    // Rasse (exakter Match, wie cattle_list.php)
    if ($rasse !== '') { $where[] = 'c.rasse = :rasse'; $params[':rasse'] = $rasse; }
    // Alter in Monaten: ctype_digit wie cattle_list.php -> nur nicht-negative Ganzzahlen.
    if ($minAge !== '' && ctype_digit($minAge)) {
        $where[] = 'c.birth_date IS NOT NULL AND TIMESTAMPDIFF(MONTH, c.birth_date, CURDATE()) >= :min_age';
        $params[':min_age'] = (int) $minAge;
    }
    if ($maxAge !== '' && ctype_digit($maxAge)) {
        $where[] = 'c.birth_date IS NOT NULL AND TIMESTAMPDIFF(MONTH, c.birth_date, CURDATE()) <= :max_age';
        $params[':max_age'] = (int) $maxAge;
    }

    // Filter: Tiere mit laufendem (offenem) Stallaufenthalt, optional je Stallname
    if ($stallFilter !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM stall_history sh
                            WHERE sh.cattle_id = c.id AND sh.farm_id = c.farm_id
                              AND sh.end_date IS NULL AND sh.stall_name = :stall)';
        $params[':stall'] = $stallFilter;
    }
    // Filter: Tiere mit aktuell laufender Wartezeit
    if ($wartezeitFilter) {
        $where[] = "EXISTS (SELECT 1 FROM treatments t
                            JOIN medications m ON m.name = t.medication
                            WHERE t.cattle_id = c.id AND t.farm_id = c.farm_id
                              AND $WZ_DAYS_EXPR > 0
                              AND DATE_ADD(t.treatment_date, INTERVAL $WZ_DAYS_EXPR DAY) >= CURDATE())";
    }
    // Filter: traechtige Tiere (letzte TU-Kontrolle = '+')
    if ($traechtigFilter) {
        $where[] = "EXISTS (SELECT 1 FROM pregnancy_checks pc
                            WHERE pc.cattle_id = c.id AND pc.farm_id = c.farm_id AND pc.result = '+'
                              AND pc.check_date = (SELECT MAX(pc2.check_date) FROM pregnancy_checks pc2
                                                   WHERE pc2.cattle_id = c.id AND pc2.farm_id = c.farm_id))";
    }
    // Filter: Trockensteher (zentrale Logik, nur korrelierte Unterabfragen)
    if ($trockensteherFilter) {
        $where[] = trockensteherCattleCondition('c');
    }
    // Filter: Milchkuehe (mind. eine Kalbung/Totgeburt)
    if ($milchkuhFilter) {
        $where[] = milchkuhCattleCondition('c');
    }
    $whereSql = implode(' AND ', $where);

    $cnt = $db->prepare("SELECT COUNT(*) FROM cattle c WHERE $whereSql");
    $cnt->execute($params);
    $total = (int) $cnt->fetchColumn();

    // current_stall + wz_ende als Unterabfragen
    $sql = "SELECT c.id, c.ear_tag, c.birth_date, c.rasse, c.geschlecht, c.mutter, c.status,
                   (SELECT sh.stall_name FROM stall_history sh
                     WHERE sh.cattle_id = c.id AND sh.farm_id = c.farm_id AND sh.end_date IS NULL
                     ORDER BY sh.start_date DESC, sh.id DESC LIMIT 1) AS current_stall,
                   (SELECT MAX(DATE_ADD(t.treatment_date, INTERVAL $WZ_DAYS_EXPR DAY))
                      FROM treatments t JOIN medications m ON m.name = t.medication
                     WHERE t.cattle_id = c.id AND t.farm_id = c.farm_id AND $WZ_DAYS_EXPR > 0) AS wz_ende
            FROM cattle c
            WHERE $whereSql
            ORDER BY c.ear_tag LIMIT :lim OFFSET :off";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $today = new DateTime('today');
    $rows = array_map(static function (array $r) use ($today): array {
        $wzEnde = $r['wz_ende'] ?: null;
        $wzAktiv = false;
        if ($wzEnde !== null) {
            try { $wzAktiv = new DateTime($wzEnde) >= $today; } catch (Exception $e) { $wzAktiv = false; }
        }
        return [
            'id'            => (int) $r['id'],
            'ear_tag'       => $r['ear_tag'],
            'birth_date'    => $r['birth_date'],
            'rasse'         => $r['rasse'],
            'geschlecht'    => $r['geschlecht'],
            'mutter'        => $r['mutter'],
            'status'        => $r['status'],
            'current_stall' => $r['current_stall'] ?: null,
            'wz_ende'       => $wzEnde,
            'wz_aktiv'      => $wzAktiv,
        ];
    }, $stmt->fetchAll());

    jsonSuccess($rows, [
        'page' => $page, 'per_page' => $perPage, 'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ]);
}

// POST -> anlegen
apiRequirePermission($farm, 'cattle.write');
apiRequireWrite($farm);

$input = jsonInput();
$earTag = normalizeEarTag((string) ($input['ear_tag'] ?? ''));
if ($earTag === '') {
    jsonError('VALIDATION', 'Ohrmarke (ear_tag) ist erforderlich.', 422);
}

$chk = $db->prepare('SELECT COUNT(*) FROM cattle WHERE farm_id = :f AND ear_tag = :e');
$chk->execute([':f' => $farmId, ':e' => $earTag]);
if ((int) $chk->fetchColumn() > 0) {
    jsonError('DUPLICATE', 'Ohrmarke existiert bereits in diesem Betrieb.', 409);
}

$rasse = (string) ($input['rasse'] ?? '');
if ($rasse !== '' && !in_array($rasse, cattleRaces(), true)) { $rasse = ''; }
$geschlecht = strtoupper((string) ($input['geschlecht'] ?? ''));
if (!in_array($geschlecht, ['M', 'W'], true)) { $geschlecht = ''; }

$ins = $db->prepare(
    'INSERT INTO cattle (farm_id, ear_tag, birth_date, rasse, geschlecht, mutter, status)
     VALUES (:f, :e, :b, :r, :g, :m, :s)'
);
$ins->execute([
    ':f' => $farmId, ':e' => $earTag,
    ':b' => !empty($input['birth_date']) ? $input['birth_date'] : null,
    ':r' => $rasse !== '' ? $rasse : null,
    ':g' => $geschlecht !== '' ? $geschlecht : null,
    ':m' => !empty($input['mutter']) ? normalizeEarTag((string) $input['mutter']) : null,
    ':s' => (($input['status'] ?? 'Aktiv') === 'Inaktiv') ? 'Inaktiv' : 'Aktiv',
]);
$newId = (int) $db->lastInsertId();
$row = $db->prepare('SELECT * FROM cattle WHERE id = :id');
$row->execute([':id' => $newId]);

jsonSuccess($row->fetch(), null, 201);
