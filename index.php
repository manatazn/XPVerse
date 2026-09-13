<?php
// XPVERSE - SINGLE FILE ARCHITECTURE
// Təhlükəsizlik və Xəta İzləmə Sistemi əlavə edilib.

error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Moscow');
$azTimezone = new DateTimeZone('Asia/Baku');
$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// 1. GİZLİ XƏTA İZLƏMƏ SİSTEMİ (Admin üçün)
function logErrorForAdmin($uid, $errorMsg, $action = 'Sistem') {
    global $dataDir;
    $path = "$dataDir/admin_errors.json";
    
    $fp = fopen($path, 'a+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    fseek($fp, 0, SEEK_END);
    $size = ftell($fp);
    rewind($fp);
    
    $json = $size > 0 ? fread($fp, $size) : '[]';
    $errors = json_decode($json, true) ?: [];
    
    $nowStr = (new DateTime('now', new DateTimeZone('Asia/Baku')))->format('Y-m-d H:i:s');
    
    array_unshift($errors, [
        'id' => uniqid('err_'),
        'uid' => $uid,
        'username' => 'Bilinmir', // Aşağıda istifadəçi tapılarsa yenilənəcək
        'action' => $action,
        'error' => $errorMsg,
        'date' => $nowStr
    ]);
    
    if (count($errors) > 300) $errors = array_slice($errors, 0, 300); // Fayl şişməsinin qarşısını alırıq
    
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($errors, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Qlobal server xətalarını tutmaq (İstifadəçiyə heç nə getməsin)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logErrorForAdmin('SYSTEM', "$errstr ($errfile : $errline)");
    return true; 
});
set_exception_handler(function($e) {
    logErrorForAdmin('SYSTEM', $e->getMessage());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode(['error' => 'Bağlantı və ya server xətası. Gözləyin.']);
        exit;
    }
});

// Təhlükəsiz Read/Write funksiyaları (Path Traversal qarşısı alınıb)
function readDB($filename) {
    global $dataDir;
    $filename = basename($filename); // Təhlükəsizlik
    $path = "$dataDir/$filename";
    if (!file_exists($path)) return [];
    
    $fp = fopen($path, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $size = filesize($path);
    $json = $size > 0 ? fread($fp, $size) : '[]';
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return json_decode($json, true) ?: [];
}

function writeDB($filename, $data) {
    global $dataDir;
    $filename = basename($filename); // Təhlükəsizlik
    $path = "$dataDir/$filename";
    $fp = fopen($path, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

$settings = readDB('settings.json');
if (empty($settings)) {
    $settings = [
        'adBlockIds' => ['int-35545'],
        'maintenance' => false,
        'adminUid' => '5461064199',
        'resetTime' => '03:00',
        'xpMultiplier' => 1
    ];
    writeDB('settings.json', $settings);
}

$now = new DateTime('now');
$resetHourStr = $settings['resetTime'] ?? '03:00';
list($rHour, $rMin) = explode(':', $resetHourStr);

$resetTarget = clone $now;
$resetTarget->setTime((int)$rHour, (int)$rMin, 0);
if ($now < $resetTarget) {
    $logicalDate = clone $now;
    $logicalDate->modify('-1 day');
    $today = $logicalDate->format('Y-m-d');
} else {
    $today = $now->format('Y-m-d');
}

if ($now >= $resetTarget) {
    $resetTarget->modify('+1 day');
}

// Xüsusi xəta qaytarma funksiyası
function sendApiError($uid, $action, $logMsg, $userMsg) {
    logErrorForAdmin($uid, $logMsg, $action);
    echo json_encode(['error' => $userMsg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action']) || !isset($input['tgId'])) {
        sendApiError('UNKNOWN', 'Invalid Request', 'Yanlış POST sorğusu və ya məlumat yoxdur.', 'Yanlış sorğu.');
    }

    $action = $input['action'];
    $uid = (string)$input['tgId'];
    $isSilentSync = $input['silent'] ?? false;
    $masterAdmin = $settings['adminUid'] ?? '5461064199';

    if (isset($settings['maintenance']) && $settings['maintenance'] === true) {
        if ($uid !== $masterAdmin && $uid !== '5461064199') {
            echo json_encode(['error' => 'MAINTENANCE', 'message' => 'Sistemdə baxım işləri gedir.']);
            exit;
        }
    }

    $users = readDB('users.json');
    $referrals = readDB('referrals.json');
    $rewards = readDB('rewards.json');
    $withdrawals = readDB('withdrawals.json');
    $tasks = readDB('tasks.json');
    $customTasks = readDB('custom_tasks.json');
    $submissions = readDB('task_submissions.json');
    
    if (isset($users[$uid]['banned']) && $users[$uid]['banned'] === true && $uid !== $masterAdmin) {
        echo json_encode(['error' => 'BANNED', 'message' => 'Hesabınız admin tərəfindən bloklanıb.']);
        exit;
    }

    if (!isset($users[$uid])) {
        $users[$uid] = [
            'tgId' => $uid,
            'firstName' => htmlspecialchars($input['firstName'] ?? 'User'),
            'lastName' => htmlspecialchars($input['lastName'] ?? ''),
            'username' => htmlspecialchars($input['username'] ?? ''),
            'photoUrl' => $input['photoUrl'] ?? '',
            'xp' => 0, 'totalXp' => 0, 'xpSpent' => 0, 'dailyXp' => 0, 'usd' => 0.00,
            'level' => 1, 'adsWatchedToday' => 0, 'totalAdsWatched' => 0, 'tasksCompleted' => 0,
            'boxesOpened' => 0, 'streak' => 1, 'lastResetDay' => $today,
            'referrer' => null, 'sponsorAzx' => false, 'rejectedTasks' => [],
            'lastActive' => $now->format('Y-m-d H:i:s'), 'banned' => false
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
                    'status' => 'Pending',
                    'ads' => 0, 'tasks' => 0,
                    'joinDate' => $now->format('M j, Y')
                ];
                writeDB('referrals.json', $referrals);
            }
        }
    } else {
        if (isset($input['firstName'])) $users[$uid]['firstName'] = htmlspecialchars($input['firstName']);
        if (isset($input['lastName'])) $users[$uid]['lastName'] = htmlspecialchars($input['lastName']);
        if (isset($input['username'])) $users[$uid]['username'] = htmlspecialchars($input['username']);
        if (isset($input['photoUrl']) && !empty($input['photoUrl'])) $users[$uid]['photoUrl'] = $input['photoUrl'];
        if (!$isSilentSync) {
            $users[$uid]['lastActive'] = $now->format('Y-m-d H:i:s');
        }
        if (!isset($users[$uid]['xpSpent'])) $users[$uid]['xpSpent'] = 0;
        if (!isset($users[$uid]['dailyXp'])) $users[$uid]['dailyXp'] = 0;
        if (!isset($users[$uid]['rejectedTasks'])) $users[$uid]['rejectedTasks'] = [];
    }

    if ($users[$uid]['lastResetDay'] !== $today) {
        $lastDay = strtotime($users[$uid]['lastResetDay']);
        $currDay = strtotime($today);
        $diff = round(($currDay - $lastDay) / 86400);
        
        if ($diff === 1) {
            $users[$uid]['streak'] = min($users[$uid]['streak'] + 1, 7);
        } else {
            $users[$uid]['streak'] = 1;
        }
        
        $users[$uid]['adsWatchedToday'] = 0;
        $users[$uid]['dailyXp'] = 0;
        $users[$uid]['lastResetDay'] = $today;
        if(isset($tasks[$uid])) $tasks[$uid] = [];
    }

    // Sistemin Username yeniləməsi (Xətalar logu üçün)
    $dbErrors = readDB('admin_errors.json');
    $errorsChanged = false;
    foreach($dbErrors as &$e) {
        if($e['uid'] === $uid && $e['username'] === 'Bilinmir' && !empty($users[$uid]['username'])) {
            $e['username'] = $users[$uid]['username'];
            $errorsChanged = true;
        }
    }
    if($errorsChanged) writeDB('admin_errors.json', $dbErrors);

    function calcLevel($xp) {
        if ($xp >= 20000) return 10;
        if ($xp >= 3000) return 5;
        if ($xp >= 1500) return 4;
        if ($xp >= 750) return 3;
        if ($xp >= 250) return 2;
        return 1;
    }

    function evaluateReferralProgress($refUid, &$users, &$referrals, &$rewards) {
        global $now;
        $refUser = $users[$refUid];
        if (empty($refUser['referrer'])) return;
        
        $referrerId = $refUser['referrer'];
        if (!isset($referrals[$referrerId])) return;
        
        $referralChanged = false;
        $rewardAdded = false;
        
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
                    $referralChanged = true;
                    $rewardAdded = true;
                    
                    $users[$referrerId]['xp'] += 250;
                    $users[$referrerId]['totalXp'] += 250;
                    $users[$referrerId]['level'] = calcLevel($users[$referrerId]['totalXp']);
                    $users[$referrerId]['usd'] += 0.025;
                    
                    if (!isset($rewards[$referrerId])) $rewards[$referrerId] = [];
                    $refName = trim(($refUser['firstName'] ?? '') . ' ' . ($refUser['lastName'] ?? ''));
                    array_unshift($rewards[$referrerId], [
                        'title' => 'Referral Bonus',
                        'desc' => "Referral: " . $refName,
                        'xp' => 250,
                        'usd' => 0.025,
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
    $response['serverTime'] = $now->getTimestamp();
    $response['serverResetTime'] = $resetTarget->getTimestamp(); 
    $response['settings'] = [
        'adBlockIds' => $settings['adBlockIds'],
        'xpMultiplier' => (float)($settings['xpMultiplier'] ?? 1)
    ];

    // --- ADMIN PANEL ROUTES ---
    if (strpos($action, 'admin_') === 0) {
        if ($uid !== $masterAdmin && $uid !== '5461064199') {
            sendApiError($uid, $action, "Yetkisiz admin girişi cəhdi", "Yetkisiz giriş.");
        }
        if (!isset($input['adminCode']) || $input['adminCode'] !== 'PZX9N4ML2DK') {
            sendApiError($uid, $action, "Yanlış Admin Kodu istifadə olundu", "Yanlış kod.");
        }

        if ($action === 'admin_dashboard') {
            $totalUsd = 0; $totalAds = 0; $totalTasks = 0; $totalXp = 0; $totalUsers = count($users);
            $totalRefs = 0;
            foreach($users as &$u) {
                $totalUsd += $u['usd']; $totalAds += $u['totalAdsWatched'];
                $totalTasks += $u['tasksCompleted']; $totalXp += $u['totalXp'];
                $lastAct = new DateTime($u['lastActive']);
                $lastAct->setTimezone($azTimezone);
                $u['lastActiveAz'] = $lastAct->format('Y-m-d H:i:s');
                $u['refCount'] = isset($referrals[$u['tgId']]) ? count($referrals[$u['tgId']]) : 0;
            }
            foreach($withdrawals as $wList) {
                foreach($wList as $w) { if ($w['status'] === 'Approved') $totalUsd += $w['amount']; }
            }
            foreach($referrals as $rList) { $totalRefs += count($rList); }

            $allWithdrawals = [];
            foreach($withdrawals as $uId => $uWithdrawals) {
                foreach($uWithdrawals as $idx => $w) {
                    $w['user_id'] = $uId; $w['idx'] = $idx;
                    $w['username'] = $users[$uId]['username'] ?? '';
                    $w['user_usd'] = $users[$uId]['usd'] ?? 0;
                    $w['user_xp_spent'] = $users[$uId]['xpSpent'] ?? 0;
                    $allWithdrawals[] = $w;
                }
            }
            
            $response['stats'] = [
                'users' => $totalUsers, 'usd' => $totalUsd, 'ads' => $totalAds, 
                'tasks' => $totalTasks, 'xp' => $totalXp, 'refs' => $totalRefs
            ];
            $response['all_users'] = array_values($users);
            $response['all_withdrawals'] = $allWithdrawals;
            $response['custom_tasks'] = $customTasks;
            $response['submissions'] = $submissions;
            $response['adminSettings'] = $settings;
            $response['errors_log'] = readDB('admin_errors.json');
            echo json_encode($response); exit;
        }

        if ($action === 'admin_update_settings') {
            $settings['adBlockIds'] = $input['blockIds'] ?? $settings['adBlockIds'];
            $settings['maintenance'] = $input['maintenance'] ?? $settings['maintenance'];
            $settings['adminUid'] = $input['adminUid'] ?? $settings['adminUid'];
            $settings['resetTime'] = $input['resetTime'] ?? $settings['resetTime'];
            $settings['xpMultiplier'] = (float)($input['xpMultiplier'] ?? 1);
            writeDB('settings.json', $settings);
            $response['message'] = 'Tənzimləmələr uğurla yeniləndi.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_reset_all_ads') {
            foreach($users as $k => $u) { $users[$k]['adsWatchedToday'] = 0; }
            writeDB('users.json', $users);
            $response['message'] = 'Bütün istifadəçilərin reklam limiti sıfırlandı.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_reset_bot') {
            writeDB('users.json', []); writeDB('referrals.json', []); 
            writeDB('withdrawals.json', []); writeDB('tasks.json', []); writeDB('task_submissions.json', []);
            $response['message'] = 'Bot tam sıfırlandı (Bütün məlumatlar silindi).';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_save_task') {
            $taskId = $input['taskId'] ?? uniqid('task_');
            $customTasks[$taskId] = [
                'id' => $taskId,
                'title' => htmlspecialchars($input['title']),
                'desc' => htmlspecialchars($input['desc']),
                'link' => htmlspecialchars($input['link']),
                'reward' => (int)$input['reward'],
                'requireInfo' => (bool)$input['requireInfo'],
                'infoLabel' => htmlspecialchars($input['infoLabel'] ?? 'UID:'),
                'active' => (bool)$input['active']
            ];
            writeDB('custom_tasks.json', $customTasks);
            $response['message'] = 'Tapşırıq uğurla yadda saxlanıldı.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_action_submission') {
            $subId = $input['subId']; $subAction = $input['subAction'];
            foreach ($submissions as $k => $sub) {
                if ($sub['subId'] === $subId && $sub['status'] === 'Pending') {
                    $targetUid = $sub['uid'];
                    if ($subAction === 'approve') {
                        $submissions[$k]['status'] = 'Approved';
                        $tReward = isset($customTasks[$sub['taskId']]) ? $customTasks[$sub['taskId']]['reward'] : 0;
                        if(isset($users[$targetUid])) {
                            $users[$targetUid]['xp'] += $tReward;
                            $users[$targetUid]['totalXp'] += $tReward;
                            $users[$targetUid]['tasksCompleted'] += 1;
                            if(!isset($tasks[$targetUid])) $tasks[$targetUid] = [];
                            $tasks[$targetUid][] = $sub['taskId'];
                        }
                    } else {
                        $submissions[$k]['status'] = 'Rejected';
                        if(isset($users[$targetUid])) {
                            if(!isset($users[$targetUid]['rejectedTasks'])) $users[$targetUid]['rejectedTasks'] = [];
                            $users[$targetUid]['rejectedTasks'][] = $sub['taskId'];
                        }
                    }
                    writeDB('task_submissions.json', $submissions);
                    writeDB('users.json', $users); writeDB('tasks.json', $tasks);
                    $response['message'] = 'Təsdiq prosesi icra olundu.';
                    echo json_encode($response); exit;
                }
            }
            sendApiError($uid, $action, "Admin təsdiq tapmadı: $subId", "Təsdiq tapılmadı.");
        }

        if ($action === 'admin_action_user') {
            $targetUid = $input['targetUid']; $act = $input['userAction'];
            if (!isset($users[$targetUid])) sendApiError($uid, $action, "İstifadəçi tapılmadı: $targetUid", "İstifadəçi tapılmadı");
            
            if ($act === 'ban') { $users[$targetUid]['banned'] = true; } 
            elseif ($act === 'unban') { $users[$targetUid]['banned'] = false; } 
            elseif ($act === 'reset_ads') { $users[$targetUid]['adsWatchedToday'] = 0; } 
            elseif ($act === 'update_balance') {
                $users[$targetUid]['usd'] = max(0, (float)$input['newUsd']);
                $users[$targetUid]['xp'] = max(0, (int)$input['newXp']);
                $users[$targetUid]['totalXp'] = max($users[$targetUid]['totalXp'], $users[$targetUid]['xp']);
            }
            writeDB('users.json', $users);
            $response['message'] = 'İstifadəçi yeniləndi.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_action_withdraw') {
            $targetUid = $input['targetUid']; $idx = $input['idx']; $wAct = $input['withdrawAction'];
            if (isset($withdrawals[$targetUid][$idx])) {
                if ($withdrawals[$targetUid][$idx]['status'] === 'Pending') {
                    if ($wAct === 'approve') {
                        $withdrawals[$targetUid][$idx]['status'] = 'Approved';
                    } else if ($wAct === 'reject') {
                        $withdrawals[$targetUid][$idx]['status'] = 'Rejected';
                        $users[$targetUid]['usd'] += $withdrawals[$targetUid][$idx]['amount'];
                        writeDB('users.json', $users);
                    }
                    writeDB('withdrawals.json', $withdrawals);
                    $response['message'] = 'Çıxarış prosesi icra olundu.';
                } else {
                    sendApiError($uid, $action, "Çıxarış artıq icra edilib", "Artıq icra olunub.");
                }
            } else {
                sendApiError($uid, $action, "Çıxarış tapılmadı", "Çıxarış tapılmadı.");
            }
            echo json_encode($response); exit;
        }
    }
    // --- END ADMIN ROUTES ---

    // NORMAL USER ACTIONS
    $mult = (float)($settings['xpMultiplier'] ?? 1);

    switch ($action) {
        case 'watch_ad':
            if ($users[$uid]['adsWatchedToday'] < 30) {
                $gain = 20 * $mult;
                $users[$uid]['adsWatchedToday'] += 1;
                $users[$uid]['totalAdsWatched'] += 1;
                $users[$uid]['xp'] += $gain;
                $users[$uid]['totalXp'] += $gain;
                $users[$uid]['dailyXp'] += $gain;
                $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
                $response['gain'] = $gain;
            } else {
                sendApiError($uid, $action, "Reklam limiti dolub", "Bu gün üçün reklam limitiniz dolub.");
            }
            break;

        case 'claim_task':
            $taskId = $input['taskId'] ?? '';
            if ($taskId === 'sponsor_azx') {
                if (empty($users[$uid]['sponsorAzx'])) {
                    $gain = 200 * $mult;
                    $users[$uid]['sponsorAzx'] = true;
                    $users[$uid]['xp'] += $gain;
                    $users[$uid]['totalXp'] += $gain;
                    $users[$uid]['dailyXp'] += $gain;
                    $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                    evaluateReferralProgress($uid, $users, $referrals, $rewards);
                    $response['gain'] = $gain;
                } else {
                    sendApiError($uid, $action, "Sponsor tapşırığı artıq edilib", "Tapşırıq artıq tamamlanıb.");
                }
            } else {
                if (!isset($tasks[$uid])) $tasks[$uid] = [];
                if (!in_array($taskId, $tasks[$uid])) {
                    if ($taskId === 'complete_all' && $users[$uid]['adsWatchedToday'] < 30) {
                        sendApiError($uid, $action, "Bütün tapşırıqlar bitməyib", "Əvvəlcə bütün gündəlik tapşırıqları bitirin.");
                    }
                    
                    $rewardXp = (int)($input['reward'] ?? 0);
                    if(isset($customTasks[$taskId]) && !$customTasks[$taskId]['requireInfo']) {
                        $rewardXp = $customTasks[$taskId]['reward'];
                    }

                    if ($rewardXp > 0) { 
                        $gain = $rewardXp * $mult;
                        $tasks[$uid][] = $taskId;
                        $users[$uid]['tasksCompleted'] += 1;
                        $users[$uid]['xp'] += $gain;
                        $users[$uid]['totalXp'] += $gain;
                        $users[$uid]['dailyXp'] += $gain;
                        $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                        evaluateReferralProgress($uid, $users, $referrals, $rewards);
                        writeDB('tasks.json', $tasks);
                        $response['gain'] = $gain;
                    }
                } else {
                    sendApiError($uid, $action, "Tapşırıq artıq edilib ($taskId)", "Tapşırıq artıq tamamlanıb.");
                }
            }
            break;

        case 'submit_task_info':
            $taskId = $input['taskId'];
            $info = htmlspecialchars($input['info']);
            if(isset($customTasks[$taskId]) && $customTasks[$taskId]['requireInfo']) {
                if(in_array($taskId, $users[$uid]['rejectedTasks'] ?? [])) {
                    sendApiError($uid, $action, "Rədd edilmiş tapşırığa məlumat cəhdi", "Rədd edilmiş tapşırığı yenidən göndərə bilməzsiniz.");
                }
                
                $alreadyPending = false;
                foreach($submissions as $s) {
                    if($s['uid'] === $uid && $s['taskId'] === $taskId && $s['status'] === 'Pending') $alreadyPending = true;
                }
                if($alreadyPending) {
                    sendApiError($uid, $action, "Artıq təsdiq gözləyir", "Artıq təsdiq gözləyirsiniz.");
                }

                $submissions[] = [
                    'subId' => uniqid('sub_'), 'uid' => $uid,
                    'username' => $users[$uid]['username'], 'name' => $users[$uid]['firstName'] . ' ' . $users[$uid]['lastName'],
                    'taskId' => $taskId, 'taskTitle' => $customTasks[$taskId]['title'],
                    'infoSubmitted' => $info, 'status' => 'Pending',
                    'date' => $now->format('Y-m-d H:i:s')
                ];
                writeDB('task_submissions.json', $submissions);
                $response['message'] = 'Məlumat göndərildi, təsdiq gözlənilir.';
            }
            break;

        case 'open_box':
            $type = $input['boxType'] ?? '';
            $costs = ['bronze' => 10000, 'silver' => 50000, 'gold' => 100000];
            
            if (isset($costs[$type]) && $users[$uid]['xp'] >= $costs[$type]) {
                $users[$uid]['xp'] -= $costs[$type];
                $users[$uid]['xpSpent'] += $costs[$type];
                $users[$uid]['boxesOpened'] += 1;
                $isJackpot = (rand(1, 10000) === 1); 
                $rewardUsd = 0;
                if ($type === 'bronze') $rewardUsd = $isJackpot ? 1.00 : 0.10;
                if ($type === 'silver') $rewardUsd = $isJackpot ? 7.00 : 0.50;
                if ($type === 'gold') $rewardUsd = $isJackpot ? 15.00 : 1.00;
                
                $users[$uid]['usd'] += $rewardUsd;
                $response['reward'] = $rewardUsd;
                $response['jackpot'] = $isJackpot;
            } else {
                sendApiError($uid, $action, "Qutu üçün kifayət qədər XP yoxdur", "Kifayət qədər XP yoxdur.");
            }
            break;

        case 'withdraw':
            $amount = (float)($input['amount'] ?? 0);
            $address = htmlspecialchars($input['address'] ?? '');
            
            if ($amount >= 10 && $amount <= $users[$uid]['usd'] && strlen($address) > 5) {
                $users[$uid]['usd'] -= $amount;
                if (!isset($withdrawals[$uid])) $withdrawals[$uid] = [];
                array_unshift($withdrawals[$uid], [
                    'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                    'amount' => $amount,
                    'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                    'date' => $now->format('M j, Y H:i:s'),
                    'status' => 'Pending'
                ]);
                writeDB('withdrawals.json', $withdrawals);
            } else {
                sendApiError($uid, $action, "Xətalı çıxarış tələbi (Balans/Ünvan)", "Balans yetərsizdir və ya ünvan yanlışdır.");
            }
            break;
            
        case 'sync': break;
    }

    if (!$isSilentSync) writeDB('users.json', $users);
    
    $response['user'] = $users[$uid];
    $response['referrals'] = $referrals[$uid] ?? [];
    $response['rewards'] = $rewards[$uid] ?? [];
    $response['withdrawals'] = $withdrawals[$uid] ?? [];
    $response['tasks'] = $tasks[$uid] ?? [];
    $response['custom_tasks'] = array_values(array_filter($customTasks, function($c) { return $c['active']; }));
    $response['pending_submissions'] = array_filter($submissions, function($s) use ($uid) { return $s['uid'] === $uid && $s['status'] === 'Pending'; });
    
    echo json_encode($response);
    exit;
}

// -----------------------------------------------------------------------------------------
// FRONTEND - HTML / JS / CSS (TAM VƏ XƏTASIZ)
// -----------------------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>XPVerse - Telegram Mini App</title>
  
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="https://sad.adsgram.ai/js/sad.min.js"></script>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&display=swap" rel="stylesheet">

  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Outfit', 'sans-serif'] },
          colors: {
            crypto: { dark: '#050511', card: '#0a0b1a', primary: '#3b82f6', glow: '#00f0ff', gold: '#ffb800', silver: '#e2e8f0', bronze: '#cd7f32' }
          },
          animation: {
            'blob': 'blob 7s infinite',
            'pulse-fast': 'pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            'shimmer': 'shimmer 2s infinite',
          },
          keyframes: {
            blob: {
              '0%': { transform: 'translate(0px, 0px) scale(1)' },
              '33%': { transform: 'translate(30px, -50px) scale(1.1)' },
              '66%': { transform: 'translate(-20px, 20px) scale(0.9)' },
              '100%': { transform: 'translate(0px, 0px) scale(1)' },
            },
            shimmer: {
              '0%': { transform: 'translateX(-100%)' },
              '100%': { transform: 'translateX(100%)' }
            }
          }
        }
      }
    }
  </script>

  <style>
    body { background-color: #050511; color: #f8fafc; overflow-x: hidden; user-select: none; -webkit-user-select: none; }
    .bg-orb-1 { position: fixed; top: -10%; left: -10%; width: 50vw; height: 50vw; background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(0, 0, 0, 0) 70%); z-index: -1; filter: blur(40px); }
    .bg-orb-2 { position: fixed; bottom: -10%; right: -10%; width: 60vw; height: 60vw; background: radial-gradient(circle, rgba(0, 240, 255, 0.1) 0%, rgba(0, 0, 0, 0) 70%); z-index: -1; filter: blur(50px); }
    input, textarea { user-select: auto !important; }
    ::-webkit-scrollbar { width: 0px; background: transparent; }
    .glass-card { background: linear-gradient(145deg, rgba(20, 22, 45, 0.7) 0%, rgba(10, 11, 26, 0.85) 100%); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.05); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3); }
    .fade-in { animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .nav-active { color: #00f0ff !important; transform: translateY(-3px); }
    .nav-active i { filter: drop-shadow(0 0 8px rgba(0, 240, 255, 0.8)); }
    .btn-3d { background: linear-gradient(to bottom, #3b82f6, #2563eb); border-bottom: 2px solid #1e3a8a; transition: all 0.1s; }
    .btn-3d:active { transform: translateY(2px); border-bottom-width: 0px; margin-bottom: 2px; }
    #toast-container { position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9); width: 90%; max-width: 380px; z-index: 999999; transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55); opacity: 0; pointer-events: none; }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }
    .box-bronze { background: linear-gradient(135deg, rgba(205,127,50,0.15), rgba(139,69,19,0.25)); border: 1px solid rgba(205,127,50,0.4); }
    .box-silver { background: linear-gradient(135deg, rgba(226,232,240,0.15), rgba(148,163,184,0.25)); border: 1px solid rgba(226,232,240,0.4); }
    .box-gold { background: linear-gradient(135deg, rgba(255,184,0,0.2), rgba(217,119,6,0.3)); border: 1px solid rgba(255,184,0,0.5); }
    .modal-overlay { background: rgba(5, 5, 17, 0.85); backdrop-filter: blur(10px); z-index: 10000; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .admin-scroll::-webkit-scrollbar { width: 4px; }
    .admin-scroll::-webkit-scrollbar-thumb { background: #3b82f6; border-radius: 4px; }
  </style>
</head>
<body class="flex flex-col min-h-screen">
  
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob" style="animation-delay: 2s"></div>

  <!-- Start Screen -->
  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-6">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1s_linear_infinite]"></div>
      <div class="absolute inset-2 rounded-full border-b-4 border-blue-500 animate-[spin_1.5s_linear_infinite_reverse]"></div>
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-rocket text-crypto-glow text-3xl animate-pulse drop-shadow-[0_0_15px_#00f0ff]"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2">XPVerse</h2>
  </div>

  <!-- Notification Toast -->
  <div id="toast-container" class="glass-card rounded-2xl p-3 flex items-center gap-3">
    <div id="toast-icon" class="w-10 h-10 rounded-full flex shrink-0 items-center justify-center text-lg shadow-inner">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-xs font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-[11px] text-slate-300 mt-0.5 leading-tight">Message</p>
    </div>
  </div>

  <!-- GLOBAL FIXED HEADER -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full py-3 px-4 glass-card rounded-b-[1.5rem] border-b-0 shadow-[0_10px_30px_rgba(0,0,0,0.5)]">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-2.5">
        <div class="relative w-10 h-10 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500">
          <img id="user-photo" src="https://via.placeholder.com/150/0a0b1a/00f0ff" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-[#050511]">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm tracking-wide">Yüklənir...</span>
          <div class="flex items-center gap-1.5 mt-0.5">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            <span class="text-[9px] text-slate-400 uppercase font-black tracking-widest">Online</span>
          </div>
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
  </header>

  <!-- MAIN CONTENT CONTAINER -->
  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-20 pb-24 relative" id="app-content">
    
    <!-- HOME PAGE -->
    <div id="view-home" class="view-section fade-in space-y-5">
      <div class="relative glass-card rounded-[1.5rem] p-5 text-center flex flex-col items-center justify-center min-h-[220px]">
        <div class="w-14 h-14 rounded-full bg-blue-900/60 border border-blue-400/40 flex items-center justify-center mb-3">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow"></i>
        </div>
        <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.25em] mb-1">Total Balance</p>
        <h1 class="text-4xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-500 tracking-tighter" id="main-xp-display">0 XP</h1>
        
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5">
            <div class="bg-blue-500/10 p-2.5 rounded-lg border border-blue-500/30"><i class="fa-solid fa-clapperboard text-blue-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black">Ads Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / 30</p>
            </div>
          </div>
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5">
            <div class="bg-amber-500/10 p-2.5 rounded-lg border border-amber-500/30"><i class="fa-solid fa-fire-flame-curved text-amber-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-3.5 rounded-[1.25rem] text-white font-black text-sm uppercase flex items-center justify-center gap-2.5 btn-3d">
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span>Watch Ad <span class="text-cyan-200 ml-1" id="watch-ad-reward">+20 XP</span></span>
      </button>

      <!-- SERVER TIMED RESET -->
      <div class="glass-card rounded-xl p-3.5 flex justify-between items-center border border-slate-800">
        <div class="flex items-center gap-2 text-slate-400 text-[11px] font-bold">
          <i class="fa-solid fa-clock text-blue-400"></i> <span class="uppercase tracking-widest">Resets in (Server Time):</span>
        </div>
        <span class="text-white font-mono font-black text-xs tracking-widest bg-slate-900/50 px-2.5 py-1 rounded-md" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <!-- TASKS PAGE -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1">
        <h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2>
      </div>
      
      <div class="glass-card rounded-[1.25rem] p-4 bg-gradient-to-br from-blue-900/30 to-[#050511]">
        <div class="flex justify-between items-start mb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-lg">
                    <i class="fa-solid fa-calendar-day text-lg text-white"></i>
                </div>
                <div>
                    <h3 class="text-[15px] font-black text-white">Daily Login</h3>
                </div>
            </div>
            <div id="daily-login-btn-container"></div>
        </div>
        <div class="bg-[#050511]/60 rounded-xl p-3 border border-slate-700/50">
            <div class="relative flex justify-between items-center" id="streak-tracker-container"></div>
        </div>
      </div>

      <div class="mt-6 mb-4">
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3"><i class="fa-solid fa-star text-amber-400"></i> Partner Tasks</h3>
        <div id="sponsor-container" class="space-y-3"></div>
        <div id="custom-tasks-container" class="space-y-3 mt-3"></div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3"><i class="fa-solid fa-list-check text-slate-600"></i> Daily Missions</h3>
        <div id="missions-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- REFERRALS PAGE -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-5">
      <div class="text-center relative mb-1">
        <h2 class="text-2xl font-black text-white">Referans</h2>
        <button onclick="toggleRefInfo()" class="absolute top-0 right-1 w-8 h-8 rounded-full bg-blue-500/20 text-blue-400 flex items-center justify-center">
          <i class="fa-solid fa-circle-question"></i>
        </button>
      </div>

      <div class="grid grid-cols-3 gap-2.5">
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-blue-500/40"><p class="text-[9px] text-slate-400 uppercase font-black">Total</p><p id="ref-total" class="text-xl font-black text-white">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-amber-500/40"><p class="text-[9px] text-slate-400 uppercase font-black">Pending</p><p id="ref-pending" class="text-xl font-black text-amber-400">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-emerald-500/40"><p class="text-[9px] text-slate-400 uppercase font-black">Approved</p><p id="ref-approved" class="text-xl font-black text-emerald-400">0</p></div>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 border border-slate-700/50">
        <div class="mb-3">
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1">Your Referral Link</label>
          <div class="flex items-center gap-2">
            <input type="text" id="ref-link-input" readonly class="flex-1 bg-[#050511]/70 border border-slate-700 rounded-lg py-2.5 px-3 text-[11px] text-slate-300 outline-none">
            <button onclick="copyRefLink()" class="bg-slate-800 text-white w-10 h-10 rounded-lg flex items-center justify-center active:scale-95 transition-transform"><i class="fa-regular fa-copy"></i></button>
          </div>
        </div>
        <button onclick="shareReferralTelegram()" class="w-full py-3 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-lg text-xs uppercase flex items-center justify-center gap-2"><i class="fa-brands fa-telegram text-base"></i> Share via Telegram</button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase pl-2 mb-3"><i class="fa-solid fa-users text-slate-600"></i> Your Referrals</h3>
        <div id="referral-list-container" class="space-y-2.5"></div>
      </div>
      <div class="mt-6">
        <h3 class="text-[10px] font-black text-slate-500 uppercase pl-2 mb-3"><i class="fa-solid fa-gift text-slate-600"></i> Reward History</h3>
        <div id="referral-rewards-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- BOX PAGE -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-4"><h2 class="text-2xl font-black text-white">Box</h2></div>
      
      <div class="box-bronze glass-card rounded-[1.25rem] p-4 flex justify-between items-center transition-transform hover:scale-[1.02]">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-orange-900 to-[#050511] border border-crypto-bronze flex items-center justify-center"><i class="fa-solid fa-box text-2xl text-crypto-bronze"></i></div>
          <div><h3 class="text-lg font-black text-white">Bronze Box</h3><div class="flex flex-col mt-0.5"><span class="text-[10px] text-slate-400 font-bold uppercase">10,000 XP</span><span class="text-[11px] text-emerald-400 font-bold">Max Reward: $1.00 USDT</span></div></div>
        </div>
        <button onclick="openBox('bronze')" class="bg-gradient-to-b from-orange-600 to-orange-800 text-white px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>

      <div class="box-silver glass-card rounded-[1.25rem] p-4 flex justify-between items-center transition-transform hover:scale-[1.02]">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-slate-600 to-[#050511] border border-crypto-silver flex items-center justify-center"><i class="fa-solid fa-box-open text-2xl text-crypto-silver"></i></div>
          <div><h3 class="text-lg font-black text-white">Silver Box</h3><div class="flex flex-col mt-0.5"><span class="text-[10px] text-slate-400 font-bold uppercase">50,000 XP</span><span class="text-[11px] text-emerald-400 font-bold">Max Reward: $7.00 USDT</span></div></div>
        </div>
        <button onclick="openBox('silver')" class="bg-gradient-to-b from-slate-300 to-slate-500 text-crypto-dark px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>

      <div class="box-gold glass-card rounded-[1.25rem] p-4 flex justify-between items-center border border-crypto-gold transition-transform hover:scale-[1.02]">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500 to-[#050511] border border-crypto-gold flex items-center justify-center"><i class="fa-solid fa-gem text-2xl text-crypto-gold"></i></div>
          <div><h3 class="text-lg font-black text-crypto-gold">Gold Box</h3><div class="flex flex-col mt-0.5"><span class="text-[10px] text-amber-200/80 font-bold uppercase">100,000 XP</span><span class="text-[11px] text-emerald-400 font-bold">Max Reward: $15.00 USDT</span></div></div>
        </div>
        <button onclick="openBox('gold')" class="bg-gradient-to-b from-yellow-400 to-amber-600 text-crypto-dark px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>
    </div>

    <!-- WALLET PAGE -->
    <div id="view-wallet" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1"><h2 class="text-2xl font-black text-white">Wallet</h2></div>
      <div class="glass-card rounded-[1.5rem] p-5 text-center border-t border-emerald-500/30 bg-gradient-to-b from-emerald-900/20 to-[#050511]">
        <p class="text-[10px] font-black text-emerald-400 uppercase mb-1">Available Balance</p>
        <h1 class="text-4xl font-black text-white tracking-tighter mb-3">$<span id="withdraw-balance-display">0</span></h1>
      </div>
      <div class="glass-card rounded-[1.25rem] p-4 space-y-4 border border-slate-700/50">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1">TON Wallet Address <span class="text-red-400">*</span></label>
          <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511]/70 border border-slate-700/60 rounded-lg py-2.5 px-3 text-xs text-white focus:outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1">Amount (USDT) <span class="text-red-400">*</span></label>
          <input type="number" id="withdraw-amount" placeholder="10" min="10" step="0.5" class="w-full bg-[#050511]/70 border border-slate-700/60 rounded-lg py-2.5 px-3 text-xs text-emerald-400 font-bold focus:outline-none focus:border-emerald-500">
        </div>
        <button onclick="requestWithdrawal()" class="w-full py-3 mt-1 bg-gradient-to-r from-emerald-600 to-teal-500 text-white font-black rounded-lg text-xs uppercase flex items-center justify-center gap-2"><i class="fa-solid fa-money-bill-transfer"></i> Request Withdrawal</button>
      </div>
      <div class="mt-6">
        <h3 class="text-[10px] font-black text-slate-500 uppercase pl-2 mb-3">Withdrawal History</h3>
        <div id="withdraw-history-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- PROFILE PAGE -->
    <div id="view-profile" class="view-section hidden fade-in space-y-5">
      <div class="glass-card rounded-[1.5rem] p-5 flex flex-col items-center justify-center border-t border-t-blue-500/30 relative">
        <button id="admin-secret-btn" onclick="openAdminAuth()" class="hidden absolute top-4 right-4 w-8 h-8 rounded-full bg-red-600/20 text-red-500 flex items-center justify-center"><i class="fa-solid fa-user-shield text-sm"></i></button>
        <div class="w-20 h-20 rounded-full p-1 bg-gradient-to-tr from-blue-500 via-crypto-glow to-purple-500 mb-3">
          <img id="profile-page-avatar" src="" alt="Avatar" class="w-full h-full rounded-full object-cover border-[3px] border-[#050511]">
        </div>
        <h2 id="profile-page-name" class="text-xl font-black text-white mb-0.5">Name</h2>
        <p id="profile-page-username" class="text-[11px] font-mono text-blue-400 mb-2.5">@username</p>
        <div class="flex items-center gap-1.5 bg-[#050511]/60 px-3 py-1.5 rounded-lg border border-slate-700/50">
            <span class="text-[9px] text-slate-400 uppercase font-bold tracking-widest">ID: <span id="profile-page-id" class="text-white ml-1">0000000</span></span>
        </div>
      </div>
      
      <div class="grid grid-cols-2 gap-3 mt-5">
        <div class="glass-card p-4 rounded-xl border-t border-t-crypto-glow/40 flex flex-col items-center text-center"><p class="text-[9px] font-black text-slate-400 uppercase">Total XP</p><p id="profile-stat-xp" class="text-lg font-black text-white">0</p></div>
        <div class="glass-card p-4 rounded-xl border-t border-t-emerald-500/40 flex flex-col items-center text-center"><p class="text-[9px] font-black text-slate-400 uppercase">Balance</p><p id="profile-stat-usd" class="text-lg font-black text-white">$0</p></div>
        <div class="glass-card p-4 rounded-xl border-t border-t-purple-500/40 flex flex-col items-center text-center"><p class="text-[9px] font-black text-slate-400 uppercase">Referrals</p><p id="profile-stat-refs" class="text-lg font-black text-white">0</p></div>
        <div class="glass-card p-4 rounded-xl border-t border-t-amber-500/40 flex flex-col items-center text-center"><p class="text-[9px] font-black text-slate-400 uppercase">Tasks Done</p><p id="profile-stat-tasks" class="text-lg font-black text-white">0</p></div>
      </div>
    </div>

    <!-- ADMIN PANEL -->
    <div id="view-admin" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-2">
        <h2 class="text-2xl font-black text-red-500 flex items-center justify-center gap-2"><i class="fa-solid fa-shield-halved"></i> İDARƏ PANELİ</h2>
      </div>

      <div class="flex flex-wrap gap-1.5 mb-2 bg-[#050511] p-1.5 rounded-lg border border-slate-700/50">
          <button onclick="switchAdminTab('dashboard')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded bg-red-600/20 text-red-400 border border-red-500/50 transition" id="tab-dashboard">Panel</button>
          <button onclick="switchAdminTab('users')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-users">İstifadəçilər</button>
          <button onclick="switchAdminTab('withdrawals')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-withdrawals">Çıxarışlar</button>
          <button onclick="switchAdminTab('tasks')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-tasks">Tapşırıqlar</button>
          <button onclick="switchAdminTab('submissions')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-submissions">Təsdiqlər</button>
          <button onclick="switchAdminTab('errors')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-errors">Xətalar</button>
          <button onclick="switchAdminTab('settings')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-settings">Tənzimləmələr</button>
      </div>

      <!-- Dashboard Stats -->
      <div id="admin-sec-dashboard" class="admin-section space-y-3">
          <div class="grid grid-cols-2 gap-2">
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Ümumi İstifadəçi</p><p id="adm-stat-users" class="text-lg font-black text-blue-400">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Qazanılan USD</p><p id="adm-stat-usd" class="text-lg font-black text-emerald-400">$0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">İzlənən Reklam</p><p id="adm-stat-ads" class="text-lg font-black text-white">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Bitən Tapşırıqlar</p><p id="adm-stat-tasks" class="text-lg font-black text-white">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Ümumi XP</p><p id="adm-stat-xp" class="text-lg font-black text-crypto-glow">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Referallar</p><p id="adm-stat-refs" class="text-lg font-black text-purple-400">0</p></div>
          </div>
      </div>

      <!-- Users Management -->
      <div id="admin-sec-users" class="admin-section hidden space-y-3">
          <input type="text" id="admin-user-search" onkeyup="filterAdminUsers()" placeholder="UID və ya İstifadəçi adı axtar..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white focus:border-blue-500 outline-none">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-user-list"></div>
      </div>

      <!-- Withdrawals -->
      <div id="admin-sec-withdrawals" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-withdrawal-list"></div>
      </div>

      <!-- Tasks Management -->
      <div id="admin-sec-tasks" class="admin-section hidden space-y-3">
          <div class="glass-card rounded-xl p-4 border border-slate-700">
              <h3 class="text-xs font-black text-white uppercase mb-3">Yeni Tapşırıq Əlavə Et</h3>
              <input type="text" id="nt-title" placeholder="Başlıq (məs: Qeydiyyatdan keç)" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-2 outline-none">
              <input type="text" id="nt-desc" placeholder="Açıqlama" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-2 outline-none">
              <input type="text" id="nt-link" placeholder="Link (URL)" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-2 outline-none">
              <input type="number" id="nt-reward" placeholder="XP Mükafatı" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-2 outline-none">
              <div class="flex items-center gap-2 mb-2">
                  <input type="checkbox" id="nt-reqInfo" onchange="document.getElementById('nt-infoLabel').classList.toggle('hidden')" class="w-4 h-4">
                  <label class="text-[10px] text-slate-300">İstifadəçidən əlavə məlumat (UID) tələb et</label>
              </div>
              <input type="text" id="nt-infoLabel" placeholder="Məlumat başlığı (məs: UID:)" class="hidden w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-3 outline-none">
              <button onclick="adminSaveTask()" class="w-full py-2 bg-blue-600 text-white rounded text-xs font-black uppercase">Tapşırığı Yarat</button>
          </div>
          <div id="admin-task-list" class="space-y-2"></div>
      </div>

      <!-- Submissions -->
      <div id="admin-sec-submissions" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-submission-list"></div>
      </div>

      <!-- Errors Log Tab -->
      <div id="admin-sec-errors" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-error-list"></div>
      </div>

      <!-- Settings -->
      <div id="admin-sec-settings" class="admin-section hidden space-y-3">
          <div class="glass-card rounded-xl p-4 border border-slate-700 space-y-3">
              <div>
                  <div class="flex items-center justify-between mb-1.5"><label class="text-[10px] font-black text-slate-400 uppercase">Baxım Rejimi (Maintenance)</label><input type="checkbox" id="admin-maintenance" class="w-4 h-4"></div>
                  <input type="text" id="admin-uid" placeholder="İcazəli Admin UID" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none">
              </div>
              <div><label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">Sıfırlanma Vaxtı</label><input type="time" id="admin-reset-time" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none"></div>
              <div><label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">XP Vurucusu (2x, 3x)</label><input type="number" step="0.1" id="admin-xp-mult" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none"></div>
              <div><label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">Adsgram Block ID-lər (Vergüllə ayırın)</label><textarea id="admin-ad-sdk" rows="3" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none" placeholder="int-35545"></textarea></div>
              <button onclick="saveAdminSettings()" class="w-full py-2 bg-blue-600 hover:bg-blue-500 text-white rounded text-xs font-black uppercase transition">Yadda Saxla</button>
              <div class="border-t border-slate-700 pt-3 mt-3 flex gap-2">
                  <button onclick="adminResetAllAds()" class="flex-1 py-2 bg-amber-600 text-white rounded text-[9px] font-black uppercase">Reklam Sıfırla</button>
                  <button onclick="adminResetBot()" class="flex-1 py-2 bg-red-600 text-white rounded text-[9px] font-black uppercase">Botu Sıfırla</button>
              </div>
          </div>
      </div>
    </div>
  </main>

  <!-- BOTTOM NAVIGATION -->
  <nav id="bottom-nav" class="fixed bottom-3 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50 shadow-[0_15px_30px_rgba(0,0,0,0.8)] border border-slate-700/50 backdrop-blur-xl">
    <div class="flex justify-between items-center px-1 py-2 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 group" data-target="home"><i class="fa-solid fa-house text-base transition-transform group-active:scale-90"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Home</span></button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 group" data-target="tasks"><i class="fa-solid fa-list-check text-base transition-transform group-active:scale-90"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Tasks</span></button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 group" data-target="referrals"><i class="fa-solid fa-users text-base transition-transform group-active:scale-90"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Referans</span></button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 group" data-target="boxes"><i class="fa-solid fa-box-open text-base transition-transform group-active:scale-90"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Box</span></button>
      <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 group" data-target="wallet"><i class="fa-solid fa-wallet text-base transition-transform group-active:scale-90"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Wallet</span></button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 group" data-target="profile"><i class="fa-solid fa-user text-base transition-transform group-active:scale-90"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Profile</span></button>
    </div>
  </nav>

  <!-- Modals -->
  <div id="admin-auth-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in">
    <div class="glass-card w-full max-w-[280px] rounded-[1.5rem] p-5 relative border border-red-500/50 shadow-[0_0_40px_rgba(239,68,68,0.3)]">
      <button onclick="closeAdminAuth()" class="absolute top-3 right-3 text-slate-400 hover:text-white transition"><i class="fa-solid fa-xmark"></i></button>
      <h3 class="text-lg font-black text-white text-center mb-4"><i class="fa-solid fa-lock text-red-500 mr-1"></i> Admin Access</h3>
      <input type="password" id="admin-code-input" placeholder="Enter Access Code" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-sm text-center font-black text-white focus:border-red-500 outline-none mb-4">
      <button onclick="submitAdminAuth()" class="w-full py-2.5 bg-red-600 text-white font-black rounded-lg text-xs uppercase">Login</button>
    </div>
  </div>

  <div id="custom-task-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in">
    <div class="glass-card w-full max-w-[300px] rounded-[1.5rem] p-5 relative border border-blue-500/50 shadow-[0_0_40px_rgba(59,130,246,0.3)]">
      <button onclick="closeCustomTaskModal()" class="absolute top-3 right-3 text-slate-400 hover:text-white transition"><i class="fa-solid fa-xmark"></i></button>
      <h3 class="text-sm font-black text-white text-center mb-1" id="ct-modal-title">Task</h3>
      <p class="text-[10px] text-slate-400 text-center mb-4" id="ct-modal-desc">Details</p>
      <label class="block text-[10px] font-black text-crypto-glow uppercase mb-1.5" id="ct-modal-label">UID:</label>
      <input type="text" id="ct-modal-input" placeholder="Məlumatı yazın..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-sm text-white focus:border-blue-500 outline-none mb-4">
      <input type="hidden" id="ct-modal-id">
      <button onclick="submitCustomTaskInfo()" class="w-full py-2.5 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-lg text-xs uppercase">Göndər</button>
    </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand(); tg.ready();
    tg.setHeaderColor('#0a0b1a'); tg.setBackgroundColor('#050511');

    const tgUser = tg.initDataUnsafe?.user || {
      id: Math.floor(Math.random() * 10000000), 
      first_name: "Demo", last_name: "User", username: "demouser", photo_url: ""
    };
    const startParam = tg.initDataUnsafe?.start_param || null;

    let appState = {
      user: {}, referrals: [], rewards: [], withdrawals: [], tasks: [], custom_tasks: [], pending_submissions: [], settings: { adBlockIds: ['int-35545'], xpMultiplier: 1 }
    };
    
    let adminToken = '';
    let adminUsers = [];
    let adminWithdrawals = [];
    let adminCustomTasks = {};
    let adminSubmissions = [];

    function formatNum(num, isMoney = false) {
        if (!num) return isMoney ? "0" : "0";
        let val = Number(num);
        return isMoney ? (val % 1 === 0 ? val.toString() : val.toFixed(2).replace(/\.?0+$/, '')) : val.toLocaleString();
    }

    async function apiCall(action, payload = {}, isSilent = false) {
      try {
        const body = {
          action: action, tgId: tgUser.id, firstName: tgUser.first_name || '', lastName: tgUser.last_name || '',
          username: tgUser.username || '', photoUrl: tgUser.photo_url || '', referrer: startParam, silent: isSilent, ...payload
        };
        const res = await fetch(window.location.href, {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        });
        
        if(!res.ok) throw new Error('HTTP Status: ' + res.status);
        
        const data = await res.json();
        
        if(data.error) {
          if (data.error === 'BANNED' || data.error === 'MAINTENANCE') {
             document.body.innerHTML = `<div class="h-screen w-full flex flex-col items-center justify-center ${data.error==='BANNED'?'bg-red-900':'bg-blue-900'} text-white p-5 text-center"><i class="fa-solid ${data.error==='BANNED'?'fa-ban':'fa-screwdriver-wrench'} text-5xl mb-4"></i><h1 class="text-2xl font-black mb-2">${data.error}</h1><p class="text-xs opacity-80">${data.message || 'Xəta.'}</p></div>`;
             return false;
          }
          if(!isSilent) showToast("Xəta", data.error, "error");
          return false;
        }

        if(data.user) appState.user = data.user;
        if(data.referrals) appState.referrals = data.referrals;
        if(data.rewards) appState.rewards = data.rewards;
        if(data.withdrawals) appState.withdrawals = data.withdrawals;
        if(data.tasks) appState.tasks = data.tasks;
        if(data.custom_tasks) appState.custom_tasks = data.custom_tasks;
        if(data.pending_submissions) appState.pending_submissions = data.pending_submissions;
        if(data.settings) appState.settings = data.settings;

        updateUI();
        return data;
      } catch (err) {
        if(!isSilent) showToast("Xəta", "Server ilə bağlantı qurulmadı. Gözləyin.", "error");
        console.error(err);
        return false;
      }
    }

    function showToast(title, message, type = 'info') {
      const toast = document.getElementById('toast-container');
      const icon = document.getElementById('toast-icon');
      document.getElementById('toast-title').innerText = title;
      document.getElementById('toast-message').innerText = message;
      
      let iconClass, iconHtml, borderStyle;
      if (type === 'success') { iconHtml = '<i class="fa-solid fa-check"></i>'; iconClass = 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50 shadow-[0_0_10px_rgba(16,185,129,0.2)]'; borderStyle = '1px solid rgba(16, 185, 129, 0.3)'; } 
      else if (type === 'error') { iconHtml = '<i class="fa-solid fa-xmark"></i>'; iconClass = 'bg-red-500/20 text-red-400 border border-red-500/50 shadow-[0_0_10px_rgba(239,68,68,0.2)]'; borderStyle = '1px solid rgba(239, 68, 68, 0.3)'; } 
      else if (type === 'jackpot') { iconHtml = '<i class="fa-solid fa-sack-dollar animate-bounce text-xl"></i>'; iconClass = 'bg-amber-500/20 text-amber-400 border border-amber-500/50 shadow-[0_0_15px_rgba(251,191,36,0.5)]'; borderStyle = '1px solid rgba(251, 191, 36, 0.5)'; } 
      else { iconHtml = '<i class="fa-solid fa-bell animate-pulse"></i>'; iconClass = 'bg-blue-500/20 text-crypto-glow border border-crypto-glow/50 shadow-[0_0_10px_rgba(0,240,255,0.2)]'; borderStyle = '1px solid rgba(0, 240, 255, 0.3)'; }

      icon.innerHTML = iconHtml; icon.className = `w-10 h-10 rounded-lg flex shrink-0 items-center justify-center text-base ${iconClass}`;
      toast.style.border = borderStyle; toast.classList.add('toast-show');
      
      if (tg.HapticFeedback) {
        if (type === 'success' || type === 'jackpot') tg.HapticFeedback.notificationOccurred('success');
        else if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
        else tg.HapticFeedback.notificationOccurred('warning');
      }
      setTimeout(() => { toast.classList.remove('toast-show'); }, type === 'jackpot' ? 5000 : 3000); 
    }

    function updateUI() {
      const u = appState.user;
      if(!u.tgId) return;
      const fullName = [u.firstName, u.lastName].filter(Boolean).join(' ') || 'User';
      document.getElementById('user-name').innerText = fullName;
      document.getElementById('user-xp').innerHTML = `${formatNum(u.xp)} <span class="text-[9px] text-crypto-glow font-bold">XP</span>`;
      document.getElementById('user-usd').innerText = formatNum(u.usd, true);
      
      const avatarFallback = `https://ui-avatars.com/api/?name=${encodeURIComponent(fullName)}&background=0a0b1a&color=00f0ff&bold=true`;
      const avatarUrl = u.photoUrl || avatarFallback;
      document.getElementById('user-photo').src = avatarUrl;
      document.getElementById('profile-page-avatar').src = avatarUrl;

      document.getElementById('profile-page-name').innerText = fullName;
      if (u.username) { document.getElementById('profile-page-username').innerText = `@${u.username}`; document.getElementById('profile-page-username').style.display = 'inline-block'; } 
      else { document.getElementById('profile-page-username').style.display = 'none'; }
      document.getElementById('profile-page-id').innerText = u.tgId;
      document.getElementById('profile-stat-xp').innerText = formatNum(u.totalXp);
      document.getElementById('profile-stat-usd').innerText = `$${formatNum(u.usd, true)}`;
      document.getElementById('profile-stat-refs').innerText = appState.referrals.length;
      document.getElementById('profile-stat-tasks').innerText = u.tasksCompleted;

      if (u.tgId === '5461064199') {
          document.getElementById('admin-secret-btn').classList.remove('hidden');
      }

      document.getElementById('main-xp-display').innerText = `${formatNum(u.xp)} XP`;
      document.getElementById('ads-watched').innerText = u.adsWatchedToday;
      document.getElementById('streak-days').innerText = u.streak;
      document.getElementById('watch-ad-reward').innerText = `+${20 * appState.settings.xpMultiplier} XP`;

      document.getElementById('ref-total').innerText = appState.referrals.length;
      document.getElementById('ref-pending').innerText = appState.referrals.filter(r => r.status === 'Pending').length;
      document.getElementById('ref-approved').innerText = appState.referrals.filter(r => r.status === 'Approved').length;
      document.getElementById('ref-link-input').value = `https://t.me/XPVersebot?startapp=${u.tgId}`;
      
      renderReferrals(); renderRewardHistory();
      document.getElementById('withdraw-balance-display').innerText = formatNum(u.usd, true);
      renderWithdrawHistory(); renderDailyLoginTask(); renderTasks(); renderCustomTasks();
    }

    async function watchAd() {
      const btn = document.getElementById('watch-ad-btn'); 
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-base"></i> <span>Yüklənir...</span>`;
      btn.classList.add('opacity-80', 'pointer-events-none');
      
      const blockIds = appState.settings.adBlockIds || ["int-35545"];
      let adShown = false;
      
      try {
          for(let i = 0; i < blockIds.length; i++) {
            let currentId = blockIds[i].trim();
            if(!currentId) continue;
            try {
              if (window.Adsgram) {
                const AdController = window.Adsgram.init({ blockId: currentId });
                await AdController.show();
                adShown = true;
                break;
              }
            } catch (e) {
              console.warn(`Reklam (${currentId}) yüklənmədi, digəri yoxlanılır...`);
            }
          }
          
          if(adShown) {
             const res = await apiCall('watch_ad');
             if(res && !res.error) showToast('Təbrik!', `Reklam izləndi! +${res.gain} XP.`, 'success');
          } else {
             showToast('Reklam Yoxdur', 'Hal-hazırda reklam tapılmadı. Zəhmət olmasa biraz sonra yenidən cəhd edin.', 'error');
          }
      } finally {
          // XƏTA HƏLLİ: Donmanın qarşısını alan qoruyucu kod (Hər zaman çalışacaq)
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-80', 'pointer-events-none');
      }
    }

    function renderDailyLoginTask() {
      const container = document.getElementById('streak-tracker-container'); const btnContainer = document.getElementById('daily-login-btn-container');
      container.innerHTML = '';
      const mult = appState.settings.xpMultiplier;
      const rewards = [10*mult, 20*mult, 30*mult, 40*mult, 50*mult, 75*mult, 100*mult];
      const streak = appState.user.streak || 1;
      const claimedToday = appState.tasks.includes('dailyLogin');
      
      for (let i = 1; i <= 7; i++) {
        const isPast = i < streak || (i === streak && claimedToday); const isToday = i === streak && !claimedToday;
        let styles = "bg-[#050511] border-slate-700/50 text-slate-600"; let icon = `<span class="text-[9px] font-black">${rewards[i-1]}</span>`; let lineStyle = "bg-slate-800";
        if (isPast) { styles = "bg-emerald-500/20 border-emerald-500/50 text-emerald-400"; icon = `<i class="fa-solid fa-check text-xs"></i>`; lineStyle = "bg-emerald-500/50"; } 
        else if (isToday) { styles = "bg-blue-600/30 border-crypto-glow shadow-[0_0_15px_rgba(0,240,255,0.4)] text-white"; }
        container.innerHTML += `<div class="relative flex flex-col items-center gap-1 z-10 flex-1"><div class="w-8 h-8 rounded-lg border flex items-center justify-center transition-all duration-300 ${styles} z-10 relative bg-[#0a0b1a]">${icon}</div><span class="text-[8px] font-black tracking-widest ${isToday ? 'text-crypto-glow drop-shadow-[0_0_3px_#00f0ff]' : 'text-slate-500'}">GÜN ${i}</span>${i < 7 ? `<div class="absolute top-4 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}</div>`;
      }
      
      if (claimedToday) btnContainer.innerHTML = `<button class="bg-emerald-900/50 border border-emerald-500/40 text-emerald-400 px-4 py-2 rounded-lg text-[10px] font-black uppercase tracking-wider flex items-center gap-1.5 cursor-not-allowed opacity-80 shadow-inner"><i class="fa-solid fa-check-double"></i> Alındı</button>`;
      else btnContainer.innerHTML = `<button onclick="claimTask('dailyLogin', ${rewards[streak - 1]})" class="bg-gradient-to-r from-crypto-glow to-blue-500 text-crypto-dark shadow-[0_3px_15px_rgba(0,240,255,0.4)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest animate-pulse-fast">AL</button>`;
    }

    function renderTasks() {
      const mult = appState.settings.xpMultiplier;
      const spContainer = document.getElementById('sponsor-container');
      if (appState.user.sponsorAzx) spContainer.innerHTML = `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800 shadow-sm"><div class="flex items-center gap-2.5"><div class="w-10 h-10 rounded-lg border bg-blue-500/10 border-blue-500/20 flex items-center justify-center"><i class="fa-brands fa-telegram text-blue-400 text-lg"></i></div><div class="flex flex-col"><span class="text-[13px] font-black text-white tracking-wide">Join @azxcrypto</span><span class="text-emerald-400 text-[9px] font-bold tracking-widest uppercase mt-0.5">Completed</span></div></div><span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-lg border border-emerald-500/30 flex items-center gap-1"><i class="fa-solid fa-check-double"></i></span></div>`;
      else spContainer.innerHTML = `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-blue-500/30 relative overflow-hidden"><div class="flex items-center gap-2.5 relative z-10"><div class="w-10 h-10 rounded-lg border bg-blue-500/20 border-blue-500/40 flex items-center justify-center"><i class="fa-brands fa-telegram text-blue-400 text-lg"></i></div><div class="flex flex-col"><span class="text-[13px] font-black text-white tracking-wide">Join @azxcrypto</span><span class="text-crypto-glow text-[9px] font-bold tracking-widest uppercase mt-0.5">+${200*mult} XP</span></div></div><button onclick="claimSponsorTask()" class="relative z-10 text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-3 py-1.5 rounded-lg active:scale-95 transition-all uppercase tracking-wider">Join</button></div>`;

      const container = document.getElementById('missions-container'); 
      container.innerHTML = '';
      
      // XƏTA HƏLLİ: Sizin göndərdiyiniz tam olmayan Array tamamlandı
      const missionsList = [
        { id: 'watch5', label: 'Watch 5 Ads', icon: 'fa-video', color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20', reward: 20*mult, target: 5, current: appState.user.adsWatchedToday },
        { id: 'watch15', label: 'Watch 15 Ads', icon: 'fa-film', color: 'text-indigo-400', bg: 'bg-indigo-500/10 border-indigo-500/20', reward: 40*mult, target: 15, current: appState.user.adsWatchedToday },
        { id: 'watch30', label: 'Watch 30 Ads', icon: 'fa-clapperboard', color: 'text-purple-400', bg: 'bg-purple-500/10 border-purple-500/20', reward: 80*mult, target: 30, current: appState.user.adsWatchedToday }
      ];

      missionsList.forEach(m => {
        const isDone = appState.tasks.includes(m.id);
        const progress = Math.min(m.current, m.target);
        const percent = (progress / m.target) * 100;
        let btnHtml = '';
        
        if (isDone) {
          btnHtml = `<button disabled class="bg-emerald-500/20 text-emerald-400 px-3 py-1.5 rounded-lg text-[9px] font-black uppercase"><i class="fa-solid fa-check"></i></button>`;
        } else if (progress >= m.target) {
          btnHtml = `<button onclick="claimTask('${m.id}', ${m.reward})" class="bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-3 py-1.5 rounded-lg text-[9px] font-black uppercase active:scale-95 transition">Claim</button>`;
        } else {
          btnHtml = `<span class="text-xs font-black text-slate-400">${progress}/${m.target}</span>`;
        }

        container.innerHTML += `
          <div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-700/50">
            <div class="flex items-center gap-2.5">
              <div class="w-9 h-9 rounded-lg border ${m.bg} flex items-center justify-center"><i class="fa-solid ${m.icon} ${m.color}"></i></div>
              <div class="flex flex-col">
                <span class="text-[11px] font-black text-white">${m.label}</span>
                <div class="w-24 h-1.5 bg-slate-800 rounded-full mt-1 overflow-hidden">
                  <div class="h-full bg-${m.color.split('-')[1]}-500 transition-all" style="width: ${percent}%"></div>
                </div>
              </div>
            </div>
            <div>${btnHtml}</div>
          </div>
        `;
      });
    }

    async function claimTask(taskId, reward) {
        const res = await apiCall('claim_task', { taskId, reward });
        if(res && res.success) showToast('Təbrik!', `Tapşırıq tamamlandı! +${res.gain} XP`, 'success');
    }

    async function claimSponsorTask() {
        window.open('https://t.me/azxcrypto', '_blank');
        setTimeout(async () => {
            const res = await apiCall('claim_task', { taskId: 'sponsor_azx' });
            if(res && res.success) showToast('Təbrik!', `Sponsor tapşırığı tamamlandı! +${res.gain} XP`, 'success');
        }, 3000);
    }

    function renderCustomTasks() {
        const container = document.getElementById('custom-tasks-container');
        container.innerHTML = '';
        if(!appState.custom_tasks || appState.custom_tasks.length === 0) return;
        
        appState.custom_tasks.forEach(t => {
            const isDone = appState.tasks.includes(t.id);
            const isPending = appState.pending_submissions.some(s => s.taskId === t.id);
            const isRejected = (appState.user.rejectedTasks || []).includes(t.id);
            
            let statusHtml = '';
            if (isDone) statusHtml = `<span class="bg-emerald-500/20 text-emerald-400 px-3 py-1.5 rounded-lg text-[9px] font-black uppercase"><i class="fa-solid fa-check"></i> Bitib</span>`;
            else if (isPending) statusHtml = `<span class="bg-amber-500/20 text-amber-400 px-3 py-1.5 rounded-lg text-[9px] font-black uppercase"><i class="fa-solid fa-clock"></i> Gözləyir</span>`;
            else if (isRejected) statusHtml = `<span class="bg-red-500/20 text-red-400 px-3 py-1.5 rounded-lg text-[9px] font-black uppercase"><i class="fa-solid fa-xmark"></i> Rədd Edildi</span>`;
            else statusHtml = `<button onclick="${t.requireInfo ? `openCustomTaskModal('${t.id}', '${t.title}', '${t.desc}', '${t.link}', '${t.infoLabel}')` : `openLinkAndClaim('${t.id}', '${t.link}')`}" class="bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-3 py-1.5 rounded-lg text-[9px] font-black uppercase active:scale-95 transition">İcra Et</button>`;

            container.innerHTML += `
            <div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-700/50">
              <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-lg border bg-purple-500/10 border-purple-500/20 flex items-center justify-center"><i class="fa-solid fa-star text-purple-400"></i></div>
                <div class="flex flex-col"><span class="text-[11px] font-black text-white">${t.title}</span><span class="text-[9px] text-crypto-glow font-bold mt-0.5">+${t.reward} XP</span></div>
              </div>
              <div>${statusHtml}</div>
            </div>`;
        });
    }

    async function openLinkAndClaim(taskId, link) {
        window.open(link, '_blank');
        setTimeout(async () => {
            const res = await apiCall('claim_task', { taskId });
            if (res && res.success) showToast('Uğurlu!', `Tapşırıq təsdiqləndi! +${res.gain} XP`, 'success');
        }, 5000);
    }

    function openCustomTaskModal(id, title, desc, link, label) {
        document.getElementById('ct-modal-id').value = id;
        document.getElementById('ct-modal-title').innerText = title;
        document.getElementById('ct-modal-desc').innerText = desc;
        document.getElementById('ct-modal-label').innerText = label;
        document.getElementById('ct-modal-input').value = '';
        window.open(link, '_blank');
        document.getElementById('custom-task-modal').classList.remove('hidden');
        document.getElementById('custom-task-modal').classList.add('flex');
    }
    
    function closeCustomTaskModal() {
        document.getElementById('custom-task-modal').classList.add('hidden');
        document.getElementById('custom-task-modal').classList.remove('flex');
    }
    
    async function submitCustomTaskInfo() {
        const id = document.getElementById('ct-modal-id').value;
        const info = document.getElementById('ct-modal-input').value.trim();
        if(!info) { showToast('Xəta', 'Məlumatı daxil edin', 'error'); return; }
        const res = await apiCall('submit_task_info', { taskId: id, info });
        if(res && res.success) { showToast('Uğurlu', res.message, 'success'); closeCustomTaskModal(); }
    }

    async function openBox(type) {
        const res = await apiCall('open_box', { boxType: type });
        if(res && !res.error) showToast(res.jackpot ? 'JACKPOT!' : 'Təbrik!', `Qutu açıldı! Qazanc: $${res.reward}`, res.jackpot ? 'jackpot' : 'success');
    }

    async function requestWithdrawal() {
        const address = document.getElementById('wallet-address').value.trim();
        const amount = parseFloat(document.getElementById('withdraw-amount').value);
        if(amount < 10) { showToast('Xəta', 'Minimum çıxarış $10-dır', 'error'); return; }
        if(address.length < 10) { showToast('Xəta', 'Düzgün cüzdan ünvanı daxil edin', 'error'); return; }
        const res = await apiCall('withdraw', { amount, address });
        if(res && !res.error) {
            showToast('Uğurlu!', 'Çıxarış tələbi göndərildi.', 'success');
            document.getElementById('wallet-address').value = ''; document.getElementById('withdraw-amount').value = '';
        }
    }

    function renderWithdrawHistory() {
        const c = document.getElementById('withdraw-history-container');
        c.innerHTML = '';
        if(!appState.withdrawals || appState.withdrawals.length === 0) { c.innerHTML = '<p class="text-xs text-slate-500 text-center py-2">Tarixçə boşdur.</p>'; return; }
        appState.withdrawals.forEach(w => {
            const statusColor = w.status === 'Approved' ? 'text-emerald-400' : (w.status === 'Rejected' ? 'text-red-400' : 'text-amber-400');
            c.innerHTML += `<div class="glass-card rounded-lg p-3 flex justify-between items-center border border-slate-700/50"><div class="flex flex-col"><span class="text-[11px] font-black text-white">${w.id}</span><span class="text-[9px] text-slate-500 mt-0.5">${w.date}</span></div><div class="flex flex-col items-end"><span class="text-[11px] font-black text-white">$${w.amount}</span><span class="text-[9px] font-bold ${statusColor} uppercase mt-0.5">${w.status}</span></div></div>`;
        });
    }

    function renderReferrals() {
        const c = document.getElementById('referral-list-container');
        c.innerHTML = '';
        if(!appState.referrals || appState.referrals.length === 0) { c.innerHTML = '<p class="text-xs text-slate-500 text-center py-2">Hələ referalınız yoxdur.</p>'; return; }
        appState.referrals.forEach(r => {
            const statusColor = r.status === 'Approved' ? 'text-emerald-400 bg-emerald-500/10' : 'text-amber-400 bg-amber-500/10';
            c.innerHTML += `<div class="glass-card rounded-lg p-3 flex justify-between items-center border border-slate-700/50"><div class="flex flex-col"><span class="text-[11px] font-black text-white">${r.name || r.username || 'İstifadəçi'}</span><span class="text-[9px] text-slate-500 mt-0.5">Qoşuldu: ${r.joinDate}</span></div><span class="${statusColor} px-2 py-1 rounded text-[9px] font-black uppercase border border-current">${r.status}</span></div>`;
        });
    }

    function renderRewardHistory() {
        const c = document.getElementById('referral-rewards-container');
        c.innerHTML = '';
        if(!appState.rewards || appState.rewards.length === 0) { c.innerHTML = '<p class="text-xs text-slate-500 text-center py-2">Tarixçə boşdur.</p>'; return; }
        appState.rewards.forEach(r => {
            c.innerHTML += `<div class="glass-card rounded-lg p-3 flex justify-between items-center border border-slate-700/50"><div class="flex flex-col"><span class="text-[11px] font-black text-white">${r.desc}</span><span class="text-[9px] text-slate-500 mt-0.5">${r.date}</span></div><span class="text-emerald-400 text-xs font-black">+$${r.usd} / +${r.xp} XP</span></div>`;
        });
    }

    function copyRefLink() {
        const input = document.getElementById('ref-link-input');
        input.select(); document.execCommand('copy');
        showToast('Kopyalandı', 'Referal linki kopyalandı.', 'success');
    }

    function shareReferralTelegram() {
        const link = document.getElementById('ref-link-input').value;
        const text = `🚀 XPVerse-ə qoşul və mənimlə birlikdə USDT qazan!\n\n${link}`;
        window.open(`https://t.me/share/url?url=&text=${encodeURIComponent(text)}`);
    }

    function switchTab(tabId) {
        document.querySelectorAll('.view-section').forEach(el => { el.classList.add('hidden'); el.classList.remove('fade-in'); });
        document.querySelectorAll('.nav-btn').forEach(el => el.classList.remove('nav-active'));
        const targetView = document.getElementById(`view-${tabId}`);
        if(targetView) { targetView.classList.remove('hidden'); targetView.classList.add('fade-in'); }
        const targetBtn = document.querySelector(`.nav-btn[data-target="${tabId}"]`);
        if(targetBtn) targetBtn.classList.add('nav-active');
    }

    // --- Admin Panel Logic ---
    function openAdminAuth() {
        document.getElementById('admin-auth-modal').classList.remove('hidden');
        document.getElementById('admin-auth-modal').classList.add('flex');
    }
    
    function closeAdminAuth() {
        document.getElementById('admin-auth-modal').classList.add('hidden');
        document.getElementById('admin-auth-modal').classList.remove('flex');
    }
    
    async function submitAdminAuth() {
        const code = document.getElementById('admin-code-input').value;
        if(!code) return;
        adminToken = code;
        const res = await apiCall('admin_dashboard', { adminCode: adminToken });
        if(res && res.success) {
            closeAdminAuth(); switchTab('admin'); populateAdminData(res);
        }
    }

    function switchAdminTab(tab) {
        document.querySelectorAll('.admin-section').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.admin-tab').forEach(el => { el.classList.remove('bg-red-600/20', 'text-red-400', 'border-red-500/50'); el.classList.add('text-slate-400'); });
        document.getElementById(`admin-sec-${tab}`).classList.remove('hidden');
        const btn = document.getElementById(`tab-${tab}`);
        btn.classList.remove('text-slate-400'); btn.classList.add('bg-red-600/20', 'text-red-400', 'border-red-500/50');
    }

    function populateAdminData(data) {
        if(data.stats) {
            document.getElementById('adm-stat-users').innerText = data.stats.users; document.getElementById('adm-stat-usd').innerText = `$${formatNum(data.stats.usd, true)}`;
            document.getElementById('adm-stat-ads').innerText = data.stats.ads; document.getElementById('adm-stat-tasks').innerText = data.stats.tasks;
            document.getElementById('adm-stat-xp').innerText = formatNum(data.stats.xp); document.getElementById('adm-stat-refs').innerText = data.stats.refs;
        }
        if(data.all_users) { adminUsers = data.all_users; renderAdminUsers(); }
        if(data.all_withdrawals) { adminWithdrawals = data.all_withdrawals; renderAdminWithdrawals(); }
        if(data.custom_tasks) { adminCustomTasks = data.custom_tasks; renderAdminCustomTasks(); }
        if(data.submissions) { adminSubmissions = data.submissions; renderAdminSubmissions(); }
        if(data.adminSettings) {
            document.getElementById('admin-maintenance').checked = data.adminSettings.maintenance || false;
            document.getElementById('admin-uid').value = data.adminSettings.adminUid || '';
            document.getElementById('admin-reset-time').value = data.adminSettings.resetTime || '03:00';
            document.getElementById('admin-xp-mult').value = data.adminSettings.xpMultiplier || 1;
            document.getElementById('admin-ad-sdk').value = (data.adminSettings.adBlockIds || []).join(', ');
        }
        if(data.errors_log) { renderAdminErrorsTab(data.errors_log); }
    }

    function renderAdminErrorsTab(errors) {
        const list = document.getElementById('admin-error-list'); list.innerHTML = '';
        if(!errors || errors.length === 0) { list.innerHTML = '<p class="text-xs text-slate-400 text-center">Xəta tapılmadı.</p>'; return; }
        errors.forEach(e => {
            list.innerHTML += `<div class="glass-card p-3 rounded-lg border border-red-500/30 bg-red-900/10 mb-2"><div class="flex justify-between items-start mb-1"><span class="text-[10px] text-red-400 font-black uppercase">UID: ${e.uid} (@${e.username})</span><span class="text-[8px] text-slate-500">${e.date}</span></div><p class="text-[9px] text-slate-300 font-mono bg-[#050511] p-2 rounded">${e.error}</p><div class="text-[8px] text-slate-500 mt-1 uppercase">Aksiya: ${e.action}</div></div>`;
        });
    }

    function renderAdminUsers(filter = '') {
        const list = document.getElementById('admin-user-list'); list.innerHTML = '';
        const f = filter.toLowerCase();
        const filtered = adminUsers.filter(u => u.tgId.includes(f) || (u.username||'').toLowerCase().includes(f));
        
        filtered.slice(0, 50).forEach(u => {
            list.innerHTML += `<div class="glass-card p-3 rounded-lg border ${u.banned ? 'border-red-500/50 bg-red-900/10' : 'border-slate-700'} mb-2"><div class="flex justify-between items-center mb-2"><span class="text-xs font-black text-white">${u.firstName} (@${u.username})</span><span class="text-[9px] text-slate-400 uppercase">UID: ${u.tgId}</span></div><div class="grid grid-cols-2 gap-2 mb-2 text-[10px]"><div><span class="text-slate-500">XP:</span> <span class="text-crypto-glow font-bold">${u.xp}</span></div><div><span class="text-slate-500">USD:</span> <span class="text-emerald-400 font-bold">$${u.usd.toFixed(2)}</span></div></div><div class="flex gap-2"><button onclick="adminActionUser('${u.tgId}', '${u.banned ? 'unban' : 'ban'}')" class="flex-1 py-1.5 ${u.banned ? 'bg-emerald-600' : 'bg-red-600'} text-white rounded text-[9px] font-black uppercase">${u.banned ? 'Ban Aç' : 'Ban Et'}</button><button onclick="adminActionUser('${u.tgId}', 'reset_ads')" class="flex-1 py-1.5 bg-blue-600 text-white rounded text-[9px] font-black uppercase">Reklam Sıfırla</button></div></div>`;
        });
    }

    function filterAdminUsers() { renderAdminUsers(document.getElementById('admin-user-search').value); }

    async function adminActionUser(targetUid, userAction) {
        const res = await apiCall('admin_action_user', { adminCode: adminToken, targetUid, userAction });
        if(res && res.success) { showToast('Uğurlu', res.message, 'success'); refreshAdmin(); }
    }

    function renderAdminWithdrawals() {
        const list = document.getElementById('admin-withdrawal-list'); list.innerHTML = '';
        adminWithdrawals.forEach(w => {
            const isPending = w.status === 'Pending';
            list.innerHTML += `<div class="glass-card p-3 rounded-lg border ${isPending ? 'border-amber-500/50' : 'border-slate-700'} mb-2"><div class="flex justify-between items-center mb-2"><span class="text-[10px] text-white font-mono bg-slate-800 px-2 py-0.5 rounded">${w.address}</span><span class="text-xs font-black text-emerald-400">$${w.amount}</span></div><div class="text-[9px] text-slate-400 mb-2">UID: ${w.user_id}</div>${isPending ? `<div class="flex gap-2"><button onclick="adminActionWithdraw('${w.user_id}', '${w.idx}', 'approve')" class="flex-1 py-1.5 bg-emerald-600 text-white rounded text-[9px] font-black uppercase">Təsdiqlə</button><button onclick="adminActionWithdraw('${w.user_id}', '${w.idx}', 'reject')" class="flex-1 py-1.5 bg-red-600 text-white rounded text-[9px] font-black uppercase">Rədd Et</button></div>` : `<div class="text-center text-[10px] font-black uppercase ${w.status==='Approved'?'text-emerald-400':'text-red-400'}">${w.status}</div>`}</div>`;
        });
    }

    async function adminActionWithdraw(targetUid, idx, withdrawAction) {
        const res = await apiCall('admin_action_withdraw', { adminCode: adminToken, targetUid, idx, withdrawAction });
        if(res && res.success) { showToast('Uğurlu', res.message, 'success'); refreshAdmin(); }
    }

    function renderAdminCustomTasks() {
        const list = document.getElementById('admin-task-list'); list.innerHTML = '';
        Object.values(adminCustomTasks).forEach(t => {
            list.innerHTML += `<div class="glass-card p-3 rounded-lg border ${t.active ? 'border-blue-500/50' : 'border-slate-700'} mb-2"><div class="flex justify-between items-center mb-1"><span class="text-xs font-black text-white">${t.title}</span><span class="text-[10px] text-crypto-glow">+${t.reward} XP</span></div><div class="flex justify-between items-center"><span class="text-[9px] uppercase font-bold ${t.active ? 'text-emerald-400' : 'text-red-400'}">${t.active ? 'Aktiv' : 'Deaktiv'}</span><button onclick="adminToggleTask('${t.id}')" class="px-3 py-1 bg-slate-800 text-white rounded text-[9px] font-black uppercase">Status Dəyiş</button></div></div>`;
        });
    }

    async function adminToggleTask(taskId) {
        const t = adminCustomTasks[taskId]; if(!t) return;
        t.active = !t.active;
        const res = await apiCall('admin_save_task', { adminCode: adminToken, ...t, taskId });
        if(res && res.success) refreshAdmin();
    }

    async function adminSaveTask() {
        const title = document.getElementById('nt-title').value; const desc = document.getElementById('nt-desc').value;
        const link = document.getElementById('nt-link').value; const reward = document.getElementById('nt-reward').value;
        const reqInfo = document.getElementById('nt-reqInfo').checked; const infoLabel = document.getElementById('nt-infoLabel').value;
        
        if(!title || !link || !reward) return showToast('Xəta', 'Boş xanaları doldurun', 'error');
        
        const res = await apiCall('admin_save_task', { adminCode: adminToken, title, desc, link, reward, requireInfo: reqInfo, infoLabel, active: true });
        if(res && res.success) { showToast('Uğurlu', res.message, 'success'); document.getElementById('nt-title').value = ''; document.getElementById('nt-link').value = ''; document.getElementById('nt-reward').value = ''; refreshAdmin(); }
    }

    function renderAdminSubmissions() {
        const list = document.getElementById('admin-submission-list'); list.innerHTML = '';
        const pending = adminSubmissions.filter(s => s.status === 'Pending');
        if(pending.length === 0) { list.innerHTML = '<p class="text-xs text-slate-500 text-center">Gözləyən təsdiq yoxdur.</p>'; return; }
        
        pending.forEach(s => {
            list.innerHTML += `<div class="glass-card p-3 rounded-lg border border-amber-500/30 mb-2"><div class="flex justify-between items-center mb-1"><span class="text-[11px] font-black text-white">${s.taskTitle}</span><span class="text-[9px] text-slate-400">${s.date}</span></div><div class="text-[9px] text-slate-400 mb-1">UID: ${s.uid} (@${s.username})</div><div class="bg-[#050511] p-2 rounded text-xs text-blue-300 font-mono mb-2 break-words">${s.infoSubmitted}</div><div class="flex gap-2"><button onclick="adminActionSubmission('${s.subId}', 'approve')" class="flex-1 py-1.5 bg-emerald-600 text-white rounded text-[9px] font-black uppercase">Təsdiq</button><button onclick="adminActionSubmission('${s.subId}', 'reject')" class="flex-1 py-1.5 bg-red-600 text-white rounded text-[9px] font-black uppercase">Rədd</button></div></div>`;
        });
    }

    async function adminActionSubmission(subId, subAction) {
        const res = await apiCall('admin_action_submission', { adminCode: adminToken, subId, subAction });
        if(res && res.success) { showToast('Uğurlu', res.message, 'success'); refreshAdmin(); }
    }

    async function saveAdminSettings() {
        const adBlockIds = document.getElementById('admin-ad-sdk').value.split(',').map(s=>s.trim()).filter(Boolean);
        const req = { adminCode: adminToken, maintenance: document.getElementById('admin-maintenance').checked, adminUid: document.getElementById('admin-uid').value, resetTime: document.getElementById('admin-reset-time').value, xpMultiplier: document.getElementById('admin-xp-mult').value, blockIds: adBlockIds };
        const res = await apiCall('admin_update_settings', req);
        if(res && res.success) showToast('Uğurlu', res.message, 'success');
    }

    async function adminResetAllAds() {
        if(!confirm('Bütün istifadəçilərin reklam limiti sıfırlanacaq. Əminsiniz?')) return;
        const res = await apiCall('admin_reset_all_ads', { adminCode: adminToken });
        if(res && res.success) { showToast('Uğurlu', res.message, 'success'); refreshAdmin(); }
    }

    async function adminResetBot() {
        if(!confirm('DİQQƏT: Bütün bot məlumatları silinəcək! Əminsiniz?')) return;
        const res = await apiCall('admin_reset_bot', { adminCode: adminToken });
        if(res && res.success) { showToast('Uğurlu', res.message, 'success'); refreshAdmin(); }
    }

    async function refreshAdmin() {
        if(!adminToken) return;
        const res = await apiCall('admin_dashboard', { adminCode: adminToken });
        if(res && res.success) populateAdminData(res);
    }

    let serverTimeOffset = 0;
    function startTimer() {
        setInterval(() => {
            if(!appState.settings) return;
            const now = Math.floor(Date.now() / 1000) + serverTimeOffset;
            let resetTarget = appState.serverResetTime || now;
            if (now >= resetTarget) resetTarget += 86400; 
            
            const diff = resetTarget - now;
            if (diff > 0) {
                const h = Math.floor(diff / 3600).toString().padStart(2, '0');
                const m = Math.floor((diff % 3600) / 60).toString().padStart(2, '0');
                const s = Math.floor(diff % 60).toString().padStart(2, '0');
                document.getElementById('reset-timer').innerText = `${h}:${m}:${s}`;
            } else {
                document.getElementById('reset-timer').innerText = "00:00:00";
            }
        }, 1000);
    }

    window.addEventListener('load', async () => {
      const initRes = await apiCall('sync');
      if(initRes && initRes.success) {
          const clientTime = Math.floor(Date.now() / 1000);
          serverTimeOffset = initRes.serverTime - clientTime;
          appState.serverResetTime = initRes.serverResetTime;
          
          document.getElementById('loading-overlay').style.opacity = '0';
          setTimeout(() => { document.getElementById('loading-overlay').style.display = 'none'; }, 500);
          startTimer();
      } else {
          document.getElementById('loading-overlay').style.opacity = '0';
          setTimeout(() => { document.getElementById('loading-overlay').style.display = 'none'; }, 500);
      }
    });
  </script>
</body>
</html>
