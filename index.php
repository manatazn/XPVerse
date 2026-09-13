<?php
// XPVERSE - SINGLE FILE ARCHITECTURE (V2)
// All requested features implemented.

error_reporting(0); // Suppress PHP errors for clear JSON
date_default_timezone_set('Europe/Moscow'); // Base server time
$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);

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

function logError($uid, $type, $reason) {
    $errs = readDB('errors.json');
    array_unshift($errs, ['uid' => $uid, 'type' => $type, 'reason' => $reason, 'date' => date('Y-m-d H:i:s')]);
    if (count($errs) > 300) array_pop($errs);
    writeDB('errors.json', $errs);
}

// Ensure Settings & Defaults
$settings = readDB('settings.json');
$defaults = [
    'adBlockIds' => ['int-35545'],
    'admins' => ['5461064199'],
    'bannedUids' => [],
    'maintenance' => false,
    'dailyAdLimit' => 30,
    'resetHour' => 3,
    'doubleXp' => false
];
$settingsChanged = false;
foreach ($defaults as $k => $v) {
    if (!isset($settings[$k])) { $settings[$k] = $v; $settingsChanged = true; }
}
if ($settingsChanged) writeDB('settings.json', $settings);

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
        echo json_encode(['error' => 'FRIENDLY', 'message' => 'Invalid connection request.']);
        exit;
    }

    $action = $input['action'];
    $uid = (string)$input['tgId'];
    $isAdmin = in_array($uid, $settings['admins']);
    
    // Check Pre-Ban and Maintenance
    if (in_array($uid, $settings['bannedUids'])) {
        echo json_encode(['error' => 'BANNED', 'message' => 'Your account has been restricted by the administrator.']); exit;
    }
    if ($settings['maintenance'] && !$isAdmin) {
        echo json_encode(['error' => 'MAINTENANCE']); exit;
    }

    $users = readDB('users.json');
    
    if (isset($users[$uid]['banned']) && $users[$uid]['banned'] === true && !$isAdmin) {
        echo json_encode(['error' => 'BANNED', 'message' => 'Your account has been banned.']); exit;
    }

    // Initialization
    $referrals = readDB('referrals.json');
    $rewards = readDB('rewards.json');
    $withdrawals = readDB('withdrawals.json');
    $tasks = readDB('tasks.json');
    $customTasks = readDB('custom_tasks.json');

    if (!isset($users[$uid])) {
        $users[$uid] = [
            'tgId' => $uid, 'firstName' => $input['firstName'] ?? 'User', 'lastName' => $input['lastName'] ?? '',
            'username' => $input['username'] ?? '', 'photoUrl' => $input['photoUrl'] ?? '',
            'xp' => 0, 'totalXp' => 0, 'usd' => 0.00, 'totalUsd' => 0.00, 'level' => 1,
            'adsWatchedToday' => 0, 'totalAdsWatched' => 0, 'tasksCompleted' => 0, 'boxesOpened' => 0,
            'streak' => 1, 'lastResetDay' => $today, 'referrer' => null, 'sponsorAzx' => false,
            'lastActive' => $now->format('Y-m-d H:i:s'), 'banned' => false, 'customTasks' => []
        ];

        if (!empty($input['referrer']) && $input['referrer'] !== $uid) {
            $refId = (string)$input['referrer'];
            if (isset($users[$refId])) {
                $users[$uid]['referrer'] = $refId;
                if (!isset($referrals[$refId])) $referrals[$refId] = [];
                $referrals[$refId][] = [
                    'uid' => $uid, 'name' => trim(($users[$uid]['firstName'] ?? '') . ' ' . ($users[$uid]['lastName'] ?? '')),
                    'username' => $users[$uid]['username'], 'status' => 'Pending', 'ads' => 0, 'tasks' => 0, 'joinDate' => $now->format('M j, Y')
                ];
                writeDB('referrals.json', $referrals);
            }
        }
    } else {
        if (!isset($users[$uid]['totalUsd'])) $users[$uid]['totalUsd'] = $users[$uid]['usd']; // Patch for old users
        if (!isset($users[$uid]['customTasks'])) $users[$uid]['customTasks'] = [];
        $users[$uid]['lastActive'] = $now->format('Y-m-d H:i:s');
    }

    // Daily Reset logic
    if ($users[$uid]['lastResetDay'] !== $today) {
        $lastDay = strtotime($users[$uid]['lastResetDay']);
        $currDay = strtotime($today);
        $diff = round(($currDay - $lastDay) / 86400);
        $users[$uid]['streak'] = ($diff === 1) ? min($users[$uid]['streak'] + 1, 7) : 1;
        $users[$uid]['adsWatchedToday'] = 0;
        $users[$uid]['lastResetDay'] = $today;
        if(isset($tasks[$uid])) {
            // Only keep complete_all if not reset, else wipe tasks array daily
            $tasks[$uid] = []; 
        }
    }

    function calcLevel($xp) {
        if ($xp >= 20000) return 10; if ($xp >= 3000) return 5;
        if ($xp >= 1500) return 4; if ($xp >= 750) return 3;
        if ($xp >= 250) return 2; return 1;
    }

    function evaluateReferralProgress($refUid, &$users, &$referrals, &$rewards) {
        global $now;
        $refUser = $users[$refUid];
        if (empty($refUser['referrer'])) return;
        $referrerId = $refUser['referrer'];
        if (!isset($referrals[$referrerId])) return;
        
        $changed = false; $rewAdded = false;
        foreach ($referrals[$referrerId] as &$r) {
            if ($r['uid'] === $refUid && $r['status'] === 'Pending') {
                if ($r['ads'] !== $refUser['totalAdsWatched'] || $r['tasks'] !== $refUser['tasksCompleted']) {
                    $r['ads'] = $refUser['totalAdsWatched']; $r['tasks'] = $refUser['tasksCompleted'];
                    $changed = true;
                }
                if ($r['ads'] >= 25 && $r['tasks'] >= 5) {
                    $r['status'] = 'Approved'; $r['approvedAt'] = $now->format('M j, Y');
                    $changed = true; $rewAdded = true;
                    $users[$referrerId]['xp'] += 250; $users[$referrerId]['totalXp'] += 250;
                    $users[$referrerId]['level'] = calcLevel($users[$referrerId]['totalXp']);
                    $users[$referrerId]['usd'] += 0.025; $users[$referrerId]['totalUsd'] += 0.025;
                    
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
        if ($changed) writeDB('referrals.json', $referrals);
        if ($rewAdded) writeDB('rewards.json', $rewards);
    }

    $response = ['success' => true];
    $resetTarget = clone $now;
    $resetTarget->setTime((int)$settings['resetHour'], 0, 0); 
    if ($hour >= (int)$settings['resetHour']) $resetTarget->modify('+1 day');
    
    $response['serverTime'] = $now->getTimestamp();
    $response['serverResetTime'] = $resetTarget->getTimestamp(); 
    $response['settings'] = $settings;

    // --- ADMIN PANEL SECURE ROUTES ---
    if (strpos($action, 'admin_') === 0) {
        if (!$isAdmin && (!isset($input['adminCode']) || $input['adminCode'] !== 'PZX9N4ML2DK')) {
            logError($uid, 'Security', 'Unauthorized admin access attempt');
            echo json_encode(['error' => 'FRIENDLY', 'message' => 'Unauthorized Access']); exit;
        }

        if ($action === 'admin_dashboard') {
            $totalUsd = 0; $totalAds = 0; $totalTasks = 0; $totalXp = 0; $totalUsers = count($users); $totalRefs = 0;
            foreach($users as $u) {
                $totalUsd += $u['usd']; $totalAds += $u['totalAdsWatched'];
                $totalTasks += $u['tasksCompleted']; $totalXp += $u['totalXp'];
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
            
            // Format User info for AZT Time
            $aztZone = new DateTimeZone('Asia/Baku');
            $userList = [];
            foreach($users as $u) {
                $activeTime = new DateTime($u['lastActive'], new DateTimeZone('Europe/Moscow'));
                $activeTime->setTimezone($aztZone);
                $u['lastActiveAzt'] = $activeTime->format('d/m/Y H:i');
                $isOnline = (time() - $activeTime->getTimestamp()) < 300;
                $u['onlineStatus'] = $isOnline ? 'Aktiv' : 'Çevrimdışı';
                $u['refCount'] = isset($referrals[$u['tgId']]) ? count($referrals[$u['tgId']]) : 0;
                $userList[] = $u;
            }
            $response['all_users'] = $userList;
            $response['all_withdrawals'] = $allWithdrawals;
            $response['all_errors'] = readDB('errors.json');
            echo json_encode($response); exit;
        }

        if ($action === 'admin_update_settings') {
            $settings['adBlockIds'] = $input['blockIds'];
            $settings['dailyAdLimit'] = (int)$input['dailyAdLimit'];
            $settings['resetHour'] = (int)$input['resetHour'];
            $settings['maintenance'] = (bool)$input['maintenance'];
            $settings['doubleXp'] = (bool)$input['doubleXp'];
            writeDB('settings.json', $settings);
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'admin_add_admin') {
            $newAdmin = (string)$input['newAdminId'];
            if (!in_array($newAdmin, $settings['admins'])) {
                $settings['admins'][] = $newAdmin;
                writeDB('settings.json', $settings);
            }
            echo json_encode(['success' => true]); exit;
        }
        
        if ($action === 'admin_ban_uid') {
            $banUid = (string)$input['banUid'];
            if (!in_array($banUid, $settings['bannedUids'])) {
                $settings['bannedUids'][] = $banUid;
                writeDB('settings.json', $settings);
                if (isset($users[$banUid])) {
                    $users[$banUid]['banned'] = true;
                    writeDB('users.json', $users);
                }
            }
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'admin_action_user') {
            $targetUid = $input['targetUid']; $act = $input['userAction'];
            if (!isset($users[$targetUid])) { echo json_encode(['error' => 'FRIENDLY', 'message' => 'User not found']); exit; }

            if ($act === 'ban') { $users[$targetUid]['banned'] = true; } 
            elseif ($act === 'unban') { $users[$targetUid]['banned'] = false; } 
            elseif ($act === 'reset_ads') { $users[$targetUid]['adsWatchedToday'] = 0; } 
            elseif ($act === 'update_balance') {
                $users[$targetUid]['usd'] = max(0, (float)$input['newUsd']);
                $users[$targetUid]['xp'] = max(0, (int)$input['newXp']);
                $users[$targetUid]['totalXp'] = max($users[$targetUid]['totalXp'], $users[$targetUid]['xp']);
            }
            writeDB('users.json', $users);
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'admin_action_withdraw') {
            $targetUid = $input['targetUid']; $idx = $input['idx']; $wAct = $input['withdrawAction'];
            if (isset($withdrawals[$targetUid][$idx]) && $withdrawals[$targetUid][$idx]['status'] === 'Pending') {
                if ($wAct === 'approve') { $withdrawals[$targetUid][$idx]['status'] = 'Approved'; } 
                else if ($wAct === 'reject') {
                    $withdrawals[$targetUid][$idx]['status'] = 'Rejected';
                    $users[$targetUid]['usd'] += $withdrawals[$targetUid][$idx]['amount'];
                    writeDB('users.json', $users);
                }
                writeDB('withdrawals.json', $withdrawals);
                echo json_encode(['success' => true]); exit;
            }
            echo json_encode(['error' => 'FRIENDLY', 'message' => 'Action failed.']); exit;
        }

        if ($action === 'admin_task_add') {
            $customTasks[] = [
                'id' => 't_' . uniqid(), 'title' => $input['title'], 'type' => $input['type'], 
                'rewardType' => $input['rewardType'], 'rewardAmount' => (float)$input['rewardAmount'], 
                'link' => $input['link'], 'requireUid' => (bool)$input['requireUid'], 
                'uidPrompt' => $input['uidPrompt'], 'limit' => (int)$input['limit'], 'completions' => 0
            ];
            writeDB('custom_tasks.json', $customTasks);
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'admin_task_delete') {
            $tid = $input['taskId'];
            foreach ($customTasks as $key => $t) {
                if ($t['id'] === $tid) { unset($customTasks[$key]); break; }
            }
            writeDB('custom_tasks.json', array_values($customTasks));
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'admin_global_reset') {
            $type = $input['resetType'];
            foreach ($users as &$u) {
                if ($type === 'ads') $u['adsWatchedToday'] = 0;
                if ($type === 'balances') { $u['usd'] = 0; $u['xp'] = 0; }
            }
            writeDB('users.json', $users);
            echo json_encode(['success' => true]); exit;
        }
    }
    // --- END ADMIN ROUTES ---

    // NORMAL USER ACTIONS
    $mult = $settings['doubleXp'] ? 2 : 1;

    if ($action === 'sync') {
        // Silent sync
    }
    else if ($action === 'watch_ad') {
        if ($users[$uid]['adsWatchedToday'] < (int)$settings['dailyAdLimit']) {
            $users[$uid]['adsWatchedToday'] += 1; $users[$uid]['totalAdsWatched'] += 1;
            $xpGain = 20 * $mult;
            $users[$uid]['xp'] += $xpGain; $users[$uid]['totalXp'] += $xpGain;
            $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
            evaluateReferralProgress($uid, $users, $referrals, $rewards);
            $response['earned'] = $xpGain;
        } else {
            logError($uid, 'ActionLimit', 'Ad limit reached');
            $response['error'] = 'FRIENDLY'; $response['message'] = 'Daily ad limit reached.';
        }
    }
    else if ($action === 'claim_task') {
        $taskId = $input['taskId'] ?? '';
        if ($taskId === 'sponsor_azx') {
            if (empty($users[$uid]['sponsorAzx'])) {
                $users[$uid]['sponsorAzx'] = true;
                $xpGain = 200 * $mult;
                $users[$uid]['xp'] += $xpGain; $users[$uid]['totalXp'] += $xpGain;
                $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
            } else { $response['error'] = 'FRIENDLY'; $response['message'] = 'Task already claimed.'; }
        } else {
            if (!isset($tasks[$uid])) $tasks[$uid] = [];
            if (!in_array($taskId, $tasks[$uid])) {
                if ($taskId === 'complete_all' && $users[$uid]['adsWatchedToday'] < (int)$settings['dailyAdLimit']) {
                    $response['error'] = 'FRIENDLY'; $response['message'] = 'Complete daily tasks first.';
                } else {
                    $rewardXp = ((int)($input['reward'] ?? 0)) * $mult;
                    if ($rewardXp > 0 && $rewardXp <= 2000) { 
                        $tasks[$uid][] = $taskId;
                        $users[$uid]['tasksCompleted'] += 1;
                        $users[$uid]['xp'] += $rewardXp; $users[$uid]['totalXp'] += $rewardXp;
                        $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                        evaluateReferralProgress($uid, $users, $referrals, $rewards);
                        writeDB('tasks.json', $tasks);
                    }
                }
            } else { $response['error'] = 'FRIENDLY'; $response['message'] = 'Already claimed today.'; }
        }
    }
    else if ($action === 'claim_custom_task') {
        $taskId = $input['taskId'] ?? '';
        $taskData = $input['taskData'] ?? true;
        
        $taskFound = false;
        foreach ($customTasks as &$ct) {
            if ($ct['id'] === $taskId) {
                $taskFound = true;
                if ($ct['limit'] > 0 && $ct['completions'] >= $ct['limit']) {
                    $response['error'] = 'FRIENDLY'; $response['message'] = 'Task limit reached.'; break;
                }
                if (isset($users[$uid]['customTasks'][$taskId])) {
                    $response['error'] = 'FRIENDLY'; $response['message'] = 'Task already claimed.'; break;
                }
                
                $users[$uid]['customTasks'][$taskId] = $taskData;
                $ct['completions']++;
                $users[$uid]['tasksCompleted'] += 1;
                
                $amt = $ct['rewardAmount'] * $mult;
                if ($ct['rewardType'] === 'usdt') { $users[$uid]['usd'] += $amt; $users[$uid]['totalUsd'] += $amt; } 
                else { $users[$uid]['xp'] += $amt; $users[$uid]['totalXp'] += $amt; $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']); }
                
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
                writeDB('custom_tasks.json', $customTasks);
                break;
            }
        }
        if (!$taskFound && !isset($response['error'])) { $response['error'] = 'FRIENDLY'; $response['message'] = 'Task not found.'; }
    }
    else if ($action === 'open_box') {
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
            $users[$uid]['totalUsd'] += $rewardUsd;
            $response['reward'] = $rewardUsd;
            $response['jackpot'] = $isJackpot;
        } else {
            $response['error'] = 'FRIENDLY'; $response['message'] = 'Not enough XP.';
        }
    }
    else if ($action === 'withdraw') {
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
        } else {
            logError($uid, 'Withdrawal', 'Invalid request: amt/address');
            $response['error'] = 'FRIENDLY'; $response['message'] = 'Invalid request.';
        }
    }

    writeDB('users.json', $users);
    $response['user'] = $users[$uid];
    $response['referrals'] = $referrals[$uid] ?? [];
    $response['rewards'] = $rewards[$uid] ?? [];
    $response['withdrawals'] = $withdrawals[$uid] ?? [];
    $response['tasks'] = $tasks[$uid] ?? [];
    $response['customTasksData'] = $customTasks;
    
    echo json_encode($response);
    exit;
}
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
      theme: { extend: {
          fontFamily: { sans: ['Outfit', 'sans-serif'] },
          colors: { crypto: { dark: '#050511', card: '#0a0b1a', primary: '#3b82f6', glow: '#00f0ff', gold: '#ffb800', silver: '#e2e8f0', bronze: '#cd7f32' } },
          animation: { 'blob': 'blob 7s infinite', 'pulse-fast': 'pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite', 'shimmer': 'shimmer 2s infinite', 'slide-up': 'slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards' },
          keyframes: {
            blob: { '0%': { transform: 'translate(0px, 0px) scale(1)' }, '33%': { transform: 'translate(30px, -50px) scale(1.1)' }, '66%': { transform: 'translate(-20px, 20px) scale(0.9)' }, '100%': { transform: 'translate(0px, 0px) scale(1)' } },
            shimmer: { '0%': { transform: 'translateX(-100%)' }, '100%': { transform: 'translateX(100%)' } },
            slideUp: { '0%': { transform: 'translateY(15px)', opacity: 0 }, '100%': { transform: 'translateY(0)', opacity: 1 } }
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

  <!-- Loading & Messages Screens -->
  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-6">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1s_linear_infinite]"></div>
      <div class="absolute inset-2 rounded-full border-b-4 border-blue-500 animate-[spin_1.5s_linear_infinite_reverse]"></div>
      <div class="absolute inset-0 flex items-center justify-center"><i class="fa-solid fa-rocket text-crypto-glow text-3xl animate-pulse"></i></div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2">XPVerse</h2>
  </div>

  <div id="maintenance-screen" class="hidden fixed inset-0 z-[100001] bg-[#050511] flex flex-col items-center justify-center text-white p-5 text-center">
      <i class="fa-solid fa-person-digging text-6xl mb-4 text-amber-500"></i>
      <h1 class="text-3xl font-black mb-2 tracking-wide">MAINTENANCE</h1>
      <p class="text-xs text-slate-400">The app is currently undergoing maintenance. We'll be right back!</p>
  </div>
  
  <div id="banned-screen" class="hidden fixed inset-0 z-[100001] bg-red-950 flex flex-col items-center justify-center text-white p-5 text-center">
      <i class="fa-solid fa-ban text-6xl mb-4 text-red-500"></i>
      <h1 class="text-3xl font-black mb-2 tracking-wide">ACCOUNT BANNED</h1>
      <p id="banned-msg" class="text-xs text-slate-300">Your account has been restricted by the administrator.</p>
  </div>

  <div id="toast-container" class="glass-card rounded-2xl p-3 flex items-center gap-3">
    <div id="toast-icon" class="w-10 h-10 rounded-full flex shrink-0 items-center justify-center text-lg shadow-inner"></div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-xs font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-[11px] text-slate-300 mt-0.5 leading-tight">Message goes here</p>
    </div>
  </div>

  <!-- Header -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full py-3 px-4 glass-card rounded-b-[1.5rem] border-b-0 shadow-[0_10px_30px_rgba(0,0,0,0.5)]">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-2.5">
        <div class="relative w-10 h-10 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500">
          <img id="user-photo" src="" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-[#050511]">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm tracking-wide leading-tight">Loading...</span>
          <div class="flex items-center gap-1.5 mt-0.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_#34d399] animate-pulse"></span><span class="text-[9px] text-slate-400 uppercase font-black tracking-widest">Online</span></div>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <div class="bg-[#050511] border border-blue-500/30 px-2.5 py-1 rounded-lg flex items-center gap-1.5">
          <i class="fa-solid fa-bolt text-crypto-glow text-[10px]"></i><span id="user-xp" class="text-white font-black text-xs tracking-wider">0 <span class="text-[9px] text-crypto-glow">XP</span></span>
        </div>
        <div class="bg-[#050511] border border-emerald-500/40 px-2.5 py-1 rounded-lg flex items-center gap-1.5">
          <i class="fa-solid fa-dollar-sign text-emerald-400 text-[9px]"></i><span id="user-usd" class="text-emerald-400 font-black text-[11px] tracking-wider">0</span>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-20 pb-24 relative" id="app-content">
    
    <!-- HOME PAGE -->
    <div id="view-home" class="view-section fade-in space-y-5">
      <div class="relative glass-card rounded-[1.5rem] p-5 text-center border-t border-t-blue-400/20 overflow-hidden flex flex-col items-center justify-center min-h-[220px]">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-48 h-48 bg-blue-500/20 rounded-full blur-[40px] pointer-events-none animate-pulse-fast"></div>
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-900/60 to-[#050511] border border-blue-400/40 flex items-center justify-center mb-3 shadow-[0_0_20px_rgba(59,130,246,0.4)]">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow drop-shadow-[0_0_10px_rgba(0,240,255,0.9)]"></i>
          </div>
          <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.25em] mb-1 opacity-90">Current Balance</p>
          <h1 class="text-4xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-500 tracking-tighter" id="main-xp-display">0 XP</h1>
        </div>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5 shadow-inner">
            <div class="bg-blue-500/10 p-2.5 rounded-lg border border-blue-500/30"><i class="fa-solid fa-clapperboard text-blue-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black tracking-wider">Ads Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / <span id="ads-max">30</span></p>
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

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-3.5 rounded-[1.25rem] text-white font-black text-sm tracking-[0.1em] uppercase flex items-center justify-center gap-2.5 btn-3d relative overflow-hidden group">
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-[shimmer_1.5s_infinite]"></div>
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span id="watch-ad-text">Watch Ad <span class="text-cyan-200 ml-1">+20 XP</span></span>
      </button>

      <div class="glass-card rounded-xl p-3.5 flex justify-between items-center border border-slate-800">
        <div class="flex items-center gap-2 text-slate-400 text-[11px] font-bold"><i class="fa-solid fa-clock text-blue-400"></i> <span class="uppercase tracking-widest">Resets in (Server Time):</span></div>
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
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center"><i class="fa-solid fa-calendar-day text-lg text-white"></i></div>
                    <div><h3 class="text-[15px] font-black text-white tracking-wide">Daily Login</h3><p class="text-[9px] text-cyan-400 font-bold uppercase tracking-widest mt-0.5">Keep your streak alive</p></div>
                </div>
                <div id="daily-login-btn-container"></div>
            </div>
            <div class="bg-[#050511]/60 rounded-xl p-3 border border-slate-700/50"><div class="relative flex justify-between items-center" id="streak-tracker-container"></div></div>
        </div>
      </div>

      <div class="mt-6 mb-4">
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2"><i class="fa-solid fa-star text-amber-400"></i> Sponsor & Extra Tasks</h3>
        <div id="sponsor-container" class="space-y-3"></div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2"><i class="fa-solid fa-list-check text-slate-600"></i> Daily Missions</h3>
        <div id="missions-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- REFERRALS PAGE -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-5">
      <div class="text-center relative mb-1">
        <h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Referans</h2>
        <p class="text-[11px] text-blue-400 mt-0.5 uppercase tracking-widest font-bold">Invite & Earn</p>
      </div>

      <div class="grid grid-cols-3 gap-2.5">
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-blue-500/40"><p class="text-[9px] text-slate-400 uppercase font-black mb-1">Total</p><p id="ref-total" class="text-xl font-black text-white">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-amber-500/40"><p class="text-[9px] text-slate-400 uppercase font-black mb-1">Pending</p><p id="ref-pending" class="text-xl font-black text-amber-400">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-emerald-500/40"><p class="text-[9px] text-slate-400 uppercase font-black mb-1">Approved</p><p id="ref-approved" class="text-xl font-black text-emerald-400">0</p></div>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 space-y-3.5 border border-slate-700/50">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-1.5 ml-1">Your Referral Link</label>
          <div class="flex items-center gap-2">
            <input type="text" id="ref-link-input" readonly class="flex-1 bg-[#050511]/70 border border-slate-700 rounded-lg py-2.5 px-3 text-[11px] text-slate-300 focus:outline-none">
            <button onclick="copyRefLink()" class="bg-slate-800 text-white w-10 h-10 rounded-lg flex items-center justify-center active:scale-95 transition-transform"><i class="fa-regular fa-copy text-sm"></i></button>
          </div>
        </div>
        <button onclick="shareReferralTelegram()" class="w-full py-3 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-lg text-xs uppercase tracking-wider flex items-center justify-center gap-2"><i class="fa-brands fa-telegram text-base"></i> Share via Telegram</button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2"><i class="fa-solid fa-users text-slate-600"></i> Your Referrals</h3>
        <div id="referral-list-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- BOX PAGE -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-4"><h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Box</h2><p class="text-[11px] text-amber-400 mt-0.5 uppercase tracking-widest font-bold">Try Your Luck, Win USDT</p></div>
      
      <div class="box-bronze glass-card rounded-[1.25rem] p-4 relative overflow-hidden flex justify-between items-center transition-transform hover:scale-[1.02]">
        <div class="flex items-center gap-3 relative z-10"><div class="w-12 h-12 rounded-xl bg-gradient-to-br from-orange-900 to-[#050511] border border-crypto-bronze flex items-center justify-center"><i class="fa-solid fa-box text-2xl text-crypto-bronze"></i></div><div><h3 class="text-lg font-black text-white">Bronze Box</h3><div class="flex flex-col mt-0.5"><span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase flex items-center"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 10,000 XP</span></div></div></div>
        <button onclick="openBox('bronze')" class="relative z-10 bg-gradient-to-b from-orange-600 to-orange-800 text-white shadow-md active:scale-95 transition-all px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>
      <div class="box-silver glass-card rounded-[1.25rem] p-4 relative overflow-hidden flex justify-between items-center transition-transform hover:scale-[1.02]">
        <div class="flex items-center gap-3 relative z-10"><div class="w-12 h-12 rounded-xl bg-gradient-to-br from-slate-600 to-[#050511] border border-crypto-silver flex items-center justify-center"><i class="fa-solid fa-box-open text-2xl text-crypto-silver"></i></div><div><h3 class="text-lg font-black text-white">Silver Box</h3><div class="flex flex-col mt-0.5"><span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase flex items-center"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 50,000 XP</span></div></div></div>
        <button onclick="openBox('silver')" class="relative z-10 bg-gradient-to-b from-slate-300 to-slate-500 text-crypto-dark shadow-md active:scale-95 transition-all px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>
      <div class="box-gold glass-card rounded-[1.25rem] p-4 relative overflow-hidden flex justify-between items-center border border-crypto-gold transition-transform hover:scale-[1.02]">
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-28 h-28 bg-crypto-gold/20 rounded-full blur-xl animate-pulse"></div>
        <div class="flex items-center gap-3 relative z-10"><div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500 to-[#050511] border border-crypto-gold flex items-center justify-center"><i class="fa-solid fa-gem text-2xl text-crypto-gold"></i></div><div><h3 class="text-lg font-black text-crypto-gold">Gold Box</h3><div class="flex flex-col mt-0.5"><span class="text-[10px] text-amber-200/80 font-bold tracking-wider uppercase flex items-center"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 100,000 XP</span></div></div></div>
        <button onclick="openBox('gold')" class="relative z-10 bg-gradient-to-b from-yellow-400 to-amber-600 text-crypto-dark shadow-lg active:scale-95 transition-all px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>
    </div>

    <!-- WALLET PAGE -->
    <div id="view-wallet" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1"><h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Wallet</h2><p class="text-[11px] text-emerald-400 mt-0.5 uppercase tracking-widest font-bold">USDT Withdrawal on TON</p></div>
      <div class="glass-card rounded-[1.5rem] p-5 text-center border-t border-emerald-500/30 bg-gradient-to-b from-emerald-900/20 to-[#050511]"><p class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-1">Available Balance</p><h1 class="text-4xl font-black text-white mb-3">$<span id="withdraw-balance-display">0</span></h1></div>
      <div class="glass-card rounded-[1.25rem] p-4 space-y-4 border border-slate-700/50">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-1.5 ml-1">TON Wallet Address <span class="text-red-400">*</span></label>
          <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511]/70 border border-slate-700/60 rounded-lg py-2.5 px-3 text-xs text-white focus:outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-1.5 ml-1">Amount (USDT) <span class="text-red-400">*</span></label>
          <input type="number" id="withdraw-amount" placeholder="10" min="10" step="0.5" class="w-full bg-[#050511]/70 border border-slate-700/60 rounded-lg py-2.5 px-3 text-xs font-bold text-emerald-400 focus:outline-none focus:border-emerald-500">
        </div>
        <button onclick="requestWithdrawal()" id="withdraw-btn" class="w-full py-3 bg-gradient-to-r from-emerald-600 to-teal-500 text-white font-black rounded-lg text-xs uppercase flex items-center justify-center gap-2"><i class="fa-solid fa-money-bill-transfer"></i> Request Withdrawal</button>
      </div>
      <div class="mt-6"><h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3"><i class="fa-solid fa-clock-rotate-left"></i> History</h3><div id="withdraw-history-container" class="space-y-2.5"></div></div>
    </div>

    <!-- PROFILE PAGE -->
    <div id="view-profile" class="view-section hidden fade-in space-y-5">
      <div class="glass-card rounded-[1.5rem] p-5 flex flex-col items-center justify-center border-t border-t-blue-500/30 shadow-lg relative overflow-hidden">
        <button id="admin-secret-btn" onclick="openAdminAuth()" class="hidden absolute top-4 right-4 w-8 h-8 rounded-full bg-red-600/20 text-red-500 border border-red-500/50 flex items-center justify-center shadow-[0_0_15px_rgba(239,68,68,0.4)] hover:scale-110"><i class="fa-solid fa-shield text-sm"></i></button>
        <div class="relative z-10 w-20 h-20 rounded-full p-1 bg-gradient-to-tr from-blue-500 via-crypto-glow to-purple-500 mb-3"><img id="profile-page-avatar" src="" alt="Avatar" class="w-full h-full rounded-full object-cover border-[3px] border-[#050511]"></div>
        <h2 id="profile-page-name" class="text-xl font-black text-white tracking-wide mb-0.5 break-words text-center">Name</h2>
        <p id="profile-page-username" class="text-[11px] font-mono text-blue-400 mb-2.5 bg-blue-500/10 px-2.5 py-0.5 rounded-full border border-blue-500/20">@username</p>
        <div class="flex items-center gap-1.5 bg-[#050511]/60 px-3 py-1.5 rounded-lg border border-slate-700/50"><i class="fa-brands fa-telegram text-slate-400 text-xs"></i><span class="text-[9px] text-slate-400 uppercase font-bold tracking-widest">ID: <span id="profile-page-id" class="text-white ml-1">0000000</span></span></div>
      </div>
      <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-1.5 mt-5"><i class="fa-solid fa-chart-pie"></i> Account Stats</h3>
      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase mb-0.5">Total XP</p><p id="profile-stat-xp" class="text-lg font-black text-white">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase mb-0.5">Total USD</p><p id="profile-stat-usd" class="text-lg font-black text-emerald-400">$0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase mb-0.5">Referrals</p><p id="profile-stat-refs" class="text-lg font-black text-white">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase mb-0.5">Tasks Done</p><p id="profile-stat-tasks" class="text-lg font-black text-white">0</p></div>
      </div>
    </div>

    <!-- ADMIN PANEL (AZ) -->
    <div id="view-admin" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-2"><h2 class="text-2xl font-black text-red-500 drop-shadow-lg flex items-center justify-center gap-2"><i class="fa-solid fa-shield-halved"></i> İDARƏ PANELİ</h2></div>
      
      <div class="flex flex-wrap gap-1 mb-2 bg-[#050511] p-1 rounded-lg border border-slate-700/50">
          <button onclick="switchAdminTab('dashboard')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded bg-red-600/20 text-red-400 border border-red-500/50" id="tab-dashboard">Əsas</button>
          <button onclick="switchAdminTab('users')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400" id="tab-users">İstifadəçilər</button>
          <button onclick="switchAdminTab('withdrawals')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400" id="tab-withdrawals">Çıxarışlar</button>
          <button onclick="switchAdminTab('tasks')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400" id="tab-tasks">Görevlər</button>
          <button onclick="switchAdminTab('errors')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400" id="tab-errors">Xətalar</button>
          <button onclick="switchAdminTab('settings')" class="admin-tab flex-1 min-w-[70px] py-2 text-[9px] font-black uppercase rounded text-slate-400" id="tab-settings">Ayarlar</button>
      </div>

      <!-- Admin: Dashboard -->
      <div id="admin-sec-dashboard" class="admin-section space-y-3">
          <div class="grid grid-cols-2 gap-2">
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Ümumi İstif.</p><p id="adm-stat-users" class="text-lg font-black text-blue-400">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Cəmi USD</p><p id="adm-stat-usd" class="text-lg font-black text-emerald-400">$0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">İzlənən Reklam</p><p id="adm-stat-ads" class="text-lg font-black text-white">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Görevlər</p><p id="adm-stat-tasks" class="text-lg font-black text-white">0</p></div>
          </div>
          <div class="flex gap-2 mt-4">
              <button onclick="adminGlobalReset('ads')" class="flex-1 py-2 bg-blue-600/20 text-blue-400 border border-blue-500/50 rounded-lg text-[10px] font-black uppercase">Bütün Reklamları Sıfırla</button>
              <button onclick="adminGlobalReset('balances')" class="flex-1 py-2 bg-red-600/20 text-red-500 border border-red-500/50 rounded-lg text-[10px] font-black uppercase">Bütün Balansları Sil</button>
          </div>
      </div>

      <!-- Admin: Users -->
      <div id="admin-sec-users" class="admin-section hidden space-y-3">
          <input type="text" id="admin-user-search" onkeyup="renderAdminUsers()" placeholder="UID və ya Username axtar..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white focus:border-blue-500 outline-none">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-user-list"></div>
      </div>

      <!-- Admin: Withdrawals -->
      <div id="admin-sec-withdrawals" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-withdrawal-list"></div>
      </div>

      <!-- Admin: Tasks -->
      <div id="admin-sec-tasks" class="admin-section hidden space-y-3">
          <div class="glass-card p-3 rounded-xl border border-slate-700 space-y-2">
              <h3 class="text-[11px] font-black text-white uppercase mb-2">Yeni Görev Əlavə Et</h3>
              <input type="text" id="adm-t-title" placeholder="Görev Açıqlaması (Məs: AZX Crypto)" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              <select id="adm-t-type" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white"><option value="sponsor">Sponsor</option><option value="normal">Normal Task</option></select>
              <select id="adm-t-rewType" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white"><option value="xp">XP</option><option value="usdt">USDT</option></select>
              <input type="number" id="adm-t-amount" placeholder="Mükafat (Məs: 200)" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              <input type="text" id="adm-t-link" placeholder="Görev Linki (Məs: tg://resolve?domain=...)" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              <div class="flex items-center gap-2">
                  <input type="checkbox" id="adm-t-reqUid" onchange="document.getElementById('adm-t-uidPrompt').style.display = this.checked ? 'block' : 'none'" class="w-4 h-4">
                  <label class="text-[10px] text-slate-300">İstifadəçidən Məlumat İstə (Məs: UID)</label>
              </div>
              <input type="text" id="adm-t-uidPrompt" placeholder="Açıqlama yaz (Məs: Qeydiyyatdan sonra UID yaz)" style="display:none;" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              <input type="number" id="adm-t-limit" placeholder="Limit (Neçə nəfər etsin? 0=Limitsiz)" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white" value="0">
              <button onclick="adminAddTask()" class="w-full py-2 bg-blue-600 text-white rounded text-[10px] font-black uppercase">Əlavə Et</button>
          </div>
          <div id="admin-task-list" class="space-y-2 mt-4"></div>
      </div>

      <!-- Admin: Errors -->
      <div id="admin-sec-errors" class="admin-section hidden space-y-3">
          <p class="text-[10px] text-slate-400 mb-2">İstifadəçilərin qarşılaşdığı gizli xətalar</p>
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-error-list"></div>
      </div>

      <!-- Admin: Settings -->
      <div id="admin-sec-settings" class="admin-section hidden space-y-3">
          <div class="glass-card rounded-xl p-4 border border-slate-700 space-y-3">
              <div>
                  <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Adsgram Block IDs (Vergül ilə)</label>
                  <textarea id="adm-s-sdk" rows="2" class="w-full bg-[#050511] border border-slate-700 rounded-lg p-2 text-xs text-white outline-none"></textarea>
              </div>
              <div class="flex gap-2">
                  <div class="flex-1"><label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Günlük Reklam Limiti</label><input type="number" id="adm-s-adlimit" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white"></div>
                  <div class="flex-1"><label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Sıfırlanma Saatı</label><input type="number" id="adm-s-resethour" min="0" max="23" class="w-full bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white"></div>
              </div>
              <div class="flex items-center justify-between bg-[#050511] p-2 rounded border border-slate-700">
                  <span class="text-[11px] font-black text-white">2X XP Aktivləşdirmə</span><input type="checkbox" id="adm-s-2x" class="w-5 h-5">
              </div>
              <div class="flex items-center justify-between bg-[#050511] p-2 rounded border border-red-500/30">
                  <span class="text-[11px] font-black text-red-400">Bakım Modu (Maintenance)</span><input type="checkbox" id="adm-s-maint" class="w-5 h-5">
              </div>
              <button onclick="saveAdminSettings()" class="w-full py-2 bg-blue-600 text-white rounded text-xs font-black uppercase tracking-wider">Ayarları Yadda Saxla</button>
          </div>
          <div class="glass-card rounded-xl p-4 border border-slate-700 space-y-2 mt-4">
              <h3 class="text-[11px] font-black text-white uppercase">Admin Əlavə Et / İstifadəçi Blokla</h3>
              <div class="flex gap-2">
                  <input type="text" id="adm-new-uid" placeholder="UID Yazın" class="flex-1 bg-[#050511] border border-slate-700 rounded p-2 text-xs text-white">
              </div>
              <div class="flex gap-2">
                  <button onclick="adminAddAdmin()" class="flex-1 py-2 bg-emerald-600/30 text-emerald-400 border border-emerald-500/50 rounded text-[9px] font-black uppercase">Admin Et</button>
                  <button onclick="adminBanUid()" class="flex-1 py-2 bg-red-600/30 text-red-500 border border-red-500/50 rounded text-[9px] font-black uppercase">Qatılmasını ÖnLƏ (Ban)</button>
              </div>
          </div>
      </div>
    </div>
  </main>

  <nav id="bottom-nav" class="fixed bottom-3 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50">
    <div class="flex justify-between items-center px-1 py-2">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center flex-1 transition-all" data-target="home"><i class="fa-solid fa-house text-base"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Home</span></button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center flex-1 transition-all" data-target="tasks"><i class="fa-solid fa-list-check text-base"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Tasks</span></button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center flex-1 transition-all" data-target="referrals"><i class="fa-solid fa-users text-base"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Referans</span></button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center flex-1 transition-all" data-target="boxes"><i class="fa-solid fa-box-open text-base"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Box</span></button>
      <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center flex-1 transition-all" data-target="wallet"><i class="fa-solid fa-wallet text-base"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Wallet</span></button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center flex-1 transition-all" data-target="profile"><i class="fa-solid fa-user text-base"></i><span class="text-[7px] font-black uppercase tracking-widest mt-0.5">Profile</span></button>
    </div>
  </nav>

  <div id="admin-auth-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4">
    <div class="glass-card w-full max-w-[280px] rounded-[1.5rem] p-5 relative border border-red-500/50">
      <button onclick="closeAdminAuth()" class="absolute top-3 right-3 text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
      <h3 class="text-lg font-black text-white text-center mb-4"><i class="fa-solid fa-lock text-red-500"></i> Admin Girişi</h3>
      <input type="password" id="admin-code-input" placeholder="Şifrə" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-sm text-center text-white mb-4">
      <button onclick="submitAdminAuth()" class="w-full py-2.5 bg-red-600 text-white font-black rounded-lg text-xs uppercase">Daxil Ol</button>
    </div>
  </div>
  
  <div id="custom-task-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 z-[999999]">
      <div class="glass-card w-full max-w-sm rounded-[1.5rem] p-5 relative border border-blue-500/50">
          <button onclick="document.getElementById('custom-task-modal').classList.add('hidden')" class="absolute top-3 right-3 text-slate-400"><i class="fa-solid fa-xmark text-lg"></i></button>
          <h3 id="c-task-title" class="text-lg font-black text-white mb-2 text-center">Görəv Təsdiqi</h3>
          <p id="c-task-prompt" class="text-[11px] text-slate-400 text-center mb-4">Please provide the required info.</p>
          <input type="text" id="c-task-input" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-sm text-white focus:border-blue-500 outline-none mb-4" placeholder="Enter here...">
          <button id="c-task-submit" class="w-full py-2.5 bg-blue-600 text-white font-black rounded-lg text-xs uppercase">Submit & Claim</button>
      </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand(); tg.ready(); tg.setHeaderColor('#0a0b1a'); tg.setBackgroundColor('#050511');

    const tgUser = tg.initDataUnsafe?.user || { id: "5461064199", first_name: "Admin", last_name: "Demo", username: "admin", photo_url: "" };
    const startParam = tg.initDataUnsafe?.start_param || null;

    let appState = { user: {}, referrals: [], rewards: [], withdrawals: [], tasks: [], customTasksData: [], settings: {} };
    let adminToken = ''; let adminData = {};

    function formatNum(num, isMoney = false) {
        if (!num) return isMoney ? "0.00" : "0";
        let val = Number(num);
        return isMoney ? val.toFixed(2) : val.toLocaleString();
    }

    async function apiCall(action, payload = {}) {
      try {
        const body = { action, tgId: tgUser.id, firstName: tgUser.first_name, lastName: tgUser.last_name, username: tgUser.username, photoUrl: tgUser.photo_url, referrer: startParam, ...payload };
        const res = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const data = await res.json();
        
        if (data.error) {
            if (data.error === 'BANNED') { document.getElementById('banned-msg').innerText = data.message; document.getElementById('banned-screen').classList.remove('hidden'); return false; }
            if (data.error === 'MAINTENANCE') { document.getElementById('maintenance-screen').classList.remove('hidden'); return false; }
            if (data.error === 'FRIENDLY') { showToast("Notice", data.message, "warning"); return false; }
            return false;
        }

        if(data.user) appState.user = data.user;
        if(data.referrals) appState.referrals = data.referrals;
        if(data.rewards) appState.rewards = data.rewards;
        if(data.withdrawals) appState.withdrawals = data.withdrawals;
        if(data.tasks) appState.tasks = data.tasks;
        if(data.settings) appState.settings = data.settings;
        if(data.customTasksData) appState.customTasksData = data.customTasksData;

        if (action !== 'sync' && !action.startsWith('admin_')) updateUI();
        return data;
      } catch (err) { return false; }
    }

    function showToast(title, message, type = 'info') {
      const toast = document.getElementById('toast-container'); const icon = document.getElementById('toast-icon');
      document.getElementById('toast-title').innerText = title; document.getElementById('toast-message').innerText = message;
      let iconClass, iconHtml;
      if (type === 'success') { iconHtml = '<i class="fa-solid fa-check"></i>'; iconClass = 'bg-emerald-500/20 text-emerald-400'; } 
      else if (type === 'warning') { iconHtml = '<i class="fa-solid fa-circle-exclamation"></i>'; iconClass = 'bg-amber-500/20 text-amber-400'; }
      else if (type === 'jackpot') { iconHtml = '<i class="fa-solid fa-sack-dollar animate-bounce"></i>'; iconClass = 'bg-amber-500/20 text-amber-400 border border-amber-500/50'; } 
      else { iconHtml = '<i class="fa-solid fa-bell"></i>'; iconClass = 'bg-blue-500/20 text-crypto-glow'; }
      icon.innerHTML = iconHtml; icon.className = `w-10 h-10 rounded-lg flex shrink-0 items-center justify-center text-base ${iconClass}`;
      toast.classList.add('toast-show');
      if (tg.HapticFeedback) tg.HapticFeedback.notificationOccurred(type === 'jackpot' ? 'success' : (type === 'warning' ? 'error' : 'success'));
      setTimeout(() => toast.classList.remove('toast-show'), 3000); 
    }

    function updateUI() {
      const u = appState.user; const s = appState.settings;
      const fullName = [u.firstName, u.lastName].filter(Boolean).join(' ') || 'User';
      document.getElementById('user-name').innerText = fullName;
      document.getElementById('user-xp').innerHTML = `${formatNum(u.xp)} <span class="text-[9px] text-crypto-glow font-bold">XP</span>`;
      document.getElementById('user-usd').innerText = formatNum(u.usd, true);
      const av = u.photoUrl || `https://ui-avatars.com/api/?name=${encodeURIComponent(fullName)}&background=0a0b1a&color=00f0ff`;
      document.getElementById('user-photo').src = av; document.getElementById('profile-page-avatar').src = av;
      document.getElementById('profile-page-name').innerText = fullName;
      document.getElementById('profile-page-username').innerText = u.username ? `@${u.username}` : '';
      document.getElementById('profile-page-id').innerText = u.tgId;
      document.getElementById('profile-stat-xp').innerText = formatNum(u.totalXp);
      document.getElementById('profile-stat-usd').innerText = `$${formatNum(u.totalUsd, true)}`;
      document.getElementById('profile-stat-refs').innerText = appState.referrals.length;
      document.getElementById('profile-stat-tasks').innerText = u.tasksCompleted;

      if ((s.admins || []).includes(u.tgId)) document.getElementById('admin-secret-btn').classList.remove('hidden');

      document.getElementById('main-xp-display').innerText = `${formatNum(u.xp)} XP`;
      document.getElementById('ads-watched').innerText = u.adsWatchedToday;
      document.getElementById('ads-max').innerText = s.dailyAdLimit || 30;
      document.getElementById('streak-days').innerText = u.streak;
      if (s.doubleXp) document.getElementById('watch-ad-text').innerHTML = `Watch Ad <span class="text-amber-400 ml-1 font-bold">+40 XP (2X)</span>`;
      else document.getElementById('watch-ad-text').innerHTML = `Watch Ad <span class="text-cyan-200 ml-1">+20 XP</span>`;

      document.getElementById('ref-total').innerText = appState.referrals.length;
      document.getElementById('ref-pending').innerText = appState.referrals.filter(r => r.status === 'Pending').length;
      document.getElementById('ref-approved').innerText = appState.referrals.filter(r => r.status === 'Approved').length;
      document.getElementById('ref-link-input').value = `https://t.me/XPVersebot?startapp=${u.tgId}`;
      document.getElementById('withdraw-balance-display').innerText = formatNum(u.usd, true);
      
      renderTasks();
      const hw = document.getElementById('withdraw-history-container');
      hw.innerHTML = appState.withdrawals.length ? appState.withdrawals.map(r => `<div class="glass-card p-3 rounded-xl border border-slate-800 flex justify-between items-center"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded-full border border-slate-700 flex items-center justify-center"><i class="fa-solid fa-arrow-right-arrow-left text-slate-400 text-xs"></i></div><div><p class="text-xs font-black text-white">${r.id}</p><p class="text-[9px] text-blue-400 font-mono">${r.address}</p></div></div><div class="text-right"><p class="text-xs font-black text-emerald-400">-$${formatNum(r.amount, true)}</p><p class="text-[8px] font-black ${r.status==='Approved'?'text-emerald-400':(r.status==='Rejected'?'text-red-400':'text-amber-400')} uppercase mt-1">${r.status}</p></div></div>`).join('') : '<p class="text-center text-[10px] text-slate-500 mt-4">No history</p>';
      
      const hr = document.getElementById('referral-list-container');
      hr.innerHTML = appState.referrals.length ? appState.referrals.map(r => `<div class="glass-card p-3 rounded-xl border border-slate-800 flex justify-between items-center"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center font-black text-white text-xs">${r.name.charAt(0)}</div><p class="text-xs font-black text-white">${r.name}</p></div><span class="text-[8px] font-black uppercase px-2 py-1 rounded border ${r.status==='Approved'?'border-emerald-500/30 text-emerald-400':'border-amber-500/30 text-amber-400'}">${r.status}</span></div>`).join('') : '<p class="text-center text-[10px] text-slate-500 mt-4">No referrals yet</p>';
    }

    function renderTasks() {
      const s = appState.settings; const mult = s.doubleXp ? 2 : 1;
      const spContainer = document.getElementById('sponsor-container'); spContainer.innerHTML = '';
      
      // Dynamic Custom Tasks
      if(appState.customTasksData) {
          appState.customTasksData.forEach(t => {
              const claimed = !!appState.user.customTasks?.[t.id];
              let limitTxt = t.limit > 0 ? `<span class="text-[8px] text-slate-400">(${t.completions}/${t.limit})</span>` : '';
              let rewTxt = t.rewardType === 'usdt' ? `+$${t.rewardAmount * mult} USDT` : `+${t.rewardAmount * mult} XP`;
              if (claimed) {
                  spContainer.innerHTML += `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-emerald-500/30"><div class="flex items-center gap-2.5"><div class="w-8 h-8 rounded bg-emerald-500/10 flex items-center justify-center"><i class="fa-solid fa-check text-emerald-400"></i></div><div><span class="text-xs font-black text-white">${t.title}</span><p class="text-[9px] text-emerald-400 uppercase mt-0.5">Completed</p></div></div></div>`;
              } else {
                  spContainer.innerHTML += `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-blue-500/30"><div class="flex items-center gap-2.5"><div class="w-8 h-8 rounded bg-blue-500/20 flex items-center justify-center"><i class="fa-solid fa-star text-blue-400"></i></div><div><span class="text-xs font-black text-white">${t.title} ${limitTxt}</span><p class="text-[9px] text-crypto-glow uppercase font-bold mt-0.5">${rewTxt}</p></div></div><button onclick="handleCustomTask('${t.id}', '${t.link}', ${t.requireUid}, '${t.uidPrompt}')" class="text-[9px] font-black bg-blue-600 text-white px-3 py-1.5 rounded shadow active:scale-95 uppercase">Do Task</button></div>`;
              }
          });
      }
      
      // Core Daily Missions
      const mContainer = document.getElementById('missions-container'); mContainer.innerHTML = '';
      const missions = [
        { id: 'watch5', label: 'Watch 5 Ads', icon: 'fa-video', target: 5, r: 20 },
        { id: 'watch15', label: 'Watch 15 Ads', icon: 'fa-film', target: 15, r: 40 },
        { id: 'watch30', label: `Watch ${s.dailyAdLimit||30} Ads`, icon: 'fa-clapperboard', target: s.dailyAdLimit||30, r: 80 },
        { id: 'complete_all', label: 'Complete All Tasks', icon: 'fa-check-to-slot', target: s.dailyAdLimit||30, r: 100 }
      ];
      missions.forEach(m => {
        const claimed = appState.tasks.includes(m.id); const curr = appState.user.adsWatchedToday; const can = !claimed && curr >= m.target;
        let btn = claimed ? `<span class="text-[9px] font-black text-emerald-400"><i class="fa-solid fa-check-double"></i></span>` : (can ? `<button onclick="apiCall('claim_task', {taskId:'${m.id}', reward:${m.r}})" class="text-[9px] font-black bg-cyan-500 text-white px-2 py-1 rounded">Claim</button>` : `<span class="text-[9px] text-slate-500">+${m.r*mult}XP</span>`);
        mContainer.innerHTML += `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded border border-slate-700 flex items-center justify-center"><i class="fa-solid ${m.icon} text-slate-400"></i></div><div><span class="text-xs font-black text-white">${m.label}</span><p class="text-[9px] text-crypto-glow mt-0.5">Prog: ${Math.min(curr, m.target)}/${m.target}</p></div></div>${btn}</div>`;
      });
      
      // Daily Login logic
      const streak = appState.user.streak || 1; const claimedDL = appState.tasks.includes('dailyLogin');
      const dlContainer = document.getElementById('streak-tracker-container'); dlContainer.innerHTML = '';
      for (let i = 1; i <= 7; i++) {
        let isPast = i < streak || (i===streak && claimedDL); let isT = i === streak && !claimedDL;
        let cls = isPast ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500' : (isT ? 'bg-blue-600/30 text-white border border-blue-400' : 'bg-[#050511] text-slate-600 border border-slate-700');
        dlContainer.innerHTML += `<div class="flex flex-col items-center flex-1"><div class="w-8 h-8 rounded-lg flex items-center justify-center text-[10px] font-black ${cls}">${isPast?'<i class="fa-solid fa-check"></i>':i}</div><span class="text-[8px] font-bold mt-1 text-slate-500">D${i}</span></div>`;
      }
      const dlBtn = document.getElementById('daily-login-btn-container');
      dlBtn.innerHTML = claimedDL ? `<span class="text-[10px] font-black text-emerald-400">Claimed</span>` : `<button onclick="apiCall('claim_task', {taskId:'dailyLogin', reward:10})" class="bg-blue-500 text-white px-3 py-1.5 rounded text-[10px] font-black uppercase">Claim</button>`;
    }

    let activeCustomTaskId = null;
    function handleCustomTask(id, link, reqUid, promptText) {
        if(link) tg.openTelegramLink(link);
        if(reqUid) {
            activeCustomTaskId = id;
            document.getElementById('c-task-prompt').innerText = promptText;
            document.getElementById('c-task-input').value = '';
            document.getElementById('custom-task-modal').classList.remove('hidden');
            document.getElementById('custom-task-modal').classList.add('flex');
        } else {
            setTimeout(() => apiCall('claim_custom_task', {taskId: id, taskData: true}), 2000);
        }
    }
    document.getElementById('c-task-submit').addEventListener('click', () => {
        const val = document.getElementById('c-task-input').value.trim();
        if(!val) return showToast('Error', 'Məlumat daxil edin', 'warning');
        document.getElementById('custom-task-modal').classList.add('hidden');
        apiCall('claim_custom_task', {taskId: activeCustomTaskId, taskData: val});
    });

    async function watchAd() {
      const btn = document.getElementById('watch-ad-btn'); const orig = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Loading...`; btn.style.pointerEvents = 'none';
      const blocks = appState.settings.adBlockIds || ["int-35545"]; let shown = false;
      for(let i=0; i<blocks.length; i++) {
        if(!blocks[i].trim()) continue;
        try { if(window.Adsgram) { const ac = window.Adsgram.init({blockId: blocks[i].trim()}); await ac.show(); shown = true; break; } } 
        catch(e) { console.warn('Ad failed', e); }
      }
      if(shown) { const res = await apiCall('watch_ad'); if(res && res.earned) showToast('Reward', `Earned +${res.earned} XP.`, 'success'); }
      else showToast('No Ads', 'No ads available. Try again later.', 'warning');
      btn.innerHTML = orig; btn.style.pointerEvents = 'auto';
    }

    async function openBox(type) {
      if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('heavy');
      const res = await apiCall('open_box', { boxType: type });
      if(res && !res.error) {
        if(res.jackpot) showToast('JACKPOT!', `Won $${res.reward.toFixed(2)} USDT!`, 'jackpot');
        else showToast('Box Opened!', `Won $${res.reward.toFixed(2)} USDT!`, 'success');
      }
    }

    async function requestWithdrawal() {
      const address = document.getElementById('wallet-address').value; const amount = parseFloat(document.getElementById('withdraw-amount').value);
      if (!address || address.length < 10) return showToast('Invalid Address', 'Enter valid TON wallet.', 'warning');
      if (isNaN(amount) || amount < 10) return showToast('Invalid Amount', 'Min $10.', 'warning');
      if (amount > appState.user.usd) return showToast('Insufficient', 'Not enough USDT.', 'warning');
      if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
      const res = await apiCall('withdraw', { amount, address });
      if(res && !res.error) { showToast('Success', 'Withdrawal requested.', 'success'); document.getElementById('wallet-address').value = ''; document.getElementById('withdraw-amount').value = ''; }
    }

    function copyRefLink() { navigator.clipboard.writeText(document.getElementById('ref-link-input').value).then(()=>showToast("Copied", "Link copied", "success")); }
    function shareReferralTelegram() { tg.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(`https://t.me/XPVersebot?startapp=${appState.user.tgId}`)}&text=${encodeURIComponent(`Earn USDT on TON! Join XPVerse now 🚀`)}`); }

    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => { el.classList.add('hidden'); el.classList.remove('animate-slide-up'); });
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));
      const tgt = document.getElementById(`view-${tabId}`); tgt.classList.remove('hidden'); tgt.classList.add('animate-slide-up');
      const tabTarget = document.querySelector(`[data-target="${tabId}"]`); if(tabTarget) tabTarget.classList.add('nav-active');
      if(tg.HapticFeedback) tg.HapticFeedback.selectionChanged(); window.scrollTo(0,0);
    }

    // SERVER-SYNCED TIMER & SILENT POLL
    let timeRem = 0; let tInt = null;
    function startTimer(curr, reset) {
        if(tInt) clearInterval(tInt); timeRem = reset - curr;
        tInt = setInterval(() => {
            if(timeRem > 0) timeRem--;
            if(timeRem <= 0) { document.getElementById('reset-timer').innerText="00:00:00"; setTimeout(()=>window.location.reload(), 2000); return; }
            const h = Math.floor(timeRem/3600); const m = Math.floor((timeRem%3600)/60); const s = Math.floor(timeRem%60);
            document.getElementById('reset-timer').innerText = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
        }, 1000);
    }
    
    // Silent auto-refresh for realtime balance updates
    setInterval(() => {
        if(document.getElementById('loading-overlay').style.display !== 'none') return;
        apiCall('sync');
    }, 15000);
    
    async function initApp() {
      const res = await apiCall('init');
      if(res && res.serverTime) startTimer(res.serverTime, res.serverResetTime);
      setTimeout(() => { document.getElementById('loading-overlay').style.opacity = '0'; setTimeout(()=>document.getElementById('loading-overlay').style.display='none', 500); }, 600);
    }

    // --- ADMIN PANEL AZERBAIJANI LOGIC ---
    function openAdminAuth() {
        if((appState.settings.admins||[]).includes(appState.user.tgId)) { submitAdminAuth(true); return; }
        document.getElementById('admin-auth-modal').classList.remove('hidden'); document.getElementById('admin-auth-modal').classList.add('flex');
    }
    function closeAdminAuth() { document.getElementById('admin-auth-modal').classList.add('hidden'); }
    async function submitAdminAuth(bypass=false) {
        const code = bypass ? '' : document.getElementById('admin-code-input').value; adminToken = code;
        const res = await apiCall('admin_dashboard', { adminCode: code });
        if(res && !res.error) {
            adminData = res; closeAdminAuth(); switchTab('admin'); switchAdminTab('dashboard');
            document.getElementById('adm-stat-users').innerText = res.stats.users;
            document.getElementById('adm-stat-usd').innerText = `$${res.stats.usd.toFixed(2)}`;
            document.getElementById('adm-stat-ads').innerText = res.stats.ads;
            document.getElementById('adm-stat-tasks').innerText = res.stats.tasks;
            
            document.getElementById('adm-s-sdk').value = (appState.settings.adBlockIds || []).join(',');
            document.getElementById('adm-s-adlimit').value = appState.settings.dailyAdLimit;
            document.getElementById('adm-s-resethour').value = appState.settings.resetHour;
            document.getElementById('adm-s-maint').checked = appState.settings.maintenance;
            document.getElementById('adm-s-2x').checked = appState.settings.doubleXp;
            
            renderAdminUsers(); renderAdminWithdrawals(); renderAdminTasks(); renderAdminErrors();
        }
    }
    function switchAdminTab(tab) {
        document.querySelectorAll('.admin-section').forEach(el=>el.classList.add('hidden'));
        document.getElementById(`admin-sec-${tab}`).classList.remove('hidden');
        document.querySelectorAll('.admin-tab').forEach(el=>el.classList.remove('bg-red-600/20','text-red-400','border','border-red-500/50'));
        document.getElementById(`tab-${tab}`).classList.add('bg-red-600/20','text-red-400','border','border-red-500/50');
    }

    function renderAdminUsers() {
        const q = document.getElementById('admin-user-search').value.toLowerCase();
        const list = adminData.all_users.filter(u => u.tgId.includes(q) || (u.username||'').toLowerCase().includes(q));
        document.getElementById('admin-user-list').innerHTML = list.map(u => `
            <div class="glass-card p-3 rounded-lg border border-slate-700 space-y-2">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-xs font-black text-white">${u.firstName} <span class="text-slate-400 font-mono text-[9px]">@${u.username}</span></p>
                        <p class="text-[9px] text-blue-400">ID: ${u.tgId}</p>
                        <p class="text-[9px] text-slate-400">Son görülmə: ${u.lastActiveAzt}</p>
                    </div>
                    <div class="text-right">
                        ${u.banned?'<span class="text-[8px] bg-red-500/20 text-red-500 px-2 py-0.5 rounded border border-red-500/50 mb-1 block">BANNED</span>':''}
                        <span class="text-[8px] px-2 py-0.5 rounded border ${u.onlineStatus==='Aktiv'?'bg-emerald-500/20 text-emerald-400 border-emerald-500/50':'bg-slate-800 text-slate-400 border-slate-700'}">${u.onlineStatus}</span>
                    </div>
                </div>
                <div class="flex gap-2 items-center bg-[#050511] p-2 rounded">
                    <input type="number" id="adm-usd-${u.tgId}" value="${u.usd.toFixed(2)}" class="w-16 bg-transparent text-xs text-emerald-400 outline-none border-b border-slate-600 text-center" title="USD">
                    <input type="number" id="adm-xp-${u.tgId}" value="${u.xp}" class="w-16 bg-transparent text-xs text-crypto-glow outline-none border-b border-slate-600 text-center" title="XP">
                    <button onclick="adminActionUser('${u.tgId}', 'update_balance', {newUsd:document.getElementById('adm-usd-${u.tgId}').value, newXp:document.getElementById('adm-xp-${u.tgId}').value})" class="bg-blue-600/20 text-blue-400 px-2 py-1 rounded text-[10px]">Saxla</button>
                </div>
                <div class="flex gap-2">
                    <button onclick="adminActionUser('${u.tgId}', 'reset_ads')" class="flex-1 bg-amber-500/20 text-amber-500 py-1 rounded text-[9px] font-black">Reklamı Sıfırla (${u.adsWatchedToday})</button>
                    <button onclick="adminActionUser('${u.tgId}', '${u.banned?'unban':'ban'}')" class="flex-1 ${u.banned?'bg-emerald-500/20 text-emerald-500':'bg-red-500/20 text-red-500'} py-1 rounded text-[9px] font-black">${u.banned?'Banı Aç':'Ban Et'}</button>
                </div>
            </div>
        `).join('');
    }
    
    function renderAdminWithdrawals() {
        const c = document.getElementById('admin-withdrawal-list');
        c.innerHTML = adminData.all_withdrawals.map(w => `<div class="glass-card p-2 rounded border border-slate-700 flex flex-col gap-2"><div class="flex justify-between items-center"><span class="text-[10px] text-white">ID: ${w.user_id}</span><span class="text-[10px] font-black text-emerald-400">$${w.amount}</span></div><p class="text-[9px] text-blue-400 font-mono bg-[#050511] p-1 rounded">${w.address}</p><div class="flex gap-2">${w.status==='Pending'?`<button onclick="apiCall('admin_action_withdraw',{adminCode:adminToken, targetUid:'${w.user_id}', idx:${w.idx}, withdrawAction:'approve'}).then(()=>submitAdminAuth(true))" class="flex-1 bg-emerald-600 text-white text-[9px] py-1 rounded">Təsdiqlə</button><button onclick="apiCall('admin_action_withdraw',{adminCode:adminToken, targetUid:'${w.user_id}', idx:${w.idx}, withdrawAction:'reject'}).then(()=>submitAdminAuth(true))" class="flex-1 bg-red-600 text-white text-[9px] py-1 rounded">Rədd Et</button>`:`<span class="w-full text-center text-[10px] text-slate-400">${w.status}</span>`}</div></div>`).join('');
    }

    function renderAdminTasks() {
        const c = document.getElementById('admin-task-list');
        c.innerHTML = appState.customTasksData.map(t => `<div class="glass-card p-3 rounded border border-slate-700 flex justify-between items-center"><div><p class="text-xs font-black text-white">${t.title} <span class="text-[9px] text-slate-400">(${t.completions}/${t.limit>0?t.limit:'∞'})</span></p><p class="text-[9px] text-crypto-glow mt-0.5">Mükafat: ${t.rewardAmount} ${t.rewardType.toUpperCase()}</p></div><button onclick="apiCall('admin_task_delete',{adminCode:adminToken, taskId:'${t.id}'}).then(()=>submitAdminAuth(true))" class="bg-red-500/20 text-red-500 w-8 h-8 rounded flex items-center justify-center"><i class="fa-solid fa-trash text-xs"></i></button></div>`).join('');
    }
    
    function renderAdminErrors() {
        const errs = adminData.all_errors || [];
        document.getElementById('admin-error-list').innerHTML = errs.map(e => `<div class="bg-[#050511] border border-red-500/30 p-2 rounded mb-1"><p class="text-[9px] text-red-400 font-black">${e.type} <span class="text-slate-500 font-normal float-right">${e.date}</span></p><p class="text-[10px] text-white mt-1">UID: ${e.uid}</p><p class="text-[10px] text-slate-400 mt-0.5">${e.reason}</p></div>`).join('');
    }

    async function adminActionUser(targetUid, userAction, extras = {}) {
        await apiCall('admin_action_user', { adminCode: adminToken, targetUid, userAction, ...extras });
        submitAdminAuth(true); // Refresh silently
    }
    async function adminGlobalReset(type) {
        if(confirm(type==='ads'?"Bütün istifadəçilərin günlük reklam limiti sıfırlansın?":"DİQQƏT: Bütün istifadəçilərin balansı SİLİNSİN?")) {
            await apiCall('admin_global_reset', { adminCode: adminToken, resetType: type }); submitAdminAuth(true);
        }
    }
    async function saveAdminSettings() {
        const blockIds = document.getElementById('adm-s-sdk').value.split(',').map(s=>s.trim()).filter(Boolean);
        const dailyAdLimit = document.getElementById('adm-s-adlimit').value;
        const resetHour = document.getElementById('adm-s-resethour').value;
        const maintenance = document.getElementById('adm-s-maint').checked;
        const doubleXp = document.getElementById('adm-s-2x').checked;
        await apiCall('admin_update_settings', { adminCode: adminToken, blockIds, dailyAdLimit, resetHour, maintenance, doubleXp });
        showToast('Ayarlar', 'Yadda saxlanıldı', 'success');
    }
    async function adminAddTask() {
        const title = document.getElementById('adm-t-title').value;
        const type = document.getElementById('adm-t-type').value;
        const rewardType = document.getElementById('adm-t-rewType').value;
        const rewardAmount = document.getElementById('adm-t-amount').value;
        const link = document.getElementById('adm-t-link').value;
        const requireUid = document.getElementById('adm-t-reqUid').checked;
        const uidPrompt = document.getElementById('adm-t-uidPrompt').value;
        const limit = document.getElementById('adm-t-limit').value;
        if(!title || !rewardAmount) return showToast('Xəta', 'Başlıq və mükafat mütləqdir', 'warning');
        await apiCall('admin_task_add', { adminCode: adminToken, title, type, rewardType, rewardAmount, link, requireUid, uidPrompt, limit });
        submitAdminAuth(true); showToast('Uğurlu', 'Görev əlavə edildi', 'success');
    }
    async function adminAddAdmin() {
        const uid = document.getElementById('adm-new-uid').value.trim(); if(!uid) return;
        await apiCall('admin_add_admin', { adminCode: adminToken, newAdminId: uid }); showToast('Admin', 'Əlavə edildi', 'success');
    }
    async function adminBanUid() {
        const uid = document.getElementById('adm-new-uid').value.trim(); if(!uid) return;
        await apiCall('admin_ban_uid', { adminCode: adminToken, banUid: uid }); showToast('Ban', 'İstifadəçi bloklandı', 'success');
    }

    initApp();
  </script>
</body>
</html>
