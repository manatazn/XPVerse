<?php
// XPVERSE - ADVANCED SINGLE FILE ARCHITECTURE
// Bütün funksiyalar, xəta idarəetməsi və idarə paneli əlavə edilmişdir.

error_reporting(0);
date_default_timezone_set('Europe/Moscow'); // Baza məntiqi üçün
$bakuTimeZone = new DateTimeZone('Asia/Baku'); // Admin paneldə göstərmək üçün
$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir)) { mkdir($dataDir, 0777, true); }

// Xüsusi xəta idarəetməsi (İstifadəçiyə heç vaxt PHP xətası getməsin, Adminə yazılsın)
function logError($errorMsg, $uid = 'unknown') {
    global $dataDir, $bakuTimeZone;
    $path = "$dataDir/errors.json";
    $errors = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    if (!is_array($errors)) $errors = [];
    $now = new DateTime('now', $bakuTimeZone);
    array_unshift($errors, [
        'time' => $now->format('Y-m-d H:i:s'),
        'uid' => $uid,
        'error' => $errorMsg
    ]);
    $errors = array_slice($errors, 0, 100); // Son 100 xətanı saxla
    file_put_contents($path, json_encode($errors, JSON_PRETTY_PRINT), LOCK_EX);
}

set_error_handler(function($severity, $message, $file, $line) {
    logError("PHP Error: $message in $file on line $line");
});
set_exception_handler(function($e) {
    logError("Exception: " . $e->getMessage());
    echo json_encode(['error' => 'Sistem xətası baş verdi.']); exit;
});

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

$settings = readDB('settings.json');
if (empty($settings)) {
    $settings = [
        'adBlockIds' => [ // 15 API ehtiyyatı
            'int-35545', 'int-35546', 'int-35547', 'int-35548', 'int-35549',
            'int-35550', 'int-35551', 'int-35552', 'int-35553', 'int-35554',
            'int-35555', 'int-35556', 'int-35557', 'int-35558', 'int-35559'
        ],
        'adLimit' => 30,
        'resetHour' => 3,
        'maintenance' => false,
        'xpMultiplier' => 1,
        'admins' => ['5461064199']
    ];
    writeDB('settings.json', $settings);
}

$now = new DateTime('now');
$hour = (int)$now->format('H');
$logicalDate = clone $now;
if ($hour < (int)$settings['resetHour']) {
    $logicalDate->modify('-1 day');
}
$today = $logicalDate->format('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action']) || !isset($input['tgId'])) {
        echo json_encode(['error' => 'Bilinməyən xəta']); exit;
    }

    $action = $input['action'];
    $uid = (string)$input['tgId'];
    $isAdmin = in_array($uid, $settings['admins']);
    
    $users = readDB('users.json');
    $referrals = readDB('referrals.json');
    $rewards = readDB('rewards.json');
    $withdrawals = readDB('withdrawals.json');
    $tasks = readDB('tasks.json');
    $customTasks = readDB('custom_tasks.json');
    $bannedUids = readDB('bans.json');

    // Önəmli: Bloklanmış UID yoxlaması
    if (in_array($uid, $bannedUids) || (isset($users[$uid]['banned']) && $users[$uid]['banned'] === true)) {
        if (!$isAdmin) {
            echo json_encode(['error' => 'BANNED', 'message' => 'Your account has been restricted.']); exit;
        }
    }

    // Baxım Modu (Maintenance)
    if ($settings['maintenance'] == true && !$isAdmin) {
        echo json_encode(['error' => 'MAINTENANCE', 'message' => 'System is under maintenance. Please try again later.']); exit;
    }

    // İstifadəçi Yaradılması
    if (!isset($users[$uid])) {
        $users[$uid] = [
            'tgId' => $uid,
            'firstName' => $input['firstName'] ?? 'User',
            'lastName' => $input['lastName'] ?? '',
            'username' => $input['username'] ?? '',
            'photoUrl' => $input['photoUrl'] ?? '',
            'xp' => 0,
            'totalXp' => 0,
            'usd' => 0.00,
            'totalUsdEarned' => 0.00,
            'level' => 1,
            'adsWatchedToday' => 0,
            'totalAdsWatched' => 0,
            'tasksCompleted' => 0,
            'boxesOpened' => 0,
            'streak' => 1,
            'lastResetDay' => $today,
            'referrer' => null,
            'sponsorAzx' => false,
            'lastActive' => $now->setTimezone($bakuTimeZone)->format('Y-m-d H:i:s'),
            'banned' => false,
            'completedCustomTasks' => []
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
        $users[$uid]['lastActive'] = clone $now->setTimezone($bakuTimeZone)->format('Y-m-d H:i:s');
    }

    // Günlük Sıfırlama
    if ($users[$uid]['lastResetDay'] !== $today) {
        $lastDay = strtotime($users[$uid]['lastResetDay']);
        $currDay = strtotime($today);
        $diff = round(($currDay - $lastDay) / 86400);
        $users[$uid]['streak'] = ($diff === 1) ? min($users[$uid]['streak'] + 1, 7) : 1;
        $users[$uid]['adsWatchedToday'] = 0;
        $users[$uid]['lastResetDay'] = $today;
        if(isset($tasks[$uid])) $tasks[$uid] = [];
    }

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
        
        $referralChanged = false; $rewardAdded = false;
        
        foreach ($referrals[$referrerId] as &$r) {
            if ($r['uid'] === $refUid && $r['status'] === 'Pending') {
                if ($r['ads'] !== $refUser['totalAdsWatched'] || $r['tasks'] !== $refUser['tasksCompleted']) {
                    $r['ads'] = $refUser['totalAdsWatched']; $r['tasks'] = $refUser['tasksCompleted'];
                    $referralChanged = true;
                }
                if ($r['ads'] >= 25 && $r['tasks'] >= 5) {
                    $r['status'] = 'Approved'; $r['approvedAt'] = $now->format('M j, Y');
                    $referralChanged = true; $rewardAdded = true;
                    
                    $users[$referrerId]['xp'] += 250;
                    $users[$referrerId]['totalXp'] += 250;
                    $users[$referrerId]['usd'] += 0.025;
                    $users[$referrerId]['totalUsdEarned'] = ($users[$referrerId]['totalUsdEarned'] ?? 0) + 0.025;
                    $users[$referrerId]['level'] = calcLevel($users[$referrerId]['totalXp']);
                    
                    if (!isset($rewards[$referrerId])) $rewards[$referrerId] = [];
                    $refName = trim(($refUser['firstName'] ?? '') . ' ' . ($refUser['lastName'] ?? ''));
                    array_unshift($rewards[$referrerId], [
                        'title' => 'Referral Bonus', 'desc' => "Referral: " . $refName,
                        'xp' => 250, 'usd' => 0.025, 'date' => $now->format('M j, Y')
                    ]);
                }
                break;
            }
        }
        if ($referralChanged) writeDB('referrals.json', $referrals);
        if ($rewardAdded) writeDB('rewards.json', $rewards);
    }

    $response = ['success' => true];
    
    // ADMIN PANEL İŞLƏMLƏRİ
    if (strpos($action, 'admin_') === 0) {
        if (!$isAdmin) { echo json_encode(['error' => 'Səlahiyyət yoxdur']); exit; }

        if ($action === 'admin_dashboard') {
            $totalUsd = 0; $totalAds = 0; $totalTasks = 0; $totalXp = 0; $totalUsers = count($users);
            $totalRefs = 0;
            foreach($users as $u) {
                $totalUsd += ($u['totalUsdEarned'] ?? $u['usd']);
                $totalAds += $u['totalAdsWatched'];
                $totalTasks += $u['tasksCompleted'];
                $totalXp += $u['totalXp'];
            }
            foreach($referrals as $rList) { $totalRefs += count($rList); }

            $allWithdrawals = [];
            foreach($withdrawals as $uId => $uWithdrawals) {
                foreach($uWithdrawals as $idx => $w) {
                    $w['user_id'] = $uId; $w['idx'] = $idx;
                    $allWithdrawals[] = $w;
                }
            }
            
            $response['stats'] = ['users' => $totalUsers, 'usd' => $totalUsd, 'ads' => $totalAds, 'tasks' => $totalTasks, 'xp' => $totalXp, 'refs' => $totalRefs];
            $response['all_users'] = array_values($users);
            $response['all_withdrawals'] = $allWithdrawals;
            $response['custom_tasks'] = $customTasks;
            $response['errors_log'] = readDB('errors.json');
            $response['banned_uids'] = $bannedUids;
            echo json_encode($response); exit;
        }

        if ($action === 'admin_update_settings') {
            $settings['adBlockIds'] = $input['blockIds'];
            $settings['adLimit'] = (int)$input['adLimit'];
            $settings['resetHour'] = (int)$input['resetHour'];
            $settings['maintenance'] = (bool)$input['maintenance'];
            $settings['xpMultiplier'] = (int)$input['xpMultiplier'];
            writeDB('settings.json', $settings);
            $response['message'] = 'Tənzimləmələr yeniləndi.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_add_admin') {
            $newAdmin = $input['newAdminUid'];
            if (!in_array($newAdmin, $settings['admins'])) {
                $settings['admins'][] = $newAdmin;
                writeDB('settings.json', $settings);
            }
            $response['message'] = 'Admin əlavə edildi.'; echo json_encode($response); exit;
        }

        if ($action === 'admin_action_user') {
            $targetUid = $input['targetUid'];
            $act = $input['userAction'];
            
            if ($act === 'force_ban_uid') {
                if (!in_array($targetUid, $bannedUids)) {
                    $bannedUids[] = $targetUid;
                    writeDB('bans.json', $bannedUids);
                }
                if (isset($users[$targetUid])) $users[$targetUid]['banned'] = true;
            } elseif ($act === 'unban_uid') {
                $bannedUids = array_values(array_filter($bannedUids, fn($id) => $id !== $targetUid));
                writeDB('bans.json', $bannedUids);
                if (isset($users[$targetUid])) $users[$targetUid]['banned'] = false;
            } elseif (isset($users[$targetUid])) {
                if ($act === 'reset_ads') {
                    $users[$targetUid]['adsWatchedToday'] = 0;
                } elseif ($act === 'update_balance') {
                    $diffUsd = (float)$input['newUsd'] - $users[$targetUid]['usd'];
                    $users[$targetUid]['usd'] = max(0, (float)$input['newUsd']);
                    if($diffUsd > 0) $users[$targetUid]['totalUsdEarned'] += $diffUsd;
                    $users[$targetUid]['xp'] = max(0, (int)$input['newXp']);
                    $users[$targetUid]['totalXp'] = max($users[$targetUid]['totalXp'], $users[$targetUid]['xp']);
                }
            }
            writeDB('users.json', $users);
            $response['message'] = 'İstifadəçi məlumatları yeniləndi.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_mass_action') {
            $act = $input['massAction'];
            if ($act === 'reset_all_ads') {
                foreach($users as $k => $v) $users[$k]['adsWatchedToday'] = 0;
            } elseif ($act === 'clear_all_balances') {
                foreach($users as $k => $v) { $users[$k]['usd'] = 0; $users[$k]['xp'] = 0; }
            }
            writeDB('users.json', $users);
            $response['message'] = 'Kütləvi işləm tamamlandı.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_action_withdraw') {
            $targetUid = $input['targetUid']; $idx = $input['idx']; $wAct = $input['withdrawAction'];
            if (isset($withdrawals[$targetUid][$idx]) && $withdrawals[$targetUid][$idx]['status'] === 'Pending') {
                if ($wAct === 'approve') $withdrawals[$targetUid][$idx]['status'] = 'Approved';
                else if ($wAct === 'reject') {
                    $withdrawals[$targetUid][$idx]['status'] = 'Rejected';
                    $users[$targetUid]['usd'] += $withdrawals[$targetUid][$idx]['amount'];
                    writeDB('users.json', $users);
                }
                writeDB('withdrawals.json', $withdrawals);
                $response['message'] = 'Çıxarış yeniləndi.';
            }
            echo json_encode($response); exit;
        }

        if ($action === 'admin_task_add') {
            $customTasks[] = [
                'id' => 'ct_'.time(),
                'desc' => $input['desc'],
                'type' => $input['type'], // Sponsor, Normal
                'rewardType' => $input['rewardType'], // USDT, XP
                'rewardAmount' => (float)$input['rewardAmount'],
                'link' => $input['link'],
                'requireUid' => (bool)$input['requireUid'],
                'uidInstructions' => $input['uidInstructions'] ?? '',
                'limit' => (int)$input['limit'],
                'completions' => 0
            ];
            writeDB('custom_tasks.json', $customTasks);
            $response['message'] = 'Görev əlavə edildi.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_task_delete') {
            $customTasks = array_values(array_filter($customTasks, fn($t) => $t['id'] !== $input['taskId']));
            writeDB('custom_tasks.json', $customTasks);
            $response['message'] = 'Görev silindi.'; echo json_encode($response); exit;
        }
    }

    // NORMAL İSTİFADƏÇİ İŞLƏMLƏRİ
    $xpMultiplier = (int)($settings['xpMultiplier'] ?? 1);
    $adLimit = (int)($settings['adLimit'] ?? 30);

    switch ($action) {
        case 'ping':
            // Sadece datanı geri qaytarır (real-time yenilənmə üçün)
            break;

        case 'watch_ad':
            if ($users[$uid]['adsWatchedToday'] < $adLimit) {
                $users[$uid]['adsWatchedToday'] += 1;
                $users[$uid]['totalAdsWatched'] += 1;
                $gainedXp = 20 * $xpMultiplier;
                $users[$uid]['xp'] += $gainedXp;
                $users[$uid]['totalXp'] += $gainedXp;
                $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
            } else {
                $response['error'] = 'Gündəlik reklam limitiniz dolub.';
            }
            break;

        case 'claim_custom_task':
            $taskId = $input['taskId'];
            $providedUid = $input['providedUid'] ?? '';
            
            $taskIndex = -1;
            foreach ($customTasks as $idx => $t) { if ($t['id'] === $taskId) $taskIndex = $idx; }
            
            if ($taskIndex !== -1) {
                $t = $customTasks[$taskIndex];
                if (!isset($users[$uid]['completedCustomTasks'])) $users[$uid]['completedCustomTasks'] = [];
                
                if (in_array($taskId, $users[$uid]['completedCustomTasks'])) {
                    $response['error'] = 'Bu görəvi artıq etmisiniz.';
                } else if ($t['limit'] > 0 && $t['completions'] >= $t['limit']) {
                    $response['error'] = 'Bu görəvin limiti dolub.';
                } else {
                    $users[$uid]['completedCustomTasks'][] = $taskId;
                    $customTasks[$taskIndex]['completions'] += 1;
                    
                    if ($t['rewardType'] === 'USDT') {
                        $users[$uid]['usd'] += $t['rewardAmount'];
                        $users[$uid]['totalUsdEarned'] = ($users[$uid]['totalUsdEarned'] ?? 0) + $t['rewardAmount'];
                    } else {
                        $users[$uid]['xp'] += ($t['rewardAmount'] * $xpMultiplier);
                        $users[$uid]['totalXp'] += ($t['rewardAmount'] * $xpMultiplier);
                    }
                    writeDB('custom_tasks.json', $customTasks);
                }
            } else {
                $response['error'] = 'Görev tapılmadı.';
            }
            break;

        case 'claim_task':
            $taskId = $input['taskId'] ?? '';
            if ($taskId === 'sponsor_azx') {
                if (empty($users[$uid]['sponsorAzx'])) {
                    $users[$uid]['sponsorAzx'] = true;
                    $users[$uid]['xp'] += (200 * $xpMultiplier);
                    $users[$uid]['totalXp'] += (200 * $xpMultiplier);
                    $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                    evaluateReferralProgress($uid, $users, $referrals, $rewards);
                } else { $response['error'] = 'Artıq edilb.'; }
            } else {
                if (!isset($tasks[$uid])) $tasks[$uid] = [];
                if (!in_array($taskId, $tasks[$uid])) {
                    if ($taskId === 'complete_all' && $users[$uid]['adsWatchedToday'] < $adLimit) {
                        $response['error'] = 'Bütün reklamları izləməlisiniz.'; break;
                    }
                    $rewardXp = (int)($input['reward'] ?? 0);
                    if ($rewardXp > 0) { 
                        $tasks[$uid][] = $taskId;
                        $users[$uid]['tasksCompleted'] += 1;
                        $users[$uid]['xp'] += ($rewardXp * $xpMultiplier);
                        $users[$uid]['totalXp'] += ($rewardXp * $xpMultiplier);
                        $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                        evaluateReferralProgress($uid, $users, $referrals, $rewards);
                        writeDB('tasks.json', $tasks);
                    }
                } else { $response['error'] = 'Bugün üçün edilib.'; }
            }
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
                $users[$uid]['totalUsdEarned'] = ($users[$uid]['totalUsdEarned'] ?? 0) + $rewardUsd;
                $response['reward'] = $rewardUsd; $response['jackpot'] = $isJackpot;
            } else { $response['error'] = 'Yetərli XP yoxdur.'; }
            break;

        case 'withdraw':
            $amount = (float)($input['amount'] ?? 0);
            $address = $input['address'] ?? '';
            if ($amount >= 10 && $amount <= $users[$uid]['usd'] && strlen($address) > 5) {
                $users[$uid]['usd'] -= $amount;
                if (!isset($withdrawals[$uid])) $withdrawals[$uid] = [];
                array_unshift($withdrawals[$uid], [
                    'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                    'amount' => $amount, 'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                    'date' => $now->format('M j, Y H:i:s'), 'status' => 'Pending'
                ]);
                writeDB('withdrawals.json', $withdrawals);
            } else { $response['error'] = 'Xətalı çıxarış tələbi.'; }
            break;
    }

    writeDB('users.json', $users);
    $response['user'] = $users[$uid];
    $response['referrals'] = $referrals[$uid] ?? [];
    $response['rewards'] = $rewards[$uid] ?? [];
    $response['withdrawals'] = $withdrawals[$uid] ?? [];
    $response['tasks'] = $tasks[$uid] ?? [];
    $response['custom_tasks'] = $customTasks;
    $response['settings'] = $settings;
    
    // Server Timer Logic
    $resetTarget = clone $now;
    $resetTarget->setTime((int)$settings['resetHour'], 0, 0);
    if ($hour >= (int)$settings['resetHour']) { $resetTarget->modify('+1 day'); }
    $response['serverTime'] = $now->getTimestamp();
    $response['serverResetTime'] = $resetTarget->getTimestamp(); 
    
    echo json_encode($response); exit;
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
      theme: { extend: { fontFamily: { sans: ['Outfit', 'sans-serif'] }, colors: { crypto: { dark: '#050511', glow: '#00f0ff', gold: '#ffb800', silver: '#e2e8f0', bronze: '#cd7f32' } } } }
    }
  </script>
  <style>
    body { background-color: #050511; color: #f8fafc; overflow-x: hidden; user-select: none; -webkit-user-select: none; }
    input, textarea { user-select: auto !important; }
    ::-webkit-scrollbar { width: 0px; background: transparent; }
    .glass-card { background: linear-gradient(145deg, rgba(20, 22, 45, 0.7) 0%, rgba(10, 11, 26, 0.85) 100%); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.05); }
    .fade-in { animation: fadeIn 0.3s forwards; } @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .nav-active { color: #00f0ff !important; transform: translateY(-3px); }
    #toast-container { position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9); width: 90%; max-width: 380px; z-index: 999999; transition: all 0.4s; opacity: 0; pointer-events: none; }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }
    .modal-overlay { background: rgba(5, 5, 17, 0.9); backdrop-filter: blur(10px); z-index: 10000; }
    .admin-scroll::-webkit-scrollbar { width: 4px; } .admin-scroll::-webkit-scrollbar-thumb { background: #3b82f6; border-radius: 4px; }
  </style>
</head>
<body class="flex flex-col min-h-screen">
  
  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0">
    <i class="fa-solid fa-spinner fa-spin text-crypto-glow text-4xl mb-4"></i>
    <h2 class="text-white font-black tracking-widest text-xl">XPVERSE</h2>
  </div>

  <div id="maintenance-overlay" class="hidden bg-[#050511] flex-col items-center justify-center z-[99999] fixed inset-0 p-5 text-center">
    <i class="fa-solid fa-person-digging text-amber-500 text-6xl mb-4"></i>
    <h1 class="text-2xl font-black text-white mb-2">UNDER MAINTENANCE</h1>
    <p class="text-sm text-slate-400">We are currently upgrading our servers. Please check back later.</p>
  </div>

  <div id="ban-overlay" class="hidden bg-red-900 flex-col items-center justify-center z-[99999] fixed inset-0 p-5 text-center">
    <i class="fa-solid fa-ban text-white text-6xl mb-4"></i>
    <h1 class="text-2xl font-black text-white mb-2">ACCOUNT BANNED</h1>
    <p class="text-sm text-red-200">Your access to this bot has been restricted by the administration.</p>
  </div>

  <div id="toast-container" class="glass-card rounded-2xl p-3 flex items-center gap-3"><div id="toast-icon"></div><div><h4 id="toast-title" class="text-xs font-black text-white"></h4><p id="toast-message" class="text-[11px] text-slate-300"></p></div></div>

  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full py-3 px-4 glass-card rounded-b-[1.5rem] border-b-0 shadow-lg">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-2">
        <img id="user-photo" src="" alt="Profile" class="w-10 h-10 rounded-full border-2 border-crypto-glow">
        <div class="flex flex-col"><span id="user-name" class="font-bold text-white text-sm">Loading...</span></div>
      </div>
      <div class="flex flex-col items-end gap-1">
        <div class="bg-[#050511] border border-blue-500/30 px-2 py-1 rounded-lg flex gap-1"><i class="fa-solid fa-bolt text-crypto-glow text-[10px] mt-0.5"></i><span id="user-xp" class="text-white font-black text-xs">0 XP</span></div>
        <div class="bg-[#050511] border border-emerald-500/40 px-2 py-1 rounded-lg flex gap-1"><i class="fa-solid fa-dollar-sign text-emerald-400 text-[10px] mt-0.5"></i><span id="user-usd" class="text-emerald-400 font-black text-[11px]">0</span></div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-20 pb-24" id="app-content">
    <!-- HOME -->
    <div id="view-home" class="view-section fade-in space-y-5">
      <div class="glass-card rounded-[1.5rem] p-5 text-center flex flex-col items-center">
        <h1 class="text-4xl font-black text-white drop-shadow-lg mt-4" id="main-xp-display">0 XP</h1>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/60 p-3 rounded-xl border border-slate-700/60 text-left"><p class="text-[9px] text-slate-500 uppercase font-black">Ads Limit</p><p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / <span id="ads-total-limit">30</span></p></div>
          <div class="bg-[#050511]/60 p-3 rounded-xl border border-slate-700/60 text-left"><p class="text-[9px] text-slate-500 uppercase font-black">Streak</p><p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> Days</p></div>
        </div>
      </div>
      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-3.5 bg-blue-600 rounded-[1.25rem] text-white font-black text-sm uppercase flex items-center justify-center gap-2 active:scale-95 transition-all"><i class="fa-solid fa-play"></i> Watch Ad <span class="text-cyan-200" id="ad-reward-txt">+20 XP</span></button>
      <div class="glass-card rounded-xl p-3 flex justify-between items-center"><span class="text-slate-400 text-xs font-bold"><i class="fa-solid fa-clock text-blue-400"></i> Resets in:</span><span class="text-white font-mono text-xs" id="reset-timer">--:--:--</span></div>
    </div>

    <!-- TASKS -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-5">
      <div class="text-center"><h2 class="text-2xl font-black text-white">Tasks</h2></div>
      <div id="custom-tasks-container" class="space-y-3 mb-4"></div>
      <div id="sponsor-container" class="space-y-3"></div>
      <div id="missions-container" class="space-y-2.5"></div>
    </div>

    <!-- REFERRALS -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-4">
      <div class="text-center"><h2 class="text-2xl font-black text-white">Referrals</h2></div>
      <div class="grid grid-cols-3 gap-2"><div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-blue-500"><p class="text-[9px] text-slate-400 uppercase">Total</p><p id="ref-total" class="text-xl font-black text-white">0</p></div><div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-amber-500"><p class="text-[9px] text-slate-400 uppercase">Pending</p><p id="ref-pending" class="text-xl font-black text-amber-400">0</p></div><div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-emerald-500"><p class="text-[9px] text-slate-400 uppercase">Approved</p><p id="ref-approved" class="text-xl font-black text-emerald-400">0</p></div></div>
      <input type="text" id="ref-link-input" readonly class="w-full bg-[#050511] border border-slate-700 rounded-lg p-2.5 text-xs text-white" onclick="this.select(); document.execCommand('copy'); showToast('Success','Copied!','success')">
      <div id="referral-list-container" class="space-y-2"></div>
    </div>

    <!-- BOXES -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-4">
      <div class="text-center"><h2 class="text-2xl font-black text-white">Box</h2></div>
      <div class="glass-card p-4 rounded-xl flex justify-between items-center border border-orange-400/30"><div><h3 class="text-lg font-black text-white">Bronze Box</h3><p class="text-[10px] text-slate-400">10,000 XP | Max $1.00</p></div><button onclick="openBox('bronze')" class="bg-orange-600 px-4 py-2 rounded text-white font-black text-xs">OPEN</button></div>
      <div class="glass-card p-4 rounded-xl flex justify-between items-center border border-slate-400/30"><div><h3 class="text-lg font-black text-white">Silver Box</h3><p class="text-[10px] text-slate-400">50,000 XP | Max $7.00</p></div><button onclick="openBox('silver')" class="bg-slate-500 px-4 py-2 rounded text-white font-black text-xs">OPEN</button></div>
      <div class="glass-card p-4 rounded-xl flex justify-between items-center border border-amber-400/50"><div><h3 class="text-lg font-black text-amber-400">Gold Box</h3><p class="text-[10px] text-slate-400">100,000 XP | Max $15.00</p></div><button onclick="openBox('gold')" class="bg-amber-500 px-4 py-2 rounded text-white font-black text-xs">OPEN</button></div>
    </div>

    <!-- WALLET -->
    <div id="view-wallet" class="view-section hidden fade-in space-y-4">
      <div class="text-center"><h2 class="text-2xl font-black text-white">Wallet</h2></div>
      <div class="glass-card rounded-xl p-5 text-center"><p class="text-[10px] text-emerald-400">Available</p><h1 class="text-4xl font-black text-white">$<span id="withdraw-balance-display">0</span></h1></div>
      <input type="text" id="wallet-address" placeholder="TON Wallet Address" class="w-full bg-[#050511] border border-slate-700 rounded-lg p-2.5 text-xs text-white">
      <input type="number" id="withdraw-amount" placeholder="Amount (Min 10)" class="w-full bg-[#050511] border border-slate-700 rounded-lg p-2.5 text-xs text-white">
      <button onclick="requestWithdrawal()" class="w-full py-3 bg-emerald-600 text-white font-black rounded-lg text-xs">WITHDRAW</button>
      <div id="withdraw-history-container" class="space-y-2"></div>
    </div>

    <!-- PROFILE -->
    <div id="view-profile" class="view-section hidden fade-in space-y-4">
      <div class="glass-card rounded-xl p-5 text-center relative">
        <button id="admin-secret-btn" onclick="switchTab('admin')" class="hidden absolute top-4 right-4 bg-red-600 text-white w-8 h-8 rounded-full shadow-lg flex items-center justify-center"><i class="fa-solid fa-shield"></i></button>
        <img id="profile-page-avatar" src="" class="w-20 h-20 rounded-full mx-auto mb-2 border-2 border-crypto-glow">
        <h2 id="profile-page-name" class="text-xl font-black text-white">Name</h2>
        <p class="text-xs text-slate-400">Total Earned: $<span id="profile-total-earned">0</span></p>
      </div>
    </div>

    <!-- ADMIN PANEL (AZƏRBAYCAN DİLİNDƏ) -->
    <div id="view-admin" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-2"><h2 class="text-xl font-black text-red-500">İDARƏ PANELİ</h2></div>
      <div class="flex flex-wrap gap-1 bg-[#050511] p-1 rounded-lg">
          <button onclick="switchAdminTab('dashboard')" class="admin-tab flex-1 py-1 text-[9px] font-black uppercase rounded bg-red-600/20 text-red-400 border border-red-500/50" id="tab-dashboard">Panel</button>
          <button onclick="switchAdminTab('users')" class="admin-tab flex-1 py-1 text-[9px] font-black uppercase rounded text-slate-400" id="tab-users">İstif.</button>
          <button onclick="switchAdminTab('tasks')" class="admin-tab flex-1 py-1 text-[9px] font-black uppercase rounded text-slate-400" id="tab-tasks">Görevlər</button>
          <button onclick="switchAdminTab('withdrawals')" class="admin-tab flex-1 py-1 text-[9px] font-black uppercase rounded text-slate-400" id="tab-withdrawals">Çıxarış</button>
          <button onclick="switchAdminTab('settings')" class="admin-tab flex-1 py-1 text-[9px] font-black uppercase rounded text-slate-400" id="tab-settings">Tənzim.</button>
          <button onclick="switchAdminTab('errors')" class="admin-tab flex-1 py-1 text-[9px] font-black uppercase rounded text-slate-400" id="tab-errors">Xətalar</button>
      </div>

      <!-- Dashboard -->
      <div id="admin-sec-dashboard" class="admin-section grid grid-cols-2 gap-2">
          <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">İstifadəçilər</p><p id="adm-stat-users" class="text-lg font-black text-blue-400">0</p></div>
          <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Cəmi USD</p><p id="adm-stat-usd" class="text-lg font-black text-emerald-400">$0</p></div>
          <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Reklam Baxışı</p><p id="adm-stat-ads" class="text-lg font-black text-white">0</p></div>
          <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Görevlər</p><p id="adm-stat-tasks" class="text-lg font-black text-white">0</p></div>
          <div class="col-span-2 mt-2 flex gap-2">
            <button onclick="adminMassAction('reset_all_ads')" class="flex-1 bg-blue-600 text-white text-[10px] py-2 rounded">Reklam Limitlərini Sıfırla</button>
            <button onclick="adminMassAction('clear_all_balances')" class="flex-1 bg-red-600 text-white text-[10px] py-2 rounded">Bütün Balansları Sil</button>
          </div>
      </div>

      <!-- Users -->
      <div id="admin-sec-users" class="admin-section hidden space-y-3">
          <div class="flex gap-2">
              <input type="text" id="admin-user-search" onkeyup="filterAdminUsers()" placeholder="UID və ya Ad axtar..." class="flex-1 bg-[#050511] border border-slate-700 rounded py-2 px-3 text-xs text-white">
              <input type="text" id="admin-pre-ban-uid" placeholder="UID Banla" class="w-24 bg-[#050511] border border-red-700 rounded py-2 px-1 text-[10px] text-white">
              <button onclick="adminForceBan()" class="bg-red-600 text-white px-2 rounded text-[10px]"><i class="fa-solid fa-gavel"></i></button>
          </div>
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-user-list"></div>
      </div>

      <!-- Tasks -->
      <div id="admin-sec-tasks" class="admin-section hidden space-y-3">
          <div class="glass-card p-3 rounded border border-slate-700 space-y-2">
              <h3 class="text-xs font-black text-white">Yeni Görev Əlavə Et</h3>
              <input type="text" id="task-desc" placeholder="Açıqlama (Məs: AZX Crypto)" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              <div class="flex gap-2">
                  <select id="task-type" class="flex-1 bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white"><option value="Normal">Normal Task</option><option value="Sponsor">Sponsor</option></select>
                  <select id="task-reward-type" class="flex-1 bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white"><option value="XP">XP</option><option value="USDT">USDT</option></select>
                  <input type="number" id="task-reward-amt" placeholder="Miqdar" class="w-20 bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              </div>
              <input type="text" id="task-link" placeholder="Link (Məs: t.me/qrup)" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              <div class="flex gap-2 items-center bg-[#050511] p-2 border border-slate-700 rounded">
                  <label class="text-xs text-slate-300 flex items-center gap-2"><input type="checkbox" id="task-req-uid" onchange="document.getElementById('task-uid-inst').classList.toggle('hidden')"> UID Tələb Et</label>
                  <input type="text" id="task-uid-inst" placeholder="Açıqlama (Məs: UID daxil et)" class="hidden flex-1 bg-transparent border-b border-slate-700 text-[10px] text-white p-1">
              </div>
              <input type="number" id="task-limit" placeholder="İstifadəçi Limiti (0 = Limitsiz)" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              <button onclick="adminAddTask()" class="w-full bg-emerald-600 text-white font-black py-2 rounded text-xs">Əlavə Et</button>
          </div>
          <div id="admin-task-list" class="space-y-2"></div>
      </div>

      <!-- Withdrawals -->
      <div id="admin-sec-withdrawals" class="admin-section hidden space-y-2" id="admin-withdrawal-list"></div>

      <!-- Settings -->
      <div id="admin-sec-settings" class="admin-section hidden space-y-3">
          <div class="glass-card p-3 rounded border border-slate-700 space-y-2">
              <label class="block text-[10px] text-slate-400">Ads API IDs (Vergüllə ayır - Çoxlu API qoy ki, error verməsin)</label>
              <textarea id="adm-set-apis" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white h-16"></textarea>
              <div class="flex gap-2">
                  <div class="flex-1"><label class="block text-[10px] text-slate-400">Günlük Reklam Limiti</label><input type="number" id="adm-set-adlimit" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white"></div>
                  <div class="flex-1"><label class="block text-[10px] text-slate-400">Sıfırlanma Saatı (Rusiya)</label><input type="number" id="adm-set-reset" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white"></div>
              </div>
              <div class="flex gap-2 items-center justify-between bg-[#050511] p-2 border border-slate-700 rounded">
                  <label class="text-xs text-slate-300">2x XP Etkinliyi</label><input type="checkbox" id="adm-set-2x">
              </div>
              <div class="flex gap-2 items-center justify-between bg-red-900/30 p-2 border border-red-700 rounded">
                  <label class="text-xs text-red-400">Bakım Modu (İstifadəçilər girə bilməz)</label><input type="checkbox" id="adm-set-maint">
              </div>
              <button onclick="adminSaveSettings()" class="w-full bg-blue-600 text-white py-2 rounded text-xs font-black">Tənzimləmələri Yadda Saxla</button>
          </div>
          <div class="glass-card p-3 rounded border border-slate-700 flex gap-2">
              <input type="text" id="adm-new-admin" placeholder="Admin UID əlavə et" class="flex-1 bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              <button onclick="adminAddAdmin()" class="bg-purple-600 text-white px-3 rounded text-xs">Əlavə et</button>
          </div>
      </div>

      <!-- Errors -->
      <div id="admin-sec-errors" class="admin-section hidden space-y-2 max-h-[60vh] overflow-y-auto admin-scroll" id="admin-error-list"></div>
    </div>
  </main>

  <!-- MODALS -->
  <div id="custom-task-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4">
      <div class="glass-card w-full max-w-sm rounded-[1.5rem] p-5 relative border border-blue-500/50">
          <button onclick="document.getElementById('custom-task-modal').classList.add('hidden')" class="absolute top-3 right-3 text-slate-400"><i class="fa-solid fa-xmark"></i></button>
          <h3 id="ctm-title" class="text-lg font-black text-white mb-2">Görev</h3>
          <p id="ctm-inst" class="text-xs text-slate-400 mb-3"></p>
          <input type="hidden" id="ctm-id">
          <input type="text" id="ctm-uid-input" placeholder="Tələb olunan UID-ni bura yazın" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-3 text-xs text-white mb-3 hidden">
          <button onclick="submitCustomTask()" class="w-full bg-blue-600 text-white py-2 rounded font-black text-xs">Təsdiqlə</button>
      </div>
  </div>

  <nav id="bottom-nav" class="fixed bottom-0 left-0 w-full glass-card border-t border-slate-700/50 z-50">
    <div class="flex justify-between items-center p-2 max-w-md mx-auto">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center flex-1" data-target="home"><i class="fa-solid fa-house"></i><span class="text-[9px] mt-1">Home</span></button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="tasks"><i class="fa-solid fa-list-check"></i><span class="text-[9px] mt-1">Tasks</span></button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="referrals"><i class="fa-solid fa-users"></i><span class="text-[9px] mt-1">Referans</span></button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="boxes"><i class="fa-solid fa-box"></i><span class="text-[9px] mt-1">Box</span></button>
      <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="wallet"><i class="fa-solid fa-wallet"></i><span class="text-[9px] mt-1">Wallet</span></button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="profile"><i class="fa-solid fa-user"></i><span class="text-[9px] mt-1">Profile</span></button>
    </div>
  </nav>

  <script>
    const tg = window.Telegram.WebApp; tg.expand(); tg.ready();
    const tgUser = tg.initDataUnsafe?.user || { id: "123456789", first_name: "Demo", last_name: "", username: "demo", photo_url: "" };
    const startParam = tg.initDataUnsafe?.start_param || null;
    let appState = { user: {}, settings: {}, custom_tasks: [] }; let currentTab = 'home';
    let isAdmin = false;

    function formatNum(num, isMoney = false) { let val = Number(num||0); return isMoney ? val.toFixed(2) : val.toLocaleString(); }
    
    function showToast(title, message, type = 'info') {
      const t = document.getElementById('toast-container'); const i = document.getElementById('toast-icon');
      document.getElementById('toast-title').innerText = title; document.getElementById('toast-message').innerText = message;
      i.innerHTML = type === 'success' ? '<i class="fa-solid fa-check text-emerald-400"></i>' : (type==='error'?'<i class="fa-solid fa-xmark text-red-400"></i>':'<i class="fa-solid fa-info text-blue-400"></i>');
      t.className = `glass-card rounded-2xl p-3 flex items-center gap-3 toast-show border border-${type==='success'?'emerald':(type==='error'?'red':'blue')}-500/50`;
      if(tg.HapticFeedback) tg.HapticFeedback.notificationOccurred(type==='info'?'warning':type);
      setTimeout(() => t.classList.remove('toast-show'), 3000);
    }

    async function apiCall(action, payload = {}, silent = false) {
      try {
        const body = { action, tgId: tgUser.id, firstName: tgUser.first_name, lastName: tgUser.last_name, username: tgUser.username, photoUrl: tgUser.photo_url, referrer: startParam, ...payload };
        const res = await fetch(window.location.href, { method: 'POST', body: JSON.stringify(body) });
        const data = await res.json();
        
        if(data.error) {
            if(data.error === 'BANNED') { document.getElementById('ban-overlay').classList.replace('hidden','flex'); return false; }
            if(data.error === 'MAINTENANCE') { document.getElementById('maintenance-overlay').classList.replace('hidden','flex'); return false; }
            if(!silent) showToast("Error", data.error, "error"); return false;
        }

        document.getElementById('loading-overlay').style.display = 'none';
        
        if(data.user) appState.user = data.user;
        if(data.settings) appState.settings = data.settings;
        if(data.referrals) appState.referrals = data.referrals;
        if(data.withdrawals) appState.withdrawals = data.withdrawals;
        if(data.tasks) appState.tasks = data.tasks;
        if(data.custom_tasks) appState.custom_tasks = data.custom_tasks;

        isAdmin = appState.settings.admins?.includes(tgUser.id.toString());
        if(isAdmin) document.getElementById('admin-secret-btn').classList.remove('hidden');

        if(action.startsWith('admin_') && data.stats) renderAdminData(data);
        
        if(!silent) updateUI();
        return data;
      } catch (err) { if(!silent) console.log("Səssiz xəta gizlədildi."); return false; }
    }

    function updateUI() {
      const u = appState.user; const s = appState.settings;
      const m = (s.xpMultiplier > 1) ? ` (x${s.xpMultiplier})` : '';
      document.getElementById('user-name').innerText = u.firstName;
      document.getElementById('user-xp').innerText = formatNum(u.xp) + " XP";
      document.getElementById('user-usd').innerText = formatNum(u.usd, true);
      document.getElementById('user-photo').src = u.photoUrl || `https://ui-avatars.com/api/?name=${u.firstName}&background=0a0b1a&color=00f0ff`;
      document.getElementById('profile-page-avatar').src = document.getElementById('user-photo').src;
      document.getElementById('profile-page-name').innerText = u.firstName;
      document.getElementById('profile-total-earned').innerText = formatNum(u.totalUsdEarned || u.usd, true);
      
      document.getElementById('main-xp-display').innerText = formatNum(u.xp) + " XP";
      document.getElementById('ads-watched').innerText = u.adsWatchedToday;
      document.getElementById('ads-total-limit').innerText = s.adLimit || 30;
      document.getElementById('streak-days').innerText = u.streak;
      document.getElementById('ad-reward-txt').innerText = `+${20*(s.xpMultiplier||1)} XP`;
      document.getElementById('withdraw-balance-display').innerText = formatNum(u.usd, true);
      
      renderTasks(); renderReferrals(); renderWithdrawHistory();
    }

    // Reklam API Fallback məntiqi (Error verməsin)
    async function watchAd() {
      const btn = document.getElementById('watch-ad-btn'); btn.classList.add('opacity-50','pointer-events-none');
      const apiList = appState.settings.adBlockIds || [];
      let success = false;
      
      for(let id of apiList) {
          if(!id.trim()) continue;
          try {
              if (window.Adsgram) { const AdController = window.Adsgram.init({ blockId: id.trim() }); await AdController.show(); success = true; break; }
          } catch(e) { console.log("Ad API Fail, trying next..."); }
      }
      
      if(success) {
          const res = await apiCall('watch_ad');
          if(res && !res.error) showToast('Təbrik', 'Reklam izlənildi!', 'success');
      } else { showToast('Xəta', 'Hal hazırda uyğun reklam yoxdur.', 'error'); }
      btn.classList.remove('opacity-50','pointer-events-none');
    }

    function renderTasks() {
        // Custom Tasks
        const ctContainer = document.getElementById('custom-tasks-container'); ctContainer.innerHTML = '';
        appState.custom_tasks.forEach(t => {
            const isDone = appState.user.completedCustomTasks?.includes(t.id);
            const rTxt = t.rewardType === 'USDT' ? `$${t.rewardAmount}` : `+${t.rewardAmount * (appState.settings.xpMultiplier||1)} XP`;
            const limitTxt = t.limit > 0 ? ` (Limit: ${t.completions}/${t.limit})` : '';
            if(isDone) ctContainer.innerHTML += `<div class="glass-card p-3 rounded-xl border border-emerald-500/30 opacity-60"><div class="flex justify-between items-center"><span class="text-xs font-black text-white">${t.desc}</span><span class="text-[10px] text-emerald-400"><i class="fa-solid fa-check"></i></span></div></div>`;
            else ctContainer.innerHTML += `<div class="glass-card p-3 rounded-xl border border-blue-500/30 flex justify-between items-center"><div><p class="text-xs font-black text-white">${t.desc} <span class="text-[9px] text-slate-500">${limitTxt}</span></p><p class="text-[10px] text-crypto-glow font-bold">${t.type} Task | ${rTxt}</p></div><button onclick="openCustomTask('${t.id}')" class="bg-blue-600 text-white px-3 py-1.5 rounded text-[10px] font-black uppercase">Get</button></div>`;
        });

        // System Tasks
        const mContainer = document.getElementById('missions-container'); mContainer.innerHTML = '';
        const limit = appState.settings.adLimit || 30;
        const missions = [{id: 'watch15', label: `Watch 15 Ads`, req: 15, rew: 40}, {id: 'watch_all', label: `Watch ${limit} Ads`, req: limit, rew: 80}];
        missions.forEach(m => {
            const done = appState.tasks?.includes(m.id); const can = !done && appState.user.adsWatchedToday >= m.req;
            let btn = done ? `<span class="text-emerald-400 text-[10px]"><i class="fa-solid fa-check"></i></span>` : (can ? `<button onclick="claimTask('${m.id}',${m.rew})" class="bg-cyan-600 px-3 py-1 text-[10px] rounded text-white">CLAIM</button>` : `<span class="text-slate-500 text-[10px]">+${m.rew} XP</span>`);
            mContainer.innerHTML += `<div class="glass-card p-3 rounded flex justify-between items-center border border-slate-700/50"><span class="text-xs text-white">${m.label} (${Math.min(appState.user.adsWatchedToday, m.req)}/${m.req})</span>${btn}</div>`;
        });
    }

    function openCustomTask(id) {
        const t = appState.custom_tasks.find(x => x.id === id); if(!t) return;
        document.getElementById('ctm-title').innerText = t.desc;
        document.getElementById('ctm-inst').innerText = t.uidInstructions || 'Linki açın və tapşırığı tamamlayın.';
        document.getElementById('ctm-id').value = id;
        document.getElementById('ctm-uid-input').style.display = t.requireUid ? 'block' : 'none';
        document.getElementById('ctm-uid-input').value = '';
        document.getElementById('custom-task-modal').classList.remove('hidden'); document.getElementById('custom-task-modal').classList.add('flex');
        tg.openTelegramLink(t.link.startsWith('http') ? t.link : `https://${t.link}`);
    }

    async function submitCustomTask() {
        const id = document.getElementById('ctm-id').value;
        const t = appState.custom_tasks.find(x => x.id === id);
        const uidVal = document.getElementById('ctm-uid-input').value;
        if(t.requireUid && !uidVal) return showToast('Xəta', 'UID tələb olunur!', 'error');
        
        document.getElementById('custom-task-modal').classList.add('hidden');
        const res = await apiCall('claim_custom_task', { taskId: id, providedUid: uidVal });
        if(res && !res.error) showToast('Uğurlu', 'Mükafat əlavə edildi!', 'success');
    }

    async function claimTask(id, rew) { const res = await apiCall('claim_task', {taskId: id, reward: rew}); if(res && !res.error) showToast('Success', 'Claimed!', 'success'); }
    async function openBox(type) { const res = await apiCall('open_box', {boxType: type}); if(res && !res.error) showToast(res.jackpot?'JACKPOT!':'Təbrik', `Sən $${res.reward} qazandın!`, res.jackpot?'success':'success'); }
    async function requestWithdrawal() {
        const a = document.getElementById('wallet-address').value; const m = parseFloat(document.getElementById('withdraw-amount').value);
        if(m < 10) return showToast('Xəta','Minimum 10 USD','error');
        const res = await apiCall('withdraw', {amount: m, address: a});
        if(res && !res.error) { showToast('Uğurlu','Tələb göndərildi','success'); document.getElementById('wallet-address').value=''; document.getElementById('withdraw-amount').value=''; }
    }
    
    function renderReferrals() { document.getElementById('ref-total').innerText = appState.referrals.length; }
    function renderWithdrawHistory() {
        const c = document.getElementById('withdraw-history-container');
        c.innerHTML = appState.withdrawals.map(w => `<div class="glass-card p-3 rounded flex justify-between border border-slate-700"><div><p class="text-xs text-white">${w.address}</p><p class="text-[9px] text-slate-500">${w.date}</p></div><div class="text-right"><p class="text-emerald-400 font-bold">$${w.amount}</p><p class="text-[9px] ${w.status==='Approved'?'text-emerald-500':(w.status==='Rejected'?'text-red-500':'text-amber-500')}">${w.status}</p></div></div>`).join('');
    }

    function switchTab(t) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('nav-active'));
      document.getElementById(`view-${t}`).classList.remove('hidden');
      const nb = document.querySelector(`[data-target="${t}"]`); if(nb) nb.classList.add('nav-active');
      if(t === 'admin' && isAdmin) apiCall('admin_dashboard');
    }

    // --- ADMIN PANEL FUNCTIONS (AZERBAIJANI) ---
    let adminCache = null;
    function switchAdminTab(t) {
        document.querySelectorAll('.admin-section').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.admin-tab').forEach(b => { b.classList.remove('bg-red-600/20', 'text-red-400', 'border-red-500/50'); b.classList.add('text-slate-400'); });
        document.getElementById(`admin-sec-${t}`).classList.remove('hidden');
        const tb = document.getElementById(`tab-${t}`); tb.classList.remove('text-slate-400'); tb.classList.add('bg-red-600/20', 'text-red-400', 'border', 'border-red-500/50');
    }

    function renderAdminData(data) {
        adminCache = data;
        document.getElementById('adm-stat-users').innerText = data.stats.users;
        document.getElementById('adm-stat-usd').innerText = `$${formatNum(data.stats.usd, true)}`;
        document.getElementById('adm-stat-ads').innerText = data.stats.ads;
        document.getElementById('adm-stat-tasks').innerText = data.stats.tasks;
        
        // Tənzimləmələr formu
        document.getElementById('adm-set-apis').value = appState.settings.adBlockIds.join(', ');
        document.getElementById('adm-set-adlimit').value = appState.settings.adLimit;
        document.getElementById('adm-set-reset').value = appState.settings.resetHour;
        document.getElementById('adm-set-2x').checked = (appState.settings.xpMultiplier > 1);
        document.getElementById('adm-set-maint').checked = appState.settings.maintenance;

        filterAdminUsers();
        renderAdminTasks();
        renderAdminWithdrawals();
        
        // Errors
        document.getElementById('admin-sec-errors').innerHTML = data.errors_log.map(e => `<div class="bg-red-900/20 border border-red-900 p-2 rounded"><p class="text-[9px] text-red-400">${e.time} | UID: ${e.uid}</p><p class="text-[10px] text-slate-300 font-mono mt-1">${e.error}</p></div>`).join('') || '<p class="text-xs text-slate-500 text-center">Xəta yoxdur</p>';
    }

    function filterAdminUsers() {
        const q = document.getElementById('admin-user-search')?.value.toLowerCase() || '';
        const list = document.getElementById('admin-user-list'); list.innerHTML = '';
        if(!adminCache) return;
        const users = adminCache.all_users.filter(u => u.tgId.includes(q) || (u.username||'').toLowerCase().includes(q) || u.firstName.toLowerCase().includes(q));
        users.forEach(u => {
            const isBan = u.banned;
            const card = `<div class="bg-[#050511] p-3 rounded border border-slate-700">
                <div class="flex justify-between items-center cursor-pointer" onclick="this.nextElementSibling.classList.toggle('hidden')">
                    <div class="flex items-center gap-2"><span class="${isBan?'text-red-500':'text-blue-400'}"><i class="fa-solid fa-user"></i></span><div><p class="text-xs text-white">${u.firstName} <span class="text-[9px] text-slate-500">UID: ${u.tgId}</span></p><p class="text-[9px] ${u.lastActive.includes(new Date().toISOString().split('T')[0]) ? 'text-emerald-400' : 'text-slate-500'}">${u.lastActive.includes(new Date().toISOString().split('T')[0]) ? 'Aktiv' : 'Çevrimdışı'} | ${u.lastActive}</p></div></div>
                    <span class="text-xs text-white"><i class="fa-solid fa-chevron-down"></i></span>
                </div>
                <div class="hidden mt-3 pt-3 border-t border-slate-700 space-y-2">
                    <div class="grid grid-cols-2 gap-2 text-[10px] text-slate-300">
                        <p>Balans: <span class="text-emerald-400">$${formatNum(u.usd,true)}</span></p>
                        <p>Total USD: $${formatNum(u.totalUsdEarned||u.usd,true)}</p>
                        <p>XP: <span class="text-crypto-glow">${formatNum(u.xp)}</span></p>
                        <p>Reklam/Gün: ${u.adsWatchedToday}</p>
                    </div>
                    <div class="flex gap-2">
                        <input type="number" id="adm-usd-${u.tgId}" value="${u.usd}" class="w-1/2 bg-slate-900 border border-slate-600 rounded p-1 text-xs text-white" placeholder="USD">
                        <input type="number" id="adm-xp-${u.tgId}" value="${u.xp}" class="w-1/2 bg-slate-900 border border-slate-600 rounded p-1 text-xs text-white" placeholder="XP">
                    </div>
                    <div class="flex gap-2">
                        <button onclick="adminUserAction('${u.tgId}','update_balance')" class="flex-1 bg-emerald-600 text-white py-1 text-[10px] rounded">Balansı Dəyiş</button>
                        <button onclick="adminUserAction('${u.tgId}','reset_ads')" class="flex-1 bg-blue-600 text-white py-1 text-[10px] rounded">Reklamı Sıfırla</button>
                        <button onclick="adminUserAction('${u.tgId}', '${isBan?'unban_uid':'force_ban_uid'}')" class="flex-1 ${isBan?'bg-slate-600':'bg-red-600'} text-white py-1 text-[10px] rounded">${isBan?'Banı Aç':'Banla'}</button>
                    </div>
                </div>
            </div>`;
            list.innerHTML += card;
        });
    }

    function renderAdminTasks() {
        const list = document.getElementById('admin-task-list'); list.innerHTML = '';
        if(!adminCache) return;
        adminCache.custom_tasks.forEach(t => {
            list.innerHTML += `<div class="bg-[#050511] p-3 rounded border border-slate-700 flex justify-between items-center">
                <div><p class="text-xs text-white">${t.desc}</p><p class="text-[9px] text-slate-400">${t.type} | Mükafat: ${t.rewardAmount} ${t.rewardType} | İcra: ${t.completions}/${t.limit||'∞'}</p></div>
                <button onclick="apiCall('admin_task_delete', {taskId: '${t.id}'}).then(()=>apiCall('admin_dashboard'))" class="text-red-500 bg-red-900/20 px-2 py-1 rounded text-[10px]"><i class="fa-solid fa-trash"></i></button>
            </div>`;
        });
    }

    function renderAdminWithdrawals() {
        const l = document.getElementById('admin-sec-withdrawals'); l.innerHTML = '';
        if(!adminCache) return;
        adminCache.all_withdrawals.filter(w => w.status === 'Pending').forEach(w => {
            l.innerHTML += `<div class="bg-[#050511] p-3 rounded border border-slate-700">
                <p class="text-[10px] text-slate-400">UID: ${w.user_id} | ${w.date}</p>
                <p class="text-xs font-mono text-blue-400 my-1">${w.address}</p>
                <div class="flex justify-between items-center mt-2">
                    <span class="text-emerald-400 font-black">$${w.amount}</span>
                    <div class="flex gap-2"><button onclick="apiCall('admin_action_withdraw',{targetUid:'${w.user_id}',idx:${w.idx},withdrawAction:'reject'}).then(()=>apiCall('admin_dashboard'))" class="bg-red-600 text-white px-3 py-1 text-[10px] rounded">Rədd et</button><button onclick="apiCall('admin_action_withdraw',{targetUid:'${w.user_id}',idx:${w.idx},withdrawAction:'approve'}).then(()=>apiCall('admin_dashboard'))" class="bg-emerald-600 text-white px-3 py-1 text-[10px] rounded">Onayla</button></div>
                </div>
            </div>`;
        });
    }

    function adminUserAction(uid, act) {
        const u = document.getElementById(`adm-usd-${uid}`)?.value; const x = document.getElementById(`adm-xp-${uid}`)?.value;
        apiCall('admin_action_user', {targetUid: uid, userAction: act, newUsd: u, newXp: x}).then(()=>apiCall('admin_dashboard'));
    }
    function adminMassAction(act) { if(confirm('Bunu etmək istədiyinizə əminsiniz?')) apiCall('admin_mass_action', {massAction: act}).then(()=>apiCall('admin_dashboard')); }
    function adminForceBan() { const u = document.getElementById('admin-pre-ban-uid').value; if(u) apiCall('admin_action_user', {targetUid: u, userAction: 'force_ban_uid'}).then(()=> { document.getElementById('admin-pre-ban-uid').value=''; apiCall('admin_dashboard');}); }
    
    function adminSaveSettings() {
        const apis = document.getElementById('adm-set-apis').value.split(',').map(s=>s.trim()).filter(s=>s);
        const l = document.getElementById('adm-set-adlimit').value;
        const r = document.getElementById('adm-set-reset').value;
        const m = document.getElementById('adm-set-maint').checked;
        const x = document.getElementById('adm-set-2x').checked ? 2 : 1;
        apiCall('admin_update_settings', {blockIds: apis, adLimit: l, resetHour: r, maintenance: m, xpMultiplier: x});
    }
    
    function adminAddTask() {
        const p = {
            desc: document.getElementById('task-desc').value, type: document.getElementById('task-type').value,
            rewardType: document.getElementById('task-reward-type').value, rewardAmount: document.getElementById('task-reward-amt').value,
            link: document.getElementById('task-link').value, requireUid: document.getElementById('task-req-uid').checked,
            uidInstructions: document.getElementById('task-uid-inst').value, limit: document.getElementById('task-limit').value || 0
        };
        apiCall('admin_task_add', p).then(()=>{ apiCall('admin_dashboard'); document.getElementById('task-desc').value=''; });
    }
    function adminAddAdmin() { const u=document.getElementById('adm-new-admin').value; if(u) apiCall('admin_add_admin',{newAdminUid:u}).then(()=>document.getElementById('adm-new-admin').value=''); }

    // REAL TIME SİNC - Hər 15 saniyədən bir datanı sessizcə yeniləyir. Çıxarış və ya balans dəyişsə dərhal əks olunacaq.
    setInterval(() => { if(!document.hidden) apiCall('ping', {}, true); }, 15000);

    window.onload = () => { setTimeout(() => apiCall('ping'), 500); };
  </script>
</body>
</html>
