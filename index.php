<?php
// XPVERSE - SINGLE FILE ARCHITECTURE (UPGRADED)
// All server-side logic and persistence is handled here.

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);

function logAppError($uid, $type, $message, $cause, $page) {
    global $dataDir;
    $path = "$dataDir/errors.json";
    $errors = [];
    if (file_exists($path)) {
        $fp = fopen($path, 'r');
        if ($fp) {
            flock($fp, LOCK_SH);
            $size = filesize($path);
            if ($size > 0) $errors = json_decode(fread($fp, $size), true) ?: [];
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
    array_unshift($errors, [
        'id' => uniqid('err_'),
        'uid' => $uid,
        'type' => $type,
        'message' => $message,
        'cause' => $cause,
        'page' => $page,
        'time' => time()
    ]);
    $errors = array_slice($errors, 0, 1000);
    
    $fp = fopen($path, 'c');
    if ($fp) {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($errors, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logAppError('SERVER', 'PHP Error', $errstr, "File: $errfile on line $errline", 'Backend');
    return true; 
});
set_exception_handler(function($ex) {
    logAppError('SERVER', 'PHP Exception', $ex->getMessage(), "File: ".$ex->getFile()." on line ".$ex->getLine(), 'Backend');
});

function readDB($filename, $default = []) {
    global $dataDir;
    $path = "$dataDir/$filename";
    if (!file_exists($path)) return $default;
    
    $fp = fopen($path, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $size = filesize($path);
    $json = $size > 0 ? fread($fp, $size) : json_encode($default);
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return json_decode($json, true) ?: $default;
}

function writeDB($filename, $data) {
    global $dataDir;
    $path = "$dataDir/$filename";
    $fp = fopen($path, 'c');
    if (!$fp) {
        logAppError('SERVER', 'DB Write Error', "Failed to open $filename", "Permission/Disk Space", 'Backend');
        return false;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

// 10-15 API fail-safe fallback keys included as default
$defaultSettings = [
    'adBlockIds' => ['int-35545', 'int-35546', 'int-35547', 'int-35548', 'int-35549', 'int-35550', 'int-35551', 'int-35552', 'int-35553', 'int-35554'],
    'globalAdLimit' => 30,
    'resetTime' => '03:00',
    'timezone' => 'Europe/Moscow',
    'maintenance' => false,
    'xp2xEnabled' => false,
    'xp2xPermanent' => false,
    'xp2xStart' => '',
    'xp2xEnd' => ''
];
$settings = readDB('settings.json', $defaultSettings);

date_default_timezone_set($settings['timezone'] ?? 'Europe/Moscow');
$now = new DateTime('now');
list($resetHour, $resetMin) = explode(':', $settings['resetTime'] ?? '03:00');
$resetHour = (int)$resetHour; $resetMin = (int)$resetMin;

$logicalDate = clone $now;
$currentHour = (int)$now->format('H');
$currentMin = (int)$now->format('i');
if ($currentHour < $resetHour || ($currentHour === $resetHour && $currentMin < $resetMin)) {
    $logicalDate->modify('-1 day');
}
$today = $logicalDate->format('Y-m-d');

$xpMulti = 1;
if (!empty($settings['xp2xEnabled'])) {
    if (!empty($settings['xp2xPermanent'])) {
        $xpMulti = 2;
    } else {
        $st = strtotime($settings['xp2xStart']);
        $ed = strtotime($settings['xp2xEnd']);
        if (time() >= $st && time() <= $ed) $xpMulti = 2;
    }
}
$settings['activeXpMulti'] = $xpMulti;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    $action = $input['action'];
    $uid = isset($input['tgId']) ? (string)$input['tgId'] : 'UNKNOWN';
    
    if ($action === 'log_client_error') {
        logAppError($uid, 'Client Error', $input['msg'] ?? 'Unknown', $input['cause'] ?? '', $input['page'] ?? 'Frontend');
        echo json_encode(['success' => true]); exit;
    }

    $admins = readDB('admins.json', ['5461064199' => true]);
    $bannedUsers = readDB('banned.json', []);
    $users = readDB('users.json', []);
    
    $isAdmin = isset($admins[$uid]);

    if (!$isAdmin && (isset($bannedUsers[$uid]) || (isset($users[$uid]['banned']) && $users[$uid]['banned'] === true))) {
        echo json_encode(['error' => 'BANNED', 'message' => 'You are banned from accessing this bot.']);
        exit;
    }

    if (!$isAdmin && $settings['maintenance'] === true) {
        echo json_encode(['error' => 'MAINTENANCE', 'message' => 'System is currently under maintenance.']);
        exit;
    }

    $referrals = readDB('referrals.json', []);
    $rewards = readDB('rewards.json', []);
    $withdrawals = readDB('withdrawals.json', []);
    $tasksState = readDB('tasks.json', []);
    $dynamicTasks = readDB('tasks_db.json', []);
    $pendingTasks = readDB('pending_tasks.json', []);

    if ($uid !== 'UNKNOWN') {
        if (!isset($users[$uid])) {
            $users[$uid] = [
                'tgId' => $uid,
                'firstName' => $input['firstName'] ?? 'User',
                'lastName' => $input['lastName'] ?? '',
                'username' => $input['username'] ?? '',
                'photoUrl' => $input['photoUrl'] ?? '',
                'xp' => 0, 'totalXpEarned' => 0,
                'usd' => 0.00, 'totalUsdEarned' => 0.00, 'withdrawnUsd' => 0.00,
                'level' => 1, 'adsWatchedToday' => 0, 'totalAdsWatched' => 0,
                'tasksCompleted' => 0, 'boxesOpened' => 0, 'streak' => 1,
                'lastResetDay' => $today, 'referrer' => null,
                'lastActive' => time(), 'registrationDate' => time(),
                'banned' => false, 'customAdLimit' => null
            ];

            if (!empty($input['referrer']) && $input['referrer'] !== $uid) {
                $refId = (string)$input['referrer'];
                if (isset($users[$refId])) {
                    $users[$uid]['referrer'] = $refId;
                    if (!isset($referrals[$refId])) $referrals[$refId] = [];
                    $referrals[$refId][] = [
                        'uid' => $uid,
                        'name' => trim(($users[$uid]['firstName'] ?? '') . ' ' . ($users[$uid]['lastName'] ?? '')),
                        'username' => $users[$uid]['username'],
                        'status' => 'Pending', 'ads' => 0, 'tasks' => 0,
                        'joinDate' => $now->format('M j, Y')
                    ];
                    writeDB('referrals.json', $referrals);
                }
            }
        } else {
            if (!isset($users[$uid]['totalUsdEarned'])) $users[$uid]['totalUsdEarned'] = $users[$uid]['usd'];
            if (!isset($users[$uid]['withdrawnUsd'])) $users[$uid]['withdrawnUsd'] = 0;
            if (!isset($users[$uid]['totalXpEarned'])) $users[$uid]['totalXpEarned'] = $users[$uid]['xp'];
            if (!isset($users[$uid]['registrationDate'])) $users[$uid]['registrationDate'] = time();
            
            if (isset($input['firstName'])) $users[$uid]['firstName'] = $input['firstName'];
            if (isset($input['lastName'])) $users[$uid]['lastName'] = $input['lastName'];
            if (isset($input['username'])) $users[$uid]['username'] = $input['username'];
            if (isset($input['photoUrl']) && !empty($input['photoUrl'])) $users[$uid]['photoUrl'] = $input['photoUrl'];
            $users[$uid]['lastActive'] = time();
        }

        if ($users[$uid]['lastResetDay'] !== $today) {
            $lastDay = strtotime($users[$uid]['lastResetDay']);
            $currDay = strtotime($today);
            $diff = round(($currDay - $lastDay) / 86400);
            
            if ($diff === 1) { $users[$uid]['streak'] = min($users[$uid]['streak'] + 1, 7); } 
            else { $users[$uid]['streak'] = 1; }
            
            $users[$uid]['adsWatchedToday'] = 0;
            $users[$uid]['lastResetDay'] = $today;
            
            if (isset($tasksState[$uid])) {
                $tasksState[$uid]['dailyLogin'] = false;
                $tasksState[$uid]['watch5'] = false;
                $tasksState[$uid]['watch15'] = false;
                $tasksState[$uid]['watch30'] = false;
                $tasksState[$uid]['complete_all'] = false;
                
                foreach ($dynamicTasks as $dt) {
                    if (!empty($dt['isDaily']) && isset($tasksState[$uid]['completed'][$dt['id']])) {
                        unset($tasksState[$uid]['completed'][$dt['id']]);
                    }
                }
            }
        }
    }

    function grantUserReward($uid, &$users, $xpReward, $usdReward, $multiplier = 1) {
        $finalXp = $xpReward * $multiplier;
        $users[$uid]['xp'] += $finalXp;
        $users[$uid]['totalXpEarned'] += $finalXp;
        $users[$uid]['usd'] += $usdReward;
        $users[$uid]['totalUsdEarned'] += $usdReward;
        return ['xp' => $finalXp, 'usd' => $usdReward];
    }

    function evaluateReferralProgress($refUid, &$users, &$referrals, &$rewards) {
        global $now;
        $refUser = $users[$refUid];
        if (empty($refUser['referrer'])) return;
        
        $referrerId = $refUser['referrer'];
        if (!isset($referrals[$referrerId])) return;
        
        $referralChanged = false; $rewardAdded = false;
        
        foreach ($referrals[$referrerId] as &$r) {
            if ($r['uid'] === $refUid && $r['status'] === 'Pending') {
                if ($r['ads'] !== $refUser['totalAdsWatched'] || $r['tasks'] !== $refUser['tasksCompleted']) {
                    $r['ads'] = $refUser['totalAdsWatched'];
                    $r['tasks'] = $refUser['tasksCompleted'];
                    $referralChanged = true;
                }
                if ($r['ads'] >= 25 && $r['tasks'] >= 5) {
                    $r['status'] = 'Approved';
                    $r['approvedAt'] = $now->format('M j, Y');
                    $referralChanged = true; $rewardAdded = true;
                    
                    grantUserReward($referrerId, $users, 250, 0.025, 1); 
                    
                    if (!isset($rewards[$referrerId])) $rewards[$referrerId] = [];
                    $refName = trim(($refUser['firstName'] ?? '') . ' ' . ($refUser['lastName'] ?? ''));
                    array_unshift($rewards[$referrerId], [
                        'title' => 'Referral Bonus',
                        'desc' => "Referral: " . $refName,
                        'xp' => 250, 'usd' => 0.025,
                        'date' => $now->format('M j, Y')
                    ]);
                }
                break;
            }
        }
        if ($referralChanged) writeDB('referrals.json', $referrals);
        if ($rewardAdded) writeDB('rewards.json', $rewards);
    }

    $response = ['success' => true];
    
    $resetTarget = clone $now;
    $resetTarget->setTime($resetHour, $resetMin, 0); 
    if ($currentHour > $resetHour || ($currentHour === $resetHour && $currentMin >= $resetMin)) {
        $resetTarget->modify('+1 day');
    }
    $response['serverTime'] = time();
    $response['serverResetTime'] = $resetTarget->getTimestamp(); 
    $response['settings'] = $settings;
    $response['isAdmin'] = $isAdmin;
    $response['bakuTime'] = (clone $now)->setTimezone(new DateTimeZone('Asia/Baku'))->format('H:i');

    if (strpos($action, 'admin_') === 0) {
        if (!$isAdmin) { echo json_encode(['error' => 'Security Breach: Unauthorized Access']); exit; }

        if ($action === 'admin_dashboard') {
            $totalUsd = 0; $totalAds = 0; $totalTasks = 0; $totalXp = 0; $totalUsers = count($users);
            $totalRefs = 0; $activeUsers = 0; $offlineUsers = 0;
            $bakuNow = time();

            foreach($users as $u) {
                $totalUsd += ($u['totalUsdEarned'] ?? 0);
                $totalAds += $u['totalAdsWatched'];
                $totalTasks += $u['tasksCompleted'];
                $totalXp += ($u['totalXpEarned'] ?? 0);
                if (($bakuNow - $u['lastActive']) < 300) { $activeUsers++; } else { $offlineUsers++; }
            }
            foreach($referrals as $rList) { $totalRefs += count($rList); }

            $allWithdrawals = [];
            foreach($withdrawals as $uId => $uWithdrawals) {
                foreach($uWithdrawals as $idx => $w) {
                    $w['user_id'] = $uId;
                    $w['user_name'] = isset($users[$uId]) ? trim($users[$uId]['firstName'] . ' ' . $users[$uId]['lastName']) : 'Unknown';
                    $w['idx'] = $idx;
                    $allWithdrawals[] = $w;
                }
            }
            
            $response['stats'] = [
                'users' => $totalUsers, 'active' => $activeUsers, 'offline' => $offlineUsers, 
                'usd' => $totalUsd, 'ads' => $totalAds, 'tasks' => $totalTasks, 
                'xp' => $totalXp, 'refs' => $totalRefs
            ];
            
            $adminUserList = [];
            foreach($users as $u) {
                $u['isOnline'] = (($bakuNow - $u['lastActive']) < 300);
                $u['lastActiveBaku'] = (new DateTime('@'.$u['lastActive']))->setTimezone(new DateTimeZone('Asia/Baku'))->format('d M Y, H:i');
                $u['regDateBaku'] = (new DateTime('@'.$u['registrationDate']))->setTimezone(new DateTimeZone('Asia/Baku'))->format('d M Y, H:i');
                
                $uRefs = $referrals[$u['tgId']] ?? [];
                $u['refTotal'] = count($uRefs);
                $u['refPending'] = count(array_filter($uRefs, fn($r) => $r['status'] === 'Pending'));
                $u['refApproved'] = count(array_filter($uRefs, fn($r) => $r['status'] === 'Approved'));
                
                $adminUserList[] = $u;
            }

            $response['all_users'] = $adminUserList;
            $response['all_withdrawals'] = $allWithdrawals;
            $response['dynamic_tasks'] = $dynamicTasks;
            $response['banned'] = array_keys($bannedUsers);
            
            $errors = readDB('errors.json', []);
            $response['errors'] = array_slice($errors, 0, 50); 
            
            echo json_encode($response); exit;
        }

        if ($action === 'admin_update_settings') {
            $settings['adBlockIds'] = array_map('trim', explode(',', $input['blockIds']));
            $settings['globalAdLimit'] = (int)$input['globalAdLimit'];
            $settings['resetTime'] = $input['resetTime'];
            $settings['maintenance'] = (bool)$input['maintenance'];
            $settings['xp2xEnabled'] = (bool)$input['xp2xEnabled'];
            $settings['xp2xPermanent'] = (bool)$input['xp2xPermanent'];
            $settings['xp2xStart'] = $input['xp2xStart'];
            $settings['xp2xEnd'] = $input['xp2xEnd'];
            writeDB('settings.json', $settings);
            $response['message'] = 'Ayarlar uğurla yeniləndi.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_action_user') {
            $targetUid = $input['targetUid'];
            $act = $input['userAction'];
            
            if ($act === 'preban') {
                $bannedUsers[$targetUid] = true;
                if(isset($users[$targetUid])) $users[$targetUid]['banned'] = true;
                writeDB('banned.json', $bannedUsers);
                writeDB('users.json', $users);
                echo json_encode(['success' => true, 'message' => "UID $targetUid bloklandı."]); exit;
            }
            if ($act === 'unban') {
                unset($bannedUsers[$targetUid]);
                if(isset($users[$targetUid])) $users[$targetUid]['banned'] = false;
                writeDB('banned.json', $bannedUsers);
                writeDB('users.json', $users);
                echo json_encode(['success' => true, 'message' => "UID $targetUid blokdan çıxarıldı."]); exit;
            }

            if (!isset($users[$targetUid])) { echo json_encode(['error' => 'İstifadəçi tapılmadı']); exit; }

            if ($act === 'reset_ads') {
                $users[$targetUid]['adsWatchedToday'] = 0;
                $response['message'] = 'İstifadəçinin reklam limiti sıfırlandı.';
            } elseif ($act === 'update_balance') {
                $changeUsd = (float)$input['newUsd'] - $users[$targetUid]['usd'];
                $users[$targetUid]['usd'] = max(0, (float)$input['newUsd']);
                if ($changeUsd > 0) $users[$targetUid]['totalUsdEarned'] += $changeUsd;

                $changeXp = (int)$input['newXp'] - $users[$targetUid]['xp'];
                $users[$targetUid]['xp'] = max(0, (int)$input['newXp']);
                if ($changeXp > 0) $users[$targetUid]['totalXpEarned'] += $changeXp;
                $response['message'] = 'Balans uğurla yeniləndi.';
            } elseif ($act === 'reset_balance') {
                $users[$targetUid]['usd'] = 0;
                $users[$targetUid]['xp'] = 0;
                $response['message'] = 'Balans sıfırlandı.';
            }
            writeDB('users.json', $users);
            echo json_encode($response); exit;
        }

        if ($action === 'admin_global_action') {
            $act = $input['globalAction'];
            if ($act === 'reset_all_ads') {
                foreach($users as $k => $u) $users[$k]['adsWatchedToday'] = 0;
                writeDB('users.json', $users);
                $response['message'] = 'Bütün reklam limitləri sıfırlandı.';
            } elseif ($act === 'reset_all_balances') {
                foreach($users as $k => $u) { $users[$k]['usd'] = 0; $users[$k]['xp'] = 0; }
                writeDB('users.json', $users);
                $response['message'] = 'Bütün istifadəçi balansları sıfırlandı!';
            }
            echo json_encode($response); exit;
        }

        if ($action === 'admin_manage_admin') {
            $targetUid = $input['targetUid'];
            $isAdd = (bool)$input['isAdd'];
            if ($isAdd) { $admins[$targetUid] = true; $response['message'] = "Admin əlavə edildi."; }
            else { unset($admins[$targetUid]); $response['message'] = "Admin silindi."; }
            writeDB('admins.json', $admins);
            echo json_encode($response); exit;
        }

        if ($action === 'admin_task_save') {
            $t = $input['task'];
            if (empty($t['id'])) $t['id'] = uniqid('task_');
            if (!isset($t['completions'])) $t['completions'] = 0;
            
            $exists = false;
            foreach ($dynamicTasks as $k => $dt) {
                if ($dt['id'] === $t['id']) { $dynamicTasks[$k] = $t; $exists = true; break; }
            }
            if (!$exists) array_push($dynamicTasks, $t);
            writeDB('tasks_db.json', $dynamicTasks);
            $response['message'] = 'Tapşırıq yadda saxlanıldı.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_task_delete') {
            $taskId = $input['taskId'];
            $dynamicTasks = array_filter($dynamicTasks, function($t) use ($taskId) { return $t['id'] !== $taskId; });
            writeDB('tasks_db.json', array_values($dynamicTasks));
            $response['message'] = 'Tapşırıq silindi.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_action_withdraw') {
            $targetUid = $input['targetUid'];
            $idx = $input['idx'];
            $wAct = $input['withdrawAction'];

            if (isset($withdrawals[$targetUid][$idx])) {
                if ($withdrawals[$targetUid][$idx]['status'] === 'Pending') {
                    if ($wAct === 'approve') {
                        $withdrawals[$targetUid][$idx]['status'] = 'Approved';
                    } else if ($wAct === 'reject') {
                        $withdrawals[$targetUid][$idx]['status'] = 'Rejected';
                        $users[$targetUid]['usd'] += $withdrawals[$targetUid][$idx]['amount'];
                        $users[$targetUid]['withdrawnUsd'] -= $withdrawals[$targetUid][$idx]['amount'];
                        writeDB('users.json', $users);
                    }
                    writeDB('withdrawals.json', $withdrawals);
                    $response['message'] = 'Çıxarış emal edildi.';
                } else {
                    echo json_encode(['error' => 'Artıq emal edilib']); exit;
                }
            } else {
                echo json_encode(['error' => 'Çıxarış tapılmadı']); exit;
            }
            echo json_encode($response); exit;
        }
    }

    $userLimit = $users[$uid]['customAdLimit'] !== null ? $users[$uid]['customAdLimit'] : (int)($settings['globalAdLimit'] ?? 30);
    if (!isset($tasksState[$uid])) {
        $tasksState[$uid] = ['dailyLogin' => false, 'watch5' => false, 'watch15' => false, 'watch30' => false, 'complete_all' => false, 'completed' => [], 'sponsorAzx' => false];
    }

    switch ($action) {
        case 'watch_ad':
            if ($users[$uid]['adsWatchedToday'] < $userLimit) {
                $users[$uid]['adsWatchedToday'] += 1;
                $users[$uid]['totalAdsWatched'] += 1;
                grantUserReward($uid, $users, 20, 0, $xpMulti);
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
            } else {
                $response['error'] = 'Daily ad limit reached';
            }
            break;

        case 'claim_static_task':
            $taskId = $input['taskId'] ?? '';
            
            if ($taskId === 'sponsor_azx' && empty($tasksState[$uid]['sponsorAzx'])) {
                $tasksState[$uid]['sponsorAzx'] = true;
                grantUserReward($uid, $users, 200, 0, $xpMulti);
                $users[$uid]['tasksCompleted'] += 1;
            } elseif (in_array($taskId, ['dailyLogin', 'watch5', 'watch15', 'watch30', 'complete_all'])) {
                if (empty($tasksState[$uid][$taskId])) {
                    $reward = (int)($input['reward'] ?? 0);
                    if ($taskId === 'complete_all' && $users[$uid]['adsWatchedToday'] < 30) { $response['error'] = 'Complete all daily tasks first.'; break; }
                    if ($taskId === 'watch5' && $users[$uid]['adsWatchedToday'] < 5) { $response['error'] = 'Not enough ads.'; break; }
                    if ($taskId === 'watch15' && $users[$uid]['adsWatchedToday'] < 15) { $response['error'] = 'Not enough ads.'; break; }
                    if ($taskId === 'watch30' && $users[$uid]['adsWatchedToday'] < 30) { $response['error'] = 'Not enough ads.'; break; }
                    
                    $tasksState[$uid][$taskId] = true;
                    $users[$uid]['tasksCompleted'] += 1;
                    grantUserReward($uid, $users, $reward, 0, $xpMulti);
                } else {
                    $response['error'] = 'Task already claimed today.';
                }
            }
            evaluateReferralProgress($uid, $users, $referrals, $rewards);
            writeDB('tasks.json', $tasksState);
            break;

        case 'claim_dynamic_task':
            $taskId = $input['taskId'];
            $proof = $input['proof'] ?? '';
            
            $task = null; foreach($dynamicTasks as $dt) { if($dt['id'] === $taskId) $task = $dt; }
            if (!$task) { $response['error'] = 'Task not found.'; break; }
            
            if (!empty($tasksState[$uid]['completed'][$taskId])) { $response['error'] = 'Task already completed.'; break; }
            
            if ((int)$task['completionLimit'] > 0 && (int)$task['completions'] >= (int)$task['completionLimit']) {
                $response['error'] = 'Task completion limit reached globally.'; break;
            }

            if (!empty($task['requireVerification'])) {
                if (empty($proof)) { $response['error'] = 'Proof/UID required.'; break; }
                if (!isset($pendingTasks[$taskId])) $pendingTasks[$taskId] = [];
                $pendingTasks[$taskId][] = ['uid' => $uid, 'proof' => $proof, 'time' => time(), 'status' => 'Pending'];
                writeDB('pending_tasks.json', $pendingTasks);
                $tasksState[$uid]['completed'][$taskId] = 'Pending';
                $response['message'] = 'Task submitted for verification.';
            } else {
                $tasksState[$uid]['completed'][$taskId] = true;
                $users[$uid]['tasksCompleted'] += 1;
                
                foreach($dynamicTasks as $k => $dt) { if($dt['id'] === $taskId) $dynamicTasks[$k]['completions']++; }
                writeDB('tasks_db.json', $dynamicTasks);
                
                grantUserReward($uid, $users, (int)$task['rewardXp'], (float)$task['rewardUsd'], $xpMulti);
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
                $response['message'] = 'Task completed successfully!';
            }
            writeDB('tasks.json', $tasksState);
            break;

        case 'open_box':
            $type = $input['boxType'] ?? '';
            $costs = ['bronze' => 10000, 'silver' => 50000, 'gold' => 100000];
            
            if (isset($costs[$type]) && $users[$uid]['xp'] >= $costs[$type]) {
                $users[$uid]['xp'] -= $costs[$type]; 
                $users[$uid]['boxesOpened'] += 1;
                $isJackpot = (rand(1, 10000) === 1); 
                $rewardUsd = 0;
                if ($type === 'bronze') $rewardUsd = $isJackpot ? 1.00 : 0.10;
                if ($type === 'silver') $rewardUsd = $isJackpot ? 7.00 : 0.50;
                if ($type === 'gold') $rewardUsd = $isJackpot ? 15.00 : 1.00;
                
                $users[$uid]['usd'] += $rewardUsd;
                $users[$uid]['totalUsdEarned'] += $rewardUsd;
                $response['reward'] = $rewardUsd;
                $response['jackpot'] = $isJackpot;
            } else {
                $response['error'] = 'Not enough XP';
            }
            break;

        case 'withdraw':
            $amount = (float)($input['amount'] ?? 0);
            $address = $input['address'] ?? '';
            
            if ($amount >= 10 && $amount <= $users[$uid]['usd'] && strlen($address) > 5) {
                $users[$uid]['usd'] -= $amount; 
                $users[$uid]['withdrawnUsd'] += $amount;
                if (!isset($withdrawals[$uid])) $withdrawals[$uid] = [];
                array_unshift($withdrawals[$uid], [
                    'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                    'amount' => $amount,
                    'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                    'date' => clone($now)->setTimezone(new DateTimeZone('Asia/Baku'))->format('d M Y, H:i'),
                    'status' => 'Pending'
                ]);
                writeDB('withdrawals.json', $withdrawals);
            } else {
                $response['error'] = 'Invalid withdrawal request or insufficient funds.';
            }
            break;

        case 'sync':
            break;
    }

    writeDB('users.json', $users);
    $response['user'] = $users[$uid];
    $response['userLimit'] = $userLimit;
    $response['referrals'] = $referrals[$uid] ?? [];
    $response['rewards'] = $rewards[$uid] ?? [];
    $response['withdrawals'] = $withdrawals[$uid] ?? [];
    $response['tasksState'] = $tasksState[$uid] ?? [];
    $response['dynamicTasks'] = $dynamicTasks;
    
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>XPVerse</title>
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="https://sad.adsgram.ai/js/sad.min.js"></script>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&display=swap" rel="stylesheet">
  <script>
    tailwind.config = {
      theme: { extend: {
          fontFamily: { sans: ['Outfit', 'sans-serif'] },
          colors: { crypto: { dark: '#050511', card: '#0a0b1a', primary: '#3b82f6', glow: '#00f0ff', gold: '#ffb800', silver: '#e2e8f0', bronze: '#cd7f32' } },
          animation: { 'blob': 'blob 7s infinite', 'pulse-fast': 'pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite' },
          keyframes: { blob: { '0%': { transform: 'translate(0px, 0px) scale(1)' }, '33%': { transform: 'translate(30px, -50px) scale(1.1)' }, '66%': { transform: 'translate(-20px, 20px) scale(0.9)' }, '100%': { transform: 'translate(0px, 0px) scale(1)' } } }
      }}
    }
  </script>
  <style>
    body { background-color: #050511; color: #f8fafc; overflow-x: hidden; user-select: none; -webkit-user-select: none; }
    .bg-orb-1 { position: fixed; top: -10%; left: -10%; width: 50vw; height: 50vw; background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(0, 0, 0, 0) 70%); z-index: -1; filter: blur(40px); }
    .bg-orb-2 { position: fixed; bottom: -10%; right: -10%; width: 60vw; height: 60vw; background: radial-gradient(circle, rgba(0, 240, 255, 0.1) 0%, rgba(0, 0, 0, 0) 70%); z-index: -1; filter: blur(50px); }
    input, select, textarea { user-select: auto !important; }
    ::-webkit-scrollbar { width: 4px; background: transparent; }
    ::-webkit-scrollbar-thumb { background: #3b82f6; border-radius: 4px; }
    .glass-card { background: linear-gradient(145deg, rgba(20, 22, 45, 0.7) 0%, rgba(10, 11, 26, 0.85) 100%); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.05); }
    .fade-in { animation: fadeIn 0.3s forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .nav-active { color: #00f0ff !important; transform: translateY(-3px); }
    .nav-active::before { content: ''; position: absolute; top: -10px; left: 50%; transform: translateX(-50%); width: 20px; height: 3px; background: #00f0ff; border-radius: 4px; box-shadow: 0 0 10px #00f0ff; }
    .modal-overlay { background: rgba(5, 5, 17, 0.9); backdrop-filter: blur(10px); z-index: 10000; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
  </style>
</head>
<body class="flex flex-col min-h-screen">
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob" style="animation-delay: 2s"></div>

  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-6">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1s_linear_infinite] shadow-[0_0_20px_rgba(0,240,255,0.6)]"></div>
      <div class="absolute inset-0 flex items-center justify-center"><i class="fa-solid fa-rocket text-crypto-glow text-3xl animate-pulse"></i></div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase">XPVerse</h2>
  </div>

  <div id="ban-overlay" class="hidden bg-red-950/90 flex-col items-center justify-center z-[100000] fixed inset-0 backdrop-blur-xl p-6 text-center">
      <i class="fa-solid fa-gavel text-6xl text-red-500 mb-4 drop-shadow-[0_0_20px_rgba(239,68,68,1)]"></i>
      <h1 class="text-3xl font-black text-white mb-2 tracking-widest uppercase">You are banned</h1>
      <p class="text-red-300 text-sm opacity-80" id="ban-msg">Access to this bot has been restricted by the administrator.</p>
  </div>

  <div id="maintenance-overlay" class="hidden bg-[#050511]/95 flex-col items-center justify-center z-[100000] fixed inset-0 backdrop-blur-xl p-6 text-center">
      <i class="fa-solid fa-screwdriver-wrench text-6xl text-blue-500 mb-4 animate-bounce"></i>
      <h1 class="text-3xl font-black text-white mb-2 tracking-widest uppercase">Maintenance Mode</h1>
      <p class="text-blue-200 text-sm opacity-80">We are upgrading the system to bring you better features. Please check back shortly.</p>
  </div>

  <div id="toast-container" class="fixed top-6 left-1/2 -translate-x-1/2 scale-90 opacity-0 pointer-events-none transition-all duration-400 z-[99999] glass-card rounded-2xl p-3 w-[90%] max-w-[380px] flex items-center gap-3">
    <div id="toast-icon" class="w-10 h-10 rounded-full flex shrink-0 items-center justify-center text-lg"></div>
    <div class="flex-1"><h4 id="toast-title" class="text-xs font-black text-white tracking-wide"></h4><p id="toast-message" class="text-[11px] text-slate-300 mt-0.5 leading-tight"></p></div>
  </div>

  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full py-3 px-4 glass-card rounded-b-[1.5rem] border-b border-white/5">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-2.5">
        <div class="relative w-10 h-10 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500 shadow-[0_0_10px_rgba(0,240,255,0.2)]">
          <img id="user-photo" src="" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-[#050511]">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm tracking-wide leading-tight">Loading...</span>
          <span class="text-[9px] text-emerald-400 uppercase font-black tracking-widest flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Online</span>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <div class="bg-[#050511] border border-blue-500/30 px-2.5 py-1 rounded-lg flex items-center gap-1.5">
          <i class="fa-solid fa-bolt text-crypto-glow text-[10px]"></i>
          <span id="user-xp" class="text-white font-black text-xs tracking-wider">0 <span class="text-[9px] text-crypto-glow">XP</span></span>
        </div>
        <div class="bg-[#050511] border border-emerald-500/40 px-2.5 py-1 rounded-lg flex items-center gap-1.5">
          <i class="fa-solid fa-dollar-sign text-emerald-400 text-[9px]"></i>
          <span id="user-usd" class="text-emerald-400 font-black text-[11px] tracking-wider">0</span>
        </div>
      </div>
    </div>
    <div id="xp-multiplier-banner" class="hidden mt-2 bg-gradient-to-r from-purple-600 to-pink-500 text-white text-[9px] font-black uppercase tracking-[0.2em] py-1 text-center rounded shadow-[0_0_10px_rgba(168,85,247,0.5)]">
        <i class="fa-solid fa-fire animate-pulse mr-1"></i> 2X XP EVENT ACTIVE <i class="fa-solid fa-fire animate-pulse ml-1"></i>
    </div>
  </header>

  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-24 pb-24 relative" id="app-content">
    
    <div id="view-home" class="view-section fade-in space-y-5">
      <div class="relative glass-card rounded-[1.5rem] p-5 text-center border-t border-t-blue-400/20 overflow-hidden flex flex-col items-center justify-center min-h-[220px]">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-48 h-48 bg-blue-500/20 rounded-full blur-[40px] pointer-events-none animate-pulse-fast"></div>
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-900/60 to-[#050511] border border-blue-400/40 flex items-center justify-center mb-3">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow"></i>
          </div>
          <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.25em] mb-1">Available XP</p>
          <h1 class="text-4xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-500 tracking-tighter drop-shadow-xl" id="main-xp-display">0 XP</h1>
        </div>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5 shadow-inner">
            <div class="bg-blue-500/10 p-2.5 rounded-lg border border-blue-500/30"><i class="fa-solid fa-clapperboard text-blue-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black tracking-wider">Ads Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / <span id="ads-limit">30</span></p>
            </div>
          </div>
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5 shadow-inner">
            <div class="bg-amber-500/10 p-2.5 rounded-lg border border-amber-500/30"><i class="fa-solid fa-fire-flame-curved text-amber-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black tracking-wider">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-3.5 bg-gradient-to-b from-blue-500 to-blue-700 rounded-[1.25rem] text-white font-black text-sm tracking-[0.1em] uppercase flex items-center justify-center gap-2.5 shadow-[0_10px_20px_rgba(59,130,246,0.3)] hover:brightness-110 active:scale-95 transition-all">
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span>Watch Ad <span class="text-cyan-200 ml-1" id="ad-reward-txt">+20 XP</span></span>
      </button>

      <div class="glass-card rounded-xl p-3.5 flex justify-between items-center border border-slate-800">
        <div class="flex items-center gap-2 text-slate-400 text-[11px] font-bold"><i class="fa-solid fa-clock text-blue-400"></i> <span class="uppercase tracking-widest">Resets in:</span></div>
        <span class="text-white font-mono font-black text-xs tracking-widest bg-slate-900/50 px-2.5 py-1 rounded-md" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <div id="view-tasks" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1"><h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2></div>
      
      <div class="glass-card rounded-[1.25rem] p-4 border border-crypto-glow shadow-[0_0_15px_rgba(0,240,255,0.1)] bg-gradient-to-br from-blue-900/30 to-[#050511]">
        <div class="flex justify-between items-start mb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-lg"><i class="fa-solid fa-calendar-day text-lg text-white"></i></div>
                <div><h3 class="text-[15px] font-black text-white tracking-wide">Daily Login</h3></div>
            </div>
            <div id="daily-login-btn-container"></div>
        </div>
        <div class="bg-[#050511]/60 rounded-xl p-3 border border-slate-700/50"><div class="relative flex justify-between items-center" id="streak-tracker-container"></div></div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3">Core Missions</h3>
        <div id="missions-container" class="space-y-2.5"></div>
      </div>

      <div id="dynamic-tasks-wrapper">
          <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 mt-5">Sponsor & Extra Tasks</h3>
          <div id="dynamic-tasks-container" class="space-y-2.5"></div>
      </div>
    </div>

    <div id="view-referrals" class="view-section hidden fade-in space-y-5">
      <div class="text-center relative mb-1"><h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Referrals</h2></div>
      
      <div class="grid grid-cols-3 gap-2.5">
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-blue-500/40"><p class="text-[9px] text-slate-400 uppercase font-black mb-1">Total</p><p id="ref-total" class="text-xl font-black text-white">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-amber-500/40"><p class="text-[9px] text-slate-400 uppercase font-black mb-1">Pending</p><p id="ref-pending" class="text-xl font-black text-amber-400">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-emerald-500/40"><p class="text-[9px] text-slate-400 uppercase font-black mb-1">Approved</p><p id="ref-approved" class="text-xl font-black text-emerald-400">0</p></div>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 space-y-3.5 border border-slate-700/50">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-1.5 ml-1">Your Referral Link</label>
          <div class="flex items-center gap-2">
            <input type="text" id="ref-link-input" readonly class="flex-1 bg-[#050511]/70 border border-slate-700 rounded-lg py-2.5 px-3 text-[11px] font-medium text-slate-300 outline-none">
            <button onclick="copyRefLink()" class="bg-slate-800 text-white w-10 h-10 rounded-lg flex items-center justify-center active:scale-95 border border-slate-600"><i class="fa-regular fa-copy text-sm"></i></button>
          </div>
        </div>
        <button onclick="shareReferralTelegram()" class="w-full py-3 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-lg text-xs uppercase tracking-wider flex items-center justify-center gap-2 shadow-[0_5px_15px_rgba(0,240,255,0.3)] active:scale-95"><i class="fa-brands fa-telegram text-base"></i> Share via Telegram</button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3">Your Referrals</h3>
        <div id="referral-list-container" class="space-y-2.5"></div>
      </div>
    </div>

    <div id="view-boxes" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-4"><h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Mystery Box</h2></div>
      
      <div class="bg-gradient-to-br from-[#1a110a] to-[#050511] glass-card rounded-[1.25rem] p-4 relative overflow-hidden flex justify-between items-center border border-[#cd7f32]/40 shadow-md">
        <div class="flex items-center gap-3 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-[#cd7f32]/20 border border-[#cd7f32] flex items-center justify-center"><i class="fa-solid fa-box text-2xl text-[#cd7f32]"></i></div>
          <div><h3 class="text-lg font-black text-white">Bronze Box</h3><div class="flex flex-col"><span class="text-[10px] text-slate-400 font-bold uppercase"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 10,000 XP</span><span class="text-[11px] text-emerald-400 font-bold">Max: $1.00</span></div></div>
        </div>
        <button onclick="openBox('bronze')" class="bg-gradient-to-b from-orange-600 to-orange-800 text-white active:scale-95 px-4 py-2 rounded-lg text-xs font-black uppercase shadow-lg">Open</button>
      </div>

      <div class="bg-gradient-to-br from-[#12161f] to-[#050511] glass-card rounded-[1.25rem] p-4 relative overflow-hidden flex justify-between items-center border border-slate-400/40 shadow-md">
        <div class="flex items-center gap-3 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-slate-500/20 border border-slate-400 flex items-center justify-center"><i class="fa-solid fa-box-open text-2xl text-slate-300"></i></div>
          <div><h3 class="text-lg font-black text-white">Silver Box</h3><div class="flex flex-col"><span class="text-[10px] text-slate-400 font-bold uppercase"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 50,000 XP</span><span class="text-[11px] text-emerald-400 font-bold">Max: $7.00</span></div></div>
        </div>
        <button onclick="openBox('silver')" class="bg-gradient-to-b from-slate-400 to-slate-600 text-crypto-dark active:scale-95 px-4 py-2 rounded-lg text-xs font-black uppercase shadow-lg">Open</button>
      </div>

      <div class="bg-gradient-to-br from-[#2a1f00] to-[#050511] glass-card rounded-[1.25rem] p-4 relative overflow-hidden flex justify-between items-center border border-[#ffb800]/50 shadow-[0_0_20px_rgba(255,184,0,0.15)]">
        <div class="flex items-center gap-3 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-[#ffb800]/20 border border-[#ffb800] flex items-center justify-center"><i class="fa-solid fa-gem text-2xl text-[#ffb800]"></i></div>
          <div><h3 class="text-lg font-black text-[#ffb800]">Gold Box</h3><div class="flex flex-col"><span class="text-[10px] text-amber-200/80 font-bold uppercase"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 100,000 XP</span><span class="text-[11px] text-emerald-400 font-bold">Max: $15.00</span></div></div>
        </div>
        <button onclick="openBox('gold')" class="bg-gradient-to-b from-yellow-400 to-amber-600 text-crypto-dark active:scale-95 px-4 py-2 rounded-lg text-xs font-black uppercase shadow-lg">Open</button>
      </div>
    </div>

    <div id="view-wallet" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1"><h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Wallet</h2></div>

      <div class="glass-card rounded-[1.5rem] p-5 text-center border-t border-emerald-500/30 bg-gradient-to-b from-emerald-900/20 to-[#050511]">
        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-1">Available Balance</p>
        <h1 class="text-4xl font-black text-white mb-3">$<span id="withdraw-balance-display">0</span></h1>
        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"><i class="fa-solid fa-chart-line"></i> Total Earned: $<span id="wallet-total-earned">0</span></p>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 space-y-4 border border-slate-700/50">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-1.5 ml-1">TON Wallet Address <span class="text-red-400">*</span></label>
          <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511]/70 border border-slate-700/60 rounded-lg py-2.5 px-3 text-xs text-white focus:outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-1.5 ml-1">Amount (USDT) <span class="text-red-400">*</span></label>
          <input type="number" id="withdraw-amount" placeholder="10" min="10" class="w-full bg-[#050511]/70 border border-slate-700/60 rounded-lg py-2.5 px-3 text-xs font-bold text-emerald-400 focus:outline-none focus:border-emerald-500">
        </div>
        <button onclick="requestWithdrawal()" class="w-full py-3 bg-gradient-to-r from-emerald-600 to-teal-500 text-white font-black rounded-lg text-xs uppercase tracking-[0.1em] shadow-[0_5px_15px_rgba(16,185,129,0.3)] active:scale-95">Request Withdrawal</button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3">Withdrawal History</h3>
        <div id="withdraw-history-container" class="space-y-2.5"></div>
      </div>
    </div>

    <div id="view-profile" class="view-section hidden fade-in space-y-5">
      <div class="glass-card rounded-[1.5rem] p-5 flex flex-col items-center justify-center border-t border-t-blue-500/30 relative overflow-hidden">
        <button id="admin-secret-btn" onclick="switchTab('admin')" class="hidden absolute top-4 right-4 w-8 h-8 rounded-full bg-red-600/20 text-red-500 border border-red-500/50 flex items-center justify-center shadow-[0_0_15px_rgba(239,68,68,0.4)] active:scale-95">
            <i class="fa-solid fa-user-shield text-sm"></i>
        </button>
        <div class="relative z-10 w-20 h-20 rounded-full p-1 bg-gradient-to-tr from-blue-500 to-purple-500 mb-3"><img id="profile-page-avatar" src="" class="w-full h-full rounded-full object-cover border-[3px] border-[#050511]"></div>
        <h2 id="profile-page-name" class="text-xl font-black text-white text-center break-words">Name</h2>
        <p id="profile-page-username" class="text-[11px] font-mono text-blue-400 mb-2 bg-blue-500/10 px-2 py-0.5 rounded-full border border-blue-500/20">@username</p>
        <div class="bg-[#050511]/60 px-3 py-1.5 rounded-lg border border-slate-700/50 text-[9px] text-slate-400 font-bold uppercase tracking-widest">ID: <span id="profile-page-id" class="text-white ml-1">0000000</span></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Total XP</p><p id="profile-stat-xp" class="text-lg font-black text-crypto-glow">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Total USD</p><p id="profile-stat-usd" class="text-lg font-black text-emerald-400">$0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Referrals</p><p id="profile-stat-refs" class="text-lg font-black text-white">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Tasks Done</p><p id="profile-stat-tasks" class="text-lg font-black text-white">0</p></div>
      </div>
    </div>

    <!-- AZERBAIJANI ADMIN PANEL -->
    <div id="view-admin" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-2"><h2 class="text-2xl font-black text-red-500 tracking-tight drop-shadow-lg flex items-center justify-center gap-2"><i class="fa-solid fa-shield-halved"></i> İDARƏ PANelİ</h2></div>

      <div class="flex gap-1 mb-2 bg-[#050511] p-1 rounded-lg border border-slate-700/50 overflow-x-auto whitespace-nowrap admin-scroll">
          <button onclick="switchAdminTab('dashboard')" class="admin-tab px-3 py-2 text-[10px] font-black uppercase rounded bg-red-600/20 text-red-400 border border-red-500/50" id="tab-dashboard">Panel</button>
          <button onclick="switchAdminTab('users')" class="admin-tab px-3 py-2 text-[10px] font-black uppercase rounded text-slate-400" id="tab-users">İstifadəçilər</button>
          <button onclick="switchAdminTab('tasks')" class="admin-tab px-3 py-2 text-[10px] font-black uppercase rounded text-slate-400" id="tab-tasks">Tapşırıqlar</button>
          <button onclick="switchAdminTab('withdrawals')" class="admin-tab px-3 py-2 text-[10px] font-black uppercase rounded text-slate-400" id="tab-withdrawals">Çıxarışlar</button>
          <button onclick="switchAdminTab('settings')" class="admin-tab px-3 py-2 text-[10px] font-black uppercase rounded text-slate-400" id="tab-settings">Ayarlar</button>
          <button onclick="switchAdminTab('errors')" class="admin-tab px-3 py-2 text-[10px] font-black uppercase rounded text-slate-400" id="tab-errors">Xətalar</button>
      </div>

      <!-- Dashboard -->
      <div id="admin-sec-dashboard" class="admin-section space-y-3">
          <div class="grid grid-cols-2 gap-2">
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Ümumi İst. / Aktiv</p><p class="text-lg font-black text-blue-400"><span id="adm-stat-users">0</span> / <span id="adm-stat-active" class="text-emerald-400">0</span></p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Ümumi USD (Qazanc)</p><p id="adm-stat-usd" class="text-lg font-black text-emerald-400">$0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Baxılan Reklamlar</p><p id="adm-stat-ads" class="text-lg font-black text-white">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Edilən Tapşırıqlar</p><p id="adm-stat-tasks" class="text-lg font-black text-white">0</p></div>
          </div>
          <div class="glass-card p-3 rounded-xl border border-slate-700 flex gap-2">
              <button onclick="adminGlobalAction('reset_all_ads')" class="flex-1 bg-amber-600 text-white text-[10px] font-black py-2 rounded">Bütün Reklamları Sıfırla</button>
              <button onclick="adminGlobalAction('reset_all_balances')" class="flex-1 bg-red-600 text-white text-[10px] font-black py-2 rounded">Bütün Balansları Sıfırla</button>
          </div>
      </div>

      <!-- Users -->
      <div id="admin-sec-users" class="admin-section hidden space-y-3">
          <input type="text" id="admin-user-search" oninput="renderAdminUsers()" placeholder="UID, Username və ya Ad ilə axtar..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none">
          
          <div class="glass-card p-3 border border-red-900 rounded mb-2">
              <p class="text-[10px] text-slate-400 mb-1 uppercase font-black">Qabaqcadan Ban (Pre-Ban)</p>
              <div class="flex gap-2">
                  <input type="text" id="preban-uid" placeholder="Telegram UID" class="flex-1 bg-[#050511] border border-slate-700 rounded py-1.5 px-2 text-xs text-white">
                  <button onclick="adminPreBan()" class="bg-red-600 text-white px-3 py-1.5 rounded text-[10px] font-black">Blokla</button>
              </div>
          </div>

          <div class="max-h-[55vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-user-list"></div>
      </div>

      <!-- User Detail Modal -->
      <div id="admin-user-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 z-[999999]">
          <div class="glass-card w-full max-w-sm rounded-[1.5rem] p-5 relative border border-blue-500/50 max-h-[90vh] overflow-y-auto admin-scroll bg-[#050511]">
              <button onclick="document.getElementById('admin-user-modal').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 text-lg"><i class="fa-solid fa-xmark"></i></button>
              <h3 class="text-lg font-black text-white mb-4 border-b border-slate-700 pb-2">İstifadəçi Detalları</h3>
              <div id="admin-user-modal-content" class="space-y-4"></div>
          </div>
      </div>

      <!-- Tasks -->
      <div id="admin-sec-tasks" class="admin-section hidden space-y-3">
          <button onclick="openTaskModal()" class="w-full py-2 bg-emerald-600 text-white text-xs font-black uppercase rounded-lg mb-2"><i class="fa-solid fa-plus"></i> Yeni Tapşırıq Əlavə Et</button>
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-tasks-list"></div>
      </div>
      
      <!-- Task Modal -->
      <div id="admin-task-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 z-[999999]">
          <div class="glass-card w-full max-w-sm rounded-[1.5rem] p-5 relative border border-emerald-500/50 max-h-[90vh] overflow-y-auto admin-scroll bg-[#050511]">
              <button onclick="document.getElementById('admin-task-modal').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 text-lg"><i class="fa-solid fa-xmark"></i></button>
              <h3 class="text-lg font-black text-white mb-3" id="task-modal-title">Tapşırıq</h3>
              <div class="space-y-3">
                  <input type="hidden" id="task-id">
                  <div><label class="text-[10px] text-slate-400 font-bold uppercase">Ad / Təsvir</label><input type="text" id="task-name" class="w-full bg-[#0a0b1a] border border-slate-700 rounded p-2 text-xs text-white"></div>
                  <div class="grid grid-cols-2 gap-2">
                      <div><label class="text-[10px] text-slate-400 font-bold uppercase">Növ</label><select id="task-type" class="w-full bg-[#0a0b1a] border border-slate-700 rounded p-2 text-xs text-white"><option value="normal">Normal</option><option value="sponsor">Sponsor</option></select></div>
                      <div><label class="text-[10px] text-slate-400 font-bold uppercase">Müddət</label><select id="task-is-daily" class="w-full bg-[#0a0b1a] border border-slate-700 rounded p-2 text-xs text-white"><option value="1">Gündəlik</option><option value="0">Daimi (1 dəfə)</option></select></div>
                  </div>
                  <div class="grid grid-cols-2 gap-2">
                      <div><label class="text-[10px] text-slate-400 font-bold uppercase">Mükafat XP</label><input type="number" id="task-reward-xp" class="w-full bg-[#0a0b1a] border border-slate-700 rounded p-2 text-xs text-white" value="0"></div>
                      <div><label class="text-[10px] text-slate-400 font-bold uppercase">Mükafat USDT</label><input type="number" step="0.01" id="task-reward-usd" class="w-full bg-[#0a0b1a] border border-slate-700 rounded p-2 text-xs text-white" value="0"></div>
                  </div>
                  <div><label class="text-[10px] text-slate-400 font-bold uppercase">Link</label><input type="text" id="task-link" class="w-full bg-[#0a0b1a] border border-slate-700 rounded p-2 text-xs text-white"></div>
                  <div><label class="text-[10px] text-slate-400 font-bold uppercase">Qeyd / Təlimat</label><input type="text" id="task-note" class="w-full bg-[#0a0b1a] border border-slate-700 rounded p-2 text-xs text-white"></div>
                  
                  <div class="p-2 border border-blue-900 rounded bg-[#0a0b1a]">
                      <label class="flex items-center gap-2 text-[10px] text-white font-bold uppercase mb-2"><input type="checkbox" id="task-require-verify"> Yoxlama Tələb Et (Təsdiq)</label>
                      <div><label class="text-[9px] text-slate-500 uppercase">İstifadəçiyə Nümunə UID/Mətn</label><input type="text" id="task-uid-example" class="w-full bg-[#050511] border border-slate-700 rounded p-1.5 text-xs text-white mt-1" placeholder="Məs: 123456789"></div>
                  </div>
                  
                  <div><label class="text-[10px] text-slate-400 font-bold uppercase">Tamamlanma Limiti (0 = Limitsiz)</label><input type="number" id="task-limit" class="w-full bg-[#0a0b1a] border border-slate-700 rounded p-2 text-xs text-white" value="0"></div>
                  <button onclick="saveTask()" class="w-full py-2 bg-blue-600 text-white font-black uppercase rounded text-xs">Yadda Saxla</button>
              </div>
          </div>
      </div>

      <!-- Withdrawals -->
      <div id="admin-sec-withdrawals" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-withdrawal-list"></div>
      </div>

      <!-- Settings -->
      <div id="admin-sec-settings" class="admin-section hidden space-y-3">
          <div class="glass-card rounded-xl p-4 border border-slate-700 space-y-3">
              <div>
                  <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Adsgram Block IDs (Vergüllə ayır)</label>
                  <textarea id="adm-set-sdk" rows="2" class="w-full bg-[#050511] border border-slate-700 rounded-lg p-2 text-xs text-white outline-none"></textarea>
              </div>
              <div class="grid grid-cols-2 gap-2">
                  <div>
                      <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Qlobal Reklam Limiti</label>
                      <input type="number" id="adm-set-adlimit" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white outline-none">
                  </div>
                  <div>
                      <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Günlük Sıfırlanma Saatı</label>
                      <input type="time" id="adm-set-reset" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white outline-none">
                  </div>
              </div>
              <div class="p-3 border border-red-900 rounded bg-[#0a0b1a]">
                  <label class="flex items-center gap-2 text-[11px] text-white font-bold uppercase"><input type="checkbox" id="adm-set-maintenance"> Təmir Rejimi (Maintenance)</label>
              </div>
              <div class="p-3 border border-purple-900 rounded bg-[#0a0b1a] space-y-2">
                  <label class="flex items-center gap-2 text-[11px] text-white font-bold uppercase border-b border-slate-800 pb-2"><input type="checkbox" id="adm-set-xp2x"> 2X XP Aktivləşdir</label>
                  <label class="flex items-center gap-2 text-[10px] text-slate-400 font-bold uppercase"><input type="checkbox" id="adm-set-xp2x-perm"> Daimi Rejim (Tarix yoxdur)</label>
                  <div class="grid grid-cols-2 gap-2 mt-2">
                      <div><label class="text-[9px] text-slate-500">Başlanğıc</label><input type="datetime-local" id="adm-set-xp2x-st" class="w-full bg-[#050511] border border-slate-700 rounded p-1 text-[10px] text-white"></div>
                      <div><label class="text-[9px] text-slate-500">Bitiş</label><input type="datetime-local" id="adm-set-xp2x-ed" class="w-full bg-[#050511] border border-slate-700 rounded p-1 text-[10px] text-white"></div>
                  </div>
              </div>
              <button onclick="saveAdminSettings()" class="w-full py-2 bg-blue-600 text-white rounded text-xs font-black uppercase tracking-wider">Yadda Saxla</button>
          </div>
      </div>

      <!-- Errors -->
      <div id="admin-sec-errors" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-error-list"></div>
      </div>
    </div>

  </main>

  <nav id="bottom-nav" class="fixed bottom-3 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50 shadow-[0_15px_30px_rgba(0,0,0,0.8)] border border-slate-700/50">
    <div class="flex justify-between items-center px-1 py-2 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all group" data-target="home"><i class="fa-solid fa-house text-base group-active:scale-90"></i><span class="text-[7px] font-black uppercase mt-0.5">Home</span></button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all group" data-target="tasks"><i class="fa-solid fa-list-check text-base group-active:scale-90"></i><span class="text-[7px] font-black uppercase mt-0.5">Tasks</span></button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all group" data-target="referrals"><i class="fa-solid fa-users text-base group-active:scale-90"></i><span class="text-[7px] font-black uppercase mt-0.5">Referrals</span></button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all group" data-target="boxes"><i class="fa-solid fa-box-open text-base group-active:scale-90"></i><span class="text-[7px] font-black uppercase mt-0.5">Box</span></button>
      <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all group" data-target="wallet"><i class="fa-solid fa-wallet text-base group-active:scale-90"></i><span class="text-[7px] font-black uppercase mt-0.5">Wallet</span></button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all group" data-target="profile"><i class="fa-solid fa-user text-base group-active:scale-90"></i><span class="text-[7px] font-black uppercase mt-0.5">Profile</span></button>
    </div>
  </nav>

  <div id="verify-task-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 z-[999999]">
      <div class="glass-card w-full max-w-sm rounded-[1.5rem] p-5 relative border border-blue-500/30">
          <button onclick="document.getElementById('verify-task-modal').classList.add('hidden')" class="absolute top-3 right-3 text-slate-400"><i class="fa-solid fa-xmark"></i></button>
          <h3 class="text-lg font-black text-white mb-2">Task Verification</h3>
          <p class="text-xs text-slate-400 mb-4" id="verify-task-note">Please provide required details to verify completion.</p>
          <input type="hidden" id="verify-task-id">
          <input type="text" id="verify-task-input" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-sm text-white focus:border-blue-500 outline-none mb-4" placeholder="Enter UID or proof">
          <button onclick="submitTaskVerification()" class="w-full py-2.5 bg-blue-600 text-white font-black rounded-lg text-xs uppercase">Submit Proof</button>
      </div>
  </div>

  <script>
    window.addEventListener('error', e => { fetch(window.location.href, {method: 'POST', body: JSON.stringify({action: 'log_client_error', msg: e.message, cause: e.filename+':'+e.lineno, tgId: tgUser?.id})}); });

    const tg = window.Telegram.WebApp;
    tg.expand(); tg.ready(); tg.setHeaderColor('#0a0b1a'); tg.setBackgroundColor('#050511');

    const tgUser = tg.initDataUnsafe?.user || { id: "1234567890", first_name: "Demo", username: "demouser" };
    const startParam = tg.initDataUnsafe?.start_param || null;

    let appState = { user: {}, settings: {}, admin: { users: [], withdrawals: [], tasks: [], errors: [], stats: {} }, dynTasks: [] };
    let timerInterval = null;

    function formatNum(num, isMoney = false) {
        if (!num) return "0";
        let val = Number(num);
        return isMoney ? (val % 1 === 0 ? val.toString() : val.toFixed(2).replace(/\.?0+$/, '')) : val.toLocaleString();
    }

    async function apiCall(action, payload = {}, quiet = false) {
      try {
        const body = { action, tgId: tgUser.id, firstName: tgUser.first_name, lastName: tgUser.last_name, username: tgUser.username, photoUrl: tgUser.photo_url, referrer: startParam, ...payload };
        const res = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const data = await res.json();
        
        if (data.error === 'BANNED') { document.getElementById('ban-overlay').style.display = 'flex'; document.getElementById('ban-msg').innerText = data.message; return false; }
        if (data.error === 'MAINTENANCE') { document.getElementById('maintenance-overlay').style.display = 'flex'; return false; }
        if (data.error) { if(!quiet) showToast("Error", data.error, "error"); return false; }

        if (data.user) appState.user = data.user;
        if (data.settings) appState.settings = data.settings;
        if (data.userLimit !== undefined) appState.userLimit = data.userLimit;
        if (data.referrals) appState.referrals = data.referrals;
        if (data.rewards) appState.rewards = data.rewards;
        if (data.withdrawals) appState.withdrawals = data.withdrawals;
        if (data.tasksState) appState.tasksState = data.tasksState;
        if (data.dynamicTasks) appState.dynTasks = data.dynamicTasks;
        
        if (action === 'admin_dashboard' && data.all_users) {
            appState.admin.users = data.all_users;
            appState.admin.withdrawals = data.all_withdrawals;
            appState.admin.errors = data.errors;
            appState.admin.stats = data.stats;
            appState.admin.banned = data.banned;
        }

        updateUI();
        if (action === 'init' || action === 'sync') startTimer(data.serverTime, data.serverResetTime);
        if (data.message && !quiet) showToast("Success", data.message, "success");
        return data;
      } catch (err) { return false; }
    }

    function showToast(title, message, type = 'info') {
      const toast = document.getElementById('toast-container'); const icon = document.getElementById('toast-icon');
      document.getElementById('toast-title').innerText = title; document.getElementById('toast-message').innerText = message;
      let iconClass, iconHtml;
      if (type === 'success') { iconHtml = '<i class="fa-solid fa-check"></i>'; iconClass = 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50 shadow-[0_0_10px_rgba(16,185,129,0.2)]'; } 
      else if (type === 'error') { iconHtml = '<i class="fa-solid fa-xmark"></i>'; iconClass = 'bg-red-500/20 text-red-400 border border-red-500/50 shadow-[0_0_10px_rgba(239,68,68,0.2)]'; } 
      else if (type === 'jackpot') { iconHtml = '<i class="fa-solid fa-sack-dollar animate-bounce text-xl"></i>'; iconClass = 'bg-amber-500/20 text-amber-400 border border-amber-500/50 shadow-[0_0_15px_rgba(251,191,36,0.5)]'; } 
      else { iconHtml = '<i class="fa-solid fa-bell animate-pulse"></i>'; iconClass = 'bg-blue-500/20 text-crypto-glow border border-crypto-glow/50 shadow-[0_0_10px_rgba(0,240,255,0.2)]'; }

      icon.innerHTML = iconHtml; icon.className = `w-10 h-10 rounded-lg flex shrink-0 items-center justify-center text-base ${iconClass}`;
      toast.classList.add('opacity-100', 'translate-y-4'); toast.classList.remove('opacity-0', 'pointer-events-none');
      if (tg.HapticFeedback) tg.HapticFeedback.notificationOccurred(type === 'success' || type === 'jackpot' ? 'success' : (type === 'error' ? 'error' : 'warning'));
      setTimeout(() => { toast.classList.remove('opacity-100', 'translate-y-4'); toast.classList.add('opacity-0', 'pointer-events-none'); }, type === 'jackpot' ? 5000 : 3000); 
    }

    function updateUI() {
      const u = appState.user; const s = appState.settings;
      if(!u.tgId) return;
      const fullName = [u.firstName, u.lastName].filter(Boolean).join(' ') || 'User';
      document.getElementById('user-name').innerText = fullName;
      document.getElementById('user-xp').innerHTML = `${formatNum(u.xp)} <span class="text-[9px] text-crypto-glow font-bold">XP</span>`;
      document.getElementById('user-usd').innerText = formatNum(u.usd, true);
      document.getElementById('user-photo').src = u.photoUrl || `https://ui-avatars.com/api/?name=${encodeURIComponent(fullName)}&background=0a0b1a&color=00f0ff&bold=true`;
      document.getElementById('profile-page-avatar').src = document.getElementById('user-photo').src;
      document.getElementById('profile-page-name').innerText = fullName;
      document.getElementById('profile-page-username').innerText = u.username ? `@${u.username}` : '';
      document.getElementById('profile-page-username').style.display = u.username ? 'inline-block' : 'none';
      document.getElementById('profile-page-id').innerText = u.tgId;
      document.getElementById('profile-stat-xp').innerText = formatNum(u.totalXpEarned);
      document.getElementById('profile-stat-usd').innerText = `$${formatNum(u.totalUsdEarned, true)}`;
      document.getElementById('profile-stat-refs').innerText = appState.referrals.length;
      document.getElementById('profile-stat-tasks').innerText = u.tasksCompleted;

      if (appState.isAdmin) document.getElementById('admin-secret-btn').classList.remove('hidden');

      document.getElementById('main-xp-display').innerText = `${formatNum(u.xp)} XP`;
      document.getElementById('ads-watched').innerText = u.adsWatchedToday;
      document.getElementById('ads-limit').innerText = appState.userLimit;
      document.getElementById('streak-days').innerText = u.streak;

      document.getElementById('ref-total').innerText = appState.referrals.length;
      document.getElementById('ref-pending').innerText = appState.referrals.filter(r => r.status === 'Pending').length;
      document.getElementById('ref-approved').innerText = appState.referrals.filter(r => r.status === 'Approved').length;
      document.getElementById('ref-link-input').value = `https://t.me/XPVersebot?startapp=${u.tgId}`;
      
      document.getElementById('withdraw-balance-display').innerText = formatNum(u.usd, true);
      document.getElementById('wallet-total-earned').innerText = formatNum(u.totalUsdEarned, true);
      
      const xpM = s.activeXpMulti > 1;
      document.getElementById('xp-multiplier-banner').style.display = xpM ? 'block' : 'none';
      document.getElementById('ad-reward-txt').innerText = `+${20 * (s.activeXpMulti || 1)} XP`;
      
      renderReferrals(); renderWithdrawHistory(); renderTasks();
    }

    function renderTasks() {
        const state = appState.tasksState; const u = appState.user; const s = appState.settings; const mult = s.activeXpMulti || 1;
        
        let streakHtml = ''; const rewards = [10, 20, 30, 40, 50, 75, 100]; const streak = u.streak || 1; const claimed = state.dailyLogin;
        for (let i = 1; i <= 7; i++) {
            const isPast = i < streak || (i === streak && claimed); const isToday = i === streak && !claimed;
            let sty = "bg-[#050511] text-slate-600"; let icn = `<span class="text-[9px] font-black">${rewards[i-1]*mult}</span>`;
            if(isPast) { sty = "bg-emerald-500/20 text-emerald-400 border-emerald-500/50 shadow-[0_0_10px_rgba(16,185,129,0.2)]"; icn = `<i class="fa-solid fa-check text-xs"></i>`; }
            else if(isToday) { sty = "bg-blue-600/30 text-white border-crypto-glow shadow-[0_0_15px_rgba(0,240,255,0.4)]"; }
            streakHtml += `<div class="relative flex flex-col items-center gap-1 z-10 flex-1"><div class="w-8 h-8 rounded-lg border border-slate-700/50 flex items-center justify-center transition-all ${sty}">${icn}</div><span class="text-[8px] font-black tracking-widest ${isToday?'text-crypto-glow':'text-slate-500'}">DAY ${i}</span>${i<7?'<div class="absolute top-4 left-1/2 w-full h-[1px] bg-slate-700/50 -z-10"></div>':''}</div>`;
        }
        document.getElementById('streak-tracker-container').innerHTML = streakHtml;
        document.getElementById('daily-login-btn-container').innerHTML = claimed ? `<button class="px-3 py-1.5 rounded-lg bg-emerald-500/20 text-emerald-400 text-[10px] font-black uppercase" disabled><i class="fa-solid fa-check mr-1"></i> Claimed</button>` : `<button onclick="apiCall('claim_static_task', {taskId: 'dailyLogin', reward: ${rewards[streak-1]}})" class="px-3 py-1.5 rounded-lg bg-crypto-primary text-white text-[10px] font-black uppercase active:scale-95 shadow-lg">Claim ${rewards[streak-1]*mult} XP</button>`;

        const st = [
            { id: 'watch5', name: 'Watch 5 Ads', req: 5, rXp: 50, done: state.watch5 },
            { id: 'watch15', name: 'Watch 15 Ads', req: 15, rXp: 150, done: state.watch15 },
            { id: 'watch30', name: 'Watch 30 Ads', req: 30, rXp: 400, done: state.watch30 },
            { id: 'complete_all', name: 'Complete All Daily', req: 30, rXp: 1000, done: state.complete_all }
        ];
        
        document.getElementById('missions-container').innerHTML = st.map(t => {
            const prog = Math.min(u.adsWatchedToday, t.req); const pct = (prog/t.req)*100;
            const can = prog >= t.req && !t.done;
            let btn = `<button disabled class="px-3 py-1 bg-slate-800 text-slate-500 text-[9px] font-black rounded-lg uppercase">${prog}/${t.req}</button>`;
            if (t.done) btn = `<button disabled class="px-3 py-1 bg-emerald-500/20 text-emerald-400 text-[9px] font-black rounded-lg uppercase"><i class="fa-solid fa-check"></i></button>`;
            else if (can) btn = `<button onclick="apiCall('claim_static_task', {taskId: '${t.id}', reward: ${t.rXp}})" class="px-3 py-1 bg-gradient-to-r from-blue-600 to-cyan-500 text-white text-[9px] font-black rounded-lg uppercase shadow-[0_0_10px_rgba(0,240,255,0.4)] active:scale-95">Claim</button>`;
            
            return `<div class="glass-card p-3 rounded-xl flex items-center justify-between border border-slate-700/50"><div class="flex-1 mr-3">
                <h4 class="text-xs font-black text-white">${t.name}</h4><div class="flex items-center gap-1.5 mt-1"><i class="fa-solid fa-bolt text-[10px] text-crypto-glow"></i> <span class="text-[10px] font-bold text-slate-300">+${t.rXp*mult} XP</span></div>
                <div class="w-full bg-slate-800 h-1 rounded-full mt-2 overflow-hidden"><div class="bg-blue-500 h-full rounded-full transition-all" style="width: ${pct}%"></div></div></div>${btn}</div>`;
        }).join('');

        let dHtml = '';
        if (!state.sponsorAzx) {
            dHtml += `<div class="glass-card p-3 rounded-xl flex flex-col gap-2 border border-purple-500/50 bg-purple-900/10 shadow-[0_0_15px_rgba(168,85,247,0.15)]"><div class="flex items-center justify-between"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded-lg bg-purple-500/20 flex items-center justify-center text-purple-400"><i class="fa-solid fa-star"></i></div><div><h4 class="text-xs font-black text-white">AZX Crypto Partner</h4><p class="text-[9px] text-slate-400 font-bold uppercase">Sponsor Mission</p></div></div><div class="text-right"><p class="text-xs font-black text-crypto-glow">+${200*mult} XP</p></div></div><div class="flex gap-2"><button onclick="window.open('https://t.me/azxcrypto', '_blank')" class="flex-1 py-1.5 bg-slate-800 rounded-lg text-[10px] font-black text-white uppercase active:scale-95">Join Channel</button><button onclick="apiCall('claim_static_task', {taskId: 'sponsor_azx'})" class="flex-1 py-1.5 bg-purple-600 rounded-lg text-[10px] font-black text-white uppercase shadow-[0_0_10px_rgba(168,85,247,0.4)] active:scale-95">Verify</button></div></div>`;
        }

        appState.dynTasks.forEach(dt => {
            const status = state.completed ? state.completed[dt.id] : null;
            if (status === true) return; 
            
            const isLim = dt.completionLimit > 0 && dt.completions >= dt.completionLimit;
            if (isLim && !status) return;

            let btn = `<button onclick="claimDynamic('${dt.id}', ${dt.requireVerification})" class="flex-1 py-1.5 bg-blue-600 rounded-lg text-[10px] font-black text-white uppercase active:scale-95">Verify</button>`;
            if (status === 'Pending') btn = `<button disabled class="flex-1 py-1.5 bg-amber-500/20 text-amber-400 rounded-lg text-[10px] font-black uppercase"><i class="fa-solid fa-clock"></i> Pending</button>`;
            
            let rText = [];
            if(dt.rewardXp > 0) rText.push(`+${dt.rewardXp*mult} XP`);
            if(dt.rewardUsd > 0) rText.push(`$${dt.rewardUsd}`);
            let rStr = rText.join(' & ');

            let sSty = dt.type === 'sponsor' ? 'border-amber-500/50 bg-amber-900/10 shadow-[0_0_15px_rgba(251,191,36,0.15)]' : 'border-slate-700/50';
            let icn = dt.type === 'sponsor' ? '<i class="fa-solid fa-gem text-amber-400"></i>' : '<i class="fa-solid fa-link text-blue-400"></i>';

            dHtml += `<div class="glass-card p-3 rounded-xl flex flex-col gap-2 border ${sSty}"><div class="flex items-center justify-between"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center">${icn}</div><div><h4 class="text-xs font-black text-white">${dt.name}</h4><p class="text-[9px] text-slate-400">${dt.note || ''}</p></div></div><div class="text-right"><p class="text-xs font-black text-crypto-glow">${rStr}</p></div></div><div class="flex gap-2"><button onclick="window.open('${dt.link}', '_blank')" class="flex-1 py-1.5 bg-slate-800 rounded-lg text-[10px] font-black text-white uppercase active:scale-95">Go to Task</button>${btn}</div></div>`;
        });
        
        if (!dHtml) dHtml = '<p class="text-[10px] text-slate-500 text-center font-bold uppercase py-4">No extra tasks available right now.</p>';
        document.getElementById('dynamic-tasks-container').innerHTML = dHtml;
    }

    function claimDynamic(id, reqV) {
        if (reqV) {
            document.getElementById('verify-task-id').value = id;
            document.getElementById('verify-task-input').value = '';
            document.getElementById('verify-task-modal').classList.remove('hidden');
            document.getElementById('verify-task-modal').style.display = 'flex';
        } else {
            apiCall('claim_dynamic_task', {taskId: id});
        }
    }
    function submitTaskVerification() {
        const id = document.getElementById('verify-task-id').value;
        const p = document.getElementById('verify-task-input').value;
        if(p.length < 3) return showToast("Error", "Proof too short", "error");
        document.getElementById('verify-task-modal').classList.add('hidden');
        apiCall('claim_dynamic_task', {taskId: id, proof: p});
    }

    function renderReferrals() {
        const c = document.getElementById('referral-list-container');
        if(!appState.referrals || appState.referrals.length === 0) { c.innerHTML = '<p class="text-[10px] text-slate-500 text-center font-bold uppercase py-4">No referrals yet.</p>'; return; }
        
        c.innerHTML = appState.referrals.map(r => {
            const isA = r.status === 'Approved';
            const sCol = isA ? 'text-emerald-400' : 'text-amber-400';
            const sBg = isA ? 'bg-emerald-500/10 border-emerald-500/30' : 'bg-amber-500/10 border-amber-500/30';
            return `<div class="glass-card p-3 rounded-xl border border-slate-700/50 flex items-center justify-between"><div class="flex items-center gap-3"><div class="w-9 h-9 rounded-full bg-slate-800 flex items-center justify-center text-slate-400 text-xs font-black uppercase border border-slate-600">${r.name.substring(0,2)}</div><div><h4 class="text-[11px] font-black text-white">${r.name}</h4><p class="text-[9px] text-slate-400">${r.joinDate}</p></div></div><div class="text-right"><div class="px-2 py-1 rounded-md border ${sBg} ${sCol} text-[8px] font-black uppercase tracking-widest mb-1 inline-block">${r.status}</div><p class="text-[9px] text-slate-500 font-bold uppercase">Ads: ${r.ads}/25 | Tasks: ${r.tasks}/5</p></div></div>`;
        }).join('');
    }

    function renderWithdrawHistory() {
        const c = document.getElementById('withdraw-history-container');
        if(!appState.withdrawals || appState.withdrawals.length === 0) { c.innerHTML = '<p class="text-[10px] text-slate-500 text-center font-bold uppercase py-4">No withdrawals yet.</p>'; return; }
        
        c.innerHTML = appState.withdrawals.map(w => {
            let sCol = 'text-amber-400', sIcn = 'fa-clock';
            if(w.status==='Approved'){ sCol='text-emerald-400'; sIcn='fa-check-double'; }
            if(w.status==='Rejected'){ sCol='text-red-400'; sIcn='fa-xmark'; }
            
            return `<div class="glass-card p-3 rounded-xl border border-slate-700/50 flex items-center justify-between"><div class="flex items-center gap-3"><div class="w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center ${sCol}"><i class="fa-solid ${sIcn}"></i></div><div><h4 class="text-[11px] font-black text-white">Withdrawal</h4><p class="text-[9px] text-slate-400">${w.date} • ${w.id}</p></div></div><div class="text-right"><h4 class="text-[13px] font-black text-emerald-400">-$${w.amount}</h4><p class="text-[9px] font-bold uppercase ${sCol}">${w.status}</p></div></div>`;
        }).join('');
    }

    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));
      const activeBtn = document.querySelector(`.nav-btn[data-target="${tabId}"]`);
      if (activeBtn) activeBtn.classList.add('nav-active');
      if (tabId === 'admin') { apiCall('admin_dashboard'); }
      window.scrollTo(0,0);
    }

    function copyRefLink() { const l = document.getElementById('ref-link-input'); l.select(); document.execCommand('copy'); showToast("Copied!", "Referral link copied to clipboard.", "success"); }
    function shareReferralTelegram() { const l = document.getElementById('ref-link-input').value; tg.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(l)}&text=${encodeURIComponent("Join XPVerse and earn rewards with me!")}`); }
    
    async function watchAd() {
      if (appState.user.adsWatchedToday >= appState.userLimit) return showToast("Limit Reached", "You have reached your daily ad limit.", "error");
      const ids = appState.settings.adBlockIds || ["int-35545"];
      const id = ids[Math.floor(Math.random() * ids.length)];
      try {
        const ad = await window.Adsgram.init({ blockId: id });
        ad.show().then((res) => { apiCall('watch_ad'); }).catch((err) => { 
            // Silent error catching for user, but log it to server
            apiCall('log_client_error', {msg: err?.description || 'Ad closed or failed', cause: id, page: 'watchAd'}, true); 
        });
      } catch (e) {
          apiCall('log_client_error', {msg: e.message, cause: 'Adsgram Init Failed', page: 'watchAd'}, true);
      }
    }

    function openBox(type) { apiCall('open_box', {boxType: type}).then(r => { if(r && r.reward) { if(r.jackpot) showToast("JACKPOT!", `You won $${r.reward}!`, "jackpot"); else showToast("Box Opened", `You won $${r.reward}`, "success"); } }); }
    function requestWithdrawal() {
      const a = document.getElementById('wallet-address').value; const m = parseFloat(document.getElementById('withdraw-amount').value);
      if(m < 10) return showToast("Error", "Minimum withdrawal is $10", "error");
      if(m > appState.user.usd) return showToast("Error", "Insufficient balance", "error");
      if(a.length < 5) return showToast("Error", "Invalid wallet address", "error");
      apiCall('withdraw', {amount: m, address: a}).then(r => { if(r && r.success) { showToast("Success", "Withdrawal requested", "success"); document.getElementById('withdraw-amount').value=''; } });
    }

    function startTimer(sTime, rTime) {
      if(timerInterval) clearInterval(timerInterval);
      let t = rTime - sTime;
      timerInterval = setInterval(() => {
        t--; if(t<=0){ clearInterval(timerInterval); location.reload(); return; }
        const h = Math.floor(t / 3600); const m = Math.floor((t % 3600) / 60); const s = Math.floor(t % 60);
        document.getElementById('reset-timer').innerText = `${h.toString().padStart(2,'0')}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
      }, 1000);
    }

    // SILENT REAL-TIME SYNC
    setInterval(() => { if(!document.hidden && appState.user.tgId) apiCall('sync', {}, true); }, 25000);

    // ==========================================
    // ADMIN PANEL JS
    // ==========================================
    function switchAdminTab(tab) {
        document.querySelectorAll('.admin-section').forEach(e => e.classList.add('hidden'));
        document.getElementById(`admin-sec-${tab}`).classList.remove('hidden');
        document.querySelectorAll('.admin-tab').forEach(e => { e.classList.remove('bg-red-600/20','text-red-400','border'); e.classList.add('text-slate-400'); });
        const tb = document.getElementById(`tab-${tab}`);
        tb.classList.add('bg-red-600/20','text-red-400','border','border-red-500/50');
        tb.classList.remove('text-slate-400');

        if(tab==='dashboard') {
            document.getElementById('adm-stat-users').innerText = appState.admin.stats.users;
            document.getElementById('adm-stat-active').innerText = appState.admin.stats.active;
            document.getElementById('adm-stat-usd').innerText = `$${formatNum(appState.admin.stats.usd, true)}`;
            document.getElementById('adm-stat-ads').innerText = appState.admin.stats.ads;
            document.getElementById('adm-stat-tasks').innerText = appState.admin.stats.tasks;
        }
        else if (tab==='users') renderAdminUsers();
        else if (tab==='tasks') renderAdminTasks();
        else if (tab==='withdrawals') renderAdminWithdraws();
        else if (tab==='errors') renderAdminErrors();
        else if (tab==='settings') {
            const s = appState.settings;
            document.getElementById('adm-set-sdk').value = s.adBlockIds.join(', ');
            document.getElementById('adm-set-adlimit').value = s.globalAdLimit;
            document.getElementById('adm-set-reset').value = s.resetTime;
            document.getElementById('adm-set-maintenance').checked = s.maintenance;
            document.getElementById('adm-set-xp2x').checked = s.xp2xEnabled;
            document.getElementById('adm-set-xp2x-perm').checked = s.xp2xPermanent;
            document.getElementById('adm-set-xp2x-st').value = s.xp2xStart;
            document.getElementById('adm-set-xp2x-ed').value = s.xp2xEnd;
        }
    }

    function renderAdminUsers() {
        const q = document.getElementById('admin-user-search').value.toLowerCase();
        let list = appState.admin.users.filter(u => u.tgId.includes(q) || (u.username||'').toLowerCase().includes(q) || u.firstName.toLowerCase().includes(q));
        document.getElementById('admin-user-list').innerHTML = list.map(u => {
            const isBan = u.banned || appState.admin.banned.includes(u.tgId);
            return `<div onclick="openAdminUserDetail('${u.tgId}')" class="glass-card p-2 rounded-lg border border-slate-700/50 flex items-center justify-between cursor-pointer hover:bg-slate-800/50">
            <div class="flex items-center gap-2"><img src="${u.photoUrl||'https://ui-avatars.com/api/?name=U'}" class="w-8 h-8 rounded-full border border-blue-500/30">
            <div><p class="text-[11px] font-black text-white">${u.firstName} ${u.lastName||''}</p><p class="text-[9px] text-slate-400 font-mono">${u.tgId}</p></div></div>
            <div class="text-right flex flex-col items-end gap-1"><span class="text-[8px] font-black uppercase px-1.5 py-0.5 rounded ${u.isOnline?'bg-emerald-500/20 text-emerald-400':'bg-slate-700 text-slate-400'}">${u.isOnline?'Aktiv':'Çevrımdışı'}</span>
            ${isBan ? '<span class="text-[8px] bg-red-500/20 text-red-400 px-1.5 py-0.5 rounded uppercase font-black">Ban</span>' : ''}</div></div>`;
        }).join('');
    }

    function openAdminUserDetail(uid) {
        const u = appState.admin.users.find(x => x.tgId === uid); if(!u) return;
        const isBan = u.banned || appState.admin.banned.includes(u.tgId);
        
        let html = `
        <div class="flex items-center gap-3 mb-4">
            <img src="${u.photoUrl||'https://ui-avatars.com/api/?name=U'}" class="w-12 h-12 rounded-full border-2 border-blue-500">
            <div><h4 class="text-sm font-black text-white">${u.firstName} ${u.lastName||''}</h4>
            <p class="text-xs text-slate-400 font-mono">${u.tgId}</p></div>
        </div>
        <div class="grid grid-cols-2 gap-2 mb-4">
            <div class="bg-[#050511] p-2 rounded border border-slate-800"><p class="text-[9px] text-slate-500 uppercase">Bu Gün Reklam</p><p class="text-sm font-black text-white">${u.adsWatchedToday}</p></div>
            <div class="bg-[#050511] p-2 rounded border border-slate-800"><p class="text-[9px] text-slate-500 uppercase">Total Reklam</p><p class="text-sm font-black text-white">${u.totalAdsWatched}</p></div>
            <div class="bg-[#050511] p-2 rounded border border-slate-800"><p class="text-[9px] text-slate-500 uppercase">Referans (Təsdiqlənən)</p><p class="text-sm font-black text-emerald-400">${u.refTotal} (${u.refApproved})</p></div>
            <div class="bg-[#050511] p-2 rounded border border-slate-800"><p class="text-[9px] text-slate-500 uppercase">Son Aktivlik</p><p class="text-[10px] font-black text-white">${u.lastActiveBaku}</p></div>
        </div>
        <div class="space-y-2 border border-slate-800 p-2 rounded bg-[#0a0b1a]">
            <p class="text-[10px] font-black text-slate-400 uppercase">Balans İdarə</p>
            <div class="flex gap-2">
                <input type="number" id="adm-u-xp" value="${u.xp}" class="w-full bg-[#050511] border border-slate-700 rounded p-1.5 text-xs text-white" placeholder="XP">
                <input type="number" step="0.01" id="adm-u-usd" value="${u.usd}" class="w-full bg-[#050511] border border-slate-700 rounded p-1.5 text-xs text-white" placeholder="USDT">
                <button onclick="adminActionUser('${u.tgId}', 'update_balance')" class="bg-blue-600 text-white px-3 py-1.5 rounded text-[10px] font-black">Yenilə</button>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-2 mt-4">
            <button onclick="adminActionUser('${u.tgId}', 'reset_ads')" class="py-2 bg-amber-600 text-white rounded text-[10px] font-black uppercase">Reklam Sıfırla</button>
            <button onclick="adminActionUser('${u.tgId}', 'reset_balance')" class="py-2 bg-red-900 text-white rounded text-[10px] font-black uppercase">Balans Sıfırla</button>
            ${isBan ? `<button onclick="adminActionUser('${u.tgId}', 'unban')" class="py-2 bg-emerald-600 text-white rounded text-[10px] font-black uppercase">Banı Aç</button>` : `<button onclick="adminActionUser('${u.tgId}', 'preban')" class="py-2 bg-red-600 text-white rounded text-[10px] font-black uppercase">Ban Et</button>`}
            <button onclick="apiCall('admin_manage_admin', {targetUid: '${u.tgId}', isAdd: true})" class="py-2 bg-purple-600 text-white rounded text-[10px] font-black uppercase">Admin Et</button>
        </div>`;
        document.getElementById('admin-user-modal-content').innerHTML = html;
        document.getElementById('admin-user-modal').style.display = 'flex';
        document.getElementById('admin-user-modal').classList.remove('hidden');
    }

    async function adminActionUser(uid, action) {
        let payload = {targetUid: uid, userAction: action};
        if(action === 'update_balance') { payload.newXp = document.getElementById('adm-u-xp').value; payload.newUsd = document.getElementById('adm-u-usd').value; }
        await apiCall('admin_action_user', payload);
        await apiCall('admin_dashboard', {}, true);
        openAdminUserDetail(uid); 
    }
    async function adminGlobalAction(act) { if(confirm("Əminsiniz?")) { await apiCall('admin_global_action', {globalAction: act}); apiCall('admin_dashboard', {}, true); } }
    async function adminPreBan() { const u = document.getElementById('preban-uid').value; if(u) { await apiCall('admin_action_user', {targetUid: u, userAction: 'preban'}); apiCall('admin_dashboard', {}, true); } }

    function openTaskModal(t = null) {
        document.getElementById('task-id').value = t ? t.id : '';
        document.getElementById('task-name').value = t ? t.name : '';
        document.getElementById('task-type').value = t ? t.type : 'normal';
        document.getElementById('task-is-daily').value = t ? (t.isDaily?1:0) : '0';
        document.getElementById('task-reward-xp').value = t ? t.rewardXp : 0;
        document.getElementById('task-reward-usd').value = t ? t.rewardUsd : 0;
        document.getElementById('task-link').value = t ? t.link : '';
        document.getElementById('task-note').value = t ? t.note : '';
        document.getElementById('task-require-verify').checked = t ? t.requireVerification : false;
        document.getElementById('task-uid-example').value = t ? (t.uidExample||'') : '';
        document.getElementById('task-limit').value = t ? (t.completionLimit||0) : 0;
        document.getElementById('admin-task-modal').style.display = 'flex';
        document.getElementById('admin-task-modal').classList.remove('hidden');
    }

    async function saveTask() {
        const task = {
            id: document.getElementById('task-id').value,
            name: document.getElementById('task-name').value,
            type: document.getElementById('task-type').value,
            isDaily: document.getElementById('task-is-daily').value === '1',
            rewardXp: parseInt(document.getElementById('task-reward-xp').value||0),
            rewardUsd: parseFloat(document.getElementById('task-reward-usd').value||0),
            link: document.getElementById('task-link').value,
            note: document.getElementById('task-note').value,
            requireVerification: document.getElementById('task-require-verify').checked,
            uidExample: document.getElementById('task-uid-example').value,
            completionLimit: parseInt(document.getElementById('task-limit').value||0)
        };
        await apiCall('admin_task_save', {task});
        document.getElementById('admin-task-modal').classList.add('hidden');
        await apiCall('admin_dashboard', {}, true);
        renderAdminTasks();
    }
    
    async function delTask(id) { if(confirm("Tapşırığı silmək istəyirsiniz?")) { await apiCall('admin_task_delete', {taskId: id}); await apiCall('admin_dashboard', {}, true); renderAdminTasks(); } }

    function renderAdminTasks() {
        document.getElementById('admin-tasks-list').innerHTML = appState.dynTasks.map(t => {
            return `<div class="glass-card p-3 rounded-lg border border-slate-700 flex justify-between items-center"><div class="flex-1"><p class="text-xs font-black text-white">${t.name} <span class="text-[8px] bg-blue-900 text-blue-300 px-1 rounded">${t.type}</span></p><p class="text-[9px] text-slate-400">XP: ${t.rewardXp} | USDT: ${t.rewardUsd} | Limit: ${t.completions}/${t.completionLimit||'∞'}</p></div><div class="flex gap-2"><button onclick='openTaskModal(${JSON.stringify(t)})' class="bg-slate-700 text-white w-7 h-7 rounded"><i class="fa-solid fa-pen text-xs"></i></button><button onclick="delTask('${t.id}')" class="bg-red-600 text-white w-7 h-7 rounded"><i class="fa-solid fa-trash text-xs"></i></button></div></div>`;
        }).join('');
    }

    function renderAdminWithdraws() {
        let wList = [...appState.admin.withdrawals].sort((a,b) => (a.status==='Pending'?-1:1));
        document.getElementById('admin-withdrawal-list').innerHTML = wList.map(w => {
            let acts = '';
            if(w.status === 'Pending') acts = `<button onclick="procWithdraw('${w.user_id}', ${w.idx}, 'approve')" class="bg-emerald-600 px-2 py-1 rounded text-[9px] font-black text-white">Təsdiq</button><button onclick="procWithdraw('${w.user_id}', ${w.idx}, 'reject')" class="bg-red-600 px-2 py-1 rounded text-[9px] font-black text-white">Rədd</button>`;
            else acts = `<span class="text-[9px] font-black uppercase ${w.status==='Approved'?'text-emerald-400':'text-red-400'}">${w.status}</span>`;
            return `<div class="glass-card p-3 rounded border border-slate-700 mb-2"><div class="flex justify-between items-start mb-2"><div><p class="text-[11px] font-black text-white">${w.user_name} (${w.user_id})</p><p class="text-[9px] text-slate-400">${w.date}</p></div><p class="text-sm font-black text-emerald-400">$${w.amount}</p></div><div class="flex justify-between items-center"><p class="text-[10px] text-slate-300 font-mono bg-[#050511] px-2 py-1 rounded select-all">${w.address}</p><div class="flex gap-2">${acts}</div></div></div>`;
        }).join('');
    }
    
    async function procWithdraw(uid, idx, act) { await apiCall('admin_action_withdraw', {targetUid: uid, idx: idx, withdrawAction: act}); await apiCall('admin_dashboard', {}, true); renderAdminWithdraws(); }

    function renderAdminErrors() {
        document.getElementById('admin-error-list').innerHTML = appState.admin.errors.map(e => {
            const dt = new Date(e.time*1000).toLocaleString('az-AZ');
            return `<div class="glass-card p-2 rounded border border-red-900/50 mb-2"><div class="flex justify-between items-center"><p class="text-[10px] font-black text-red-400">${e.type} <span class="text-slate-500 font-normal">(${e.page})</span></p><p class="text-[8px] text-slate-500">${dt}</p></div><p class="text-[11px] text-white mt-1">${e.message}</p><p class="text-[9px] text-slate-400 mt-0.5">UID: ${e.uid}</p><p class="text-[9px] text-slate-500 font-mono mt-1 p-1 bg-[#050511] rounded break-all">${e.cause}</p></div>`;
        }).join('');
    }

    async function saveAdminSettings() {
        const set = {
            blockIds: document.getElementById('adm-set-sdk').value,
            globalAdLimit: document.getElementById('adm-set-adlimit').value,
            resetTime: document.getElementById('adm-set-reset').value,
            maintenance: document.getElementById('adm-set-maintenance').checked,
            xp2xEnabled: document.getElementById('adm-set-xp2x').checked,
            xp2xPermanent: document.getElementById('adm-set-xp2x-perm').checked,
            xp2xStart: document.getElementById('adm-set-xp2x-st').value,
            xp2xEnd: document.getElementById('adm-set-xp2x-ed').value
        };
        await apiCall('admin_update_settings', set);
    }

    window.onload = () => { apiCall('init').then(r => { document.getElementById('loading-overlay').style.opacity = '0'; setTimeout(()=>document.getElementById('loading-overlay').style.display = 'none', 500); }); };
  </script>
</body>
</html>
