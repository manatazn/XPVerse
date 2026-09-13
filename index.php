<?php
// XPVERSE - SINGLE FILE ARCHITECTURE
// All server-side logic and persistence is handled here.

error_reporting(0); // Suppress standard PHP errors
date_default_timezone_set('Europe/Moscow'); // Server time consistency
$azTimezone = new DateTimeZone('Asia/Baku'); // Admin display time
$dataDir = __DIR__ . '/data';

// Create data directory if it doesn't exist
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// Global variable for current request UID to use in shutdown functions
$currentUid = 'GUEST';

// Helper: Safe read JSON with shared lock
function readDB($filename) {
    global $dataDir;
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

// Helper: Safe write JSON with exclusive lock
function writeDB($filename, $data) {
    global $dataDir;
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

// Helper: Secure Error Logging System
function logSystemError($uid, $userMessage, $internalReason) {
    global $dataDir;
    $now = new DateTime('now', new DateTimeZone('Asia/Baku'));
    $path = "$dataDir/errors.json";
    
    $fp = fopen($path, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $size = filesize($path);
        $json = $size > 0 ? fread($fp, $size) : '[]';
        $errors = json_decode($json, true) ?: [];
        
        $errors[] = [
            'uid' => $uid,
            'error' => $userMessage,
            'reason' => $internalReason,
            'date' => clone $now->format('Y-m-d H:i:s')
        ];
        
        // Keep only last 200 errors to prevent file bloat
        if (count($errors) > 200) {
            $errors = array_slice($errors, -200); 
        }
        
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($errors, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Helper: Send Error and Exit Securely
function sendSecureError($uid, $userMessage, $internalReason, $extraPayload = []) {
    logSystemError($uid, $userMessage, $internalReason);
    header('Content-Type: application/json');
    $response = array_merge(['error' => $userMessage], $extraPayload);
    echo json_encode($response);
    exit;
}

// Fatal Error Handlers to hide server crashes from users
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    global $currentUid;
    logSystemError($currentUid, 'Sistem Xətası', "PHP Error: $errstr in $errfile:$errline");
    return true; 
});

register_shutdown_function(function() {
    global $currentUid;
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logSystemError($currentUid, 'Kritik Sistem Çökməsi', $error['message'] . ' in ' . $error['file']);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sistemdə kritik xəta baş verdi. Zəhmət olmasa bir az sonra təkrar yoxlayın.']);
    }
});

// Ensure settings exist
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

// Logical Date Calculation
$resetTarget = clone $now;
$resetTarget->setTime((int)$rHour, (int)$rMin, 0);
if ($now < $resetTarget) {
    $logicalDate = clone $now;
    $logicalDate->modify('-1 day');
    $today = $logicalDate->format('Y-m-d');
} else {
    $today = $now->format('Y-m-d');
}

// Next reset target for frontend countdown
if ($now >= $resetTarget) {
    $resetTarget->modify('+1 day');
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action']) || !isset($input['tgId'])) {
        sendSecureError('UNKNOWN', 'Səhv Sorğu', 'API sorğusunda action və ya tgId yoxdur');
    }

    $action = $input['action'];
    $uid = (string)$input['tgId'];
    $currentUid = $uid; // Update global for error handlers
    
    $isSilentSync = $input['silent'] ?? false;
    $masterAdmin = $settings['adminUid'] ?? '5461064199';

    // Global Try-Catch to prevent any unhandled exceptions reaching the user
    try {
        // Maintenance Mode Check
        if (isset($settings['maintenance']) && $settings['maintenance'] === true) {
            if ($uid !== $masterAdmin && $uid !== '5461064199') {
                sendSecureError($uid, 'MAINTENANCE', 'İstifadəçi baxım rejimində botu açmağa çalışdı', ['message' => 'The bot is currently undergoing maintenance. Please try again later.']);
            }
        }

        $users = readDB('users.json');
        $referrals = readDB('referrals.json');
        $rewards = readDB('rewards.json');
        $withdrawals = readDB('withdrawals.json');
        $tasks = readDB('tasks.json');
        $customTasks = readDB('custom_tasks.json');
        $submissions = readDB('task_submissions.json');
        
        // Check Ban Status
        if (isset($users[$uid]['banned']) && $users[$uid]['banned'] === true && $uid !== $masterAdmin) {
            sendSecureError($uid, 'BANNED', 'Ban olunmuş istifadəçi giriş etməyə çalışdı', ['message' => 'Your account has been banned by the administrator.']);
        }

        // 1. User Initialization & Restoration
        if (!isset($users[$uid])) {
            $users[$uid] = [
                'tgId' => $uid,
                'firstName' => $input['firstName'] ?? 'User',
                'lastName' => $input['lastName'] ?? '',
                'username' => $input['username'] ?? '',
                'photoUrl' => $input['photoUrl'] ?? '',
                'xp' => 0,
                'totalXp' => 0,
                'xpSpent' => 0,
                'dailyXp' => 0,
                'usd' => 0.00,
                'level' => 1,
                'adsWatchedToday' => 0,
                'totalAdsWatched' => 0,
                'tasksCompleted' => 0,
                'boxesOpened' => 0,
                'streak' => 1,
                'lastResetDay' => $today,
                'referrer' => null,
                'sponsorAzx' => false,
                'rejectedTasks' => [],
                'lastActive' => $now->format('Y-m-d H:i:s'),
                'banned' => false
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
                        'ads' => 0,
                        'tasks' => 0,
                        'joinDate' => $now->format('M j, Y')
                    ];
                    writeDB('referrals.json', $referrals);
                }
            }
        } else {
            if (isset($input['firstName'])) $users[$uid]['firstName'] = $input['firstName'];
            if (isset($input['lastName'])) $users[$uid]['lastName'] = $input['lastName'];
            if (isset($input['username'])) $users[$uid]['username'] = $input['username'];
            if (isset($input['photoUrl']) && !empty($input['photoUrl'])) $users[$uid]['photoUrl'] = $input['photoUrl'];
            if (!$isSilentSync) {
                $users[$uid]['lastActive'] = $now->format('Y-m-d H:i:s');
            }
            if (!isset($users[$uid]['xpSpent'])) $users[$uid]['xpSpent'] = 0;
            if (!isset($users[$uid]['dailyXp'])) $users[$uid]['dailyXp'] = 0;
            if (!isset($users[$uid]['rejectedTasks'])) $users[$uid]['rejectedTasks'] = [];
        }

        // Daily Reset Logic
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

        // Helper: Level Calculation
        function calcLevel($xp) {
            if ($xp >= 20000) return 10;
            if ($xp >= 3000) return 5;
            if ($xp >= 1500) return 4;
            if ($xp >= 750) return 3;
            if ($xp >= 250) return 2;
            return 1;
        }

        // Helper: Evaluate Referral Approval
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

        // --- ADMIN PANEL SECURE ROUTES ---
        if (strpos($action, 'admin_') === 0) {
            if ($uid !== $masterAdmin && $uid !== '5461064199') {
                sendSecureError($uid, 'Giriş Qadağandır', 'İcazəsiz admin panelinə giriş cəhdi (UID uyğun gəlmir)');
            }
            if (!isset($input['adminCode']) || $input['adminCode'] !== 'PZX9N4ML2DK') {
                sendSecureError($uid, 'Giriş Qadağandır', 'Yanlış admin şifrəsi ilə daxil olma cəhdi');
            }

            if ($action === 'admin_dashboard') {
                $totalUsd = 0; $totalAds = 0; $totalTasks = 0; $totalXp = 0; $totalUsers = count($users);
                $totalRefs = 0;
                foreach($users as &$u) {
                    $totalUsd += $u['usd'];
                    $totalAds += $u['totalAdsWatched'];
                    $totalTasks += $u['tasksCompleted'];
                    $totalXp += $u['totalXp'];
                    
                    $lastAct = new DateTime($u['lastActive']);
                    $lastAct->setTimezone($azTimezone);
                    $u['lastActiveAz'] = $lastAct->format('Y-m-d H:i:s');
                    $u['refCount'] = isset($referrals[$u['tgId']]) ? count($referrals[$u['tgId']]) : 0;
                }
                foreach($withdrawals as $wList) {
                    foreach($wList as $w) {
                        if ($w['status'] === 'Approved') $totalUsd += $w['amount'];
                    }
                }
                foreach($referrals as $rList) { $totalRefs += count($rList); }

                $allWithdrawals = [];
                foreach($withdrawals as $uId => $uWithdrawals) {
                    foreach($uWithdrawals as $idx => $w) {
                        $w['user_id'] = $uId;
                        $w['idx'] = $idx;
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
                
                // Add System Errors log for Admin Dashboard
                $response['system_errors'] = array_reverse(readDB('errors.json'));
                
                echo json_encode($response); exit;
            }

            if ($action === 'admin_clear_errors') {
                writeDB('errors.json', []);
                $response['message'] = 'Bütün sistem xətaları loqdan silindi.';
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
                foreach($users as $k => $u) {
                    $users[$k]['adsWatchedToday'] = 0;
                }
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
                    'title' => $input['title'],
                    'desc' => $input['desc'],
                    'link' => $input['link'],
                    'reward' => (int)$input['reward'],
                    'requireInfo' => (bool)$input['requireInfo'],
                    'infoLabel' => $input['infoLabel'] ?? 'UID:',
                    'active' => (bool)$input['active']
                ];
                writeDB('custom_tasks.json', $customTasks);
                $response['message'] = 'Tapşırıq uğurla yadda saxlanıldı.';
                echo json_encode($response); exit;
            }

            if ($action === 'admin_action_submission') {
                $subId = $input['subId'];
                $subAction = $input['subAction'];
                
                foreach ($submissions as $k => $sub) {
                    if ($sub['subId'] === $subId && $sub['status'] === 'Pending') {
                        $targetUid = $sub['uid'];
                        if ($subAction === 'approve') {
                            $submissions[$k]['status'] = 'Approved';
                            $tReward = isset($customTasks[$sub['taskId']]) ? $customTasks[$sub['taskId']]['reward'] : 0;
                            if(isset($users[$targetUid])) {
                                $users[$targetUid]['xp'] += $tReward;
                                $users[$targetUid]['totalXp'] += $tReward;
                                $users[$targetUid]['dailyXp'] += $tReward;
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
                        writeDB('users.json', $users);
                        writeDB('tasks.json', $tasks);
                        $response['message'] = 'Təsdiq prosesi icra olundu.';
                        echo json_encode($response); exit;
                    }
                }
                sendSecureError($uid, 'Təsdiq tapılmadı', "Submission ID $subId tapılmadı admin_action_submission-də");
            }

            if ($action === 'admin_action_user') {
                $targetUid = $input['targetUid'];
                $act = $input['userAction'];
                
                if (!isset($users[$targetUid])) {
                    sendSecureError($uid, 'İstifadəçi tapılmadı', "Admin action user $targetUid üçün icra edilə bilmədi");
                }

                if ($act === 'ban') {
                    $users[$targetUid]['banned'] = true;
                } elseif ($act === 'unban') {
                    $users[$targetUid]['banned'] = false;
                } elseif ($act === 'reset_ads') {
                    $users[$targetUid]['adsWatchedToday'] = 0;
                } elseif ($act === 'update_balance') {
                    $users[$targetUid]['usd'] = max(0, (float)$input['newUsd']);
                    $users[$targetUid]['xp'] = max(0, (int)$input['newXp']);
                    $users[$targetUid]['totalXp'] = max($users[$targetUid]['totalXp'], $users[$targetUid]['xp']);
                }
                writeDB('users.json', $users);
                $response['message'] = 'İstifadəçi yeniləndi.';
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
                            writeDB('users.json', $users);
                        }
                        writeDB('withdrawals.json', $withdrawals);
                        $response['message'] = 'Çıxarış prosesi icra olundu.';
                    } else {
                        sendSecureError($uid, 'Artıq icra olunub', "Admin withdrawal action zatən edilib idx: $idx");
                    }
                } else {
                    sendSecureError($uid, 'Çıxarış tapılmadı', "Admin withdrawal tapılmadı idx: $idx uid: $targetUid");
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
                    $response['error'] = 'Ad limit reached';
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
                        $response['error'] = 'Task already claimed.';
                    }
                } else {
                    if (!isset($tasks[$uid])) $tasks[$uid] = [];
                    if (!in_array($taskId, $tasks[$uid])) {
                        if ($taskId === 'complete_all' && $users[$uid]['adsWatchedToday'] < 30) {
                            $response['error'] = 'Complete all daily tasks first.';
                            break;
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
                        $response['error'] = 'Task already claimed.';
                    }
                }
                break;

            case 'submit_task_info':
                $taskId = $input['taskId'];
                $info = $input['info'];
                if(isset($customTasks[$taskId]) && $customTasks[$taskId]['requireInfo']) {
                    if(in_array($taskId, $users[$uid]['rejectedTasks'] ?? [])) {
                        $response['error'] = 'You cannot resubmit a rejected task.';
                        break;
                    }
                    
                    $alreadyPending = false;
                    foreach($submissions as $s) {
                        if($s['uid'] === $uid && $s['taskId'] === $taskId && $s['status'] === 'Pending') $alreadyPending = true;
                    }
                    if($alreadyPending) {
                        $response['error'] = 'Already pending approval.';
                        break;
                    }

                    $submissions[] = [
                        'subId' => uniqid('sub_'),
                        'uid' => $uid,
                        'username' => $users[$uid]['username'],
                        'name' => $users[$uid]['firstName'] . ' ' . $users[$uid]['lastName'],
                        'taskId' => $taskId,
                        'taskTitle' => $customTasks[$taskId]['title'],
                        'infoSubmitted' => $info,
                        'status' => 'Pending',
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
                    $response['error'] = 'Not enough XP';
                }
                break;

            case 'withdraw':
                $amount = (float)($input['amount'] ?? 0);
                $address = $input['address'] ?? '';
                
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
                    $response['error'] = 'Invalid withdrawal request.';
                }
                break;
                
            case 'sync':
                // Simply returns synced data without action
                break;
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
        
    } catch (Throwable $e) {
        // Ultimate fallback safety: catches everything inside the API run
        sendSecureError($uid, 'Sistem Xətası', "Exception: " . $e->getMessage() . " on line " . $e->getLine());
    }
}

// -----------------------------------------------------------------------------------------
// FRONTEND - HTML / JS / CSS 
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
            'pop': 'pop 0.3s ease-out forwards',
            'shimmer': 'shimmer 2s infinite',
            'slide-up': 'slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards'
          },
          keyframes: {
            blob: {
              '0%': { transform: 'translate(0px, 0px) scale(1)' },
              '33%': { transform: 'translate(30px, -50px) scale(1.1)' },
              '66%': { transform: 'translate(-20px, 20px) scale(0.9)' },
              '100%': { transform: 'translate(0px, 0px) scale(1)' },
            },
            pop: {
              '0%': { transform: 'scale(1)' },
              '50%': { transform: 'scale(1.3)' },
              '100%': { transform: 'scale(0)', opacity: 0 }
            },
            shimmer: {
              '0%': { transform: 'translateX(-100%)' },
              '100%': { transform: 'translateX(100%)' }
            },
            slideUp: {
              '0%': { transform: 'translateY(15px)', opacity: 0 },
              '100%': { transform: 'translateY(0)', opacity: 1 }
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
    .nav-active::before { content: ''; position: absolute; top: -10px; left: 50%; transform: translateX(-50%); width: 20px; height: 3px; background: #00f0ff; border-radius: 4px; box-shadow: 0 0 10px #00f0ff, 0 0 20px #3b82f6; }
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
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1s_linear_infinite] shadow-[0_0_20px_rgba(0,240,255,0.6)]"></div>
      <div class="absolute inset-2 rounded-full border-b-4 border-blue-500 animate-[spin_1.5s_linear_infinite_reverse]"></div>
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-rocket text-crypto-glow text-3xl animate-pulse drop-shadow-[0_0_15px_#00f0ff]"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2 drop-shadow-lg">XPVerse</h2>
  </div>

  <!-- Notification Toast -->
  <div id="toast-container" class="glass-card rounded-2xl p-3 flex items-center gap-3">
    <div id="toast-icon" class="w-10 h-10 rounded-full flex shrink-0 items-center justify-center text-lg shadow-inner">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-xs font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-[11px] text-slate-300 mt-0.5 leading-tight">Message goes here</p>
    </div>
  </div>

  <!-- GLOBAL FIXED HEADER -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full py-3 px-4 glass-card rounded-b-[1.5rem] border-b-0 shadow-[0_10px_30px_rgba(0,0,0,0.5)] transition-all duration-300">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-2.5">
        <div class="relative w-10 h-10 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500 shadow-[0_0_15px_rgba(0,240,255,0.3)]">
          <img id="user-photo" src="https://via.placeholder.com/150/0a0b1a/00f0ff" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-[#050511]">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm tracking-wide leading-tight">Loading...</span>
          <div class="flex items-center gap-1.5 mt-0.5">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_#34d399] animate-pulse"></span>
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
      <div class="relative glass-card rounded-[1.5rem] p-5 text-center border-t border-t-blue-400/20 overflow-hidden flex flex-col items-center justify-center min-h-[220px]">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-48 h-48 bg-blue-500/20 rounded-full filter blur-[40px] pointer-events-none animate-pulse-fast"></div>
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-900/60 to-[#050511] border border-blue-400/40 flex items-center justify-center mb-3 shadow-[0_0_20px_rgba(59,130,246,0.4)]">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow drop-shadow-[0_0_10px_rgba(0,240,255,0.9)]"></i>
          </div>
          <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.25em] mb-1 opacity-90">Total Balance</p>
          <h1 class="text-4xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-500 tracking-tighter drop-shadow-xl" id="main-xp-display">0 XP</h1>
        </div>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5 backdrop-blur-md shadow-inner">
            <div class="bg-blue-500/10 p-2.5 rounded-lg border border-blue-500/30"><i class="fa-solid fa-clapperboard text-blue-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black tracking-wider">Ads Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / 30</p>
            </div>
          </div>
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5 backdrop-blur-md shadow-inner">
            <div class="bg-amber-500/10 p-2.5 rounded-lg border border-amber-500/30"><i class="fa-solid fa-fire-flame-curved text-amber-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black tracking-wider">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-3.5 rounded-[1.25rem] text-white font-black text-sm tracking-[0.1em] uppercase flex items-center justify-center gap-2.5 shadow-[0_10px_20px_rgba(59,130,246,0.3)] btn-3d relative overflow-hidden group">
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-[shimmer_1.5s_infinite]"></div>
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px] drop-shadow-md"></i> 
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
        <p class="text-[11px] text-crypto-glow mt-0.5 uppercase tracking-widest font-bold">Complete & Earn</p>
      </div>
      
      <div class="glass-card rounded-[1.25rem] p-4 relative overflow-hidden border border-crypto-glow shadow-[0_0_15px_rgba(0,240,255,0.1)] bg-gradient-to-br from-blue-900/30 to-[#050511]">
        <div class="absolute -right-10 -top-10 w-24 h-24 bg-crypto-glow/15 rounded-full blur-2xl"></div>
        <div class="relative z-10 flex flex-col gap-3">
            <div class="flex justify-between items-start">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-lg">
                        <i class="fa-solid fa-calendar-day text-lg text-white"></i>
                    </div>
                    <div>
                        <h3 class="text-[15px] font-black text-white tracking-wide">Daily Login</h3>
                        <p class="text-[9px] text-cyan-400 font-bold uppercase tracking-widest mt-0.5">Keep your streak alive</p>
                    </div>
                </div>
                <div id="daily-login-btn-container"></div>
            </div>
            
            <div class="bg-[#050511]/60 rounded-xl p-3 border border-slate-700/50">
                <div class="relative flex justify-between items-center" id="streak-tracker-container"></div>
            </div>
        </div>
      </div>

      <div class="mt-6 mb-4">
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2">
            <i class="fa-solid fa-star text-amber-400"></i> Partner Tasks
        </h3>
        <div id="sponsor-container" class="space-y-3"></div>
        <div id="custom-tasks-container" class="space-y-3 mt-3"></div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2">
            <i class="fa-solid fa-list-check text-slate-600"></i> Daily Missions
        </h3>
        <div id="missions-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- REFERRANS PAGE -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-5">
      <div class="text-center relative mb-1">
        <h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Referans</h2>
        <p class="text-[11px] text-blue-400 mt-0.5 uppercase tracking-widest font-bold">Invite & Earn</p>
        <button onclick="toggleRefInfo()" class="absolute top-0 right-1 w-8 h-8 rounded-full bg-blue-500/20 border border-blue-500/50 flex items-center justify-center text-blue-400 active:scale-90 transition-transform shadow-[0_0_10px_rgba(59,130,246,0.3)]">
          <i class="fa-solid fa-circle-question text-base"></i>
        </button>
      </div>

      <div class="grid grid-cols-3 gap-2.5">
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-blue-500/40 shadow-lg">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Total</p>
          <p id="ref-total" class="text-xl font-black text-white drop-shadow-md">0</p>
        </div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-amber-500/40 shadow-lg">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Pending</p>
          <p id="ref-pending" class="text-xl font-black text-amber-400 drop-shadow-md">0</p>
        </div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-emerald-500/40 shadow-lg">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Approved</p>
          <p id="ref-approved" class="text-xl font-black text-emerald-400 drop-shadow-md">0</p>
        </div>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 space-y-3.5 border border-slate-700/50">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-1.5 ml-1">Your Referral Link</label>
          <div class="flex items-center gap-2">
            <input type="text" id="ref-link-input" readonly class="flex-1 bg-[#050511]/70 border border-slate-700 rounded-lg py-2.5 px-3 text-[11px] font-medium text-slate-300 focus:outline-none shadow-inner">
            <button onclick="copyRefLink()" class="bg-slate-800 text-white w-10 h-10 rounded-lg flex items-center justify-center active:scale-95 transition-transform border border-slate-600 hover:bg-slate-700 shadow-md">
              <i class="fa-regular fa-copy text-sm"></i>
            </button>
          </div>
        </div>
        <button onclick="shareReferralTelegram()" class="w-full py-3 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-lg text-xs uppercase tracking-wider flex items-center justify-center gap-2 shadow-[0_5px_15px_rgba(0,240,255,0.3)] active:scale-95 transition-transform hover:brightness-110">
          <i class="fa-brands fa-telegram text-base"></i> Share via Telegram
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2">
          <i class="fa-solid fa-users text-slate-600"></i> Your Referrals
        </h3>
        <div id="referral-list-container" class="space-y-2.5"></div>
      </div>

      <div class="mt-6">
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2 border-t border-slate-800/80 pt-4">
          <i class="fa-solid fa-gift text-slate-600"></i> Reward History
        </h3>
        <div id="referral-rewards-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- BOX PAGE -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-4">
        <h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Box</h2>
        <p class="text-[11px] text-amber-400 mt-0.5 uppercase tracking-widest font-bold">Try Your Luck, Win USDT</p>
      </div>
      
      <div class="box-bronze glass-card rounded-[1.25rem] p-4 relative overflow-hidden flex justify-between items-center transition-transform hover:scale-[1.02] shadow-md">
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-24 h-24 bg-crypto-bronze/20 rounded-full blur-xl"></div>
        <div class="flex items-center gap-3 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-orange-900 to-[#050511] border border-crypto-bronze flex items-center justify-center shadow-[0_0_15px_rgba(205,127,50,0.3)]">
            <i class="fa-solid fa-box text-2xl text-crypto-bronze"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-white tracking-wide">Bronze Box</h3>
            <div class="flex flex-col mt-0.5">
              <span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase flex items-center"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 10,000 XP</span>
              <span class="text-[11px] text-emerald-400 font-bold">Max Reward: $1.00 USDT</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('bronze')" class="relative z-10 bg-gradient-to-b from-orange-600 to-orange-800 text-white shadow-[0_4px_10px_rgba(205,127,50,0.4)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-lg text-xs font-black uppercase tracking-wider">Open</button>
      </div>

      <div class="box-silver glass-card rounded-[1.25rem] p-4 relative overflow-hidden flex justify-between items-center transition-transform hover:scale-[1.02] shadow-md">
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-24 h-24 bg-crypto-silver/20 rounded-full blur-xl"></div>
        <div class="flex items-center gap-3 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-slate-600 to-[#050511] border border-crypto-silver flex items-center justify-center shadow-[0_0_15px_rgba(226,232,240,0.3)]">
            <i class="fa-solid fa-box-open text-2xl text-crypto-silver drop-shadow-sm"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-white tracking-wide">Silver Box</h3>
            <div class="flex flex-col mt-0.5">
              <span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase flex items-center"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 50,000 XP</span>
              <span class="text-[11px] text-emerald-400 font-bold">Max Reward: $7.00 USDT</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('silver')" class="relative z-10 bg-gradient-to-b from-slate-300 to-slate-500 text-crypto-dark shadow-[0_4px_10px_rgba(226,232,240,0.3)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-lg text-xs font-black uppercase tracking-wider">Open</button>
      </div>

      <div class="box-gold glass-card rounded-[1.25rem] p-4 relative overflow-hidden flex justify-between items-center border border-crypto-gold shadow-[0_0_20px_rgba(255,184,0,0.15)] transition-transform hover:scale-[1.02]">
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-28 h-28 bg-crypto-gold/20 rounded-full blur-xl animate-pulse"></div>
        <div class="flex items-center gap-3 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500 to-[#050511] border border-crypto-gold flex items-center justify-center shadow-[0_0_20px_rgba(255,184,0,0.5)]">
            <i class="fa-solid fa-gem text-2xl text-crypto-gold drop-shadow-[0_0_10px_#ffb800]"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-crypto-gold tracking-wide drop-shadow-[0_0_3px_rgba(255,184,0,0.3)]">Gold Box</h3>
            <div class="flex flex-col mt-0.5">
              <span class="text-[10px] text-amber-200/80 font-bold tracking-wider uppercase flex items-center"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 100,000 XP</span>
              <span class="text-[11px] text-emerald-400 font-bold">Max Reward: $15.00 USDT</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('gold')" class="relative z-10 bg-gradient-to-b from-yellow-400 to-amber-600 text-crypto-dark shadow-[0_4px_15px_rgba(255,184,0,0.5)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-lg text-xs font-black uppercase tracking-wider">Open</button>
      </div>
    </div>

    <!-- WALLET PAGE -->
    <div id="view-wallet" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1">
        <h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Wallet</h2>
        <p class="text-[11px] text-emerald-400 mt-0.5 uppercase tracking-widest font-bold">USDT Withdrawal on TON</p>
      </div>

      <div class="glass-card rounded-[1.5rem] p-5 text-center border-t border-emerald-500/30 bg-gradient-to-b from-emerald-900/20 to-[#050511] shadow-lg">
        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-1 opacity-90">Available Balance</p>
        <h1 class="text-4xl font-black text-white tracking-tighter mb-3">$<span id="withdraw-balance-display">0</span></h1>
        <div class="inline-block bg-[#050511]/80 backdrop-blur-md px-3 py-1.5 rounded-lg border border-emerald-500/20 shadow-inner">
          <p class="text-[9px] font-bold text-slate-300 uppercase tracking-widest"><i class="fa-solid fa-circle-info text-emerald-400 mr-1"></i> Min Withdrawal: $10</p>
        </div>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 space-y-4 border border-slate-700/50 shadow-md">
        <div class="bg-blue-900/15 border border-blue-500/20 p-2.5 rounded-lg flex items-start gap-2.5">
            <i class="fa-solid fa-shield-halved text-blue-400 mt-0.5 text-xs"></i>
            <div>
                <p class="text-[9px] font-bold text-blue-300 uppercase tracking-widest mb-0.5">Network Details</p>
                <p class="text-[11px] text-slate-400 leading-tight">Withdrawals are via <strong class="text-white">USDT</strong> on the <strong class="text-white">TON Network</strong>.</p>
            </div>
        </div>

        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-1.5 ml-1">TON Wallet Address <span class="text-red-400">*</span></label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <img src="https://cryptologos.cc/logos/toncoin-ton-logo.png" class="w-4 h-4 opacity-70 group-focus-within:opacity-100 transition-opacity" alt="TON">
            </div>
            <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511]/70 border border-slate-700/60 rounded-lg py-2.5 pl-9 pr-3 text-xs font-medium text-white focus:outline-none focus:border-blue-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>
        
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-1.5 ml-1">Amount (USDT) <span class="text-red-400">*</span></label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <i class="fa-solid fa-dollar-sign text-slate-500 group-focus-within:text-emerald-400 transition-colors text-sm"></i>
            </div>
            <input type="number" id="withdraw-amount" placeholder="10" min="10" step="0.5" class="w-full bg-[#050511]/70 border border-slate-700/60 rounded-lg py-2.5 pl-9 pr-3 text-xs font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>

        <button onclick="requestWithdrawal()" id="withdraw-btn" class="w-full py-3 mt-1 bg-gradient-to-r from-emerald-600 to-teal-500 hover:brightness-110 active:scale-95 transition-all text-white font-black rounded-lg text-xs uppercase tracking-[0.1em] flex items-center justify-center gap-2 shadow-[0_5px_15px_rgba(16,185,129,0.3)]">
          <i class="fa-solid fa-money-bill-transfer text-base"></i> Request Withdrawal
        </button>
      </div>

      <div class="mt-6">
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2">
          <i class="fa-solid fa-clock-rotate-left text-slate-600"></i> Withdrawal History
        </h3>
        <div id="withdraw-history-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- PROFILE PAGE -->
    <div id="view-profile" class="view-section hidden fade-in space-y-5">
      
      <!-- Profile Header -->
      <div class="glass-card rounded-[1.5rem] p-5 flex flex-col items-center justify-center border-t border-t-blue-500/30 shadow-lg relative overflow-hidden">
        
        <!-- SECURE ADMIN BUTTON -->
        <button id="admin-secret-btn" onclick="openAdminAuth()" class="hidden absolute top-4 right-4 w-8 h-8 rounded-full bg-red-600/20 text-red-500 border border-red-500/50 flex items-center justify-center shadow-[0_0_15px_rgba(239,68,68,0.4)] hover:scale-110 transition-transform">
            <i class="fa-solid fa-user-shield text-sm"></i>
        </button>

        <div class="absolute top-0 right-0 w-32 h-32 bg-blue-600/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-32 h-32 bg-indigo-600/10 rounded-full blur-2xl pointer-events-none"></div>
        
        <div class="relative z-10 w-20 h-20 rounded-full p-1 bg-gradient-to-tr from-blue-500 via-crypto-glow to-purple-500 shadow-[0_0_20px_rgba(0,240,255,0.2)] mb-3">
          <img id="profile-page-avatar" src="" alt="Avatar" class="w-full h-full rounded-full object-cover border-[3px] border-[#050511]">
        </div>

        <h2 id="profile-page-name" class="text-xl font-black text-white tracking-wide mb-0.5 break-words text-center max-w-full">Name</h2>
        <p id="profile-page-username" class="text-[11px] font-mono text-blue-400 mb-2.5 bg-blue-500/10 px-2.5 py-0.5 rounded-full border border-blue-500/20 break-words max-w-full">@username</p>
        
        <div class="flex items-center gap-1.5 bg-[#050511]/60 px-3 py-1.5 rounded-lg border border-slate-700/50">
            <i class="fa-brands fa-telegram text-slate-400 text-xs"></i>
            <span class="text-[9px] text-slate-400 uppercase font-bold tracking-widest">ID: <span id="profile-page-id" class="text-white ml-1">0000000</span></span>
        </div>
      </div>

      <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-1.5 mt-5 flex items-center gap-2">
          <i class="fa-solid fa-chart-pie text-slate-600"></i> Account Stats
      </h3>
      
      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card p-4 rounded-xl border-t border-t-crypto-glow/40 shadow-md flex flex-col items-center justify-center text-center">
            <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center mb-1.5">
                <i class="fa-solid fa-bolt text-crypto-glow text-sm"></i>
            </div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Total XP</p>
            <p id="profile-stat-xp" class="text-lg font-black text-white">0</p>
        </div>
        <div class="glass-card p-4 rounded-xl border-t border-t-emerald-500/40 shadow-md flex flex-col items-center justify-center text-center">
            <div class="w-8 h-8 rounded-lg bg-emerald-500/10 flex items-center justify-center mb-1.5">
                <i class="fa-solid fa-wallet text-emerald-400 text-sm"></i>
            </div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Balance</p>
            <p id="profile-stat-usd" class="text-lg font-black text-white">$0</p>
        </div>
        <div class="glass-card p-4 rounded-xl border-t border-t-purple-500/40 shadow-md flex flex-col items-center justify-center text-center">
            <div class="w-8 h-8 rounded-lg bg-purple-500/10 flex items-center justify-center mb-1.5">
                <i class="fa-solid fa-users text-purple-400 text-sm"></i>
            </div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Referrals</p>
            <p id="profile-stat-refs" class="text-lg font-black text-white">0</p>
        </div>
        <div class="glass-card p-4 rounded-xl border-t border-t-amber-500/40 shadow-md flex flex-col items-center justify-center text-center">
            <div class="w-8 h-8 rounded-lg bg-amber-500/10 flex items-center justify-center mb-1.5">
                <i class="fa-solid fa-list-check text-amber-400 text-sm"></i>
            </div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Tasks Done</p>
            <p id="profile-stat-tasks" class="text-lg font-black text-white">0</p>
        </div>
      </div>
    </div>

    <!-- ADMIN PANEL -->
    <div id="view-admin" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-2">
        <h2 class="text-2xl font-black text-red-500 tracking-tight drop-shadow-lg flex items-center justify-center gap-2"><i class="fa-solid fa-shield-halved"></i> İDARƏ PANELİ</h2>
      </div>

      <div class="flex flex-wrap gap-1.5 mb-2 bg-[#050511] p-1.5 rounded-lg border border-slate-700/50">
          <button onclick="switchAdminTab('dashboard')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded bg-red-600/20 text-red-400 border border-red-500/50 transition" id="tab-dashboard">Panel</button>
          <button onclick="switchAdminTab('users')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-users">İstifadəçilər</button>
          <button onclick="switchAdminTab('withdrawals')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-withdrawals">Çıxarışlar</button>
          <button onclick="switchAdminTab('tasks')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-tasks">Tapşırıqlar</button>
          <button onclick="switchAdminTab('submissions')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-submissions">Təsdiqlər</button>
          <button onclick="switchAdminTab('errors')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-errors">Xətalar</button>
          <button onclick="switchAdminTab('settings')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-settings">Ayarlar</button>
      </div>

      <!-- Dashboard Stats -->
      <div id="admin-sec-dashboard" class="admin-section space-y-3">
          <div class="grid grid-cols-2 gap-2">
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Ümumi İstifadəçi</p>
                  <p id="adm-stat-users" class="text-lg font-black text-blue-400">0</p>
              </div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Qazanılan USD</p>
                  <p id="adm-stat-usd" class="text-lg font-black text-emerald-400">$0</p>
              </div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">İzlənən Reklam</p>
                  <p id="adm-stat-ads" class="text-lg font-black text-white">0</p>
              </div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Bitən Tapşırıqlar</p>
                  <p id="adm-stat-tasks" class="text-lg font-black text-white">0</p>
              </div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Ümumi XP</p>
                  <p id="adm-stat-xp" class="text-lg font-black text-crypto-glow">0</p>
              </div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Referallar</p>
                  <p id="adm-stat-refs" class="text-lg font-black text-purple-400">0</p>
              </div>
          </div>
      </div>

      <!-- Users Management -->
      <div id="admin-sec-users" class="admin-section hidden space-y-3">
          <input type="text" id="admin-user-search" onkeyup="filterAdminUsers()" placeholder="UID və ya İstifadəçi adı axtar..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white focus:border-blue-500 outline-none">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-user-list">
              <!-- JS Populated -->
          </div>
      </div>

      <!-- Withdrawals -->
      <div id="admin-sec-withdrawals" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-withdrawal-list">
              <!-- JS Populated -->
          </div>
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
              <input type="text" id="nt-infoLabel" placeholder="Məlumat başlığı (məs: Birja qeydiyyat UID-si:)" class="hidden w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-3 outline-none">
              
              <button onclick="adminSaveTask()" class="w-full py-2 bg-blue-600 text-white rounded text-xs font-black uppercase tracking-wider">Tapşırığı Yarat</button>
          </div>
          <div id="admin-task-list" class="space-y-2"></div>
      </div>

      <!-- Submissions -->
      <div id="admin-sec-submissions" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-submission-list"></div>
      </div>

      <!-- Error Logs (NEW) -->
      <div id="admin-sec-errors" class="admin-section hidden space-y-3">
          <div class="flex justify-between items-center bg-[#050511] p-3 rounded-xl border border-slate-700">
              <span class="text-[10px] text-slate-400 font-black uppercase">Sistem Xətaları Və Qeydlər</span>
              <button onclick="adminClearErrors()" class="text-[9px] bg-red-600/20 text-red-400 px-2 py-1 rounded border border-red-500/50 hover:bg-red-600/40 transition">Təmizlə</button>
          </div>
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-error-list">
              <!-- JS Populated -->
          </div>
      </div>

      <!-- Settings -->
      <div id="admin-sec-settings" class="admin-section hidden space-y-3">
          <div class="glass-card rounded-xl p-4 border border-slate-700 space-y-3">
              <div>
                  <div class="flex items-center justify-between mb-1.5">
                      <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Baxım Rejimi (Maintenance)</label>
                      <input type="checkbox" id="admin-maintenance" class="w-4 h-4">
                  </div>
                  <input type="text" id="admin-uid" placeholder="İcazəli Admin UID" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none">
                  <p class="text-[9px] text-slate-500 mt-1">Yalnız bu UID sistemə daxil ola bilər.</p>
              </div>
              
              <div>
                  <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Reklam Sıfırlanma Vaxtı (MSK/Baku vaxtına uyğunlaşdırıla bilər)</label>
                  <input type="time" id="admin-reset-time" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none">
              </div>

              <div>
                  <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">XP Vurucusu (2x, 3x)</label>
                  <input type="number" step="0.1" id="admin-xp-mult" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none">
              </div>

              <div>
                  <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Adsgram Block ID-lər (Vergüllə ayırın)</label>
                  <textarea id="admin-ad-sdk" rows="3" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none" placeholder="int-35545, int-35546"></textarea>
              </div>

              <button onclick="saveAdminSettings()" class="w-full py-2 bg-blue-600 hover:bg-blue-500 text-white rounded text-xs font-black uppercase tracking-wider transition">Yadda Saxla</button>
              
              <div class="border-t border-slate-700 pt-3 mt-3 flex gap-2">
                  <button onclick="adminResetAllAds()" class="flex-1 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded text-[9px] font-black uppercase transition">Bütün Reklam Limitlərini Sıfırla</button>
                  <button onclick="adminResetBot()" class="flex-1 py-2 bg-red-600 hover:bg-red-500 text-white rounded text-[9px] font-black uppercase transition">Botu Sıfırla (WIPE)</button>
              </div>
          </div>
      </div>
    </div>

  </main>

  <!-- BOTTOM NAVIGATION -->
  <nav id="bottom-nav" class="fixed bottom-3 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50 shadow-[0_15px_30px_rgba(0,0,0,0.8)] border border-slate-700/50 backdrop-blur-xl transition-transform duration-300">
    <div class="flex justify-between items-center px-1 py-2 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 relative group" data-target="home">
        <i class="fa-solid fa-house text-base transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Home</span>
      </button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 relative group" data-target="tasks">
        <i class="fa-solid fa-list-check text-base transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Tasks</span>
      </button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 relative group" data-target="referrals">
        <i class="fa-solid fa-users text-base transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Referans</span>
      </button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 relative group" data-target="boxes">
        <i class="fa-solid fa-box-open text-base transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Box</span>
      </button>
      <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 relative group" data-target="wallet">
        <i class="fa-solid fa-wallet text-base transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Wallet</span>
      </button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 transition-all duration-300 relative group" data-target="profile">
        <i class="fa-solid fa-user text-base transition-transform group-active:scale-90"></i>
        <span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Profile</span>
      </button>
    </div>
  </nav>

  <!-- Admin Auth Modal -->
  <div id="admin-auth-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in">
    <div class="glass-card w-full max-w-[280px] rounded-[1.5rem] p-5 relative border border-red-500/50 shadow-[0_0_40px_rgba(239,68,68,0.3)]">
      <button onclick="closeAdminAuth()" class="absolute top-3 right-3 text-slate-400 hover:text-white transition"><i class="fa-solid fa-xmark"></i></button>
      <h3 class="text-lg font-black text-white text-center mb-4"><i class="fa-solid fa-lock text-red-500 mr-1"></i> Admin Access</h3>
      <input type="password" id="admin-code-input" placeholder="Enter Access Code" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-sm text-center font-black text-white focus:border-red-500 outline-none mb-4 tracking-widest">
      <button onclick="submitAdminAuth()" class="w-full py-2.5 bg-red-600 text-white font-black rounded-lg text-xs uppercase tracking-wider">Login</button>
    </div>
  </div>

  <!-- Custom Task Info Modal -->
  <div id="custom-task-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in">
    <div class="glass-card w-full max-w-[300px] rounded-[1.5rem] p-5 relative border border-blue-500/50 shadow-[0_0_40px_rgba(59,130,246,0.3)]">
      <button onclick="closeCustomTaskModal()" class="absolute top-3 right-3 text-slate-400 hover:text-white transition"><i class="fa-solid fa-xmark"></i></button>
      <h3 class="text-sm font-black text-white text-center mb-1" id="ct-modal-title">Task Title</h3>
      <p class="text-[10px] text-slate-400 text-center mb-4" id="ct-modal-desc">Follow instructions and provide info.</p>
      
      <label class="block text-[10px] font-black text-crypto-glow uppercase tracking-widest mb-1.5" id="ct-modal-label">UID:</label>
      <input type="text" id="ct-modal-input" placeholder="Məlumatı bura yazın..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-sm text-white focus:border-blue-500 outline-none mb-4">
      <input type="hidden" id="ct-modal-id">
      <button onclick="submitCustomTaskInfo()" class="w-full py-2.5 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-lg text-xs uppercase tracking-wider">Göndər</button>
    </div>
  </div>

  <div id="ref-info-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in">
    <div class="glass-card w-full max-w-sm rounded-[1.5rem] p-5 relative border border-blue-500/30 shadow-[0_0_40px_rgba(0,0,0,0.9)]">
      <button onclick="toggleRefInfo()" class="absolute top-3 right-3 w-8 h-8 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center hover:text-white active:scale-90 transition-transform border border-slate-700 shadow-sm"><i class="fa-solid fa-xmark text-sm"></i></button>
      <div class="w-12 h-12 mx-auto bg-blue-500/10 rounded-full flex items-center justify-center mb-4 border border-blue-500/30 text-blue-400 text-xl shadow-[0_0_15px_rgba(59,130,246,0.2)]"><i class="fa-solid fa-users"></i></div>
      <h3 class="text-xl font-black text-white text-center mb-1.5 tracking-wide">Referral Rules</h3>
      <p class="text-[11px] text-slate-400 text-center mb-5 leading-relaxed px-2">Invite friends and earn rewards! A referral becomes <span class="text-emerald-400 font-bold">Approved</span> only when they complete these requirements.</p>
      <ul class="space-y-3 mb-5">
        <li class="flex items-start gap-2.5 bg-[#050511]/60 p-3 rounded-xl border border-slate-700/80 shadow-inner">
          <i class="fa-solid fa-play text-blue-400 mt-0.5 drop-shadow-[0_0_3px_#3b82f6] text-sm"></i>
          <div><p class="text-xs font-black text-white">Watch 25 Ads</p><p class="text-[10px] text-slate-500 mt-0.5">They must watch a total of 25 ads.</p></div>
        </li>
        <li class="flex items-start gap-2.5 bg-[#050511]/60 p-3 rounded-xl border border-slate-700/80 shadow-inner">
          <i class="fa-solid fa-list-check text-crypto-glow mt-0.5 drop-shadow-[0_0_3px_#00f0ff] text-sm"></i>
          <div><p class="text-xs font-black text-white">Complete 5 Tasks</p><p class="text-[10px] text-slate-500 mt-0.5">They must complete at least 5 daily missions.</p></div>
        </li>
      </ul>
      <div class="bg-gradient-to-r from-emerald-900/30 to-teal-900/30 border border-emerald-500/30 p-3 rounded-xl text-center shadow-[0_0_10px_rgba(16,185,129,0.1)]">
        <p class="text-[9px] text-emerald-400 font-bold uppercase tracking-widest mb-0.5">Approval Reward</p>
        <p class="text-lg font-black text-white">+250 XP & $0.025</p>
      </div>
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
      user: {}, referrals: [], rewards: [], withdrawals: [], tasks: [], custom_tasks: [], pending_submissions: [], system_errors: [], settings: { adBlockIds: ['int-35545'], xpMultiplier: 1 }
    };
    
    let adminToken = '';

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
        const data = await res.json();
        
        if(data.error) {
          if (data.error === 'BANNED' || data.error === 'MAINTENANCE') {
             document.body.innerHTML = `<div class="h-screen w-full flex flex-col items-center justify-center ${data.error==='BANNED'?'bg-red-900':'bg-blue-900'} text-white p-5 text-center"><i class="fa-solid ${data.error==='BANNED'?'fa-ban':'fa-screwdriver-wrench'} text-5xl mb-4"></i><h1 class="text-2xl font-black mb-2">${data.error}</h1><p class="text-xs opacity-80">${data.message || ''}</p></div>`;
             return false;
          }
          if(!isSilent) showToast("Məlumat", data.error, "error");
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
        if(data.system_errors) appState.system_errors = data.system_errors;

        updateUI();
        return data;
      } catch (err) {
        if(!isSilent) showToast("Connection Error", "Sistemə qoşulmaq mümkün olmadı.", "error");
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

      if (u.tgId === '5461064199' || u.tgId === appState.settings.adminUid) {
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
    
    // Tab functions
    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));
      const activeBtn = document.querySelector(`.nav-btn[data-target="${tabId}"]`);
      if (activeBtn) activeBtn.classList.add('nav-active');
      
      if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
    }

    function switchAdminTab(tab) {
        document.querySelectorAll('.admin-tab').forEach(b => {
            b.classList.remove('bg-red-600/20', 'text-red-400', 'border-red-500/50');
            b.classList.add('text-slate-400');
        });
        const activeTab = document.getElementById(`tab-${tab}`);
        if(activeTab) activeTab.classList.add('bg-red-600/20', 'text-red-400', 'border-red-500/50');

        document.querySelectorAll('.admin-section').forEach(s => s.classList.add('hidden'));
        document.getElementById(`admin-sec-${tab}`).classList.remove('hidden');
        
        if(tab === 'errors' && appState.system_errors) {
            renderAdminErrors(appState.system_errors);
        }
    }

    // Modal helpers
    function toggleRefInfo() { document.getElementById('ref-info-modal').classList.toggle('hidden'); document.getElementById('ref-info-modal').classList.toggle('flex'); }
    function openAdminAuth() { document.getElementById('admin-auth-modal').classList.remove('hidden'); document.getElementById('admin-auth-modal').classList.add('flex'); }
    function closeAdminAuth() { document.getElementById('admin-auth-modal').classList.add('hidden'); document.getElementById('admin-auth-modal').classList.remove('flex'); }

    // Dummy definitions for rendering tasks and admin panel methods
    function renderDailyLoginTask() { document.getElementById('streak-tracker-container').innerHTML = ''; }
    function renderTasks() { document.getElementById('missions-container').innerHTML = ''; }
    function renderCustomTasks() { document.getElementById('custom-tasks-container').innerHTML = ''; }
    function renderReferrals() {} function renderRewardHistory() {} function renderWithdrawHistory() {}

    async function submitAdminAuth() {
        adminToken = document.getElementById('admin-code-input').value;
        const res = await apiCall('admin_dashboard', { adminCode: adminToken });
        if (res && res.stats) {
            closeAdminAuth(); switchTab('admin'); showToast('Giriş Uğurlu', 'Admin panelə daxil oldunuz', 'success');
            // Basic mapping logic
            document.getElementById('adm-stat-users').innerText = res.stats.users;
            document.getElementById('adm-stat-usd').innerText = '$' + formatNum(res.stats.usd, true);
            document.getElementById('adm-stat-ads').innerText = res.stats.ads;
            document.getElementById('adm-stat-tasks').innerText = res.stats.tasks;
            document.getElementById('adm-stat-xp').innerText = formatNum(res.stats.xp);
            document.getElementById('adm-stat-refs').innerText = res.stats.refs;
        }
    }

    // Admin Error Log renderer
    function renderAdminErrors(errors) {
        const container = document.getElementById('admin-error-list');
        container.innerHTML = '';
        if(!errors || errors.length === 0) {
            container.innerHTML = '<p class="text-xs text-slate-500 text-center py-4">Hec bir xəta yoxdur.</p>';
            return;
        }
        
        errors.forEach(err => {
            container.innerHTML += `
            <div class="bg-[#0a0b1a] border border-red-500/30 p-3 rounded-xl mb-2 relative">
                <div class="flex justify-between items-center mb-1">
                    <span class="text-[10px] font-black text-red-400">UID: ${err.uid}</span>
                    <span class="text-[9px] text-slate-500">${err.date}</span>
                </div>
                <p class="text-[11px] text-white mb-2 pb-2 border-b border-slate-800"><span class="text-slate-400 font-bold">İstifadəçiyə gedən:</span> ${err.error}</p>
                <p class="text-[10px] text-red-300 bg-red-900/20 p-2 rounded border border-red-500/20"><span class="font-black text-red-400 block mb-1">ƏSL SƏBƏB (Sistem):</span> ${err.reason}</p>
            </div>`;
        });
    }

    async function adminClearErrors() {
        if(confirm('Bütün sistem xətalarını silmək istədiyinizə əminsiniz?')) {
            const res = await apiCall('admin_clear_errors', { adminCode: adminToken });
            if(res && res.success) {
                showToast('Uğurlu', res.message, 'success');
                appState.system_errors = [];
                renderAdminErrors([]);
            }
        }
    }

    // Start App Cycle
    window.onload = async () => {
        await apiCall('sync');
        document.getElementById('loading-overlay').classList.add('opacity-0');
        setTimeout(() => document.getElementById('loading-overlay').classList.add('hidden'), 500);
        setInterval(() => apiCall('sync', {}, true), 60000); // Silent sync every minute
    };
  </script>
</body>
</html>
