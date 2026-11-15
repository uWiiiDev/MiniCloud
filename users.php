<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Strikter Schutz: Nur Administratoren oder Teil-Administratoren mit Berechtigung 'users'
requireAdmin('users');

$pdo = getDB();
ensureUserSecurityColumns($pdo);
$currentAdminId = (int)$_SESSION['user_id'];
$availableAdminPerms = getAvailableAdminPermissions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: users.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // --- 1. Neuen Benutzer anlegen ---
    if ($action === 'create_user') {
        $username         = trim((string)($_POST['username'] ?? ''));
        $email            = trim((string)($_POST['email'] ?? ''));
        $password         = (string)($_POST['password'] ?? '');
        $quotaMb          = max(1, (int)($_POST['quota_mb'] ?? 100));
        $makeAdmin        = isset($_POST['is_admin']) && $_POST['is_admin'] === '1' ? 1 : 0;
        $rawCreatePerms   = isset($_POST['admin_permissions']) && is_array($_POST['admin_permissions']) ? $_POST['admin_permissions'] : [];
        $createPermsStr   = implode(',', parseAdminPermissionsString(implode(',', array_map('strval', $rawCreatePerms))));
        if (isset($_POST['is_admin']) && $_POST['is_admin'] === 'partial') {
            $makeAdmin = 0;
        } elseif ($makeAdmin === 1) {
            $createPermsStr = '';
        }
        $twoFactor        = isset($_POST['two_factor_enabled']) ? max(0, min(2, (int)$_POST['two_factor_enabled'])) : 0;
        $allowUserSharing = isset($_POST['allow_user_sharing']) && $_POST['allow_user_sharing'] === '1' ? 1 : 0;

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            setFlash('error', 'Benutzername muss 3–32 Zeichen lang sein (Buchstaben, Zahlen, . _ -).');
        } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            setFlash('error', 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
        } elseif ($twoFactor === 1 && $email === '') {
            setFlash('error', 'Für die 2FA per E-Mail muss eine gültige E-Mail-Adresse hinterlegt werden.');
        } elseif (strlen($password) < 8) {
            setFlash('error', 'Das Passwort muss mindestens 8 Zeichen lang sein.');
        } else {
            $stmtCheck = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
            $stmtCheck->execute([':u' => $username]);
            if ($stmtCheck->fetch()) {
                setFlash('error', sprintf('Der Benutzername "%s" existiert bereits.', $username));
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmtIns = $pdo->prepare(
                    'INSERT INTO users (username, email, password_hash, two_factor_enabled, is_admin, admin_permissions, theme, allow_user_sharing, storage_quota)
                     VALUES (:u, :em, :h, :tfa, :adm, :perms, :theme, :aus, :q)'
                );
                $stmtIns->execute([
                    ':u'     => $username,
                    ':em'    => $email !== '' ? $email : null,
                    ':h'     => $hash,
                    ':tfa'   => $twoFactor,
                    ':adm'   => isFullAdmin() ? $makeAdmin : 0,
                    ':perms' => (isFullAdmin() && $makeAdmin === 0) ? $createPermsStr : '',
                    ':theme' => 'light',
                    ':aus'   => $allowUserSharing,
                    ':q'     => $quotaMb * 1024 * 1024,
                ]);
                $newUserId = (int)$pdo->lastInsertId();
                if ($newUserId > 0 && !empty($_POST['group_ids']) && is_array($_POST['group_ids'])) {
                    $stmtGrpIns = $pdo->prepare('INSERT IGNORE INTO group_members (group_id, user_id) VALUES (:gid, :uid)');
                    foreach ($_POST['group_ids'] as $rawGid) {
                        $gid = (int)$rawGid;
                        if ($gid > 0) {
                            $stmtGrpIns->execute([':gid' => $gid, ':uid' => $newUserId]);
                        }
                    }
                }
                logAuditEvent($pdo, 'user_create', sprintf('Neues Benutzerkonto „%s“ (%d MB%s) angelegt', $username, $quotaMb, $makeAdmin ? ', Admin' : ''));
                setFlash('success', sprintf('Benutzer "%s" (%d MB) wurde erfolgreich angelegt.', $username, $quotaMb));
            }
        }
        header('Location: users.php#tab-accounts');
        exit;
    }

    // --- 2. Benutzer löschen ---
    if ($action === 'delete_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId === $currentAdminId) {
            setFlash('error', 'Sie können Ihr eigenes Administratorkonto nicht löschen.');
        } elseif ($targetUserId > 0) {
            $stmtTargetUser = $pdo->prepare('SELECT username, is_admin, admin_permissions FROM users WHERE id = :id LIMIT 1');
            $stmtTargetUser->execute([':id' => $targetUserId]);
            $targetRow = $stmtTargetUser->fetch();

            if ($targetRow) {
                $targetIsPrivileged = ((int)$targetRow['is_admin'] === 1) || (trim((string)($targetRow['admin_permissions'] ?? '')) !== '');
                if ($targetIsPrivileged && !isFullAdmin()) {
                    setFlash('error', 'Nur Voll-Administratoren können Konten von Administratoren oder Teil-Administratoren löschen.');
                    header('Location: users.php#tab-accounts');
                    exit;
                }

                $deletedUsername = (string)$targetRow['username'];

                // Physische Dateien (und Versionsstände) des Benutzers vor dem Löschen entfernen
                $stmtFiles = $pdo->prepare('SELECT id, filename, stored_name FROM files WHERE user_id = :uid');
                $stmtFiles->execute([':uid' => $targetUserId]);
                foreach ($stmtFiles->fetchAll() as $f) {
                    deletePhysicalFileVersions($pdo, (int)$f['id']);
                    $sn = !empty($f['stored_name']) ? (string)$f['stored_name'] : (string)($f['filename'] ?? '');
                    if ($sn !== '') {
                        $p = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename($sn);
                        if (is_file($p)) {
                            @unlink($p);
                        }
                    }
                }
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $targetUserId]);
                logAuditEvent($pdo, 'user_delete', sprintf('Benutzerkonto „%s“ inklusive Dateien gelöscht', $deletedUsername));
                setFlash('success', 'Benutzerkonto und zugehörige Dateien wurden gelöscht.');
            }
        }
        header('Location: users.php#tab-accounts');
        exit;
    }

    // --- 2b. Benutzerkonto sperren / entsperren ---
    if ($action === 'toggle_lock_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $lockReason   = mb_substr(trim((string)($_POST['lock_reason'] ?? '')), 0, 250);

        if ($targetUserId === $currentAdminId) {
            setFlash('error', 'Sie können Ihr eigenes Konto nicht sperren.');
        } elseif ($targetUserId > 0) {
            $stmtTarget = $pdo->prepare('SELECT username, is_admin, admin_permissions, is_locked FROM users WHERE id = :id LIMIT 1');
            $stmtTarget->execute([':id' => $targetUserId]);
            $targetRow = $stmtTarget->fetch();

            if ($targetRow) {
                $targetIsPrivileged = ((int)$targetRow['is_admin'] === 1) || (trim((string)($targetRow['admin_permissions'] ?? '')) !== '');
                if ($targetIsPrivileged && !isFullAdmin()) {
                    setFlash('error', 'Nur Voll-Administratoren können andere Administratoren oder Teil-Administratoren sperren.');
                } else {
                    $newLockState = ((int)$targetRow['is_locked'] === 1) ? 0 : 1;
                    $pdo->prepare('UPDATE users SET is_locked = :l, lock_reason = :r WHERE id = :id')
                        ->execute([
                            ':l'  => $newLockState,
                            ':r'  => $newLockState === 1 ? $lockReason : '',
                            ':id' => $targetUserId,
                        ]);

                    logAuditEvent(
                        $pdo,
                        $newLockState === 1 ? 'user_lock' : 'user_unlock',
                        sprintf(
                            'Benutzerkonto „%s“ %s%s',
                            (string)$targetRow['username'],
                            $newLockState === 1 ? 'gesperrt' : 'entsperrt',
                            ($newLockState === 1 && $lockReason !== '') ? (' (Grund: ' . $lockReason . ')') : ''
                        )
                    );
                    setFlash(
                        'success',
                        sprintf(
                            'Benutzer „%s“ wurde %s.',
                            (string)$targetRow['username'],
                            $newLockState === 1 ? 'gesperrt (aktive Sitzungen werden sofort beendet)' : 'wieder entsperrt'
                        )
                    );
                }
            }
        }
        header('Location: users.php#tab-accounts');
        exit;
    }

    // --- 3. Benutzer Passwort / Quota / E-Mail / 2FA / Teilen-Sichtbarkeit / Teil-Admin-Rechte aktualisieren ---
    if ($action === 'update_user') {
        $targetUserId     = (int)($_POST['user_id'] ?? 0);
        $email            = trim((string)($_POST['email'] ?? ''));
        $quotaMb          = max(1, (int)($_POST['quota_mb'] ?? 100));
        $newPassword      = (string)($_POST['new_password'] ?? '');
        $makeAdmin        = isset($_POST['is_admin']) && $_POST['is_admin'] === '1' ? 1 : 0;
        $twoFactor        = isset($_POST['two_factor_enabled']) ? max(0, min(2, (int)$_POST['two_factor_enabled'])) : 0;
        $allowUserSharing = isset($_POST['allow_user_sharing']) && $_POST['allow_user_sharing'] === '1' ? 1 : 0;
        $rawPerms = [];
        if (isset($_POST['admin_permissions']) && is_array($_POST['admin_permissions'])) {
            $rawPerms = $_POST['admin_permissions'];
        } elseif (isset($_POST['admin_perms']) && is_array($_POST['admin_perms'])) {
            $rawPerms = $_POST['admin_perms'];
        }
        $cleanPermsStr = implode(',', parseAdminPermissionsString(implode(',', array_map('strval', $rawPerms))));
        if ($cleanPermsStr !== '' && $targetUserId !== $currentAdminId) {
            $makeAdmin = 0;
        }

        $stmtExisting = $pdo->prepare('SELECT is_admin, admin_permissions FROM users WHERE id = :id LIMIT 1');
        $stmtExisting->execute([':id' => $targetUserId]);
        $existingUser = $stmtExisting->fetch();

        if (!isFullAdmin() && $existingUser) {
            $existingIsPrivileged = ((int)$existingUser['is_admin'] === 1) || (trim((string)($existingUser['admin_permissions'] ?? '')) !== '');
            if ($existingIsPrivileged && $targetUserId !== $currentAdminId) {
                setFlash('error', 'Nur Voll-Administratoren dürfen Konten von Administratoren oder Teil-Administratoren bearbeiten.');
                header('Location: users.php#tab-accounts');
                exit;
            }
            $makeAdmin     = (int)$existingUser['is_admin'];
            $cleanPermsStr = (string)($existingUser['admin_permissions'] ?? '');
        }

        if ($targetUserId === $currentAdminId) {
            $makeAdmin = isFullAdmin() ? 1 : (int)($existingUser['is_admin'] ?? 0);
        }

        if ($targetUserId > 0 && $existingUser) {
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                setFlash('error', 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
                header('Location: users.php#tab-accounts');
                exit;
            }
            if ($twoFactor === 1 && $email === '') {
                setFlash('error', 'Um die 2FA per E-Mail zu aktivieren, muss eine gültige E-Mail-Adresse angegeben werden.');
                header('Location: users.php#tab-accounts');
                exit;
            }

            if ($newPassword !== '') {
                if (strlen($newPassword) < 8) {
                    setFlash('error', 'Neues Passwort muss mindestens 8 Zeichen lang sein.');
                    header('Location: users.php#tab-accounts');
                    exit;
                }
                $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmtUpd = $pdo->prepare(
                    'UPDATE users
                     SET email = :em,
                         two_factor_enabled = :tfa,
                         allow_user_sharing = :aus,
                         password_hash = :h,
                         storage_quota = :q,
                         is_admin = :adm,
                         admin_permissions = :perms
                     WHERE id = :id'
                );
                $stmtUpd->execute([
                    ':em'    => $email !== '' ? $email : null,
                    ':tfa'   => $twoFactor,
                    ':aus'   => $allowUserSharing,
                    ':h'     => $hash,
                    ':q'     => $quotaMb * 1024 * 1024,
                    ':adm'   => $makeAdmin,
                    ':perms' => $makeAdmin === 1 ? '' : $cleanPermsStr,
                    ':id'    => $targetUserId,
                ]);
            } else {
                $stmtUpd = $pdo->prepare(
                    'UPDATE users
                     SET email = :em,
                         two_factor_enabled = :tfa,
                         allow_user_sharing = :aus,
                         storage_quota = :q,
                         is_admin = :adm,
                         admin_permissions = :perms
                     WHERE id = :id'
                );
                $stmtUpd->execute([
                    ':em'    => $email !== '' ? $email : null,
                    ':tfa'   => $twoFactor,
                    ':aus'   => $allowUserSharing,
                    ':q'     => $quotaMb * 1024 * 1024,
                    ':adm'   => $makeAdmin,
                    ':perms' => $makeAdmin === 1 ? '' : $cleanPermsStr,
                    ':id'    => $targetUserId,
                ]);
            }

            if (isset($_POST['sync_groups']) && $_POST['sync_groups'] === '1') {
                $pdo->prepare('DELETE FROM group_members WHERE user_id = :uid')->execute([':uid' => $targetUserId]);
                if (!empty($_POST['group_ids']) && is_array($_POST['group_ids'])) {
                    $stmtGrpIns = $pdo->prepare('INSERT IGNORE INTO group_members (group_id, user_id) VALUES (:gid, :uid)');
                    foreach ($_POST['group_ids'] as $rawGid) {
                        $gid = (int)$rawGid;
                        if ($gid > 0) {
                            $stmtGrpIns->execute([':gid' => $gid, ':uid' => $targetUserId]);
                        }
                    }
                }
            }
            setFlash('success', 'Benutzer-Einstellungen und Berechtigungen wurden aktualisiert.');
        }
        header('Location: users.php#tab-accounts');
        exit;
    }

    // --- 4. Neue Gruppe anlegen ---
    if ($action === 'create_group') {
        $groupName = trim((string)($_POST['group_name'] ?? ''));
        $groupDesc = trim((string)($_POST['group_description'] ?? ''));

        if ($groupName === '') {
            setFlash('error', 'Bitte geben Sie einen Gruppennamen ein.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO `groups` (name, description) VALUES (:n, :d)');
                $stmt->execute([':n' => mb_substr($groupName, 0, 100), ':d' => mb_substr($groupDesc, 0, 255)]);
                setFlash('success', sprintf('Gruppe "%s" wurde angelegt.', $groupName));
            } catch (PDOException) {
                setFlash('error', 'Eine Gruppe mit diesem Namen existiert bereits.');
            }
        }
        header('Location: users.php#tab-groups');
        exit;
    }

    // --- 5. Gruppe löschen ---
    if ($action === 'delete_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if ($groupId > 0) {
            $pdo->prepare('DELETE FROM `groups` WHERE id = :id')->execute([':id' => $groupId]);
            setFlash('success', 'Gruppe wurde gelöscht.');
        }
        header('Location: users.php#tab-groups');
        exit;
    }

    // --- 6. Benutzer zu Gruppe hinzufügen / entfernen ---
    if ($action === 'add_group_member') {
        $groupId  = (int)($_POST['group_id'] ?? 0);
        $memberId = (int)($_POST['user_id'] ?? 0);
        if ($groupId > 0 && $memberId > 0) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO group_members (group_id, user_id) VALUES (:gid, :uid)');
            $stmt->execute([':gid' => $groupId, ':uid' => $memberId]);
            setFlash('success', 'Benutzer wurde zur Gruppe hinzugefügt.');
        }
        header('Location: users.php#tab-groups');
        exit;
    }

    if ($action === 'remove_group_member') {
        $gmId = (int)($_POST['gm_id'] ?? 0);
        if ($gmId > 0) {
            $pdo->prepare('DELETE FROM group_members WHERE id = :id')->execute([':id' => $gmId]);
            setFlash('success', 'Benutzer wurde aus der Gruppe entfernt.');
        }
        header('Location: users.php#tab-groups');
        exit;
    }

    // --- 7. Gruppenordner anlegen ---
    if ($action === 'create_group_folder') {
        $folderName     = trim((string)($_POST['folder_name'] ?? ''));
        $folderDesc     = trim((string)($_POST['folder_description'] ?? ''));
        $initialGroupId = (int)($_POST['initial_group_id'] ?? 0);

        if ($folderName === '') {
            setFlash('error', 'Bitte geben Sie einen Namen für den Gruppenordner ein.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO group_folders (name, description, created_by) VALUES (:n, :d, :c)');
                $stmt->execute([
                    ':n' => mb_substr($folderName, 0, 120),
                    ':d' => mb_substr($folderDesc, 0, 255),
                    ':c' => $currentAdminId,
                ]);
                $newGfId = (int)$pdo->lastInsertId();
                if ($newGfId > 0 && $initialGroupId > 0) {
                    $stmtPerm = $pdo->prepare(
                        'INSERT INTO group_folder_permissions (group_folder_id, group_id, can_write) VALUES (:gf, :gid, 1)'
                    );
                    $stmtPerm->execute([':gf' => $newGfId, ':gid' => $initialGroupId]);
                }
                setFlash('success', sprintf('Gruppenordner "%s" wurde erfolgreich erstellt.', $folderName));
            } catch (PDOException) {
                setFlash('error', 'Ein Gruppenordner mit diesem Namen existiert bereits.');
            }
        }
        header('Location: users.php#tab-groups');
        exit;
    }

    // --- 8. Gruppenordner löschen ---
    if ($action === 'delete_group_folder') {
        $gfId = (int)($_POST['group_folder_id'] ?? 0);
        if ($gfId > 0) {
            $stmtFiles = $pdo->prepare('SELECT id, filename, stored_name FROM files WHERE group_folder_id = :gfid');
            $stmtFiles->execute([':gfid' => $gfId]);
            foreach ($stmtFiles->fetchAll() as $f) {
                deletePhysicalFileVersions($pdo, (int)$f['id']);
                $sn = !empty($f['stored_name']) ? (string)$f['stored_name'] : (string)($f['filename'] ?? '');
                if ($sn !== '') {
                    $p = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename($sn);
                    if (is_file($p)) {
                        @unlink($p);
                    }
                }
            }
            $pdo->prepare('DELETE FROM group_folders WHERE id = :id')->execute([':id' => $gfId]);
            setFlash('success', 'Gruppenordner und darin enthaltene Dateien wurden gelöscht.');
        }
        header('Location: users.php#tab-groups');
        exit;
    }

    // --- 9. Berechtigung für Gruppenordner vergeben (Benutzer oder Gruppe) ---
    if ($action === 'grant_group_folder_permission') {
        $gfId       = (int)($_POST['group_folder_id'] ?? 0);
        $targetType = (string)($_POST['target_type'] ?? 'user');
        $targetId   = (int)($_POST['target_id'] ?? 0);
        $canWrite   = isset($_POST['can_write']) && $_POST['can_write'] === '1' ? 1 : 0;

        if ($gfId > 0 && $targetId > 0) {
            if ($targetType === 'group') {
                $pdo->prepare('DELETE FROM group_folder_permissions WHERE group_folder_id = :gf AND group_id = :gid')
                    ->execute([':gf' => $gfId, ':gid' => $targetId]);
                $stmt = $pdo->prepare(
                    'INSERT INTO group_folder_permissions (group_folder_id, group_id, can_write) VALUES (:gf, :gid, :cw)'
                );
                $stmt->execute([':gf' => $gfId, ':gid' => $targetId, ':cw' => $canWrite]);
                setFlash('success', 'Gruppenberechtigung für den Gruppenordner wurde gespeichert.');
            } else {
                $pdo->prepare('DELETE FROM group_folder_permissions WHERE group_folder_id = :gf AND user_id = :uid')
                    ->execute([':gf' => $gfId, ':uid' => $targetId]);
                $stmt = $pdo->prepare(
                    'INSERT INTO group_folder_permissions (group_folder_id, user_id, can_write) VALUES (:gf, :uid, :cw)'
                );
                $stmt->execute([':gf' => $gfId, ':uid' => $targetId, ':cw' => $canWrite]);
                setFlash('success', 'Benutzerberechtigung für den Gruppenordner wurde gespeichert.');
            }
        }
        header('Location: users.php#tab-groups');
        exit;
    }

    // --- 10. Berechtigung für Gruppenordner entziehen ---
    if ($action === 'revoke_group_folder_permission') {
        $permId = (int)($_POST['permission_id'] ?? 0);
        if ($permId > 0) {
            $pdo->prepare('DELETE FROM group_folder_permissions WHERE id = :id')->execute([':id' => $permId]);
            setFlash('success', 'Berechtigung wurde entzogen.');
        }
        header('Location: users.php#tab-groups');
        exit;
    }

    // --- 11. Öffentliche Selbstregistrierung & optionale PayPal-Bezahlschranke aktualisieren ---
    if ($action === 'update_registration_settings') {
        $allowRegistration = isset($_POST['allow_registration']) && $_POST['allow_registration'] === '1' ? '1' : '0';
        $registrationCode  = mb_substr(trim((string)($_POST['registration_code'] ?? '')), 0, 100);
        $regQuotaMb        = max(1, min(102400, (int)($_POST['registration_default_quota_mb'] ?? 100)));

        $paywallEnabled    = isset($_POST['registration_paywall_enabled']) && $_POST['registration_paywall_enabled'] === '1' ? '1' : '0';
        $paypalMode        = strtolower(trim((string)($_POST['paypal_mode'] ?? 'sandbox'))) === 'live' ? 'live' : 'sandbox';
        $paypalClientId    = mb_substr(trim((string)($_POST['paypal_client_id'] ?? '')), 0, 255);
        $paypalSecretInput = trim((string)($_POST['paypal_client_secret'] ?? ''));
        $clearPaypalSecret = isset($_POST['clear_paypal_secret']) && $_POST['clear_paypal_secret'] === '1';
        $paypalPlanId      = mb_substr(trim((string)($_POST['paypal_plan_id'] ?? '')), 0, 128);
        $paypalPriceRaw    = str_replace(',', '.', trim((string)($_POST['paypal_price_eur'] ?? '1.00')));
        $paypalPriceVal    = max(0.50, min(9999.00, round((float)$paypalPriceRaw, 2)));
        $paypalPriceStr    = number_format($paypalPriceVal, 2, '.', '');
        $paypalInterval    = strtoupper(trim((string)($_POST['paypal_billing_interval'] ?? 'MONTH'))) === 'YEAR' ? 'YEAR' : 'MONTH';
        $paypalPaidQuotaMb = max(100, min(1024000, (int)($_POST['paypal_paid_quota_mb'] ?? 1024)));
        $allowInviteBypass = isset($_POST['paypal_allow_invite_bypass']) && $_POST['paypal_allow_invite_bypass'] === '1' ? '1' : '0';

        saveAppSetting($pdo, 'allow_registration', $allowRegistration);
        saveAppSetting($pdo, 'registration_code', $registrationCode);
        saveAppSetting($pdo, 'registration_default_quota_mb', (string)$regQuotaMb);
        saveAppSetting($pdo, 'registration_paywall_enabled', $paywallEnabled);
        saveAppSetting($pdo, 'paypal_mode', $paypalMode);
        saveAppSetting($pdo, 'paypal_client_id', $paypalClientId);
        if ($clearPaypalSecret) {
            saveAppSetting($pdo, 'paypal_client_secret', '');
        } elseif ($paypalSecretInput !== '') {
            saveAppSetting($pdo, 'paypal_client_secret', $paypalSecretInput);
        }
        saveAppSetting($pdo, 'paypal_plan_id', $paypalPlanId);
        saveAppSetting($pdo, 'paypal_price_eur', $paypalPriceStr);
        saveAppSetting($pdo, 'paypal_billing_interval', $paypalInterval);
        saveAppSetting($pdo, 'paypal_paid_quota_mb', (string)$paypalPaidQuotaMb);
        saveAppSetting($pdo, 'paypal_allow_invite_bypass', $allowInviteBypass);

        if (isset($_POST['auto_create_paypal_plan']) && $_POST['auto_create_paypal_plan'] === '1') {
            require_once __DIR__ . '/includes/paypal_helper.php';
            $freshSettings = getAppSettings($pdo, true);
            $planErr = null;
            $newPlanId = createOrSyncPayPalBillingPlan($pdo, $freshSettings, $planErr);
            if ($newPlanId !== null) {
                setFlash('success', sprintf('Einstellungen gespeichert und PayPal-Abo-Plan (%s) erfolgreich automatisch bei PayPal angelegt!', $newPlanId));
            } else {
                setFlash('error', 'Einstellungen gespeichert, aber der PayPal-Abo-Plan konnte nicht automatisch angelegt werden: ' . ($planErr ?: 'Unbekannter Fehler.'));
            }
            header('Location: users.php#tab-registration');
            exit;
        }

        setFlash('success', 'Einstellungen für die öffentliche Selbstregistrierung und Bezahlschranke wurden gespeichert.');
        header('Location: users.php#tab-registration');
        exit;
    }
}

$currentSettings = getAppSettings($pdo);

// Alle Benutzer samt Dateianzahl und Speicherbelegung laden
$allUsers = $pdo->query(
    'SELECT u.id, u.username, u.email, u.two_factor_enabled, u.totp_secret, u.allow_user_sharing,
            u.is_admin, u.is_locked, u.lock_reason, u.admin_permissions, u.theme, u.storage_quota, u.created_at,
            COALESCE(SUM(f.file_size), 0) AS used_bytes, COUNT(f.id) AS file_count
     FROM users u
     LEFT JOIN files f ON f.user_id = u.id
     GROUP BY u.id
     ORDER BY u.id ASC'
)->fetchAll();

// Gruppen & Mitgliedschaften laden
$allGroups = $pdo->query('SELECT * FROM `groups` ORDER BY name ASC')->fetchAll();

$groupMembersRaw = $pdo->query(
    'SELECT gm.id AS gm_id, gm.group_id, gm.user_id, u.username
     FROM group_members gm
     INNER JOIN users u ON u.id = gm.user_id
     ORDER BY u.username ASC'
)->fetchAll();
$membersByGroup = [];
$groupsByUser   = [];
$groupIdsByUser = [];
$groupNamesMap  = [];
foreach ($allGroups as $gRow) {
    $groupNamesMap[(int)$gRow['id']] = (string)$gRow['name'];
}
foreach ($groupMembersRaw as $gm) {
    $gid = (int)$gm['group_id'];
    $uid = (int)$gm['user_id'];
    $membersByGroup[$gid][] = $gm;
    if (isset($groupNamesMap[$gid])) {
        $groupsByUser[$uid][]   = $groupNamesMap[$gid];
        $groupIdsByUser[$uid][] = $gid;
    }
}

// Gruppenordner & Berechtigungen laden
$allGroupFolders = $pdo->query(
    'SELECT gf.*,
            (SELECT COUNT(*) FROM files f WHERE f.group_folder_id = gf.id) AS file_count,
            (SELECT COALESCE(SUM(f.file_size), 0) FROM files f WHERE f.group_folder_id = gf.id) AS folder_size
     FROM group_folders gf
     ORDER BY gf.name ASC'
)->fetchAll();

$permsRaw = $pdo->query(
    'SELECT gfp.*, u.username, g.name AS group_name
     FROM group_folder_permissions gfp
     LEFT JOIN users u ON u.id = gfp.user_id
     LEFT JOIN `groups` g ON g.id = gfp.group_id
     ORDER BY gfp.id ASC'
)->fetchAll();
$permsByFolder = [];
foreach ($permsRaw as $p) {
    $permsByFolder[(int)$p['group_folder_id']][] = $p;
}

// Kennzahlen berechnen
$totalUsersCount    = count($allUsers);
$activeUsersCount   = 0;
$lockedUsersCount   = 0;
$fullAdminsCount    = 0;
$partialAdminsCount = 0;
$twoFaUsersCount    = 0;
$shareVisUsersCount = 0;
$totalUsedBytes     = 0;
$totalQuotaBytes    = 0;
$totalUserFiles     = 0;

foreach ($allUsers as $uStat) {
    $isLck = (int)($uStat['is_locked'] ?? 0) === 1;
    if ($isLck) {
        $lockedUsersCount++;
    } else {
        $activeUsersCount++;
    }
    $pList = parseAdminPermissionsString((string)($uStat['admin_permissions'] ?? ''));
    if ((int)$uStat['is_admin'] === 1) {
        $fullAdminsCount++;
    } elseif (!empty($pList)) {
        $partialAdminsCount++;
    }
    if ((int)($uStat['two_factor_enabled'] ?? 0) > 0) {
        $twoFaUsersCount++;
    }
    if ((int)($uStat['allow_user_sharing'] ?? 0) === 1) {
        $shareVisUsersCount++;
    }
    $totalUsedBytes  += (int)$uStat['used_bytes'];
    $totalQuotaBytes += (int)$uStat['storage_quota'];
    $totalUserFiles  += (int)$uStat['file_count'];
}
$totalQuotaPct = $totalQuotaBytes > 0 ? min(100.0, round(($totalUsedBytes / $totalQuotaBytes) * 100, 1)) : 0.0;

$pageTitle = 'Benutzer- & Gruppenverwaltung';
require_once __DIR__ . '/includes/header.php';
?>

<!-- KOPFBEREICH -->
<div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs mb-6 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
    <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shrink-0">
            👥
        </div>
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">Benutzer- &amp; Gruppenverwaltung</h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Benutzerkonten, Speicher-Kontingente, Teil-Admin-Rechte, Benutzergruppen, Gruppenordner &amp; Selbstregistrierung.
            </p>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button
            type="button"
            onclick="toggleCreateUserPanel(true)"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
        >
            <span>➕</span>
            <span>Neuen Benutzer anlegen</span>
        </button>
        <a
            href="admin.php"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 text-xs sm:text-sm font-semibold transition-colors"
        >
            <span>🛡️</span>
            <span>System-Administration</span>
        </a>
        <a
            href="index.php"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors"
        >
            <span>&larr; Dashboard</span>
        </a>
    </div>
</div>

<!-- 4 KOMPAKTE KPI-KACHELN -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs flex items-center justify-between gap-3">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Benutzerkonten</p>
            <p class="text-2xl font-extrabold text-slate-900 dark:text-white mt-0.5"><?= $totalUsersCount ?></p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                <span class="text-emerald-600 dark:text-emerald-400 font-semibold"><?= $activeUsersCount ?> aktiv</span>
                <?php if ($lockedUsersCount > 0): ?>
                    &bull; <span class="text-red-600 dark:text-red-400 font-semibold"><?= $lockedUsersCount ?> gesperrt</span>
                <?php endif; ?>
            </p>
        </div>
        <span class="w-10 h-10 rounded-xl bg-brand-50 dark:bg-brand-950/60 text-brand-600 dark:text-brand-400 flex items-center justify-center text-xl">👥</span>
    </div>

    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs flex items-center justify-between gap-3">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Rollen &amp; Sicherheit</p>
            <p class="text-lg font-extrabold text-slate-900 dark:text-white mt-0.5">
                <?= $fullAdminsCount ?> Admin<?= $fullAdminsCount === 1 ? '' : 's' ?>
                <?php if ($partialAdminsCount > 0): ?>
                    <span class="text-xs font-bold text-indigo-600 dark:text-indigo-400">+ <?= $partialAdminsCount ?> Teil-Admin</span>
                <?php endif; ?>
            </p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                🔒 <?= $twoFaUsersCount ?> mit 2FA &bull; 👁️ <?= $shareVisUsersCount ?> sichtbar
            </p>
        </div>
        <span class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xl">🛡️</span>
    </div>

    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs flex items-center justify-between gap-3">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Gruppen &amp; Team-Ordner</p>
            <p class="text-lg font-extrabold text-slate-900 dark:text-white mt-0.5">
                <?= count($allGroups) ?> Gruppen &bull; <?= count($allGroupFolders) ?> Ordner
            </p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                Gemeinsame Team-Ablagen &amp; Rechte
            </p>
        </div>
        <span class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xl">🏢</span>
    </div>

    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
        <div class="flex items-center justify-between text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            <span>Belegter Speicher / Quota</span>
            <span class="text-slate-900 dark:text-white"><?= number_format($totalQuotaPct, 1, ',', '.') ?>%</span>
        </div>
        <p class="text-sm font-extrabold text-slate-900 dark:text-white mt-1 truncate">
            <?= e(formatBytes($totalUsedBytes)) ?> <span class="text-xs font-normal text-slate-500 dark:text-slate-400">von <?= e(formatBytes($totalQuotaBytes, 0)) ?></span>
        </p>
        <div class="w-full h-2 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden mt-2">
            <div class="h-full bg-brand-600 rounded-full transition-all" style="width: <?= $totalQuotaPct ?>%"></div>
        </div>
        <p class="text-[11px] text-slate-400 mt-1"><?= number_format($totalUserFiles, 0, ',', '.') ?> persönliche Dateien</p>
    </div>
</div>

<!-- SUB-TABS NAVIGATION -->
<nav aria-label="Benutzerverwaltungs-Bereiche" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-2 shadow-xs mb-6 flex flex-wrap items-center gap-1.5">
    <button
        type="button"
        data-users-tab-btn="tab-accounts"
        onclick="switchUsersTab('tab-accounts')"
        class="users-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer"
    >
        <span>👥</span>
        <span>Benutzerkonten</span>
        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200"><?= $totalUsersCount ?></span>
    </button>

    <button
        type="button"
        data-users-tab-btn="tab-groups"
        onclick="switchUsersTab('tab-groups')"
        class="users-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer"
    >
        <span>🏢</span>
        <span>Gruppen &amp; Gruppenordner</span>
        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200"><?= count($allGroups) + count($allGroupFolders) ?></span>
    </button>

    <button
        type="button"
        data-users-tab-btn="tab-registration"
        onclick="switchUsersTab('tab-registration')"
        class="users-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer"
    >
        <span>📝</span>
        <span>Selbstregistrierung &amp; PayPal-Abo</span>
        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold <?= ($currentSettings['allow_registration'] ?? '0') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-slate-200/80 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
            <?= ($currentSettings['allow_registration'] ?? '0') === '1' ? 'An' : 'Aus' ?>
        </span>
    </button>
</nav>

<!-- ======================================================================= -->
<!-- TAB 1: BENUTZERKONTEN (MIT SUCHE, FILTERN, SORTIERUNG & INLINE-DRAWER)  -->
<!-- ======================================================================= -->
<div id="tab-accounts" data-users-tab-panel="tab-accounts" class="space-y-6 mb-10">

    <!-- AUFKLAPPBARES PANEL: NEUEN BENUTZER ANLEGEN -->
    <section id="createUserCard" class="hidden mc-card bg-white dark:bg-slate-900 rounded-2xl border-2 border-brand-500/70 dark:border-brand-500/60 p-5 sm:p-6 shadow-md">
        <div class="flex items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 text-lg">
                    ➕
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Neuen Benutzer anlegen</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Erstellen Sie manuell ein neues Benutzerkonto inkl. E-Mail-Adresse (für Passwort-Reset &amp; 2FA) und Speicher-Quota.
                    </p>
                </div>
            </div>
            <button
                type="button"
                onclick="toggleCreateUserPanel(false)"
                class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-semibold cursor-pointer"
            >
                ✕ Schließen
            </button>
        </div>

        <form action="users.php" method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_user">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Benutzername *</label>
                    <input
                        type="text"
                        name="username"
                        id="newUsernameInput"
                        required
                        minlength="3"
                        maxlength="32"
                        placeholder="z.B. anna_schmidt"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">E-Mail-Adresse (Reset &amp; 2FA)</label>
                    <input
                        type="email"
                        name="email"
                        maxlength="190"
                        placeholder="z.B. anna@beispiel.de"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Initial-Passwort *</label>
                    <input
                        type="password"
                        name="password"
                        required
                        minlength="8"
                        placeholder="Mindestens 8 Zeichen"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Speicherplatz (MB)</label>
                    <input
                        type="number"
                        name="quota_mb"
                        value="<?= e($currentSettings['registration_default_quota_mb'] ?? '100') ?>"
                        min="1"
                        max="1024000"
                        required
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Rolle</label>
                    <select
                        name="is_admin"
                        onchange="const box = document.getElementById('createUserPartialPermsBox'); if (box) box.classList.toggle('hidden', this.value !== 'partial');"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                        <option value="0">Standardbenutzer</option>
                        <?php if (isFullAdmin()): ?>
                            <option value="partial">🛡️ Teil-Administrator (Bereiche wählen)</option>
                            <option value="1">Voll-Administrator (Alle Rechte)</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <?php if (isFullAdmin()): ?>
                <div id="createUserPartialPermsBox" class="hidden p-3.5 rounded-xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800">
                    <p class="text-xs font-bold text-indigo-900 dark:text-indigo-200 pb-2 mb-2 border-b border-indigo-200/70 dark:border-indigo-800">
                        🛡️ Teil-Admin-Bereiche für diesen Benutzer auswählen:
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        <?php foreach ($availableAdminPerms as $pKey => $pMeta): ?>
                            <label class="flex items-start gap-2 p-2 rounded-lg bg-white/80 dark:bg-slate-900/60 border border-indigo-200/60 dark:border-indigo-800/60 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                <input
                                    type="checkbox"
                                    name="admin_permissions[]"
                                    value="<?= e($pKey) ?>"
                                    class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                >
                                <span class="leading-tight">
                                    <span class="font-semibold"><?= $pMeta['icon'] ?> <?= e($pMeta['label']) ?></span>
                                    <span class="block text-[10px] text-slate-500 dark:text-slate-400"><?= e($pMeta['desc']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($allGroups)): ?>
                <div class="p-3 rounded-xl bg-white/80 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700">
                    <p class="text-xs font-bold text-slate-700 dark:text-slate-200 mb-2">🏢 Direkt zu Benutzergruppen hinzufügen (optional):</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <?php foreach ($allGroups as $gItem): ?>
                            <label class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                <input type="checkbox" name="group_ids[]" value="<?= (int)$gItem['id'] ?>" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span>🏢 <?= e((string)$gItem['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-slate-200 dark:border-slate-800">
                <div class="flex flex-wrap items-center gap-4">
                    <label class="inline-flex items-center gap-2 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                        <input type="checkbox" name="two_factor_enabled" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span>🔒 <strong>2FA per E-Mail aktivieren</strong></span>
                    </label>
                    <label class="inline-flex items-center gap-2 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                        <input type="checkbox" name="allow_user_sharing" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span>👁️ <strong>In Teilen-Auswahl sichtbar</strong></span>
                    </label>
                </div>

                <button
                    type="submit"
                    class="py-2 px-5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
                >
                    ✓ Benutzerkonto jetzt anlegen
                </button>
            </div>
        </form>
    </section>

    <!-- VOLLBREITE BENUTZER-TABELLE MIT FILTER-, SORTIER- & SEITENLEISTE -->
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <!-- Werkzeugleiste: Suche, Schnellfilter, Sortierung & Seitengröße -->
        <div class="p-4 sm:p-5 border-b border-slate-200 dark:border-slate-800 space-y-3.5">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Registrierte Cloud-Benutzer</h2>
                    <span id="adminUserCountBadge" class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-brand-100 dark:bg-brand-950 text-brand-700 dark:text-brand-300">
                        <?= $totalUsersCount ?>
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <!-- Live-Suche -->
                    <div class="relative flex-1 sm:w-72">
                        <span class="absolute inset-y-0 left-3 flex items-center text-slate-400 pointer-events-none text-xs">🔍</span>
                        <input
                            type="search"
                            id="adminUserSearchInput"
                            oninput="applyUsersTableFilter()"
                            placeholder="Name, E-Mail, #ID, Gruppe suchen…"
                            autocomplete="off"
                            class="w-full pl-8 pr-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                    </div>

                    <!-- Sortierung -->
                    <select
                        id="adminUserSortSelect"
                        onchange="applyUsersTableFilter()"
                        aria-label="Sortierung"
                        class="px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-medium text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                    >
                        <option value="id_asc">Sortierung: ID (aufsteigend)</option>
                        <option value="id_desc">Sortierung: Neueste zuerst</option>
                        <option value="name_asc">Sortierung: Name (A–Z)</option>
                        <option value="used_desc">Sortierung: Speicher belegt (↓)</option>
                        <option value="quota_desc">Sortierung: Quota (↓)</option>
                    </select>

                    <!-- Einträge pro Seite -->
                    <select
                        id="adminUserPageSizeSelect"
                        onchange="changeUsersPageSize()"
                        aria-label="Einträge pro Seite"
                        class="px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-medium text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                    >
                        <option value="15">15 / Seite</option>
                        <option value="30" selected>30 / Seite</option>
                        <option value="100">100 / Seite</option>
                        <option value="999999">Alle zeigen</option>
                    </select>
                </div>
            </div>

            <!-- Schnellfilter-Pills -->
            <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Rollen- und Statusfilter">
                    <button type="button" onclick="setUsersRoleFilter('all')" data-user-filter-pill="all"
                            class="px-2.5 py-1 rounded-lg text-xs font-bold bg-brand-600 text-white shadow-2xs cursor-pointer transition-colors">
                        Alle (<?= $totalUsersCount ?>)
                    </button>
                    <button type="button" onclick="setUsersRoleFilter('admin')" data-user-filter-pill="admin"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 cursor-pointer transition-colors">
                        🛡️ Admins &amp; Teil-Admins (<?= $fullAdminsCount + $partialAdminsCount ?>)
                    </button>
                    <button type="button" onclick="setUsersRoleFilter('locked')" data-user-filter-pill="locked"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 cursor-pointer transition-colors">
                        🔒 Gesperrt (<?= $lockedUsersCount ?>)
                    </button>
                    <button type="button" onclick="setUsersRoleFilter('2fa')" data-user-filter-pill="2fa"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 cursor-pointer transition-colors">
                        📱 2FA aktiv (<?= $twoFaUsersCount ?>)
                    </button>
                    <button type="button" onclick="setUsersRoleFilter('sharing')" data-user-filter-pill="sharing"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 cursor-pointer transition-colors">
                        👁️ Teilen sichtbar (<?= $shareVisUsersCount ?>)
                    </button>
                </div>

                <span class="text-[11px] text-slate-400">
                    💡 Klick auf <strong>„✏️ Bearbeiten“</strong> klappt die Detail- &amp; Rechteverwaltung für einen Benutzer auf.
                </span>
            </div>
        </div>

        <!-- Tabelle -->
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Benutzer &amp; Rolle</th>
                        <th class="py-3 px-4">E-Mail &amp; Gruppen</th>
                        <th class="py-3 px-4 w-56">Speicherbelegung</th>
                        <th class="py-3 px-4">Sicherheit &amp; Status</th>
                        <th class="py-3 px-4 text-right">Aktionen</th>
                    </tr>
                </thead>
                <tbody id="adminUsersTableBody" class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php foreach ($allUsers as $u): ?>
                        <?php
                        $uId         = (int)$u['id'];
                        $uName       = (string)$u['username'];
                        $uQuotaBytes = (int)$u['storage_quota'];
                        $uUsedBytes  = (int)$u['used_bytes'];
                        $uQuotaMb    = max(1, (int)round($uQuotaBytes / (1024 * 1024)));
                        $uQuotaPct   = $uQuotaBytes > 0 ? min(100.0, round(($uUsedBytes / $uQuotaBytes) * 100, 1)) : 0.0;
                        $uEmail      = trim((string)($u['email'] ?? ''));
                        $u2faMode    = (int)($u['two_factor_enabled'] ?? 0);
                        $uHasTotp    = trim((string)($u['totp_secret'] ?? '')) !== '';
                        $uShareVis   = (int)($u['allow_user_sharing'] ?? 0) === 1;
                        $uLocked     = (int)($u['is_locked'] ?? 0) === 1;
                        $uLockReason = trim((string)($u['lock_reason'] ?? ''));
                        $uIsFullAdm  = (int)$u['is_admin'] === 1;
                        $uAdminPerms = parseAdminPermissionsString((string)($u['admin_permissions'] ?? ''));
                        $uIsAnyAdmin = $uIsFullAdm || !empty($uAdminPerms);
                        $uGroups     = $groupsByUser[$uId] ?? [];
                        $uCreatedFmt = !empty($u['created_at']) ? date('d.m.Y', strtotime((string)$u['created_at'])) : '–';

                        $uSearchText = mb_strtolower(
                            $uName . ' ' .
                            $uEmail . ' #' . $uId . ' ' .
                            implode(' ', $uGroups) . ' ' .
                            ($uIsFullAdm ? 'admin administrator' : (!empty($uAdminPerms) ? 'teil-admin teiladmin ' . implode(' ', $uAdminPerms) : 'standard')) . ' ' .
                            ($uLocked ? 'gesperrt sperre locked ' . $uLockReason : 'aktiv') . ' ' .
                            ($uShareVis ? 'teilen sichtbar' : 'verborgen') . ' ' .
                            ($u2faMode > 0 ? '2fa totp' : ''),
                            'UTF-8'
                        );
                        ?>
                        <!-- Kompakte Hauptzeile -->
                        <tr
                            data-admin-user-row="1"
                            data-user-id="<?= $uId ?>"
                            data-user-name="<?= e(mb_strtolower($uName, 'UTF-8')) ?>"
                            data-user-used="<?= $uUsedBytes ?>"
                            data-user-quota="<?= $uQuotaBytes ?>"
                            data-user-is-admin="<?= $uIsAnyAdmin ? '1' : '0' ?>"
                            data-user-is-locked="<?= $uLocked ? '1' : '0' ?>"
                            data-user-has-2fa="<?= $u2faMode > 0 ? '1' : '0' ?>"
                            data-user-sharing="<?= $uShareVis ? '1' : '0' ?>"
                            data-user-search="<?= e($uSearchText) ?>"
                            class="<?= $uLocked ? 'bg-red-50/40 dark:bg-red-950/20' : 'hover:bg-slate-50/70 dark:hover:bg-slate-800/40' ?> transition-colors"
                        >
                            <!-- Spalte 1: Name, ID & Rollen-Badges -->
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-xl <?= $uLocked ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300' : ($uIsFullAdm ? 'bg-brand-100 dark:bg-brand-950 text-brand-700 dark:text-brand-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300') ?> font-bold text-xs flex items-center justify-center shrink-0">
                                        <?= e(mb_strtoupper(mb_substr($uName, 0, 2, 'UTF-8'), 'UTF-8')) ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-semibold text-slate-900 dark:text-white flex flex-wrap items-center gap-1.5">
                                            <span class="<?= $uLocked ? 'line-through text-red-700 dark:text-red-300' : '' ?>"><?= e($uName) ?></span>
                                            <span class="text-[11px] font-mono text-slate-400">#<?= $uId ?></span>
                                            <?php if ($uId === $currentAdminId): ?>
                                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200">Sie</span>
                                            <?php endif; ?>
                                            <?php if ($uIsFullAdm): ?>
                                                <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-brand-600 text-white" title="Voll-Administrator mit allen Rechten">Admin</span>
                                            <?php elseif (!empty($uAdminPerms)): ?>
                                                <?php
                                                $permNames = [];
                                                foreach ($uAdminPerms as $pk) {
                                                    if (isset($availableAdminPerms[$pk])) {
                                                        $permNames[] = $availableAdminPerms[$pk]['label'];
                                                    }
                                                }
                                                ?>
                                                <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-indigo-600 text-white" title="<?= e('Übertragene Admin-Rechte: ' . implode(', ', $permNames)) ?>">
                                                    🛡️ Teil-Admin (<?= count($uAdminPerms) ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-[11px] text-slate-400">
                                            Seit <?= e($uCreatedFmt) ?> &bull; Theme: <?= e((string)$u['theme']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Spalte 2: E-Mail & Gruppen -->
                            <td class="py-3 px-4 text-xs">
                                <div class="text-slate-700 dark:text-slate-200 truncate max-w-[230px]" title="<?= e($uEmail !== '' ? $uEmail : 'Keine E-Mail-Adresse hinterlegt') ?>">
                                    <?= $uEmail !== '' ? '✉️ ' . e($uEmail) : '<span class="italic text-slate-400">Keine E-Mail hinterlegt</span>' ?>
                                </div>
                                <?php if (!empty($uGroups)): ?>
                                    <div class="flex flex-wrap items-center gap-1 mt-1">
                                        <?php foreach ($uGroups as $gBadge): ?>
                                            <span class="px-1.5 py-0.2 rounded bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700 text-[10px] font-semibold text-slate-600 dark:text-slate-300">
                                                🏢 <?= e($gBadge) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Spalte 3: Speicherbelegung & Quota -->
                            <td class="py-3 px-4 text-xs whitespace-nowrap">
                                <div class="flex items-center justify-between gap-2">
                                    <span><strong><?= e(formatBytes($uUsedBytes)) ?></strong> / <?= e(formatBytes($uQuotaBytes, 0)) ?></span>
                                    <span class="text-[11px] text-slate-400"><?= (int)$u['file_count'] ?> Dat.</span>
                                </div>
                                <div class="w-full h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden mt-1.5">
                                    <div class="h-full <?= $uQuotaPct >= 90 ? 'bg-red-600' : ($uQuotaPct >= 75 ? 'bg-amber-500' : 'bg-brand-600') ?> rounded-full" style="width: <?= max($uUsedBytes > 0 ? 3 : 0, $uQuotaPct) ?>%"></div>
                                </div>
                            </td>

                            <!-- Spalte 4: Sicherheit & Sichtbarkeit -->
                            <td class="py-3 px-4 text-xs">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <?php if ($uLocked): ?>
                                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full bg-red-600 text-white" title="<?= e($uLockReason !== '' ? 'Grund: ' . $uLockReason : 'Vom Administrator gesperrt') ?>">
                                            🔒 Gesperrt
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300">
                                            ● Aktiv
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($u2faMode === 2 && $uHasTotp): ?>
                                        <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-emerald-600 text-white" title="2FA per Authenticator-App (TOTP)">📱 TOTP</span>
                                    <?php elseif ($u2faMode === 1 && $uEmail !== ''): ?>
                                        <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-emerald-600 text-white" title="2FA per E-Mail">🔒 2FA</span>
                                    <?php endif; ?>

                                    <?php if ($uShareVis): ?>
                                        <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-blue-600 text-white" title="In Teilen-Auswahl sichtbar">👁️ Teilen</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($uLocked && $uLockReason !== ''): ?>
                                    <div class="text-[11px] font-medium text-red-600 dark:text-red-400 truncate max-w-[200px] mt-0.5" title="<?= e($uLockReason) ?>">
                                        ⛔ <?= e($uLockReason) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Spalte 5: Aktionen (Aufklapp-Editor, Sperren, Löschen) -->
                            <td class="py-3 px-4 text-right whitespace-nowrap">
                                <div class="inline-flex items-center justify-end gap-1.5">
                                    <button
                                        type="button"
                                        onclick="toggleUserEditDrawer(<?= $uId ?>)"
                                        id="btnEditUser-<?= $uId ?>"
                                        class="px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-slate-700 dark:text-slate-200 text-xs font-semibold transition-colors cursor-pointer"
                                    >
                                        ✏️ Bearbeiten
                                    </button>

                                    <?php if ($uId !== $currentAdminId && (isFullAdmin() || !$uIsAnyAdmin)): ?>
                                        <form
                                            action="users.php"
                                            method="post"
                                            class="inline"
                                            onsubmit="return handleLockUserSubmit(this, <?= $uLocked ? 'true' : 'false' ?>, <?= e((string)json_encode($uName)) ?>);"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="toggle_lock_user">
                                            <input type="hidden" name="user_id" value="<?= $uId ?>">
                                            <input type="hidden" name="lock_state" value="<?= $uLocked ? '0' : '1' ?>">
                                            <input type="hidden" name="lock_reason" value="">
                                            <?php if ($uLocked): ?>
                                                <button
                                                    type="submit"
                                                    title="Benutzerkonto wieder entsperren"
                                                    class="px-2.5 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/70 text-xs font-medium cursor-pointer"
                                                >
                                                    🔓 Entsperren
                                                </button>
                                            <?php else: ?>
                                                <button
                                                    type="submit"
                                                    title="Benutzerkonto sperren (sofortiger Logout)"
                                                    class="px-2.5 py-1.5 rounded-lg bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/70 text-xs font-medium cursor-pointer"
                                                >
                                                    🔒
                                                </button>
                                            <?php endif; ?>
                                        </form>

                                        <form action="users.php" method="post" class="inline" onsubmit="return confirm('Benutzer &quot;<?= e(addslashes($uName)) ?>&quot; samt allen persönlichen Dateien wirklich unwiderruflich löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $uId ?>">
                                            <button
                                                type="submit"
                                                title="Benutzerkonto samt Dateien löschen"
                                                class="px-2.5 py-1.5 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 text-red-600 dark:text-red-300 text-xs font-medium cursor-pointer"
                                            >
                                                🗑️
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- Aufklappbare Bearbeitungs-Schublade (Inline-Drawer) für diesen Benutzer -->
                        <tr id="userEditDrawer-<?= $uId ?>" data-user-drawer-for="<?= $uId ?>" class="hidden bg-slate-50/90 dark:bg-slate-800/60">
                            <td colspan="5" class="p-4 sm:p-5 border-l-4 border-brand-600">
                                <form action="users.php" method="post" class="space-y-4">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="user_id" value="<?= $uId ?>">

                                    <div class="flex flex-wrap items-center justify-between gap-2 pb-2 border-b border-slate-200 dark:border-slate-700">
                                        <p class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                            ✏️ Konto &amp; Berechtigungen bearbeiten: <span class="text-brand-600 dark:text-brand-400"><?= e($uName) ?> (#<?= $uId ?>)</span>
                                        </p>
                                        <button
                                            type="button"
                                            onclick="toggleUserEditDrawer(<?= $uId ?>)"
                                            class="text-xs text-slate-500 hover:text-slate-800 dark:hover:text-white cursor-pointer"
                                        >
                                            ✕ Einklappen
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">E-Mail-Adresse (Reset &amp; 2FA)</label>
                                            <input
                                                type="email"
                                                name="email"
                                                value="<?= e($uEmail) ?>"
                                                placeholder="Keine E-Mail hinterlegt"
                                                class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                                            >
                                        </div>

                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Speicher-Quota (in MB)</label>
                                            <input
                                                type="number"
                                                name="quota_mb"
                                                value="<?= $uQuotaMb ?>"
                                                min="1"
                                                max="1024000"
                                                class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                                            >
                                        </div>

                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Neues Passwort setzen (optional)</label>
                                            <input
                                                type="password"
                                                name="new_password"
                                                autocomplete="new-password"
                                                placeholder="Leer lassen = unverändert"
                                                class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                                            >
                                        </div>

                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">2FA-Methode beim Login</label>
                                            <select
                                                name="two_factor_enabled"
                                                class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                                            >
                                                <option value="0" <?= $u2faMode === 0 ? 'selected' : '' ?>>2FA: Deaktiviert</option>
                                                <option value="1" <?= $u2faMode === 1 ? 'selected' : '' ?>>2FA: Per E-Mail-Code</option>
                                                <?php if ($uHasTotp || $u2faMode === 2): ?>
                                                    <option value="2" <?= $u2faMode === 2 ? 'selected' : '' ?>>2FA: Authenticator-App (TOTP)</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <?php if (isFullAdmin() && $uId !== $currentAdminId): ?>
                                        <div class="p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 space-y-2.5">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <span class="text-xs font-bold text-slate-800 dark:text-slate-200">🛡️ Delegierte Teil-Admin-Bereiche (falls kein Voll-Admin):</span>
                                                <label class="inline-flex items-center gap-1.5 text-xs font-bold text-brand-700 dark:text-brand-300 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        name="is_admin"
                                                        value="1"
                                                        <?= $uIsFullAdm ? 'checked' : '' ?>
                                                        onchange="if (this.checked) { this.form.querySelectorAll('.partial-perm-cb').forEach(cb => cb.checked = false); }"
                                                        class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                                    >
                                                    <span>👑 Voll-Administrator (Alle Rechte)</span>
                                                </label>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2">
                                                <?php foreach ($availableAdminPerms as $pKey => $pMeta): ?>
                                                    <label class="flex items-start gap-2 p-2 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                                        <input
                                                            type="checkbox"
                                                            name="admin_permissions[]"
                                                            value="<?= e($pKey) ?>"
                                                            <?= in_array($pKey, $uAdminPerms, true) ? 'checked' : '' ?>
                                                            onchange="if (this.checked) { const fullAdm = this.form.querySelector('input[name=is_admin]'); if (fullAdm && !fullAdm.disabled) fullAdm.checked = false; }"
                                                            class="partial-perm-cb mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                        >
                                                        <span class="leading-tight">
                                                            <span class="font-semibold block"><?= $pMeta['icon'] ?> <?= e($pMeta['short']) ?></span>
                                                            <span class="text-[10px] text-slate-400"><?= e($pMeta['label']) ?></span>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($allGroups)): ?>
                                        <?php $uCurrentGroupIds = $groupIdsByUser[$uId] ?? []; ?>
                                        <div class="p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 space-y-2">
                                            <input type="hidden" name="sync_groups" value="1">
                                            <span class="block text-xs font-bold text-slate-800 dark:text-slate-200">🏢 Mitgliedschaft in Benutzergruppen:</span>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <?php foreach ($allGroups as $gItem): ?>
                                                    <?php $gItemId = (int)$gItem['id']; ?>
                                                    <label class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                                        <input
                                                            type="checkbox"
                                                            name="group_ids[]"
                                                            value="<?= $gItemId ?>"
                                                            <?= in_array($gItemId, $uCurrentGroupIds, true) ? 'checked' : '' ?>
                                                            class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                        >
                                                        <span class="font-medium">🏢 <?= e((string)$gItem['name']) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                                        <label class="inline-flex items-center gap-2 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                            <input type="checkbox" name="allow_user_sharing" value="1" <?= $uShareVis ? 'checked' : '' ?> class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                            <span>👁️ <strong>In Teilen-Auswahl sichtbar</strong> (für Datei-, Kalender-, Notiz- &amp; Chat-Freigaben anderer Nutzer)</span>
                                        </label>

                                        <div class="flex items-center gap-2">
                                            <button
                                                type="button"
                                                onclick="toggleUserEditDrawer(<?= $uId ?>)"
                                                class="px-3.5 py-1.5 rounded-xl bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold cursor-pointer"
                                            >
                                                Abbrechen
                                            </button>
                                            <button
                                                type="submit"
                                                class="px-4 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-xs transition-colors cursor-pointer"
                                            >
                                                💾 Änderungen speichern
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr id="adminUserNoMatchRow" class="hidden">
                        <td colspan="5" class="py-8 px-4 text-center text-xs text-slate-500 dark:text-slate-400 italic">
                            Kein registrierter Benutzer entspricht Ihrer aktuellen Suche oder Filterauswahl.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Fußzeile mit Seitennavigation (Pagination) -->
        <div id="usersPaginationFooter" class="px-4 py-3 bg-slate-50/70 dark:bg-slate-800/40 border-t border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-600 dark:text-slate-300">
            <span id="usersPaginationInfo">Zeige 1–<?= $totalUsersCount ?> von <?= $totalUsersCount ?> Benutzern</span>
            <div class="inline-flex items-center gap-1.5">
                <button
                    type="button"
                    id="btnUsersPrevPage"
                    onclick="stepUsersPage(-1)"
                    class="px-3 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold disabled:opacity-40 cursor-pointer"
                >
                    &larr; Zurück
                </button>
                <span id="usersPageIndicator" class="px-2 font-bold">Seite 1 / 1</span>
                <button
                    type="button"
                    id="btnUsersNextPage"
                    onclick="stepUsersPage(1)"
                    class="px-3 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold disabled:opacity-40 cursor-pointer"
                >
                    Weiter &rarr;
                </button>
            </div>
        </div>
    </section>
</div>

<!-- ======================================================================= -->
<!-- TAB 2: GRUPPEN & GRUPPENORDNER                                          -->
<!-- ======================================================================= -->
<div id="tab-groups" data-users-tab-panel="tab-groups" class="hidden space-y-6 mb-10">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">

        <!-- LINKE SPALTE: BENUTZERGRUPPEN -->
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-5">
            <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-2.5">
                    <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400 text-lg">
                        👥
                    </span>
                    <div>
                        <h2 class="text-base font-bold text-slate-900 dark:text-white">Benutzergruppen (Teams / Abteilungen)</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Fassen Sie Benutzer in Gruppen zusammen, um Gruppenordner, Notizen, Aufgaben oder Passwörter mit einem Klick für ein ganzes Team freizugeben.
                        </p>
                    </div>
                </div>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300">
                    <?= count($allGroups) ?>
                </span>
            </div>

            <!-- Neue Gruppe anlegen -->
            <form action="users.php" method="post" class="grid grid-cols-1 sm:grid-cols-12 gap-2.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_group">
                <input
                    type="text"
                    name="group_name"
                    required
                    maxlength="100"
                    placeholder="Gruppenname (z.B. Familie, Vorstand, IT)"
                    class="sm:col-span-5 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                >
                <input
                    type="text"
                    name="group_description"
                    maxlength="255"
                    placeholder="Optionale Kurzbeschreibung"
                    class="sm:col-span-4 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                >
                <button
                    type="submit"
                    class="sm:col-span-3 py-2 px-3 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm transition-colors cursor-pointer"
                >
                    ➕ Gruppe anlegen
                </button>
            </form>

            <!-- Liste aller Gruppen -->
            <?php if (empty($allGroups)): ?>
                <p class="text-xs text-slate-400 italic py-4 text-center">
                    Noch keine Benutzergruppen angelegt.
                </p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($allGroups as $grp): ?>
                        <?php
                        $gid = (int)$grp['id'];
                        $gMembers = $membersByGroup[$gid] ?? [];
                        ?>
                        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <span>🏢 <?= e((string)$grp['name']) ?></span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200">
                                            <?= count($gMembers) ?> Mitglied<?= count($gMembers) === 1 ? '' : 'er' ?>
                                        </span>
                                    </h3>
                                    <?php if (trim((string)($grp['description'] ?? '')) !== ''): ?>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5"><?= e((string)$grp['description']) ?></p>
                                    <?php endif; ?>
                                </div>

                                <form action="users.php" method="post" onsubmit="return confirm('Gruppe &quot;<?= e(addslashes((string)$grp['name'])) ?>&quot; wirklich löschen?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_group">
                                    <input type="hidden" name="group_id" value="<?= $gid ?>">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 text-red-600 dark:text-red-300 text-xs font-medium cursor-pointer">
                                        Löschen
                                    </button>
                                </form>
                            </div>

                            <!-- Aktuelle Mitglieder -->
                            <div class="flex flex-wrap items-center gap-1.5">
                                <?php if (empty($gMembers)): ?>
                                    <span class="text-xs text-slate-400 italic">Noch keine Mitglieder zugeordnet.</span>
                                <?php else: ?>
                                    <?php foreach ($gMembers as $gm): ?>
                                        <form action="users.php" method="post" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="remove_group_member">
                                            <input type="hidden" name="gm_id" value="<?= (int)$gm['gm_id'] ?>">
                                            <span class="font-medium text-slate-800 dark:text-slate-200">👤 <?= e((string)$gm['username']) ?></span>
                                            <button type="submit" class="ml-1 text-slate-400 hover:text-red-600 font-bold cursor-pointer" title="Aus Gruppe entfernen">&times;</button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Mitglied hinzufügen -->
                            <form action="users.php" method="post" class="flex items-center gap-2 pt-2 border-t border-slate-200/70 dark:border-slate-700/70">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="add_group_member">
                                <input type="hidden" name="group_id" value="<?= $gid ?>">
                                <select name="user_id" required class="flex-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
                                    <option value="">+ Benutzer zur Gruppe hinzufügen…</option>
                                    <?php foreach ($allUsers as $uOpt): ?>
                                        <option value="<?= (int)$uOpt['id'] ?>"><?= e((string)$uOpt['username']) ?> (#<?= (int)$uOpt['id'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-brand-600 hover:text-white text-xs font-semibold transition-colors cursor-pointer">
                                    Hinzufügen
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- RECHTE SPALTE: GEMEINSAME GRUPPENORDNER -->
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-5">
            <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-2.5">
                    <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-600 dark:text-amber-400 text-lg">
                        📁
                    </span>
                    <div>
                        <h2 class="text-base font-bold text-slate-900 dark:text-white">Gemeinsame Gruppenordner</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Team-Ordner im Dateimanager mit feingranularen Lese- oder Schreibrechten für ganze Gruppen oder einzelne Benutzer.
                        </p>
                    </div>
                </div>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                    <?= count($allGroupFolders) ?>
                </span>
            </div>

            <!-- Neuen Gruppenordner anlegen -->
            <form action="users.php" method="post" class="grid grid-cols-1 sm:grid-cols-12 gap-2.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_group_folder">
                <input
                    type="text"
                    name="folder_name"
                    required
                    maxlength="120"
                    placeholder="Ordnername (z.B. Familie, Projekt)"
                    class="sm:col-span-4 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                >
                <input
                    type="text"
                    name="folder_description"
                    maxlength="255"
                    placeholder="Optionale Beschreibung"
                    class="sm:col-span-3 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                >
                <select
                    name="initial_group_id"
                    title="Optional direkt für eine Gruppe freigeben"
                    class="sm:col-span-3 px-2.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                >
                    <option value="0">+ Direkt für Gruppe freigeben (optional)…</option>
                    <?php foreach ($allGroups as $gOpt): ?>
                        <option value="<?= (int)$gOpt['id'] ?>">🏢 <?= e((string)$gOpt['name']) ?> (Schreibrecht)</option>
                    <?php endforeach; ?>
                </select>
                <button
                    type="submit"
                    class="sm:col-span-2 py-2 px-3 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm transition-colors cursor-pointer"
                >
                    ➕ Erstellen
                </button>
            </form>

            <!-- Liste aller Gruppenordner -->
            <?php if (empty($allGroupFolders)): ?>
                <p class="text-xs text-slate-400 italic py-4 text-center">
                    Noch keine Gruppenordner eingerichtet.
                </p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($allGroupFolders as $gf): ?>
                        <?php
                        $gfId = (int)$gf['id'];
                        $fPerms = $permsByFolder[$gfId] ?? [];
                        ?>
                        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-white flex flex-wrap items-center gap-2">
                                        <a href="index.php?group_folder=<?= $gfId ?>" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors" title="Gruppenordner im Dateimanager öffnen">
                                            📁 <?= e((string)$gf['name']) ?>
                                        </a>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200">
                                            <?= (int)$gf['file_count'] ?> Dateien &bull; <?= e(formatBytes((int)$gf['folder_size'])) ?>
                                        </span>
                                    </h3>
                                    <?php if (trim((string)($gf['description'] ?? '')) !== ''): ?>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5"><?= e((string)$gf['description']) ?></p>
                                    <?php endif; ?>
                                </div>

                                <div class="inline-flex items-center gap-1.5 shrink-0">
                                    <a
                                        href="index.php?group_folder=<?= $gfId ?>"
                                        class="px-2.5 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold transition-colors shadow-2xs"
                                        title="Diesen Gruppenordner im Dateimanager öffnen"
                                    >
                                        📂 Öffnen
                                    </a>
                                    <form action="users.php" method="post" class="inline" onsubmit="return confirm('Gruppenordner &quot;<?= e(addslashes((string)$gf['name'])) ?>&quot; samt enthaltenen Dateien wirklich löschen?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_group_folder">
                                        <input type="hidden" name="group_folder_id" value="<?= $gfId ?>">
                                        <button type="submit" class="px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 text-red-600 dark:text-red-300 text-xs font-medium cursor-pointer">
                                            Löschen
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Aktuelle Berechtigungen -->
                            <div class="flex flex-wrap items-center gap-1.5">
                                <?php if (empty($fPerms)): ?>
                                    <span class="text-xs text-slate-400 italic">Noch keine Gruppen oder Benutzer freigeschaltet (nur für Admins sichtbar).</span>
                                <?php else: ?>
                                    <?php foreach ($fPerms as $fp): ?>
                                        <form action="users.php" method="post" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="revoke_group_folder_permission">
                                            <input type="hidden" name="permission_id" value="<?= (int)$fp['id'] ?>">
                                            <span>
                                                <?= !empty($fp['group_id']) ? '🏢 ' . e((string)$fp['group_name']) : '👤 ' . e((string)$fp['username']) ?>
                                            </span>
                                            <span class="px-1.5 py-0.2 rounded text-[10px] font-bold <?= (int)$fp['can_write'] === 1 ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300' ?>">
                                                <?= (int)$fp['can_write'] === 1 ? 'Lesen & Schreiben' : 'Nur Lesen' ?>
                                            </span>
                                            <button type="submit" class="text-slate-400 hover:text-red-600 font-bold cursor-pointer" title="Berechtigung entziehen">&times;</button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Neue Berechtigung hinzufügen -->
                            <form action="users.php" method="post" class="grid grid-cols-1 sm:grid-cols-12 gap-2 pt-2 border-t border-slate-200/70 dark:border-slate-700/70 items-center">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="grant_group_folder_permission">
                                <input type="hidden" name="group_folder_id" value="<?= $gfId ?>">
                                <select
                                    name="target_type"
                                    onchange="const f = this.form; f.querySelector('.gf-sel-group').classList.toggle('hidden', this.value !== 'group'); f.querySelector('.gf-sel-user').classList.toggle('hidden', this.value !== 'user'); f.querySelector('.gf-sel-group').disabled = (this.value !== 'group'); f.querySelector('.gf-sel-user').disabled = (this.value !== 'user');"
                                    class="sm:col-span-3 px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                                >
                                    <option value="group">🏢 Gruppe</option>
                                    <option value="user">👤 Benutzer</option>
                                </select>

                                <select name="target_id" class="gf-sel-group sm:col-span-4 px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                                    <?php foreach ($allGroups as $gOpt): ?>
                                        <option value="<?= (int)$gOpt['id'] ?>"><?= e((string)$gOpt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <select name="target_id" disabled class="gf-sel-user hidden sm:col-span-4 px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                                    <?php foreach ($allUsers as $uOpt): ?>
                                        <option value="<?= (int)$uOpt['id'] ?>"><?= e((string)$uOpt['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label class="sm:col-span-3 inline-flex items-center gap-1.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                    <input type="checkbox" name="can_write" value="1" checked class="rounded border-slate-300 text-brand-600">
                                    <span>Schreibrecht</span>
                                </label>

                                <button type="submit" class="sm:col-span-2 px-2.5 py-1.5 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-brand-600 hover:text-white text-xs font-semibold transition-colors cursor-pointer">
                                    Freigeben
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<!-- ======================================================================= -->
<!-- TAB 3: ÖFFENTLICHE SELBSTREGISTRIERUNG & PAYPAL-BEZAHLSCHRANKE          -->
<!-- ======================================================================= -->
<div id="tab-registration" data-users-tab-panel="tab-registration" class="hidden space-y-6 mb-10">
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 mb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 text-lg">
                    📝
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Öffentliche Selbstregistrierung &amp; Bezahlschranke (PayPal-Abo)</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Legen Sie fest, ob Besucher sich selbst registrieren dürfen und ob dafür optional ein vollautomatisches PayPal-Abo erforderlich ist.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase <?= ($currentSettings['allow_registration'] ?? '0') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                    Registrierung: <?= ($currentSettings['allow_registration'] ?? '0') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                </span>
                <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase <?= ($currentSettings['registration_paywall_enabled'] ?? '0') === '1' ? 'bg-indigo-100 dark:bg-indigo-950 text-indigo-800 dark:text-indigo-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                    💳 PayPal-Schranke: <?= ($currentSettings['registration_paywall_enabled'] ?? '0') === '1' ? 'Aktiv' : 'Aus (Kostenlos)' ?>
                </span>
            </div>
        </div>

        <form action="users.php" method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="update_registration_settings">

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
                <div class="lg:col-span-6">
                    <label class="flex items-start gap-2.5 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                        <input
                            type="checkbox"
                            name="allow_registration"
                            value="1"
                            <?= ($currentSettings['allow_registration'] ?? '0') === '1' ? 'checked' : '' ?>
                            class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        <span>
                            <strong>Selbstregistrierung auf der Anmeldeseite erlauben</strong><br>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400">Neue Konten sind aus Datenschutzgründen in der Teilen-Auswahl standardmäßig unsichtbar.</span>
                        </span>
                    </label>
                </div>

                <div class="lg:col-span-3">
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Einladungs-/Gutscheincode (optional)
                    </label>
                    <input
                        type="text"
                        name="registration_code"
                        maxlength="100"
                        value="<?= e($currentSettings['registration_code'] ?? '') ?>"
                        placeholder="Leer = ohne Code"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div class="lg:col-span-3">
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Gratis-Start-Quota (MB)
                    </label>
                    <input
                        type="number"
                        name="registration_default_quota_mb"
                        min="1"
                        max="102400"
                        value="<?= e($currentSettings['registration_default_quota_mb'] ?? '100') ?>"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-indigo-50/50 dark:bg-indigo-950/20 border border-indigo-200/80 dark:border-indigo-800/60 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-start gap-2.5 text-xs text-slate-800 dark:text-slate-100 cursor-pointer">
                        <input
                            type="checkbox"
                            name="registration_paywall_enabled"
                            value="1"
                            <?= ($currentSettings['registration_paywall_enabled'] ?? '0') === '1' ? 'checked' : '' ?>
                            class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        >
                        <span>
                            <strong class="text-sm">💳 Registrierung hinter PayPal-Bezahlschranke (Abo) setzen</strong><br>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                Wenn aktiviert, müssen neue Nutzer bei der Registrierung ein PayPal-Abo abschließen, bevor das Konto automatisch freigeschaltet wird.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input
                            type="checkbox"
                            name="paypal_allow_invite_bypass"
                            value="1"
                            <?= ($currentSettings['paypal_allow_invite_bypass'] ?? '1') === '1' ? 'checked' : '' ?>
                            class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        >
                        <span>🔑 Einladungs-Code schaltet weiterhin kostenlos frei (Familie/Freunde)</span>
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-2 border-t border-indigo-200/60 dark:border-indigo-800/50">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">PayPal-Modus</label>
                        <select name="paypal_mode" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                            <option value="sandbox" <?= ($currentSettings['paypal_mode'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>🧪 Sandbox (Testmodus / Simulation)</option>
                            <option value="live" <?= ($currentSettings['paypal_mode'] ?? 'sandbox') === 'live' ? 'selected' : '' ?>>🟢 Live (Echte PayPal-Zahlungen)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Abo-Preis (in EUR)</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0.50"
                            max="9999.00"
                            name="paypal_price_eur"
                            value="<?= e($currentSettings['paypal_price_eur'] ?? '1.00') ?>"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                        >
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Abrechnungs-Intervall</label>
                        <select name="paypal_billing_interval" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                            <option value="MONTH" <?= ($currentSettings['paypal_billing_interval'] ?? 'MONTH') === 'MONTH' ? 'selected' : '' ?>>Monatlich (z. B. 1,00 € / Monat)</option>
                            <option value="YEAR" <?= ($currentSettings['paypal_billing_interval'] ?? 'MONTH') === 'YEAR' ? 'selected' : '' ?>>Jährlich (spart PayPal-Fixgebühren)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Abo-Speicherplatz (MB, 1024 = 1 GB)</label>
                        <input
                            type="number"
                            min="100"
                            max="1024000"
                            name="paypal_paid_quota_mb"
                            value="<?= e($currentSettings['paypal_paid_quota_mb'] ?? '1024') ?>"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                        >
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">PayPal Client-ID</label>
                        <input
                            type="text"
                            name="paypal_client_id"
                            value="<?= e($currentSettings['paypal_client_id'] ?? '') ?>"
                            placeholder="Aus developer.paypal.com"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono text-slate-900 dark:text-white"
                        >
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            PayPal Secret-Key
                            <?php if (trim((string)($currentSettings['paypal_client_secret'] ?? '')) !== ''): ?>
                                <span class="text-emerald-600 dark:text-emerald-400 font-bold">(hinterlegt ✓)</span>
                            <?php endif; ?>
                        </label>
                        <input
                            type="password"
                            name="paypal_client_secret"
                            value=""
                            autocomplete="new-password"
                            placeholder="<?= trim((string)($currentSettings['paypal_client_secret'] ?? '')) !== '' ? '•••••••• (Leer lassen zum Behalten)' : 'Geheimer Schlüssel aus PayPal App' ?>"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono text-slate-900 dark:text-white"
                        >
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">PayPal Plan-ID (optional / wird automatisch erzeugt)</label>
                        <input
                            type="text"
                            name="paypal_plan_id"
                            value="<?= e($currentSettings['paypal_plan_id'] ?? '') ?>"
                            placeholder="P-XXXXXXXX (oder per Button rechts anlegen)"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono text-slate-900 dark:text-white"
                        >
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 pt-1 text-[11px] text-slate-500 dark:text-slate-400">
                    <div>
                        🔗 Automatische Webhook-URL für PayPal:
                        <code class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-slate-800 dark:text-slate-200 select-all"><?= e(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'runready.cloud') . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/subscription.php?webhook=1') ?></code>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="submit"
                            name="auto_create_paypal_plan"
                            value="1"
                            class="py-2 px-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs shadow-xs transition-colors cursor-pointer"
                        >
                            ⚡ Speichern &amp; PayPal-Plan automatisch anlegen
                        </button>
                        <button
                            type="submit"
                            class="py-2 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
                        >
                            Einstellungen speichern
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </section>
</div>

<script>
let currentUsersRoleFilter = 'all';
let currentUsersPage = 1;

function switchUsersTab(tabId, updateHash = true) {
    const hashMap = {
        'accounts': 'tab-accounts',
        'users': 'tab-accounts',
        'tab-users': 'tab-accounts',
        'groups': 'tab-groups',
        'group-folders': 'tab-groups',
        'registration': 'tab-registration'
    };
    const resolvedTab = hashMap[tabId] || tabId;

    document.querySelectorAll('[data-users-tab-panel]').forEach((panel) => {
        if (panel.getAttribute('data-users-tab-panel') === resolvedTab) {
            panel.classList.remove('hidden');
        } else {
            panel.classList.add('hidden');
        }
    });

    document.querySelectorAll('[data-users-tab-btn]').forEach((btn) => {
        const bId = btn.getAttribute('data-users-tab-btn');
        if (bId === resolvedTab) {
            btn.className = 'users-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer bg-brand-600 text-white shadow-xs';
        } else {
            btn.className = 'users-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800';
        }
    });

    if (updateHash && history.replaceState) {
        history.replaceState(null, '', '#' + resolvedTab);
    }
}

function toggleCreateUserPanel(forceOpen) {
    switchUsersTab('tab-accounts');
    const card = document.getElementById('createUserCard');
    if (!card) return;
    const show = typeof forceOpen === 'boolean' ? forceOpen : card.classList.contains('hidden');
    card.classList.toggle('hidden', !show);
    if (show) {
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        const inp = document.getElementById('newUsernameInput');
        if (inp) setTimeout(() => inp.focus(), 120);
    }
}

function toggleUserEditDrawer(userId) {
    const drawer = document.getElementById('userEditDrawer-' + userId);
    if (!drawer) return;
    const isHidden = drawer.classList.contains('hidden');
    // Alle anderen Schubladen schließen, damit die Tabelle übersichtlich bleibt
    document.querySelectorAll('[data-user-drawer-for]').forEach((el) => {
        el.classList.add('hidden');
    });
    if (isHidden) {
        drawer.classList.remove('hidden');
    }
}

function handleLockUserSubmit(form, isCurrentlyLocked, username) {
    if (isCurrentlyLocked) {
        return confirm(`Möchten Sie das Benutzerkonto „${username}“ wieder entsperren?`);
    }
    const reason = prompt(
        `Benutzerkonto „${username}“ jetzt sperren?\n\nDer Benutzer wird sofort abgemeldet und kann sich nicht mehr einloggen.\nOptionaler Sperrgrund (wird beim Anmeldeversuch angezeigt):`,
        ''
    );
    if (reason === null) {
        return false;
    }
    const reasonInput = form.querySelector('input[name="lock_reason"]');
    if (reasonInput) {
        reasonInput.value = reason.trim();
    }
    return true;
}

function setUsersRoleFilter(roleKey) {
    currentUsersRoleFilter = roleKey;
    currentUsersPage = 1;
    document.querySelectorAll('[data-user-filter-pill]').forEach((btn) => {
        const active = btn.getAttribute('data-user-filter-pill') === roleKey;
        btn.className = active
            ? 'px-2.5 py-1 rounded-lg text-xs font-bold bg-brand-600 text-white shadow-2xs cursor-pointer transition-colors'
            : 'px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 cursor-pointer transition-colors';
    });
    applyUsersTableFilter();
}

function changeUsersPageSize() {
    currentUsersPage = 1;
    applyUsersTableFilter();
}

function stepUsersPage(delta) {
    currentUsersPage = Math.max(1, currentUsersPage + delta);
    applyUsersTableFilter();
}

function applyUsersTableFilter() {
    const tbody = document.getElementById('adminUsersTableBody');
    if (!tbody) return;

    const q = String(document.getElementById('adminUserSearchInput')?.value || '').trim().toLowerCase();
    const sortBy = document.getElementById('adminUserSortSelect')?.value || 'id_asc';
    const pageSize = parseInt(document.getElementById('adminUserPageSizeSelect')?.value || '30', 10) || 30;

    const rows = Array.from(tbody.querySelectorAll('tr[data-admin-user-row="1"]'));

    // 1. Sortieren (inkl. zugehöriger Drawer-Zeile direkt dahinter)
    rows.sort((a, b) => {
        const idA = parseInt(a.getAttribute('data-user-id') || '0', 10);
        const idB = parseInt(b.getAttribute('data-user-id') || '0', 10);
        if (sortBy === 'id_desc') return idB - idA;
        if (sortBy === 'name_asc') {
            return (a.getAttribute('data-user-name') || '').localeCompare(b.getAttribute('data-user-name') || '', 'de');
        }
        if (sortBy === 'used_desc') {
            const uA = parseInt(a.getAttribute('data-user-used') || '0', 10);
            const uB = parseInt(b.getAttribute('data-user-used') || '0', 10);
            return uB !== uA ? uB - uA : idA - idB;
        }
        if (sortBy === 'quota_desc') {
            const qA = parseInt(a.getAttribute('data-user-quota') || '0', 10);
            const qB = parseInt(b.getAttribute('data-user-quota') || '0', 10);
            return qB !== qA ? qB - qA : idA - idB;
        }
        return idA - idB;
    });

    const noMatchRow = document.getElementById('adminUserNoMatchRow');

    // 2. Filtern
    const matchedRows = [];
    rows.forEach((row) => {
        const uid = row.getAttribute('data-user-id');
        const drawer = document.getElementById('userEditDrawer-' + uid);
        tbody.appendChild(row);
        if (drawer) tbody.appendChild(drawer);

        const hay = (row.getAttribute('data-user-search') || '').toLowerCase();
        const matchesQuery = q === '' || hay.includes(q);

        let matchesRole = true;
        if (currentUsersRoleFilter === 'admin') {
            matchesRole = row.getAttribute('data-user-is-admin') === '1';
        } else if (currentUsersRoleFilter === 'locked') {
            matchesRole = row.getAttribute('data-user-is-locked') === '1';
        } else if (currentUsersRoleFilter === '2fa') {
            matchesRole = row.getAttribute('data-user-has-2fa') === '1';
        } else if (currentUsersRoleFilter === 'sharing') {
            matchesRole = row.getAttribute('data-user-sharing') === '1';
        }

        if (matchesQuery && matchesRole) {
            matchedRows.push(row);
        } else {
            row.classList.add('hidden');
            if (drawer) drawer.classList.add('hidden');
        }
    });

    if (noMatchRow) {
        tbody.appendChild(noMatchRow);
        noMatchRow.classList.toggle('hidden', matchedRows.length > 0);
    }

    // 3. Paginieren
    const totalMatches = matchedRows.length;
    const totalPages = Math.max(1, Math.ceil(totalMatches / pageSize));
    if (currentUsersPage > totalPages) {
        currentUsersPage = totalPages;
    }
    const startIdx = (currentUsersPage - 1) * pageSize;
    const endIdx = startIdx + pageSize;

    matchedRows.forEach((row, idx) => {
        const uid = row.getAttribute('data-user-id');
        const drawer = document.getElementById('userEditDrawer-' + uid);
        if (idx >= startIdx && idx < endIdx) {
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
            if (drawer) drawer.classList.add('hidden');
        }
    });

    const badge = document.getElementById('adminUserCountBadge');
    if (badge) badge.textContent = String(totalMatches);

    const infoEl = document.getElementById('usersPaginationInfo');
    if (infoEl) {
        if (totalMatches === 0) {
            infoEl.textContent = '0 Treffer';
        } else {
            infoEl.textContent = `Zeige ${startIdx + 1}–${Math.min(endIdx, totalMatches)} von ${totalMatches} Benutzern`;
        }
    }
    const pageInd = document.getElementById('usersPageIndicator');
    if (pageInd) pageInd.textContent = `Seite ${currentUsersPage} / ${totalPages}`;

    const prevBtn = document.getElementById('btnUsersPrevPage');
    const nextBtn = document.getElementById('btnUsersNextPage');
    if (prevBtn) prevBtn.disabled = currentUsersPage <= 1;
    if (nextBtn) nextBtn.disabled = currentUsersPage >= totalPages;
}

(() => {
    const initialHash = (window.location.hash || '').replace(/^#/, '');
    if (['tab-groups', 'groups', 'group-folders'].includes(initialHash)) {
        switchUsersTab('tab-groups', false);
    } else if (['tab-registration', 'registration'].includes(initialHash)) {
        switchUsersTab('tab-registration', false);
    } else {
        switchUsersTab('tab-accounts', false);
    }
    applyUsersTableFilter();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
