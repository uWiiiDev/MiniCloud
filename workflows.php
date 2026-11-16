<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_workflows'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('warning', 'Das Workflow- & Automatisierungs-Modul ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

ensureWorkflowSchema($pdo);

$userId    = (int)$_SESSION['user_id'];
$username  = (string)($_SESSION['username'] ?? 'Benutzer');
$isAdmin   = isAdmin();
$csrfToken = getCsrfToken();
$templates = getWorkflowTemplatesCatalog();

// Aktuelle E-Mail-Adresse des Benutzers als Vorschlag laden
$stmtUserMail = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
$stmtUserMail->execute([':id' => $userId]);
$userDefaultEmail = trim((string)($stmtUserMail->fetchColumn() ?: ''));

// ============================================================================
// POST-Aktionen verarbeiten
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($postCsrf) ? $postCsrf : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte versuchen Sie es erneut.');
        header('Location: workflows.php');
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    // 1. Fertige Vorlage mit 1 Klick anlegen
    if ($action === 'create_from_template') {
        $tplKey = trim((string)($_POST['template_key'] ?? ''));
        if (!isset($templates[$tplKey])) {
            setFlash('error', 'Die gewählte Workflow-Vorlage wurde nicht gefunden.');
            header('Location: workflows.php');
            exit;
        }
        $tpl = $templates[$tplKey];
        $emailTo = trim((string)($tpl['act_send_email_to'] ?? ''));
        if ($emailTo === '' && $tplKey === 'tax_email_forward' && $userDefaultEmail !== '') {
            $emailTo = $userDefaultEmail;
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO `workflows` (
                `user_id`, `name`, `icon`, `category_label`, `is_active`,
                `trigger_on_upload`, `show_in_file_menu`, `stop_processing`, `priority_order`,
                `cond_match_mode`, `cond_source_folder`, `cond_extensions`,
                `cond_filename_keywords`, `cond_content_keywords`, `cond_content_match_mode`,
                `cond_min_size_kb`, `cond_min_age_days`,
                `act_set_favorite`, `act_add_comment`, `act_rename_pattern`,
                `act_move_folder`, `act_copy_folder`, `act_compress_zip`,
                `act_send_email_to`, `act_send_email_subject`, `act_send_email_body`, `act_send_email_mode`,
                `act_create_task_title`, `act_create_task_due_days`, `act_create_task_priority`,
                `act_match_banking`
            ) VALUES (
                :uid, :name, :icon, :cat, 1,
                :trig_up, :show_menu, :stop_proc, 10,
                :c_mode, :c_folder, :c_ext,
                :c_fn_kw, :c_txt_kw, :c_txt_mode,
                :c_min_kb, :c_min_age,
                :a_fav, :a_com, :a_ren,
                :a_move, :a_copy, :a_zip,
                :a_mail_to, :a_mail_sub, :a_mail_body, :a_mail_mode,
                :a_task_title, :a_task_days, :a_task_prio,
                :a_bank
            )
        ");
        $stmtIns->execute([
            ':uid'          => $userId,
            ':name'         => (string)$tpl['name'],
            ':icon'         => (string)$tpl['icon'],
            ':cat'          => (string)$tpl['category_label'],
            ':trig_up'      => (int)$tpl['trigger_on_upload'],
            ':show_menu'    => (int)$tpl['show_in_file_menu'],
            ':stop_proc'    => (int)$tpl['stop_processing'],
            ':c_mode'       => (string)$tpl['cond_match_mode'],
            ':c_folder'     => (string)$tpl['cond_source_folder'],
            ':c_ext'        => (string)$tpl['cond_extensions'],
            ':c_fn_kw'      => (string)$tpl['cond_filename_keywords'],
            ':c_txt_kw'     => (string)$tpl['cond_content_keywords'],
            ':c_txt_mode'   => (string)$tpl['cond_content_match_mode'],
            ':c_min_kb'     => (int)$tpl['cond_min_size_kb'],
            ':c_min_age'    => (int)$tpl['cond_min_age_days'],
            ':a_fav'        => (int)$tpl['act_set_favorite'],
            ':a_com'        => (string)$tpl['act_add_comment'],
            ':a_ren'        => (string)$tpl['act_rename_pattern'],
            ':a_move'       => (string)$tpl['act_move_folder'],
            ':a_copy'       => (string)$tpl['act_copy_folder'],
            ':a_zip'        => (int)$tpl['act_compress_zip'],
            ':a_mail_to'    => $emailTo,
            ':a_mail_sub'   => (string)$tpl['act_send_email_subject'],
            ':a_mail_body'  => (string)$tpl['act_send_email_body'],
            ':a_mail_mode'  => (string)$tpl['act_send_email_mode'],
            ':a_task_title' => (string)$tpl['act_create_task_title'],
            ':a_task_days'  => (int)$tpl['act_create_task_due_days'],
            ':a_task_prio'  => (string)$tpl['act_create_task_priority'],
            ':a_bank'       => (int)$tpl['act_match_banking'],
        ]);

        setFlash('success', sprintf('⚡ Workflow-Vorlage „%s“ wurde aktiviert! Sie können sie jederzeit anpassen.', (string)$tpl['name']));
        header('Location: workflows.php');
        exit;
    }

    // 2. Workflow neu anlegen oder aktualisieren
    if ($action === 'save_workflow') {
        $wfId            = (int)($_POST['workflow_id'] ?? 0);
        $name            = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 190, 'UTF-8');
        $icon            = mb_substr(trim((string)($_POST['icon'] ?? '⚡')), 0, 16, 'UTF-8') ?: '⚡';
        $categoryLabel   = mb_substr(trim((string)($_POST['category_label'] ?? '')), 0, 120, 'UTF-8');
        $isActive        = isset($_POST['is_active']) ? 1 : 0;
        $triggerOnUpload = isset($_POST['trigger_on_upload']) ? 1 : 0;
        $showInFileMenu  = isset($_POST['show_in_file_menu']) ? 1 : 0;
        $stopProcessing  = isset($_POST['stop_processing']) ? 1 : 0;
        $priorityOrder   = max(1, min(999, (int)($_POST['priority_order'] ?? 10)));

        $condMatchMode = (string)($_POST['cond_match_mode'] ?? 'all');
        if (!in_array($condMatchMode, ['all', 'any'], true)) {
            $condMatchMode = 'all';
        }
        $rawSourceFolder = trim((string)($_POST['cond_source_folder'] ?? ''));
        $condSourceFolder = ($rawSourceFolder === '__root__' || $rawSourceFolder === '')
            ? $rawSourceFolder
            : sanitizeFolderPathInput($rawSourceFolder);

        $condExtensions       = mb_substr(strtolower(trim((string)($_POST['cond_extensions'] ?? ''))), 0, 255, 'UTF-8');
        $condFilenameKeywords = mb_substr(trim((string)($_POST['cond_filename_keywords'] ?? '')), 0, 500, 'UTF-8');
        $condContentKeywords  = mb_substr(trim((string)($_POST['cond_content_keywords'] ?? '')), 0, 1000, 'UTF-8');
        $condContentMatchMode = (string)($_POST['cond_content_match_mode'] ?? 'any');
        if (!in_array($condContentMatchMode, ['any', 'all'], true)) {
            $condContentMatchMode = 'any';
        }
        $condMinSizeKb  = max(0, (int)($_POST['cond_min_size_kb'] ?? 0));
        $condMinAgeDays = max(0, (int)($_POST['cond_min_age_days'] ?? 0));

        $actSetFavorite   = isset($_POST['act_set_favorite']) ? 1 : 0;
        $actAddComment    = mb_substr(trim((string)($_POST['act_add_comment'] ?? '')), 0, 500, 'UTF-8');
        $actRenamePattern = mb_substr(trim((string)($_POST['act_rename_pattern'] ?? '')), 0, 255, 'UTF-8');
        $actMoveFolder    = mb_substr(trim((string)($_POST['act_move_folder'] ?? '')), 0, 500, 'UTF-8');
        $actCopyFolder    = mb_substr(trim((string)($_POST['act_copy_folder'] ?? '')), 0, 500, 'UTF-8');
        $actCompressZip   = isset($_POST['act_compress_zip']) ? 1 : 0;

        $actSendEmailTo      = mb_substr(trim((string)($_POST['act_send_email_to'] ?? '')), 0, 255, 'UTF-8');
        $actSendEmailSubject = mb_substr(trim((string)($_POST['act_send_email_subject'] ?? '')), 0, 255, 'UTF-8');
        $actSendEmailBody    = trim((string)($_POST['act_send_email_body'] ?? ''));
        $actSendEmailMode    = (string)($_POST['act_send_email_mode'] ?? 'attachment_or_link');
        if (!in_array($actSendEmailMode, ['attachment_or_link', 'share_link', 'info_only'], true)) {
            $actSendEmailMode = 'attachment_or_link';
        }

        $actCreateTaskTitle    = mb_substr(trim((string)($_POST['act_create_task_title'] ?? '')), 0, 255, 'UTF-8');
        $actCreateTaskDueDays  = max(0, min(365, (int)($_POST['act_create_task_due_days'] ?? 7)));
        $actCreateTaskPriority = (string)($_POST['act_create_task_priority'] ?? 'medium');
        if (!in_array($actCreateTaskPriority, ['low', 'medium', 'high', 'urgent'], true)) {
            $actCreateTaskPriority = 'medium';
        }
        $actMatchBanking = isset($_POST['act_match_banking']) ? 1 : 0;

        if ($name === '') {
            setFlash('error', 'Bitte geben Sie einen Namen für den Workflow ein.');
            header('Location: workflows.php');
            exit;
        }
        if ($actSendEmailTo !== '' && filter_var($actSendEmailTo, FILTER_VALIDATE_EMAIL) === false) {
            setFlash('error', 'Bitte geben Sie eine gültige Empfänger-E-Mail-Adresse für den E-Mail-Versand an.');
            header('Location: workflows.php');
            exit;
        }

        $params = [
            ':name'         => $name,
            ':icon'         => $icon,
            ':cat'          => $categoryLabel,
            ':active'       => $isActive,
            ':trig_up'      => $triggerOnUpload,
            ':show_menu'    => $showInFileMenu,
            ':stop_proc'    => $stopProcessing,
            ':prio_ord'     => $priorityOrder,
            ':c_mode'       => $condMatchMode,
            ':c_folder'     => $condSourceFolder,
            ':c_ext'        => $condExtensions,
            ':c_fn_kw'      => $condFilenameKeywords,
            ':c_txt_kw'     => $condContentKeywords,
            ':c_txt_mode'   => $condContentMatchMode,
            ':c_min_kb'     => $condMinSizeKb,
            ':c_min_age'    => $condMinAgeDays,
            ':a_fav'        => $actSetFavorite,
            ':a_com'        => $actAddComment,
            ':a_ren'        => $actRenamePattern,
            ':a_move'       => $actMoveFolder,
            ':a_copy'       => $actCopyFolder,
            ':a_zip'        => $actCompressZip,
            ':a_mail_to'    => $actSendEmailTo,
            ':a_mail_sub'   => $actSendEmailSubject,
            ':a_mail_body'  => $actSendEmailBody,
            ':a_mail_mode'  => $actSendEmailMode,
            ':a_task_title' => $actCreateTaskTitle,
            ':a_task_days'  => $actCreateTaskDueDays,
            ':a_task_prio'  => $actCreateTaskPriority,
            ':a_bank'       => $actMatchBanking,
        ];

        if ($wfId > 0) {
            $params[':id']  = $wfId;
            $params[':uid'] = $userId;
            $stmtUpd = $pdo->prepare("
                UPDATE `workflows`
                SET `name` = :name, `icon` = :icon, `category_label` = :cat,
                    `is_active` = :active, `trigger_on_upload` = :trig_up, `show_in_file_menu` = :show_menu,
                    `stop_processing` = :stop_proc, `priority_order` = :prio_ord,
                    `cond_match_mode` = :c_mode, `cond_source_folder` = :c_folder, `cond_extensions` = :c_ext,
                    `cond_filename_keywords` = :c_fn_kw, `cond_content_keywords` = :c_txt_kw, `cond_content_match_mode` = :c_txt_mode,
                    `cond_min_size_kb` = :c_min_kb, `cond_min_age_days` = :c_min_age,
                    `act_set_favorite` = :a_fav, `act_add_comment` = :a_com, `act_rename_pattern` = :a_ren,
                    `act_move_folder` = :a_move, `act_copy_folder` = :a_copy, `act_compress_zip` = :a_zip,
                    `act_send_email_to` = :a_mail_to, `act_send_email_subject` = :a_mail_sub,
                    `act_send_email_body` = :a_mail_body, `act_send_email_mode` = :a_mail_mode,
                    `act_create_task_title` = :a_task_title, `act_create_task_due_days` = :a_task_days,
                    `act_create_task_priority` = :a_task_prio, `act_match_banking` = :a_bank
                WHERE `id` = :id AND `user_id` = :uid
            ");
            $stmtUpd->execute($params);
            setFlash('success', sprintf('Workflow „%s“ wurde gespeichert.', $name));
        } else {
            $params[':uid'] = $userId;
            $stmtIns = $pdo->prepare("
                INSERT INTO `workflows` (
                    `user_id`, `name`, `icon`, `category_label`,
                    `is_active`, `trigger_on_upload`, `show_in_file_menu`, `stop_processing`, `priority_order`,
                    `cond_match_mode`, `cond_source_folder`, `cond_extensions`,
                    `cond_filename_keywords`, `cond_content_keywords`, `cond_content_match_mode`,
                    `cond_min_size_kb`, `cond_min_age_days`,
                    `act_set_favorite`, `act_add_comment`, `act_rename_pattern`,
                    `act_move_folder`, `act_copy_folder`, `act_compress_zip`,
                    `act_send_email_to`, `act_send_email_subject`, `act_send_email_body`, `act_send_email_mode`,
                    `act_create_task_title`, `act_create_task_due_days`, `act_create_task_priority`, `act_match_banking`
                ) VALUES (
                    :uid, :name, :icon, :cat,
                    :active, :trig_up, :show_menu, :stop_proc, :prio_ord,
                    :c_mode, :c_folder, :c_ext,
                    :c_fn_kw, :c_txt_kw, :c_txt_mode,
                    :c_min_kb, :c_min_age,
                    :a_fav, :a_com, :a_ren,
                    :a_move, :a_copy, :a_zip,
                    :a_mail_to, :a_mail_sub, :a_mail_body, :a_mail_mode,
                    :a_task_title, :a_task_days, :a_task_prio, :a_bank
                )
            ");
            $stmtIns->execute($params);
            setFlash('success', sprintf('Neuer Workflow „%s“ wurde angelegt.', $name));
        }

        header('Location: workflows.php');
        exit;
    }

    // 3. Aktiv-Status umschalten
    if ($action === 'toggle_active') {
        $wfId = (int)($_POST['workflow_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, name, is_active FROM workflows WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute([':id' => $wfId, ':uid' => $userId]);
        $wf = $stmt->fetch();
        if ($wf) {
            $newState = ((int)$wf['is_active'] === 1) ? 0 : 1;
            $pdo->prepare('UPDATE workflows SET is_active = :st WHERE id = :id')->execute([':st' => $newState, ':id' => $wfId]);
            setFlash('success', sprintf('Workflow „%s“ wurde %s.', (string)$wf['name'], $newState === 1 ? 'aktiviert' : 'pausiert'));
        }
        header('Location: workflows.php');
        exit;
    }

    // 4. Workflow löschen
    if ($action === 'delete_workflow') {
        $wfId = (int)($_POST['workflow_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, name FROM workflows WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute([':id' => $wfId, ':uid' => $userId]);
        $wf = $stmt->fetch();
        if ($wf) {
            $pdo->prepare('DELETE FROM workflows WHERE id = :id AND user_id = :uid')->execute([':id' => $wfId, ':uid' => $userId]);
            setFlash('success', sprintf('Workflow „%s“ wurde gelöscht.', (string)$wf['name']));
        }
        header('Location: workflows.php');
        exit;
    }

    // 5. Einzelne Datei (oder Mehrfachauswahl) direkt aus dem Datei-Manager (index.php) per 1-Klick-Makro ausführen
    if ($action === 'execute_single') {
        $wfId         = (int)($_POST['workflow_id'] ?? 0);
        $singleFileId = (int)($_POST['file_id'] ?? 0);
        $rawBatchIds  = isset($_POST['batch_file_ids']) && trim((string)$_POST['batch_file_ids']) !== ''
            ? explode(',', (string)$_POST['batch_file_ids'])
            : [];
        $returnFolder = isset($_POST['return_folder']) ? sanitizeFolderPathInput((string)$_POST['return_folder']) : '';
        $returnGfId   = isset($_POST['group_folder_id']) ? (int)$_POST['group_folder_id'] : 0;

        $stmtWf = $pdo->prepare('SELECT * FROM workflows WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmtWf->execute([':id' => $wfId, ':uid' => $userId]);
        $wf = $stmtWf->fetch();

        $redirectParams = [];
        if ($returnGfId > 0) {
            $redirectParams['group_folder'] = (string)$returnGfId;
        }
        if ($returnFolder !== '') {
            $redirectParams['folder'] = $returnFolder;
        }
        $backUrl = 'index.php' . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : '');

        if (!$wf) {
            setFlash('error', 'Workflow nicht gefunden.');
            header('Location: ' . $backUrl);
            exit;
        }

        $targetIds = [];
        if ($singleFileId > 0) {
            $targetIds[] = $singleFileId;
        }
        foreach ($rawBatchIds as $rid) {
            $idVal = (int)trim((string)$rid);
            if ($idVal > 0 && !in_array($idVal, $targetIds, true)) {
                $targetIds[] = $idVal;
            }
        }

        $processedFiles = 0;
        $actionDetails  = [];
        foreach ($targetIds as $fid) {
            $authFile = getAuthorizedFileForDownload($pdo, $userId, $fid, $isAdmin);
            if (!$authFile) {
                continue;
            }
            $res = executeWorkflowOnFile($pdo, $wf, $fid, $userId, 'manual');
            if ($res['ok']) {
                $processedFiles++;
                foreach ($res['executed_actions'] as $actMsg) {
                    $actionDetails[$actMsg] = $actMsg;
                }
            }
        }

        if ($processedFiles > 0) {
            setFlash(
                'success',
                sprintf(
                    '⚡ Workflow „%s“ auf %d %s ausgeführt: %s',
                    (string)$wf['name'],
                    $processedFiles,
                    $processedFiles === 1 ? 'Datei' : 'Dateien',
                    implode(' • ', array_slice(array_values($actionDetails), 0, 5))
                )
            );
        } else {
            setFlash('warning', sprintf('Workflow „%s“ hat keine Änderungen an der Auswahl vorgenommen.', (string)$wf['name']));
        }

        header('Location: ' . $backUrl);
        exit;
    }

    // 6. Aufräum-Lauf: Workflows jetzt auf bestehenden Ordner / bestehende Dateien anwenden
    if ($action === 'run_folder_batch') {
        $selectedWfId   = (int)($_POST['workflow_id'] ?? 0); // 0 = alle aktiven Workflows
        $scanFolderRaw  = trim((string)($_POST['scan_folder'] ?? ''));
        $ignoreCondFold = isset($_POST['ignore_cond_folder']);

        if ($selectedWfId > 0) {
            $stmtWfs = $pdo->prepare('SELECT * FROM workflows WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmtWfs->execute([':id' => $selectedWfId, ':uid' => $userId]);
            $workflowsToRun = $stmtWfs->fetchAll();
        } else {
            $stmtWfs = $pdo->prepare('SELECT * FROM workflows WHERE user_id = :uid AND is_active = 1 ORDER BY priority_order ASC, id ASC');
            $stmtWfs->execute([':uid' => $userId]);
            $workflowsToRun = $stmtWfs->fetchAll();
        }

        if (empty($workflowsToRun)) {
            setFlash('warning', 'Keine aktiven Workflows zum Ausführen vorhanden.');
            header('Location: workflows.php');
            exit;
        }

        // Zu prüfende Dateien des Benutzers laden
        if ($scanFolderRaw === '__root__') {
            $stmtFiles = $pdo->prepare(
                "SELECT * FROM files WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL AND folder_path = '' ORDER BY id DESC LIMIT 300"
            );
            $stmtFiles->execute([':uid' => $userId]);
        } elseif ($scanFolderRaw !== '' && $scanFolderRaw !== '__all__') {
            $cleanScanFolder = sanitizeFolderPathInput($scanFolderRaw);
            $stmtFiles = $pdo->prepare(
                'SELECT * FROM files
                 WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL
                   AND (folder_path = :exact OR folder_path LIKE :prefix)
                 ORDER BY id DESC LIMIT 300'
            );
            $stmtFiles->execute([
                ':uid'    => $userId,
                ':exact'  => $cleanScanFolder,
                ':prefix' => $cleanScanFolder . '/%',
            ]);
        } else {
            $stmtFiles = $pdo->prepare(
                'SELECT * FROM files WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 300'
            );
            $stmtFiles->execute([':uid' => $userId]);
        }

        $candidateFiles = $stmtFiles->fetchAll();
        $matchedFilesCount = 0;
        $totalActionsRun   = 0;

        foreach ($candidateFiles as $fRow) {
            $fid = (int)$fRow['id'];
            $cachedContentText = null;
            $fileMatchedAny = false;

            foreach ($workflowsToRun as $wf) {
                if (doesWorkflowMatchFile($pdo, $wf, $fRow, $cachedContentText, $ignoreCondFold)) {
                    $res = executeWorkflowOnFile($pdo, $wf, $fid, $userId, 'batch');
                    if ($res['ok']) {
                        $fileMatchedAny = true;
                        $totalActionsRun += count($res['executed_actions']);
                        $fid = (int)$res['file_id'];
                        $stmtReload = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
                        $stmtReload->execute([':id' => $fid]);
                        $fRow = $stmtReload->fetch();
                        if (!$fRow) {
                            break;
                        }
                    }
                    if ((int)($wf['stop_processing'] ?? 0) === 1) {
                        break;
                    }
                }
            }

            if ($fileMatchedAny) {
                $matchedFilesCount++;
            }
        }

        if ($matchedFilesCount > 0) {
            setFlash(
                'success',
                sprintf(
                    '✅ Ordner-Lauf abgeschlossen: %d %s erkannt und %d Workflow-Aktion(en) automatisch ausgeführt.',
                    $matchedFilesCount,
                    $matchedFilesCount === 1 ? 'Datei wurde' : 'Dateien wurden',
                    $totalActionsRun
                )
            );
        } else {
            setFlash(
                'warning',
                sprintf(
                    'Ordner-Lauf abgeschlossen (%d Dateien geprüft): Keine Datei entsprach den gewählten Workflow-Bedingungen.',
                    count($candidateFiles)
                )
            );
        }

        header('Location: workflows.php');
        exit;
    }

    // 7. Ausführungs-Protokoll leeren
    if ($action === 'clear_logs') {
        $pdo->prepare('DELETE FROM workflow_logs WHERE user_id = :uid')->execute([':uid' => $userId]);
        setFlash('success', 'Das Workflow-Protokoll wurde geleert.');
        header('Location: workflows.php');
        exit;
    }
}

// ============================================================================
// Daten für die Ansicht laden
// ============================================================================
$stmtWfs = $pdo->prepare('SELECT * FROM workflows WHERE user_id = :uid ORDER BY priority_order ASC, id ASC');
$stmtWfs->execute([':uid' => $userId]);
$myWorkflows = $stmtWfs->fetchAll();

$stmtLogs = $pdo->prepare('SELECT * FROM workflow_logs WHERE user_id = :uid ORDER BY id DESC LIMIT 50');
$stmtLogs->execute([':uid' => $userId]);
$workflowLogs = $stmtLogs->fetchAll();

// Vorhandene persönliche Ordner für das Ordner-Auswahlfeld laden
$stmtFolders = $pdo->prepare(
    "SELECT DISTINCT folder_path FROM (
        SELECT folder_path FROM files WHERE user_id = :u1 AND group_folder_id IS NULL AND deleted_at IS NULL AND folder_path <> ''
        UNION
        SELECT folder_path FROM user_folders WHERE user_id = :u2 AND group_folder_id IS NULL AND folder_path <> ''
     ) AS t ORDER BY folder_path ASC"
);
$stmtFolders->execute([':u1' => $userId, ':u2' => $userId]);
$existingPersonalFolders = $stmtFolders->fetchAll(PDO::FETCH_COLUMN);

$activeWorkflowsCount = 0;
$uploadWorkflowsCount = 0;
$menuWorkflowsCount   = 0;
$totalExecutionsCount = 0;
foreach ($myWorkflows as $w) {
    if ((int)$w['is_active'] === 1) {
        $activeWorkflowsCount++;
        if ((int)$w['trigger_on_upload'] === 1) {
            $uploadWorkflowsCount++;
        }
        if ((int)$w['show_in_file_menu'] === 1) {
            $menuWorkflowsCount++;
        }
    }
    $totalExecutionsCount += (int)$w['run_count'];
}

$pageTitle = 'Smart-Workflows & Automatisierung';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Kopfbereich -->
<div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs mb-6">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div class="flex items-start sm:items-center gap-3.5">
            <div class="w-12 h-12 rounded-2xl bg-amber-100 dark:bg-amber-950/80 border border-amber-200 dark:border-amber-800/70 text-amber-600 dark:text-amber-300 flex items-center justify-center text-2xl shrink-0 shadow-2xs">
                ⚡
            </div>
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex flex-wrap items-center gap-2">
                    <span>Smart-Workflows &amp; Automatisierung</span>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                        <?= $activeWorkflowsCount ?> aktiv
                    </span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Dokumente automatisch nach Volltext &amp; Dateiname kategorisieren, umbenennen, in Ordner sortieren, per E-Mail versenden oder mit Kontoauszügen &amp; Aufgaben verknüpfen.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 shrink-0">
            <button
                type="button"
                onclick="openBatchRunModal(0)"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors cursor-pointer"
                title="Wendet Ihre Workflows nachträglich auf bereits hochgeladene Dateien an"
            >
                <span>📂</span>
                <span>Aufräum-Lauf starten</span>
            </button>
            <button
                type="button"
                onclick="openWorkflowEditorModal(null)"
                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs transition-colors cursor-pointer"
            >
                <span>➕</span>
                <span>Neuer Workflow</span>
            </button>
        </div>
    </div>

    <!-- 4 kompakte Status-Kacheln -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-5 pt-5 border-t border-slate-200/80 dark:border-slate-800">
        <div class="p-3.5 rounded-xl bg-slate-50/80 dark:bg-slate-800/50 border border-slate-200/70 dark:border-slate-700/70">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Aktive Regeln</p>
            <p class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white mt-0.5">
                <?= $activeWorkflowsCount ?> <span class="text-xs font-normal text-slate-400">/ <?= count($myWorkflows) ?> gesamt</span>
            </p>
        </div>
        <div class="p-3.5 rounded-xl bg-emerald-50/60 dark:bg-emerald-950/30 border border-emerald-200/70 dark:border-emerald-800/60">
            <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">📤 Beim Datei-Upload</p>
            <p class="text-lg sm:text-xl font-extrabold text-emerald-900 dark:text-emerald-200 mt-0.5">
                <?= $uploadWorkflowsCount ?> <span class="text-xs font-normal text-emerald-700/80 dark:text-emerald-300/80">automatisch</span>
            </p>
        </div>
        <div class="p-3.5 rounded-xl bg-indigo-50/60 dark:bg-indigo-950/30 border border-indigo-200/70 dark:border-indigo-800/60">
            <p class="text-[11px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-400">⚡ Im ⋯ Datei-Menü</p>
            <p class="text-lg sm:text-xl font-extrabold text-indigo-900 dark:text-indigo-200 mt-0.5">
                <?= $menuWorkflowsCount ?> <span class="text-xs font-normal text-indigo-700/80 dark:text-indigo-300/80">Schnellaktionen</span>
            </p>
        </div>
        <div class="p-3.5 rounded-xl bg-amber-50/60 dark:bg-amber-950/30 border border-amber-200/70 dark:border-amber-800/60">
            <p class="text-[11px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">🚀 Ausführungen</p>
            <p class="text-lg sm:text-xl font-extrabold text-amber-900 dark:text-amber-200 mt-0.5">
                <?= $totalExecutionsCount ?> <span class="text-xs font-normal text-amber-700/80 dark:text-amber-300/80">Dokumente verarbeitet</span>
            </p>
        </div>
    </div>
</div>

<!-- 1. MEINE WORKFLOWS & REGELN -->
<section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden mb-6">
    <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2">
        <div>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                📋 Meine Workflows &amp; Automatisierungs-Regeln (<?= count($myWorkflows) ?>)
            </h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Regeln mit „📤 Upload-Automatik“ prüfen jede neu hochgeladene Datei sofort im Hintergrund (inkl. PDF-/Word-Volltext).
            </p>
        </div>
        <a href="index.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline shrink-0">
            📂 Zum Datei-Manager &rarr;
        </a>
    </div>

    <?php if (empty($myWorkflows)): ?>
        <div class="p-8 sm:p-10 text-center space-y-3">
            <div class="mx-auto w-12 h-12 rounded-2xl bg-amber-100 dark:bg-amber-950/70 text-amber-600 dark:text-amber-300 flex items-center justify-center text-2xl">
                ⚡
            </div>
            <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                Noch keine eigenen Workflows eingerichtet
            </h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 max-w-lg mx-auto">
                Wählen Sie unten einfach eine der fertigen <strong>1-Klick-Vorlagen</strong> (z. B. <em>Rechnungen automatisch sortieren</em> oder <em>Beleg per E-Mail an Steuerberater senden</em>) oder erstellen Sie eine eigene Regel.
            </p>
        </div>
    <?php else: ?>
        <div class="divide-y divide-slate-200/80 dark:divide-slate-800">
            <?php foreach ($myWorkflows as $wf): ?>
                <?php
                $wfId      = (int)$wf['id'];
                $isActive  = (int)$wf['is_active'] === 1;
                $trigUp    = (int)$wf['trigger_on_upload'] === 1;
                $showMenu  = (int)$wf['show_in_file_menu'] === 1;
                $catLabel  = trim((string)$wf['category_label']);

                // Bedingungen lesbar zusammenfassen
                $condBadges = [];
                if ($wf['cond_source_folder'] === '__root__') {
                    $condBadges[] = '📂 Nur im Hauptverzeichnis';
                } elseif (trim((string)$wf['cond_source_folder']) !== '') {
                    $condBadges[] = '📂 Ordner: ' . (string)$wf['cond_source_folder'];
                }
                if (trim((string)$wf['cond_extensions']) !== '') {
                    $condBadges[] = '📄 Typ: .' . str_replace(',', ', .', (string)$wf['cond_extensions']);
                }
                if (trim((string)$wf['cond_filename_keywords']) !== '') {
                    $condBadges[] = '🔤 Dateiname enthält: „' . (string)$wf['cond_filename_keywords'] . '“';
                }
                if (trim((string)$wf['cond_content_keywords']) !== '') {
                    $modeWord = ($wf['cond_content_match_mode'] ?? 'any') === 'all' ? 'ALLE' : 'EINES';
                    $condBadges[] = '🔎 Volltext (' . $modeWord . '): „' . mb_substr((string)$wf['cond_content_keywords'], 0, 70) . (mb_strlen((string)$wf['cond_content_keywords']) > 70 ? '…' : '') . '“';
                }
                if ((int)$wf['cond_min_size_kb'] > 0) {
                    $condBadges[] = '📏 ≥ ' . (int)$wf['cond_min_size_kb'] . ' KB';
                }
                if ((int)$wf['cond_min_age_days'] > 0) {
                    $condBadges[] = '⏳ Älter als ' . (int)$wf['cond_min_age_days'] . ' Tage';
                }
                if (empty($condBadges)) {
                    $condBadges[] = '🌐 Alle Dateien';
                }

                // Aktionen lesbar zusammenfassen
                $actBadges = [];
                if ($catLabel !== '') {
                    $actBadges[] = '🏷️ Kategorie: ' . $catLabel;
                }
                if (trim((string)$wf['act_rename_pattern']) !== '') {
                    $actBadges[] = '✏️ Umbenennen: ' . (string)$wf['act_rename_pattern'];
                }
                if (trim((string)$wf['act_move_folder']) !== '') {
                    $actBadges[] = '📁 Verschieben ➔ ' . (string)$wf['act_move_folder'];
                }
                if (trim((string)$wf['act_copy_folder']) !== '') {
                    $actBadges[] = '📋 Kopie ➔ ' . (string)$wf['act_copy_folder'];
                }
                if (trim((string)$wf['act_send_email_to']) !== '') {
                    $actBadges[] = '📧 E-Mail ➔ ' . (string)$wf['act_send_email_to'];
                }
                if (trim((string)$wf['act_create_task_title']) !== '') {
                    $actBadges[] = '✅ Aufgabe anlegen (+' . (int)$wf['act_create_task_due_days'] . 'd)';
                }
                if ((int)$wf['act_match_banking'] === 1) {
                    $actBadges[] = '🏦 Auto-Beleg-Verknüpfung (Konto)';
                }
                if ((int)$wf['act_set_favorite'] === 1) {
                    $actBadges[] = '⭐ Als Favorit markieren';
                }
                if ((int)$wf['act_compress_zip'] === 1) {
                    $actBadges[] = '🗜️ Als ZIP archivieren';
                }
                ?>
                <div class="p-4 sm:p-5 flex flex-col lg:flex-row lg:items-center justify-between gap-4 <?= $isActive ? 'hover:bg-slate-50/70 dark:hover:bg-slate-800/40' : 'opacity-60 bg-slate-50/40 dark:bg-slate-900/40' ?> transition-colors">
                    <div class="flex items-start gap-3.5 min-w-0 flex-1">
                        <div class="w-10 h-10 rounded-xl bg-amber-100/80 dark:bg-amber-950/70 text-amber-700 dark:text-amber-300 flex items-center justify-center text-xl shrink-0 mt-0.5">
                            <?= e((string)$wf['icon']) ?>
                        </div>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                                    <?= e((string)$wf['name']) ?>
                                </h3>
                                <?php if ($isActive): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                        ● Aktiv
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                                        ⏸ Pausiert
                                    </span>
                                <?php endif; ?>

                                <?php if ($trigUp): ?>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-sky-100 dark:bg-sky-950 text-sky-700 dark:text-sky-300" title="Läuft automatisch bei jedem Datei-Upload">
                                        📤 Upload-Automatik
                                    </span>
                                <?php endif; ?>
                                <?php if ($showMenu): ?>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300" title="Als 1-Klick-Schnellaktion im ⋯ Menü jeder Datei verfügbar">
                                        ⚡ Im ⋯ Datei-Menü
                                    </span>
                                <?php endif; ?>
                                <?php if ((int)$wf['run_count'] > 0): ?>
                                    <span class="text-[11px] text-slate-400 dark:text-slate-500">
                                        &bull; <?= (int)$wf['run_count'] ?>× ausgeführt<?= !empty($wf['last_run_at']) ? ' (zuletzt ' . date('d.m.Y H:i', strtotime((string)$wf['last_run_at'])) . ')' : '' ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- WENN -> DANN Visualisierung -->
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 text-xs">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-[10px] font-extrabold uppercase tracking-wider px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                        WENN
                                    </span>
                                    <?php foreach ($condBadges as $cb): ?>
                                        <span class="px-2 py-0.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200/80 dark:border-slate-700 text-[11px]">
                                            <?= e($cb) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>

                                <span class="hidden sm:inline text-slate-400 font-bold">&rarr;</span>

                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-[10px] font-extrabold uppercase tracking-wider px-1.5 py-0.5 rounded bg-amber-500 text-slate-950">
                                        DANN
                                    </span>
                                    <?php foreach ($actBadges as $ab): ?>
                                        <span class="px-2 py-0.5 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-900 dark:text-amber-200 border border-amber-200/80 dark:border-amber-800/70 text-[11px] font-medium">
                                            <?= e($ab) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Aktions-Buttons -->
                    <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                        <button
                            type="button"
                            onclick="openBatchRunModal(<?= $wfId ?>)"
                            class="px-2.5 py-1.5 rounded-xl bg-teal-50 dark:bg-teal-950/60 hover:bg-teal-100 dark:hover:bg-teal-900/70 text-teal-700 dark:text-teal-300 text-xs font-semibold transition-colors cursor-pointer"
                            title="Diesen Workflow jetzt auf bestehende Dateien in einem Ordner anwenden"
                        >
                            ▶️ Jetzt ausführen
                        </button>

                        <button
                            type="button"
                            onclick='openWorkflowEditorModal(<?= e((string)json_encode($wf, JSON_UNESCAPED_UNICODE)) ?>)'
                            class="px-2.5 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/70 text-indigo-700 dark:text-indigo-300 text-xs font-semibold transition-colors cursor-pointer"
                        >
                            ✏️ Bearbeiten
                        </button>

                        <form action="workflows.php" method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="workflow_id" value="<?= $wfId ?>">
                            <button
                                type="submit"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold transition-colors cursor-pointer"
                                title="<?= $isActive ? 'Workflow vorübergehend pausieren' : 'Workflow wieder aktivieren' ?>"
                            >
                                <?= $isActive ? '⏸ Pausieren' : '▶️ Aktivieren' ?>
                            </button>
                        </form>

                        <form action="workflows.php" method="post" class="inline" onsubmit="return confirm('Möchten Sie den Workflow &quot;<?= e(addslashes((string)$wf['name'])) ?>&quot; wirklich löschen?');">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_workflow">
                            <input type="hidden" name="workflow_id" value="<?= $wfId ?>">
                            <button
                                type="submit"
                                class="px-2.5 py-1.5 rounded-xl bg-red-50 dark:bg-red-950/60 hover:bg-red-100 dark:hover:bg-red-900/70 text-red-600 dark:text-red-300 text-xs font-semibold transition-colors cursor-pointer"
                                title="Workflow löschen"
                            >
                                🗑️
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- 2. SCHNELLSTART-VORLAGEN (1-Klick-Aktivierung) -->
<section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs mb-6">
    <div class="mb-4">
        <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
            <span>✨ Fertige 1-Klick-Workflow-Vorlagen</span>
        </h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
            Mit einem Klick übernehmen und nach Ihren Wünschen (z. B. Zielordner, Schlagworte oder Empfänger-E-Mail) anpassen.
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($templates as $tplKey => $tpl): ?>
            <div class="flex flex-col justify-between p-4 rounded-2xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 hover:border-amber-400 dark:hover:border-amber-500 transition-all">
                <div class="space-y-2">
                    <div class="flex items-start justify-between gap-2">
                        <span class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 flex items-center justify-center text-xl shrink-0">
                            <?= e((string)$tpl['icon']) ?>
                        </span>
                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-100/80 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300">
                            🏷️ <?= e((string)$tpl['category_label']) ?>
                        </span>
                    </div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">
                        <?= e((string)$tpl['name']) ?>
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                        <?= e((string)$tpl['desc']) ?>
                    </p>
                </div>

                <div class="flex items-center gap-2 pt-4 mt-4 border-t border-slate-200/70 dark:border-slate-700/70">
                    <button
                        type="button"
                        onclick='openWorkflowEditorModal(<?= e((string)json_encode(array_merge($tpl, ['id' => 0, 'is_active' => 1, 'priority_order' => 10, 'act_send_email_to' => ($tplKey === 'tax_email_forward' ? $userDefaultEmail : '')]), JSON_UNESCAPED_UNICODE)) ?>)'
                        class="flex-1 px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-bold text-center transition-colors cursor-pointer"
                    >
                        ⚙️ Anpassen &amp; anlegen
                    </button>
                    <form action="workflows.php" method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_from_template">
                        <input type="hidden" name="template_key" value="<?= e($tplKey) ?>">
                        <button
                            type="submit"
                            class="px-3 py-1.5 rounded-xl bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-100 text-xs font-semibold transition-colors cursor-pointer"
                            title="Sofort mit den Standardwerten aktivieren"
                        >
                            ➕ Sofort
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- 3. AUSFÜHRUNGS-PROTOKOLL (Workflow-Log) -->
<section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2">
        <div>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                📜 Ausführungs-Protokoll (Letzte 50 Aktivitäten)
            </h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Hier sehen Sie jederzeit nachvollziehbar, welche Datei durch welchen Workflow verschoben, umbenannt oder versendet wurde.
            </p>
        </div>
        <?php if (!empty($workflowLogs)): ?>
            <form action="workflows.php" method="post" onsubmit="return confirm('Möchten Sie das Ausführungs-Protokoll wirklich leeren?');">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="clear_logs">
                <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-950/50 text-slate-600 dark:text-slate-300 hover:text-red-600 text-xs font-semibold transition-colors cursor-pointer">
                    🧹 Protokoll leeren
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($workflowLogs)): ?>
        <div class="p-6 text-center text-xs text-slate-400">
            Noch keine Workflow-Ausführungen protokolliert.
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Zeitpunkt</th>
                        <th class="py-3 px-4">Auslöser</th>
                        <th class="py-3 px-4">Datei</th>
                        <th class="py-3 px-4">Workflow</th>
                        <th class="py-3 px-4">Ausgeführte Aktionen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800">
                    <?php foreach ($workflowLogs as $log): ?>
                        <?php
                        $tType = (string)$log['trigger_type'];
                        $tBadge = match ($tType) {
                            'manual' => ['label' => '⚡ 1-Klick-Menü', 'cls' => 'bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300'],
                            'batch'  => ['label' => '📂 Ordner-Lauf',  'cls' => 'bg-teal-100 dark:bg-teal-950 text-teal-700 dark:text-teal-300'],
                            default  => ['label' => '📤 Upload',       'cls' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300'],
                        };
                        ?>
                        <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                            <td class="py-3 px-4 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                                <?= e(date('d.m.Y H:i', strtotime((string)$log['created_at']) ?: time())) ?> Uhr
                            </td>
                            <td class="py-3 px-4 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?= $tBadge['cls'] ?>">
                                    <?= e($tBadge['label']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 font-semibold text-slate-900 dark:text-white">
                                📄 <?= e((string)$log['file_name']) ?>
                            </td>
                            <td class="py-3 px-4 font-medium text-amber-700 dark:text-amber-300">
                                <?= e((string)$log['workflow_name']) ?>
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-600 dark:text-slate-300">
                                <?= e((string)$log['actions_summary']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- ======================================================================== -->
<!-- MODAL 1: WORKFLOW ERSTELLEN / BEARBEITEN (#workflowEditorModal)          -->
<!-- ======================================================================== -->
<div
    id="workflowEditorModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-3xl rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden max-h-[92vh] flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 flex items-center justify-center text-lg font-bold">
                    ⚡
                </span>
                <div>
                    <h3 id="wfModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                        Workflow konfigurieren
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        WENN Bedingungen zutreffen &rarr; DANN Aktionen automatisch ausführen
                    </p>
                </div>
            </div>
            <button type="button" onclick="closeWorkflowEditorModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 cursor-pointer">
                ✕
            </button>
        </div>

        <form action="workflows.php" method="post" class="p-5 overflow-y-auto space-y-5 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_workflow">
            <input type="hidden" name="workflow_id" id="wf_id" value="0">

            <!-- Grunddaten & Auslöser -->
            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Icon</label>
                    <input type="text" name="icon" id="wf_icon" value="⚡" maxlength="8" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-center text-base">
                </div>
                <div class="sm:col-span-6">
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Name des Workflows *</label>
                    <input type="text" name="name" id="wf_name" required placeholder="z.B. Telekom-Rechnungen ablegen & per Mail senden" class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white font-semibold">
                </div>
                <div class="sm:col-span-4">
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">🏷️ Kategorie-Label (optional)</label>
                    <input type="text" name="category_label" id="wf_category_label" placeholder="z.B. Rechnung, Steuer, Vertrag" class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4 p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 text-xs">
                <label class="inline-flex items-center gap-2 font-semibold text-slate-800 dark:text-slate-200 cursor-pointer">
                    <input type="checkbox" name="is_active" id="wf_is_active" value="1" checked class="w-4 h-4 rounded text-brand-600">
                    <span>✅ Regel ist aktiv</span>
                </label>
                <label class="inline-flex items-center gap-2 font-semibold text-slate-800 dark:text-slate-200 cursor-pointer">
                    <input type="checkbox" name="trigger_on_upload" id="wf_trigger_on_upload" value="1" checked class="w-4 h-4 rounded text-brand-600">
                    <span>📤 Automatisch beim Datei-Upload ausführen</span>
                </label>
                <label class="inline-flex items-center gap-2 font-semibold text-slate-800 dark:text-slate-200 cursor-pointer">
                    <input type="checkbox" name="show_in_file_menu" id="wf_show_in_file_menu" value="1" checked class="w-4 h-4 rounded text-brand-600">
                    <span>⚡ Als 1-Klick-Schnellaktion im <code>⋯</code> Datei-Menü zeigen</span>
                </label>
                <label class="inline-flex items-center gap-2 text-slate-600 dark:text-slate-400 cursor-pointer">
                    <input type="checkbox" name="stop_processing" id="wf_stop_processing" value="1" class="w-4 h-4 rounded text-brand-600">
                    <span>🛑 Nach Treffer keine weiteren Upload-Regeln prüfen</span>
                </label>
            </div>

            <!-- TEIL 1: WENN (Bedingungen) -->
            <div class="p-4 rounded-2xl bg-slate-50/90 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 space-y-3.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 class="text-xs sm:text-sm font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-200 flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900 text-[10px]">WENN</span>
                        <span>Filter &amp; Erkennungs-Bedingungen (leer lassen = gilt für alle Dateien)</span>
                    </h4>
                    <select name="cond_match_mode" id="wf_cond_match_mode" class="px-2.5 py-1 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                        <option value="all">Alle ausgefüllten Bedingungen müssen zutreffen (UND)</option>
                        <option value="any">Mindestens eine Bedingung muss zutreffen (ODER)</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">📂 Quellordner-Einschränkung</label>
                        <input
                            type="text"
                            name="cond_source_folder"
                            id="wf_cond_source_folder"
                            list="personalFoldersDatalist"
                            placeholder="Leer = Überall (oder __root__ für Hauptverzeichnis)"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">📄 Dateiendungen (Komma-getrennt)</label>
                        <input
                            type="text"
                            name="cond_extensions"
                            id="wf_cond_extensions"
                            placeholder="z.B. pdf, docx, xlsx, epub (leer = alle)"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                        >
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">🔤 Dateiname enthält eines dieser Wörter (Komma-getrennt)</label>
                    <input
                        type="text"
                        name="cond_filename_keywords"
                        id="wf_cond_filename_keywords"
                        placeholder="z.B. Rechnung, Beleg, Scan, Telekom"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                    >
                </div>

                <div>
                    <div class="flex flex-wrap items-center justify-between gap-1 mb-1">
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">🔎 Dokument-Volltext (PDF, Word, Excel, Text) enthält Schlagwörter</label>
                        <select name="cond_content_match_mode" id="wf_cond_content_match_mode" class="px-2 py-0.5 rounded bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-[11px]">
                            <option value="any">Mindestens eines der Wörter (ODER)</option>
                            <option value="all">Alle angegebenen Wörter (UND)</option>
                        </select>
                    </div>
                    <input
                        type="text"
                        name="cond_content_keywords"
                        id="wf_cond_content_keywords"
                        placeholder="z.B. Rechnungsnummer, IBAN, MwSt, Gesamtbetrag, Kundennummer"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                    >
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">📏 Mindest-Dateigröße (in KB, 0 = egal)</label>
                        <input type="number" min="0" name="cond_min_size_kb" id="wf_cond_min_size_kb" value="0" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">⏳ Mindestalter der Datei in Tagen (für Aufräum-Läufe, 0 = egal)</label>
                        <input type="number" min="0" name="cond_min_age_days" id="wf_cond_min_age_days" value="0" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                    </div>
                </div>
            </div>

            <!-- TEIL 2: DANN (Aktionen) -->
            <div class="p-4 rounded-2xl bg-amber-50/50 dark:bg-amber-950/20 border border-amber-200/80 dark:border-amber-800/60 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 class="text-xs sm:text-sm font-extrabold uppercase tracking-wider text-amber-900 dark:text-amber-200 flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded bg-amber-500 text-slate-950 text-[10px]">DANN</span>
                        <span>Automatisch auszuführende Aktionen (beliebig kombinierbar)</span>
                    </h4>
                    <span class="text-[11px] text-amber-800/80 dark:text-amber-300/80">
                        Platzhalter: <code>{DATUM}</code>, <code>{JAHR}</code>, <code>{MONAT}</code>, <code>{NAME}</code>, <code>{DATEINAME}</code>, <code>{KATEGORIE}</code>, <code>{SHARE_LINK}</code>
                    </span>
                </div>

                <!-- Sortieren & Umbenennen -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">📁 Automatisch verschieben in Zielordner</label>
                        <input
                            type="text"
                            name="act_move_folder"
                            id="wf_act_move_folder"
                            list="personalFoldersDatalist"
                            placeholder="z.B. Finanzen/Rechnungen/{JAHR} (existiert er nicht, wird er angelegt)"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">✏️ Automatisch umbenennen (Muster)</label>
                        <input
                            type="text"
                            name="act_rename_pattern"
                            id="wf_act_rename_pattern"
                            placeholder="z.B. {DATUM}_{KATEGORIE}_{NAME} (Endung bleibt erhalten)"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                        >
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">💬 Notiz / Kategorie-Kommentar an Datei anheften</label>
                        <input
                            type="text"
                            name="act_add_comment"
                            id="wf_act_add_comment"
                            placeholder="z.B. 🏷️ Automatisch kategorisiert als {KATEGORIE} ({DATUM})"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">📋 Zusätzliche Backup-Kopie in Ordner legen (optional)</label>
                        <input
                            type="text"
                            name="act_copy_folder"
                            id="wf_act_copy_folder"
                            list="personalFoldersDatalist"
                            placeholder="z.B. Backup/{JAHR}"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                        >
                    </div>
                </div>

                <!-- E-Mail-Versand -->
                <div class="p-3.5 rounded-xl bg-white/80 dark:bg-slate-900/70 border border-slate-200 dark:border-slate-700 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs font-bold text-slate-800 dark:text-slate-100">📧 Automatischer Versand per vordefinierter E-Mail</span>
                        <select name="act_send_email_mode" id="wf_act_send_email_mode" class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                            <option value="attachment_or_link">📎 Datei als E-Mail-Anhang mitsenden (+ Freigabelink)</option>
                            <option value="share_link">🔗 Nur sicheren MiniCloud-Freigabelink (14 Tage) senden</option>
                            <option value="info_only">ℹ️ Nur Benachrichtigungs-Text senden</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Empfänger-E-Mail-Adresse</label>
                            <input
                                type="email"
                                name="act_send_email_to"
                                id="wf_act_send_email_to"
                                placeholder="z.B. steuerberater@kanzlei.de"
                                class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                            >
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">E-Mail-Betreff</label>
                            <input
                                type="text"
                                name="act_send_email_subject"
                                id="wf_act_send_email_subject"
                                placeholder="z.B. [Beleg {DATUM}] {DATEINAME}"
                                class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                            >
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Nachrichtentext (<code>{SHARE_LINK}</code> fügt automatisch den Direktlink ein)</label>
                        <textarea
                            name="act_send_email_body"
                            id="wf_act_send_email_body"
                            rows="3"
                            placeholder="Hallo,&#10;&#10;anbei erhalten Sie automatisch das Dokument „{DATEINAME}“ ({GROESSE}) vom {DATUM}.&#10;&#10;Download-Link: {SHARE_LINK}"
                            class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                        ></textarea>
                    </div>
                </div>

                <!-- Aufgabe / To-Do & Modul-Verknüpfungen -->
                <div class="p-3.5 rounded-xl bg-white/80 dark:bg-slate-900/70 border border-slate-200 dark:border-slate-700 space-y-3">
                    <span class="text-xs font-bold text-slate-800 dark:text-slate-100 block">✅ Aufgabe / Zahlungserinnerung &amp; Modul-Aktionen</span>
                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                        <div class="sm:col-span-6">
                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Aufgaben-Titel in To-Do-Liste anlegen (leer = keine Aufgabe)</label>
                            <input
                                type="text"
                                name="act_create_task_title"
                                id="wf_act_create_task_title"
                                placeholder="z.B. 💸 Rechnung prüfen & überweisen: {DATEINAME}"
                                class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                            >
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Fällig in (Tagen)</label>
                            <input type="number" min="0" max="365" name="act_create_task_due_days" id="wf_act_create_task_due_days" value="7" class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Priorität</label>
                            <select name="act_create_task_priority" id="wf_act_create_task_priority" class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="low">🟢 Niedrig</option>
                                <option value="medium" selected>🔵 Mittel</option>
                                <option value="high">🟠 Hoch</option>
                                <option value="urgent">🔴 Dringend</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 pt-2 border-t border-slate-200/70 dark:border-slate-800 text-xs">
                        <label class="inline-flex items-center gap-2 font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input type="checkbox" name="act_match_banking" id="wf_act_match_banking" value="1" class="w-4 h-4 rounded text-brand-600">
                            <span>🏦 Passende Bankbuchung suchen &amp; PDF automatisch als Beleg anhängen</span>
                        </label>
                        <label class="inline-flex items-center gap-2 font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input type="checkbox" name="act_set_favorite" id="wf_act_set_favorite" value="1" class="w-4 h-4 rounded text-brand-600">
                            <span>⭐ Datei als Favorit markieren</span>
                        </label>
                        <label class="inline-flex items-center gap-2 font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input type="checkbox" name="act_compress_zip" id="wf_act_compress_zip" value="1" class="w-4 h-4 rounded text-brand-600">
                            <span>🗜️ Als <code>.zip</code> komprimieren &amp; Original in Papierkorb legen</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button
                    type="button"
                    onclick="closeWorkflowEditorModal()"
                    class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs sm:text-sm font-semibold cursor-pointer"
                >
                    Abbrechen
                </button>
                <button
                    type="submit"
                    class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs cursor-pointer"
                >
                    💾 Workflow speichern
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================== -->
<!-- MODAL 2: AUFRÄUM-LAUF AUF BESTEHENDEN ORDner ANWENDEN (#batchRunModal)   -->
<!-- ======================================================================== -->
<div
    id="batchRunModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-lg rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-xl bg-teal-100 dark:bg-teal-950 text-teal-700 dark:text-teal-300 flex items-center justify-center text-lg font-bold">
                    📂
                </span>
                <div>
                    <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                        Aufräum-Lauf auf bestehende Dateien
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Prüft bereits hochgeladene Dateien und führt passende Workflows sofort aus
                    </p>
                </div>
            </div>
            <button type="button" onclick="closeBatchRunModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 cursor-pointer">
                ✕
            </button>
        </div>

        <form action="workflows.php" method="post" class="p-5 space-y-4 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="run_folder_batch">

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Welcher Workflow soll ausgeführt werden?</label>
                <select name="workflow_id" id="batchRunWfSelect" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white font-semibold">
                    <option value="0">⚡ Alle aktiven Workflows der Reihe nach prüfen (<?= $activeWorkflowsCount ?>)</option>
                    <?php foreach ($myWorkflows as $wfOpt): ?>
                        <option value="<?= (int)$wfOpt['id'] ?>">
                            <?= e((string)$wfOpt['icon'] . ' ' . (string)$wfOpt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Welche bestehenden Dateien sollen geprüft werden?</label>
                <select name="scan_folder" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                    <option value="__root__">🏠 Nur Dateien direkt im Hauptverzeichnis (unsortierter Posteingang)</option>
                    <option value="__all__">🌐 Alle persönlichen Ordner &amp; Dateien durchsuchen</option>
                    <?php foreach ($existingPersonalFolders as $pf): ?>
                        <option value="<?= e((string)$pf) ?>">📁 Ordner: <?= e((string)$pf) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="flex items-start gap-2.5 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 text-xs cursor-pointer">
                <input type="checkbox" name="ignore_cond_folder" value="1" checked class="mt-0.5 w-4 h-4 rounded text-teal-600">
                <span>
                    <strong>Quellordner-Bedingung für diesen manuellen Lauf ignorieren</strong><br>
                    <span class="text-slate-500 dark:text-slate-400">Damit werden Dateien im oben gewählten Ordner auch dann sortiert, wenn die Regel sonst auf einen anderen Eingangsordner eingestellt ist.</span>
                </span>
            </label>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeBatchRunModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs sm:text-sm font-semibold cursor-pointer">
                    Abbrechen
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-xs sm:text-sm font-bold shadow-xs cursor-pointer">
                    🚀 Jetzt prüfen &amp; ausführen
                </button>
            </div>
        </form>
    </div>
</div>

<datalist id="personalFoldersDatalist">
    <option value="Finanzen/Rechnungen/{JAHR}"></option>
    <option value="Steuer/{JAHR}"></option>
    <option value="Dokumente/Vertraege/{JAHR}"></option>
    <option value="Archiv/{JAHR}"></option>
    <option value="E-Books"></option>
    <?php foreach ($existingPersonalFolders as $pf): ?>
        <option value="<?= e((string)$pf) ?>"></option>
    <?php endforeach; ?>
</datalist>

<script>
const workflowEditorModal = document.getElementById('workflowEditorModal');
const batchRunModal       = document.getElementById('batchRunModal');

function openWorkflowEditorModal(wf) {
    if (!workflowEditorModal) return;
    const isEdit = Boolean(wf && Number(wf.id) > 0);
    document.getElementById('wfModalTitle').textContent = isEdit
        ? 'Workflow bearbeiten'
        : (wf ? 'Workflow-Vorlage anpassen & anlegen' : 'Neuen Workflow erstellen');

    document.getElementById('wf_id').value                       = wf ? Number(wf.id || 0) : 0;
    document.getElementById('wf_icon').value                     = (wf && wf.icon) ? wf.icon : '⚡';
    document.getElementById('wf_name').value                     = (wf && wf.name) ? wf.name : '';
    document.getElementById('wf_category_label').value           = (wf && wf.category_label) ? wf.category_label : '';
    document.getElementById('wf_is_active').checked              = !wf || Number(wf.is_active ?? 1) === 1;
    document.getElementById('wf_trigger_on_upload').checked      = !wf || Number(wf.trigger_on_upload ?? 1) === 1;
    document.getElementById('wf_show_in_file_menu').checked      = !wf || Number(wf.show_in_file_menu ?? 1) === 1;
    document.getElementById('wf_stop_processing').checked        = Boolean(wf && Number(wf.stop_processing) === 1);

    document.getElementById('wf_cond_match_mode').value          = (wf && wf.cond_match_mode) ? wf.cond_match_mode : 'all';
    document.getElementById('wf_cond_source_folder').value       = (wf && wf.cond_source_folder) ? wf.cond_source_folder : '';
    document.getElementById('wf_cond_extensions').value          = (wf && wf.cond_extensions) ? wf.cond_extensions : '';
    document.getElementById('wf_cond_filename_keywords').value   = (wf && wf.cond_filename_keywords) ? wf.cond_filename_keywords : '';
    document.getElementById('wf_cond_content_keywords').value    = (wf && wf.cond_content_keywords) ? wf.cond_content_keywords : '';
    document.getElementById('wf_cond_content_match_mode').value  = (wf && wf.cond_content_match_mode) ? wf.cond_content_match_mode : 'any';
    document.getElementById('wf_cond_min_size_kb').value         = wf ? Number(wf.cond_min_size_kb || 0) : 0;
    document.getElementById('wf_cond_min_age_days').value        = wf ? Number(wf.cond_min_age_days || 0) : 0;

    document.getElementById('wf_act_move_folder').value          = (wf && wf.act_move_folder) ? wf.act_move_folder : '';
    document.getElementById('wf_act_rename_pattern').value       = (wf && wf.act_rename_pattern) ? wf.act_rename_pattern : '';
    document.getElementById('wf_act_add_comment').value          = (wf && wf.act_add_comment) ? wf.act_add_comment : '';
    document.getElementById('wf_act_copy_folder').value          = (wf && wf.act_copy_folder) ? wf.act_copy_folder : '';
    document.getElementById('wf_act_send_email_mode').value      = (wf && wf.act_send_email_mode) ? wf.act_send_email_mode : 'attachment_or_link';
    document.getElementById('wf_act_send_email_to').value        = (wf && wf.act_send_email_to) ? wf.act_send_email_to : '';
    document.getElementById('wf_act_send_email_subject').value   = (wf && wf.act_send_email_subject) ? wf.act_send_email_subject : '';
    document.getElementById('wf_act_send_email_body').value      = (wf && wf.act_send_email_body) ? wf.act_send_email_body : '';
    document.getElementById('wf_act_create_task_title').value    = (wf && wf.act_create_task_title) ? wf.act_create_task_title : '';
    document.getElementById('wf_act_create_task_due_days').value = wf ? Number(wf.act_create_task_due_days ?? 7) : 7;
    document.getElementById('wf_act_create_task_priority').value = (wf && wf.act_create_task_priority) ? wf.act_create_task_priority : 'medium';
    document.getElementById('wf_act_match_banking').checked      = Boolean(wf && Number(wf.act_match_banking) === 1);
    document.getElementById('wf_act_set_favorite').checked       = Boolean(wf && Number(wf.act_set_favorite) === 1);
    document.getElementById('wf_act_compress_zip').checked       = Boolean(wf && Number(wf.act_compress_zip) === 1);

    workflowEditorModal.classList.remove('hidden');
    workflowEditorModal.classList.add('flex');
    setTimeout(() => document.getElementById('wf_name')?.focus(), 40);
}

function closeWorkflowEditorModal() {
    if (!workflowEditorModal) return;
    workflowEditorModal.classList.add('hidden');
    workflowEditorModal.classList.remove('flex');
}

function openBatchRunModal(wfId) {
    if (!batchRunModal) return;
    const sel = document.getElementById('batchRunWfSelect');
    if (sel) sel.value = String(wfId || 0);
    batchRunModal.classList.remove('hidden');
    batchRunModal.classList.add('flex');
}

function closeBatchRunModal() {
    if (!batchRunModal) return;
    batchRunModal.classList.add('hidden');
    batchRunModal.classList.remove('flex');
}

if (workflowEditorModal) {
    workflowEditorModal.addEventListener('click', (e) => {
        if (e.target === workflowEditorModal) closeWorkflowEditorModal();
    });
}
if (batchRunModal) {
    batchRunModal.addEventListener('click', (e) => {
        if (e.target === batchRunModal) closeBatchRunModal();
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
