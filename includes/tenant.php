<?php
/**
 * tenant.php
 * -----------------------------------------------------------------------------
 * Mandantenlogik (Multi-Tenancy) und rollenbasiertes Berechtigungssystem.
 *
 * Zentrales Prinzip: JEDE fachliche Query wird auf den aktuellen Betrieb
 * (farm_id) beschränkt. Diese Datei stellt die Funktionen bereit, die das
 * konsistent und an EINER Stelle erzwingen.
 *
 * Wird über bootstrap.php eingebunden (nach config/functions/auth).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Rollen-Defaults (Quelle der Wahrheit für Berechtigungen)
// ---------------------------------------------------------------------------

/**
 * Liefert die Standard-Berechtigungen je Rolle.
 */
function roleDefaults(): array
{
    return [
        'owner' => [
            'cattle.read', 'cattle.write', 'treatments.read', 'treatments.write',
            'medications.manage', 'inseminations.write', 'pregnancy.write',
            'stall.manage', 'users.manage', 'export.access', 'import.access',
        ],
        'manager' => [
            'cattle.read', 'cattle.write', 'treatments.read', 'treatments.write',
            'medications.manage', 'inseminations.write', 'pregnancy.write',
            'stall.manage', 'export.access', 'import.access',
        ],
        'worker' => [
            'cattle.read', 'cattle.write', 'treatments.read', 'treatments.write',
            'inseminations.write', 'pregnancy.write', 'export.access',
        ],
        'viewer' => [
            'cattle.read', 'treatments.read', 'export.access',
        ],
    ];
}

/**
 * Alle bekannten Permission-Strings (für die UI / Validierung).
 */
function allPermissions(): array
{
    return [
        'cattle.read', 'cattle.write',
        'treatments.read', 'treatments.write',
        'medications.manage',
        'inseminations.write', 'pregnancy.write',
        'stall.manage', 'users.manage',
        'export.access', 'import.access',
    ];
}

/**
 * Berechnet die effektiven Berechtigungen aus Rolle + individuellen Overrides.
 *
 * @param string $role         Rolle aus farm_users
 * @param array  $overrides    [ ['permission' => 'cattle.write', 'granted' => 1], ... ]
 */
function computePermissions(string $role, array $overrides = []): array
{
    $defaults = roleDefaults();
    $perms = $defaults[$role] ?? [];
    $perms = array_fill_keys($perms, true);

    foreach ($overrides as $o) {
        $perm = $o['permission'] ?? null;
        if ($perm === null) {
            continue;
        }
        if ((int) ($o['granted'] ?? 0) === 1) {
            $perms[$perm] = true;
        } else {
            unset($perms[$perm]);
        }
    }
    return array_keys($perms);
}

// ---------------------------------------------------------------------------
// 2. Betriebe eines Users
// ---------------------------------------------------------------------------

/**
 * Liefert alle Betriebe, denen ein User zugeordnet ist (inkl. Rolle).
 */
function getUserFarms(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT f.id, f.name, f.is_demo, f.is_active, fu.role
         FROM farm_users fu
         JOIN farms f ON f.id = fu.farm_id
         WHERE fu.user_id = :uid AND f.is_active = 1
         ORDER BY f.name'
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Setzt den aktiven Betrieb in der Session, nachdem die Berechtigung geprüft wurde.
 * Berechnet und cached Rolle + Permissions. Gibt false zurück, wenn der User
 * keinen Zugriff auf den Betrieb hat (System-Admin darf jeden Betrieb wählen).
 */
function setCurrentFarm(PDO $db, int $userId, int $farmId): bool
{
    // System-Admin darf jeden aktiven Betrieb betreten
    if (isAdmin()) {
        $stmt = $db->prepare('SELECT id, name, is_demo FROM farms WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $farmId]);
        $farm = $stmt->fetch();
        if (!$farm) {
            return false;
        }
        $_SESSION['current_farm_id']   = (int) $farm['id'];
        $_SESSION['current_farm_name'] = $farm['name'];
        $_SESSION['farm_role']         = 'owner'; // Admin hat vollen Zugriff
        $_SESSION['farm_permissions']  = roleDefaults()['owner'];
        $_SESSION['is_demo_farm']      = (int) $farm['is_demo'] === 1;
        return true;
    }

    // Regulärer User: Zugehörigkeit prüfen
    $stmt = $db->prepare(
        'SELECT fu.id AS farm_user_id, fu.role, f.name, f.is_demo
         FROM farm_users fu
         JOIN farms f ON f.id = fu.farm_id
         WHERE fu.user_id = :uid AND fu.farm_id = :fid AND f.is_active = 1'
    );
    $stmt->execute([':uid' => $userId, ':fid' => $farmId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    // Individuelle Overrides laden
    $ovStmt = $db->prepare('SELECT permission, granted FROM permissions WHERE farm_user_id = :fuid');
    $ovStmt->execute([':fuid' => (int) $row['farm_user_id']]);
    $overrides = $ovStmt->fetchAll();

    $_SESSION['current_farm_id']   = $farmId;
    $_SESSION['current_farm_name'] = $row['name'];
    $_SESSION['farm_role']         = $row['role'];
    $_SESSION['farm_permissions']  = computePermissions($row['role'], $overrides);
    $_SESSION['is_demo_farm']      = (int) $row['is_demo'] === 1;
    return true;
}

/**
 * Gibt die aktuelle farm_id zurück. Leitet zu farm_select.php um, wenn keiner gesetzt.
 */
function getCurrentFarmId(): int
{
    if (empty($_SESSION['current_farm_id'])) {
        redirect(url('farm_select.php'));
    }
    return (int) $_SESSION['current_farm_id'];
}

/**
 * Stellt sicher, dass ein gültiger Betriebs-Kontext gesetzt ist.
 */
function requireFarmAccess(): void
{
    requireLogin();
    if (empty($_SESSION['current_farm_id'])) {
        redirect(url('farm_select.php'));
    }
}

// ---------------------------------------------------------------------------
// 3. Berechtigungs-Prüfungen
// ---------------------------------------------------------------------------

function currentRole(): ?string
{
    return $_SESSION['farm_role'] ?? null;
}

/**
 * Prüft, ob der aktuelle User im aktuellen Betrieb eine Berechtigung hat.
 */
function hasPermission(string $permission): bool
{
    $perms = $_SESSION['farm_permissions'] ?? [];
    return in_array($permission, $perms, true);
}

/**
 * Erzwingt eine Berechtigung; bricht sonst mit 403 ab.
 */
function requirePermission(string $permission): void
{
    requireFarmAccess();
    if (!hasPermission($permission)) {
        http_response_code(403);
        flash('error', 'Keine Berechtigung für diese Aktion (' . $permission . ').', 'danger');
        redirect(url('index.php'));
    }
}

// ---------------------------------------------------------------------------
// 4. Demo-Betrieb (Read-Only)
// ---------------------------------------------------------------------------

function isDemoBetrieb(): bool
{
    return !empty($_SESSION['is_demo_farm']);
}

/**
 * Blockiert Schreiboperationen im Demo-Betrieb.
 */
function requireWriteAccess(): void
{
    if (isDemoBetrieb()) {
        http_response_code(403);
        flash('error', 'Im Demo-Betrieb sind keine Änderungen möglich.', 'warning');
        redirect(url('index.php'));
    }
}

// ---------------------------------------------------------------------------
// 5. Ställe pro Betrieb (ersetzt hardcodierte Liste)
// ---------------------------------------------------------------------------

/**
 * Liefert die aktiven Ställe eines Betriebs aus der DB.
 */
function getStallList(PDO $db, int $farmId): array
{
    $stmt = $db->prepare(
        'SELECT name FROM stalls WHERE farm_id = :fid AND is_active = 1 ORDER BY name'
    );
    $stmt->execute([':fid' => $farmId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
