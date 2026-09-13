<?php
// XPVERSE - SINGLE FILE ARCHITECTURE (ADVANCED EDITION)
// All server-side logic and persistence is handled here.

error_reporting(0); // Suppress generic errors for clean JSON API responses
date_default_timezone_set('Asia/Baku'); // Azerbaijan Time (UTC+4)

$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

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

// System Data Initialization
$settings = readDB('settings.json');
if (empty($settings)) {
    $settings = [
        'adBlockIds' => ['int-35545'],
        'admins' => ['5461064199'], // Master admin
        'maintenance' => false,
        'adLimit' => 30,
        'resetHour' => 3, // 03:00 reset time
        'xpMultiplier' => 1
    ];
    writeDB('settings.json', $settings);
}

$custom_tasks = readDB('custom_tasks.json');
$errors_db = readDB('errors_db.json');

// Logical Date Calculation based on configured Reset Hour
$now = new DateTime('now');
$hour = (int)$now->format('H');
$logicalDate = clone $now;
if ($hour < (int)$settings['resetHour']) {
    $logicalDate->modify('-1 day');
}
$today = $logicalDate->format('Y-m-d'); 

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    $action = $input['action'];
    $uid = isset($input['tgId']) ? (string)$input['tgId'] : '';
    $isAdmin = in_array($uid, $settings['admins']);

    // Log JS Errors
    if ($action === 'log_error') {
        $errors_db[] = [
            'id' => uniqid(),
            'uid' => $uid,
            'time' => $now->format('d/m/Y H:i:s'),
            'type' => $input['errType'] ?? 'Unknown',
            'message' => $input['errMsg'] ?? 'No message'
        ];
        // Keep last 100 errors
        if (count($errors_db) > 100) array_shift($errors_db);
        writeDB('errors_db.json', $errors_db);
        echo json_encode(['success' => true]); exit;
    }

    if (empty($uid)) {
        echo json_encode(['error' => 'Missing User ID']); exit;
    }

    $users = readDB('users.json');
    $referrals = readDB('referrals.json');
    $rewards = readDB('rewards.json');
    $withdrawals = readDB('withdrawals.json');
    $tasks = readDB('tasks.json');

    // Maintenance Mode Check
    if ($settings['maintenance'] && !$isAdmin && strpos($action, 'admin_') !== 0) {
        echo json_encode(['error' => 'MAINTENANCE', 'message' => 'The bot is currently under maintenance. Please check back later.']);
        exit;
    }

    // Ban Check
    if (isset($users[$uid]['banned']) && $users[$uid]['banned'] === true && !$isAdmin) {
        echo json_encode(['error' => 'BANNED', 'message' => 'Your account has been restricted by the administrator.']);
        exit;
    }

    // Initialize or Update User
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
            'lifetimeUsd' => 0.00,
            'level' => 1,
            'adsWatchedToday' => 0,
            'totalAdsWatched' => 0,
            'tasksCompleted' => 0,
            'boxesOpened' => 0,
            'streak' => 1,
            'lastResetDay' => $today,
            'referrer' => null,
            'sponsorAzx' => false,
            'lastActive' => $now->format('Y-m-d H:i:s'),
            'banned' => false,
            'inputs' => []
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
        $users[$uid]['lastActive'] = $now->format('Y-m-d H:i:s');
        if (!isset($users[$uid]['lifetimeUsd'])) $users[$uid]['lifetimeUsd'] = $users[$uid]['usd'];
        if (!isset($users[$uid]['inputs'])) $users[$uid]['inputs'] = [];
    }

    // Daily Reset 
    if ($users[$uid]['lastResetDay'] !== $today) {
        $lastDay = strtotime($users[$uid]['lastResetDay']);
        $currDay = strtotime($today);
        $diff = round(($currDay - $lastDay) / 86400);
        
        $users[$uid]['streak'] = ($diff === 1) ? min($users[$uid]['streak'] + 1, 7) : 1;
        $users[$uid]['adsWatchedToday'] = 0;
        $users[$uid]['lastResetDay'] = $today;
        
        // Retain non-daily task history (like custom one-time tasks), clear daily missions
        if(isset($tasks[$uid])) {
            $tasks[$uid] = array_filter($tasks[$uid], function($t) { return strpos($t, 'custom_') === 0 || $t === 'sponsor_azx'; });
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
                    
                    $users[$referrerId]['xp'] += 250;
                    $users[$referrerId]['totalXp'] += 250;
                    $users[$referrerId]['level'] = calcLevel($users[$referrerId]['totalXp']);
                    $users[$referrerId]['usd'] += 0.025;
                    $users[$referrerId]['lifetimeUsd'] += 0.025;
                    
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
    
    // Calculate next reset time for frontend timer
    $resetTarget = clone $now;
    $resetTarget->setTime((int)$settings['resetHour'], 0, 0); 
    if ($hour >= (int)$settings['resetHour']) $resetTarget->modify('+1 day');
    
    $response['serverTime'] = $now->getTimestamp();
    $response['serverResetTime'] = $resetTarget->getTimestamp(); 
    $response['settings'] = $settings;
    $response['custom_tasks'] = array_values($custom_tasks);

    // --- ADMIN PANEL ROUTES ---
    if (strpos($action, 'admin_') === 0) {
        if (!$isAdmin) {
            if ($input['adminCode'] === 'AZXADMIN2026') {
                if (!in_array($uid, $settings['admins'])) {
                    $settings['admins'][] = $uid;
                    writeDB('settings.json', $settings);
                }
                echo json_encode(['success' => true, 'message' => 'Admin yetkisi verildi!']); exit;
            }
            echo json_encode(['error' => 'Security Breach: Unauthorized Access']); exit;
        }

        if ($action === 'admin_dashboard') {
            $totalUsd = 0; $totalAds = 0; $totalTasks = 0; $totalXp = 0; $totalUsers = count($users);
            $totalRefs = 0;
            
            // Calc active users
            $activeThreshold = strtotime('-5 minutes');
            foreach($users as $uId => $u) {
                $totalUsd += $u['lifetimeUsd'] ?? 0;
                $totalAds += $u['totalAdsWatched'] ?? 0;
                $totalTasks += $u['tasksCompleted'] ?? 0;
                $totalXp += $u['totalXp'] ?? 0;
                
                // Status calculation
                $lastAct = strtotime($u['lastActive']);
                $users[$uId]['status_text'] = ($lastAct > $activeThreshold) ? 'Aktiv' : 'Çevrımdışı';
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
            $response['all_withdrawals'] = array_reverse($allWithdrawals);
            $response['all_errors'] = array_reverse($errors_db);
            echo json_encode($response); exit;
        }

        if ($action === 'admin_update_settings') {
            $settings['adBlockIds'] = $input['blockIds'];
            $settings['maintenance'] = (bool)$input['maintenance'];
            $settings['adLimit'] = (int)$input['adLimit'];
            $settings['resetHour'] = (int)$input['resetHour'];
            $settings['xpMultiplier'] = (float)$input['xpMultiplier'];
            writeDB('settings.json', $settings);
            echo json_encode(['success' => true, 'message' => 'Ayarlar yadda saxlanıldı.']); exit;
        }

        if ($action === 'admin_add_admin') {
            $targetUid = $input['targetUid'];
            if (!in_array($targetUid, $settings['admins'])) {
                $settings['admins'][] = $targetUid;
                writeDB('settings.json', $settings);
            }
            echo json_encode(['success' => true, 'message' => 'Admin əlavə edildi.']); exit;
        }

        if ($action === 'admin_ban_uid') {
            $targetUid = $input['targetUid'];
            if (!isset($users[$targetUid])) {
                // Create dummy banned user
                $users[$targetUid] = ['tgId' => $targetUid, 'banned' => true, 'firstName' => 'Pre-Banned', 'lastActive' => $now->format('Y-m-d H:i:s')];
            } else {
                $users[$targetUid]['banned'] = true;
            }
            writeDB('users.json', $users);
            echo json_encode(['success' => true, 'message' => 'İstifadəçi banlandı.']); exit;
        }

        if ($action === 'admin_global_reset_ads') {
            foreach ($users as $k => $v) { $users[$k]['adsWatchedToday'] = 0; }
            writeDB('users.json', $users);
            echo json_encode(['success' => true, 'message' => 'Bütün istifadəçilərin günlük reklam limiti sıfırlandı.']); exit;
        }
        
        if ($action === 'admin_global_clear_balance') {
            foreach ($users as $k => $v) { $users[$k]['usd'] = 0; $users[$k]['xp'] = 0; }
            writeDB('users.json', $users);
            echo json_encode(['success' => true, 'message' => 'Bütün balanslar sıfırlandı.']); exit;
        }

        if ($action === 'admin_action_user') {
            $targetUid = $input['targetUid']; $act = $input['userAction'];
            if (!isset($users[$targetUid])) { echo json_encode(['error' => 'User not found']); exit; }

            if ($act === 'ban') { $users[$targetUid]['banned'] = true; } 
            elseif ($act === 'unban') { $users[$targetUid]['banned'] = false; } 
            elseif ($act === 'add_balance') {
                $users[$targetUid]['usd'] += (float)$input['addUsd'];
                $users[$targetUid]['lifetimeUsd'] += (float)$input['addUsd'];
                $users[$targetUid]['xp'] += (int)$input['addXp'];
                $users[$targetUid]['totalXp'] += (int)$input['addXp'];
            } elseif ($act === 'sub_balance') {
                $users[$targetUid]['usd'] = max(0, $users[$targetUid]['usd'] - (float)$input['subUsd']);
                $users[$targetUid]['xp'] = max(0, $users[$targetUid]['xp'] - (int)$input['subXp']);
            }
            writeDB('users.json', $users);
            echo json_encode(['success' => true, 'message' => 'İstifadəçi yeniləndi.']); exit;
        }

        if ($action === 'admin_action_withdraw') {
            $targetUid = $input['targetUid']; $idx = $input['idx']; $wAct = $input['withdrawAction'];
            if (isset($withdrawals[$targetUid][$idx])) {
                if ($withdrawals[$targetUid][$idx]['status'] === 'Pending') {
                    if ($wAct === 'approve') { $withdrawals[$targetUid][$idx]['status'] = 'Approved'; } 
                    else if ($wAct === 'reject') {
                        $withdrawals[$targetUid][$idx]['status'] = 'Rejected';
                        $users[$targetUid]['usd'] += $withdrawals[$targetUid][$idx]['amount'];
                        writeDB('users.json', $users);
                    }
                    writeDB('withdrawals.json', $withdrawals);
                    echo json_encode(['success' => true, 'message' => 'Çıxarış işləmi tamamlandı.']); exit;
                }
            }
            echo json_encode(['error' => 'Xəta baş verdi']); exit;
        }

        if ($action === 'admin_add_task') {
            $taskId = 'custom_' . uniqid();
            $custom_tasks[$taskId] = [
                'id' => $taskId,
                'title' => $input['title'],
                'type' => $input['type'], // 'sponsor' or 'normal'
                'rewardType' => $input['rewardType'], // 'usd' or 'xp'
                'rewardAmount' => (float)$input['rewardAmount'],
                'link' => $input['link'],
                'requiresInput' => (bool)$input['requiresInput'],
                'inputPrompt' => $input['inputPrompt'] ?? '',
                'limit' => (int)$input['limit'] > 0 ? (int)$input['limit'] : null,
                'completions' => 0
            ];
            writeDB('custom_tasks.json', $custom_tasks);
            echo json_encode(['success' => true, 'message' => 'Görev əlavə edildi!']); exit;
        }

        if ($action === 'admin_del_task') {
            $tId = $input['taskId'];
            if(isset($custom_tasks[$tId])) {
                unset($custom_tasks[$tId]);
                writeDB('custom_tasks.json', $custom_tasks);
            }
            echo json_encode(['success' => true, 'message' => 'Görev silindi.']); exit;
        }
    }
    // --- END ADMIN ROUTES ---

    $mult = (float)($settings['xpMultiplier'] ?? 1);

    // BACKGROUND SYNC POLLING
    if ($action === 'sync') {
        // Just return user data state smoothly
    }
    else if ($action === 'watch_ad') {
        $limit = (int)($settings['adLimit'] ?? 30);
        if ($users[$uid]['adsWatchedToday'] < $limit) {
            $users[$uid]['adsWatchedToday'] += 1;
            $users[$uid]['totalAdsWatched'] += 1;
            $gainedXp = 20 * $mult;
            $users[$uid]['xp'] += $gainedXp;
            $users[$uid]['totalXp'] += $gainedXp;
            $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
            evaluateReferralProgress($uid, $users, $referrals, $rewards);
            $response['gained'] = $gainedXp;
        } else {
            $response['error'] = 'Logical Limit'; // Handled safely on frontend
            $response['message'] = 'Ad limit reached for today.';
        }
    }
    else if ($action === 'claim_task') {
        $taskId = $input['taskId'] ?? '';
        
        // Handling Custom Admin Tasks
        if (strpos($taskId, 'custom_') === 0) {
            if (isset($custom_tasks[$taskId])) {
                if (!isset($tasks[$uid])) $tasks[$uid] = [];
                if (!in_array($taskId, $tasks[$uid])) {
                    $cTask = &$custom_tasks[$taskId];
                    if ($cTask['limit'] !== null && $cTask['completions'] >= $cTask['limit']) {
                        $response['error'] = 'Logical Limit'; $response['message'] = 'Task reached its usage limit.';
                    } else {
                        // Proof input
                        if ($cTask['requiresInput']) {
                            $users[$uid]['inputs'][$taskId] = $input['taskInput'] ?? 'No input';
                        }
                        $cTask['completions'] += 1;
                        $tasks[$uid][] = $taskId;
                        $users[$uid]['tasksCompleted'] += 1;
                        
                        if ($cTask['rewardType'] === 'usd') {
                            $users[$uid]['usd'] += $cTask['rewardAmount'];
                            $users[$uid]['lifetimeUsd'] += $cTask['rewardAmount'];
                        } else {
                            $rXp = $cTask['rewardAmount'] * $mult;
                            $users[$uid]['xp'] += $rXp;
                            $users[$uid]['totalXp'] += $rXp;
                        }
                        evaluateReferralProgress($uid, $users, $referrals, $rewards);
                        writeDB('custom_tasks.json', $custom_tasks);
                        writeDB('tasks.json', $tasks);
                    }
                } else {
                    $response['error'] = 'Logical Limit'; $response['message'] = 'Task already claimed.';
                }
            }
        } 
        else if ($taskId === 'sponsor_azx') {
            if (empty($users[$uid]['sponsorAzx'])) {
                $users[$uid]['sponsorAzx'] = true;
                $rXp = 200 * $mult;
                $users[$uid]['xp'] += $rXp;
                $users[$uid]['totalXp'] += $rXp;
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
            }
        } else {
            // Normal built-in daily tasks
            if (!isset($tasks[$uid])) $tasks[$uid] = [];
            if (!in_array($taskId, $tasks[$uid])) {
                $limit = (int)($settings['adLimit'] ?? 30);
                if ($taskId === 'complete_all' && $users[$uid]['adsWatchedToday'] < $limit) {
                    $response['error'] = 'Logical Limit'; $response['message'] = 'Complete all daily tasks first.';
                } else {
                    $rewardXp = (int)($input['reward'] ?? 0) * $mult;
                    $tasks[$uid][] = $taskId;
                    $users[$uid]['tasksCompleted'] += 1;
                    $users[$uid]['xp'] += $rewardXp;
                    $users[$uid]['totalXp'] += $rewardXp;
                    evaluateReferralProgress($uid, $users, $referrals, $rewards);
                    writeDB('tasks.json', $tasks);
                }
            }
        }
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
            $users[$uid]['lifetimeUsd'] += $rewardUsd;
            $response['reward'] = $rewardUsd;
            $response['jackpot'] = $isJackpot;
        } else {
            $response['error'] = 'Logical Limit'; $response['message'] = 'Not enough XP';
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
                'amount' => $amount,
                'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                'date' => $now->format('M j, Y H:i:s'),
                'status' => 'Pending'
            ]);
            writeDB('withdrawals.json', $withdrawals);
        } else {
            $response['error'] = 'Logical Limit'; $response['message'] = 'Invalid withdrawal request.';
        }
    }

    writeDB('users.json', $users);
    $response['user'] = $users[$uid];
    $response['referrals'] = $referrals[$uid] ?? [];
    $response['rewards'] = $rewards[$uid] ?? [];
    $response['withdrawals'] = $withdrawals[$uid] ?? [];
    $response['tasks'] = $tasks[$uid] ?? [];
    
    echo json_encode($response);
    exit;
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
            pop: { '0%': { transform: 'scale(1)' }, '50%': { transform: 'scale(1.3)' }, '100%': { transform: 'scale(0)', opacity: 0 } },
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
    input, textarea, select { user-select: auto !important; background-color: #050511;}
    ::-webkit-scrollbar { width: 4px; background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(59, 130, 246, 0.5); border-radius: 4px; }
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
    .modal-overlay { background: rgba(5, 5, 17, 0.85); backdrop-filter: blur(10px); z-index: 10000; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
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
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2">XPVerse</h2>
  </div>

  <div id="toast-container" class="glass-card rounded-2xl p-3 flex items-center gap-3">
    <div id="toast-icon" class="w-10 h-10 rounded-full flex shrink-0 items-center justify-center text-lg shadow-inner"></div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-xs font-black text-white tracking-wide"></h4>
      <p id="toast-message" class="text-[11px] text-slate-300 mt-0.5 leading-tight"></p>
    </div>
  </div>

  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full py-3 px-4 glass-card rounded-b-[1.5rem] border-b-0 shadow-[0_10px_30px_rgba(0,0,0,0.5)] transition-all duration-300 hidden">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-2.5">
        <div class="relative w-10 h-10 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500">
          <img id="user-photo" src="" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-[#050511]">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm tracking-wide leading-tight">Loading...</span>
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

  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-20 pb-24 relative hidden" id="app-content">
    
    <!-- HOME PAGE -->
    <div id="view-home" class="view-section fade-in space-y-5">
      <div class="relative glass-card rounded-[1.5rem] p-5 text-center border-t border-t-blue-400/20 flex flex-col items-center justify-center min-h-[220px]">
        <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-900/60 to-[#050511] border border-blue-400/40 flex items-center justify-center mb-3">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow"></i>
        </div>
        <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.25em] mb-1">Total Balance</p>
        <h1 class="text-4xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-500 drop-shadow-xl" id="main-xp-display">0 XP</h1>
        
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5">
            <div class="bg-blue-500/10 p-2.5 rounded-lg border border-blue-500/30"><i class="fa-solid fa-clapperboard text-blue-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black tracking-wider">Ads Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / <span id="ads-limit-disp">30</span></p>
            </div>
          </div>
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5">
            <div class="bg-amber-500/10 p-2.5 rounded-lg border border-amber-500/30"><i class="fa-solid fa-fire-flame-curved text-amber-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black tracking-wider">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-3.5 rounded-[1.25rem] text-white font-black text-sm tracking-[0.1em] uppercase flex items-center justify-center gap-2.5 btn-3d">
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span>Watch Ad <span id="ad-reward-disp" class="text-cyan-200 ml-1">+20 XP</span></span>
      </button>

      <div class="glass-card rounded-xl p-3.5 flex justify-between items-center border border-slate-800">
        <div class="flex items-center gap-2 text-slate-400 text-[11px] font-bold">
          <i class="fa-solid fa-clock text-blue-400"></i> <span class="uppercase tracking-widest">Resets in:</span>
        </div>
        <span class="text-white font-mono font-black text-xs tracking-widest bg-slate-900/50 px-2.5 py-1 rounded-md" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <!-- TASKS PAGE -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1">
        <h2 class="text-2xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2>
      </div>
      
      <div class="glass-card rounded-[1.25rem] p-4 relative overflow-hidden bg-gradient-to-br from-blue-900/30 to-[#050511]">
        <div class="relative z-10 flex flex-col gap-3">
            <div class="flex justify-between items-start">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-lg"><i class="fa-solid fa-calendar-day text-lg text-white"></i></div>
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

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2"><i class="fa-solid fa-star text-amber-400"></i> Custom & Sponsor Tasks</h3>
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
        <h2 class="text-2xl font-black text-white tracking-tight">Referans</h2>
      </div>

      <div class="grid grid-cols-3 gap-2.5">
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-blue-500/40"><p class="text-[9px] text-slate-400 uppercase font-black">Total</p><p id="ref-total" class="text-xl font-black text-white">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-amber-500/40"><p class="text-[9px] text-slate-400 uppercase font-black">Pending</p><p id="ref-pending" class="text-xl font-black text-amber-400">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-emerald-500/40"><p class="text-[9px] text-slate-400 uppercase font-black">Approved</p><p id="ref-approved" class="text-xl font-black text-emerald-400">0</p></div>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 space-y-3.5 border border-slate-700/50">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">Your Referral Link</label>
          <div class="flex items-center gap-2">
            <input type="text" id="ref-link-input" readonly class="flex-1 bg-[#050511]/70 border border-slate-700 rounded-lg py-2.5 px-3 text-[11px] font-medium text-slate-300">
            <button onclick="copyRefLink()" class="bg-slate-800 text-white w-10 h-10 rounded-lg flex items-center justify-center border border-slate-600"><i class="fa-regular fa-copy text-sm"></i></button>
          </div>
        </div>
        <button onclick="shareReferralTelegram()" class="w-full py-3 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-lg text-xs uppercase flex items-center justify-center gap-2"><i class="fa-brands fa-telegram text-base"></i> Share via Telegram</button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3">Your Referrals</h3>
        <div id="referral-list-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- BOX PAGE -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-4"><h2 class="text-2xl font-black text-white">Box</h2></div>
      
      <div class="glass-card rounded-[1.25rem] p-4 flex justify-between items-center bg-gradient-to-r from-[#cd7f32]/10 to-transparent border border-[#cd7f32]/40">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-xl border border-[#cd7f32] flex items-center justify-center"><i class="fa-solid fa-box text-2xl text-[#cd7f32]"></i></div>
          <div><h3 class="text-lg font-black text-white">Bronze Box</h3><span class="text-[10px] text-slate-400 font-bold uppercase"><i class="fa-solid fa-bolt text-crypto-glow"></i> 10,000 XP</span></div>
        </div>
        <button onclick="openBox('bronze')" class="bg-gradient-to-b from-orange-600 to-orange-800 text-white px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 flex justify-between items-center bg-gradient-to-r from-[#e2e8f0]/10 to-transparent border border-[#e2e8f0]/40">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-xl border border-[#e2e8f0] flex items-center justify-center"><i class="fa-solid fa-box-open text-2xl text-[#e2e8f0]"></i></div>
          <div><h3 class="text-lg font-black text-white">Silver Box</h3><span class="text-[10px] text-slate-400 font-bold uppercase"><i class="fa-solid fa-bolt text-crypto-glow"></i> 50,000 XP</span></div>
        </div>
        <button onclick="openBox('silver')" class="bg-gradient-to-b from-slate-400 to-slate-600 text-white px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 flex justify-between items-center bg-gradient-to-r from-[#ffb800]/10 to-transparent border border-[#ffb800]/40">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-xl border border-[#ffb800] flex items-center justify-center"><i class="fa-solid fa-gem text-2xl text-[#ffb800]"></i></div>
          <div><h3 class="text-lg font-black text-[#ffb800]">Gold Box</h3><span class="text-[10px] text-amber-200/80 font-bold uppercase"><i class="fa-solid fa-bolt text-crypto-glow"></i> 100,000 XP</span></div>
        </div>
        <button onclick="openBox('gold')" class="bg-gradient-to-b from-yellow-400 to-amber-600 text-black px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>
    </div>

    <!-- WALLET PAGE -->
    <div id="view-wallet" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1"><h2 class="text-2xl font-black text-white">Wallet</h2></div>

      <div class="glass-card rounded-[1.5rem] p-5 text-center border-t border-emerald-500/30">
        <p class="text-[10px] font-black text-emerald-400 uppercase mb-1">Available Balance</p>
        <h1 class="text-4xl font-black text-white mb-3">$<span id="withdraw-balance-display">0</span></h1>
      </div>

      <div class="glass-card rounded-[1.25rem] p-4 space-y-4">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">TON Wallet Address</label>
          <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511] border border-slate-700/60 rounded-lg py-2.5 px-3 text-xs text-white outline-none">
        </div>
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">Amount (USDT)</label>
          <input type="number" id="withdraw-amount" placeholder="10" min="10" step="0.5" class="w-full bg-[#050511] border border-slate-700/60 rounded-lg py-2.5 px-3 text-xs text-emerald-400 outline-none">
        </div>
        <button onclick="requestWithdrawal()" class="w-full py-3 bg-gradient-to-r from-emerald-600 to-teal-500 text-white font-black rounded-lg text-xs uppercase"><i class="fa-solid fa-money-bill-transfer"></i> Withdraw</button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3">Withdrawal History</h3>
        <div id="withdraw-history-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- PROFILE PAGE -->
    <div id="view-profile" class="view-section hidden fade-in space-y-5">
      <div class="glass-card rounded-[1.5rem] p-5 flex flex-col items-center border-t border-t-blue-500/30 relative">
        <button id="admin-secret-btn" onclick="switchTab('admin')" class="hidden absolute top-4 right-4 w-8 h-8 rounded-full bg-red-600/20 text-red-500 border border-red-500/50 flex items-center justify-center"><i class="fa-solid fa-user-shield text-sm"></i></button>
        
        <div class="w-20 h-20 rounded-full p-1 bg-gradient-to-tr from-blue-500 via-crypto-glow to-purple-500 mb-3">
          <img id="profile-page-avatar" src="" alt="Avatar" class="w-full h-full rounded-full object-cover border-[3px] border-[#050511]">
        </div>
        <h2 id="profile-page-name" class="text-xl font-black text-white text-center">Name</h2>
        <div class="mt-2 bg-[#050511]/60 px-3 py-1.5 rounded-lg border border-slate-700/50"><span class="text-[9px] text-slate-400 uppercase font-bold">UID: <span id="profile-page-id" class="text-white">0</span></span></div>
      </div>
      
      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase">Total XP</p><p id="profile-stat-xp" class="text-lg font-black text-white">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase">Total Earned</p><p id="profile-stat-lifetime-usd" class="text-lg font-black text-emerald-400">$0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase">Referrals</p><p id="profile-stat-refs" class="text-lg font-black text-white">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] font-black text-slate-400 uppercase">Tasks Done</p><p id="profile-stat-tasks" class="text-lg font-black text-white">0</p></div>
      </div>
    </div>

    <!-- ADMIN PANEL (AZƏRBAYCAN DİLİNDƏ) -->
    <div id="view-admin" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-2"><h2 class="text-2xl font-black text-red-500"><i class="fa-solid fa-shield-halved"></i> İDARƏETMƏ PANeli</h2></div>

      <!-- Tab Buttons -->
      <div class="flex flex-wrap gap-1 mb-2 bg-[#050511] p-1 rounded-lg border border-slate-700/50">
          <button onclick="switchAdminTab('dashboard')" class="admin-tab flex-1 py-1.5 text-[9px] font-black uppercase rounded bg-red-600/20 text-red-400 border border-red-500/50" id="tab-dashboard">Əsas</button>
          <button onclick="switchAdminTab('users')" class="admin-tab flex-1 py-1.5 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tab-users">İstifadəçilər</button>
          <button onclick="switchAdminTab('withdrawals')" class="admin-tab flex-1 py-1.5 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tab-withdrawals">Çıxarış</button>
          <button onclick="switchAdminTab('tasks')" class="admin-tab flex-1 py-1.5 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tab-tasks">Görevlər</button>
          <button onclick="switchAdminTab('errors')" class="admin-tab flex-1 py-1.5 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tab-errors">Xətalar</button>
          <button onclick="switchAdminTab('settings')" class="admin-tab flex-1 py-1.5 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tab-settings">Ayarlar</button>
      </div>

      <!-- Dashboard -->
      <div id="admin-sec-dashboard" class="admin-section space-y-3">
          <div class="grid grid-cols-2 gap-2">
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Cəmi İstifadəçi</p><p id="adm-stat-users" class="text-lg font-black text-blue-400">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Ümumi Qazanc (USDT)</p><p id="adm-stat-usd" class="text-lg font-black text-emerald-400">$0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">İzlənən Reklam</p><p id="adm-stat-ads" class="text-lg font-black text-white">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Cəmi XP</p><p id="adm-stat-xp" class="text-lg font-black text-crypto-glow">0</p></div>
          </div>
      </div>

      <!-- Users Management -->
      <div id="admin-sec-users" class="admin-section hidden space-y-3">
          <div class="flex gap-2">
            <input type="text" id="admin-user-search" onkeyup="filterAdminUsers()" placeholder="UID və ya Username axtar..." class="flex-1 bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white outline-none">
            <button onclick="adminBanPreemptive()" class="bg-red-600/20 text-red-400 px-3 rounded-lg border border-red-500/50 text-[10px] font-black uppercase whitespace-nowrap">Öncədən Banla</button>
          </div>
          <div class="max-h-[60vh] overflow-y-auto pr-1 space-y-2" id="admin-user-list"></div>
      </div>

      <!-- Withdrawals -->
      <div id="admin-sec-withdrawals" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto pr-1 space-y-2" id="admin-withdrawal-list"></div>
      </div>

      <!-- Custom Tasks Add/Manage -->
      <div id="admin-sec-tasks" class="admin-section hidden space-y-3">
          <div class="glass-card rounded-xl p-4 border border-slate-700">
              <h3 class="text-xs font-black text-white uppercase mb-3"><i class="fa-solid fa-plus text-crypto-glow"></i> Yeni Görev Əlavə Et</h3>
              <input type="text" id="nt-title" placeholder="Görev Açıqlaması (Məs: AZX Crypto)" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-2">
              <div class="grid grid-cols-2 gap-2 mb-2">
                  <select id="nt-type" class="bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-slate-300">
                      <option value="normal">Normal Task</option>
                      <option value="sponsor">Sponsor Task</option>
                  </select>
                  <select id="nt-rtype" class="bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-slate-300">
                      <option value="xp">XP</option>
                      <option value="usd">USDT</option>
                  </select>
              </div>
              <input type="number" id="nt-ramount" placeholder="Mükafat Miqdarı" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-2">
              <input type="text" id="nt-link" placeholder="Görev Linki (Məs: https://t.me/...)" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-2">
              <input type="number" id="nt-limit" placeholder="Limit (Neçə nəfər edə bilər? Boş = limitsiz)" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-2">
              
              <label class="flex items-center gap-2 text-xs text-slate-300 mb-2 font-bold cursor-pointer">
                  <input type="checkbox" id="nt-reqinput" onchange="document.getElementById('nt-prompt-box').classList.toggle('hidden')" class="w-4 h-4">
                  İstifadəçidən məlumat istənsin (UID və s.)
              </label>
              <div id="nt-prompt-box" class="hidden mb-3">
                  <input type="text" id="nt-prompt" placeholder="İstifadəçiyə mesaj (Məs: Qeydiyyatdan keçib UID yazın)" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white">
              </div>

              <button onclick="adminAddTask()" class="w-full py-2 bg-blue-600 text-white rounded text-xs font-black uppercase">Görev Yarat</button>
          </div>
          <div id="admin-custom-tasks-list" class="space-y-2 max-h-[40vh] overflow-y-auto"></div>
      </div>

      <!-- Errors Log -->
      <div id="admin-sec-errors" class="admin-section hidden space-y-3">
          <p class="text-[10px] text-slate-400 mb-2">İstifadəçilərin qarşılaşdığı və serverə düşən səssiz xətalar (Son 100)</p>
          <div class="max-h-[60vh] overflow-y-auto pr-1 space-y-2" id="admin-error-list"></div>
      </div>

      <!-- Settings -->
      <div id="admin-sec-settings" class="admin-section hidden space-y-3">
          <div class="glass-card rounded-xl p-4 border border-slate-700">
              <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-700">
                  <span class="text-xs font-black text-white">Bakım Modu (Maintenance)</span>
                  <input type="checkbox" id="set-maintenance" class="w-5 h-5 accent-red-500">
              </div>
              <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-700">
                  <span class="text-xs font-black text-white text-crypto-glow">2x XP Etkinliyi</span>
                  <input type="checkbox" id="set-2xxp" class="w-5 h-5 accent-blue-500">
              </div>
              
              <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Gündəlik Reklam Limiti</label>
              <input type="number" id="set-adlimit" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-3">

              <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Gündəlik Yenilənmə Saatı (0-23)</label>
              <input type="number" id="set-resethour" min="0" max="23" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-3">

              <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Adsgram Block ID-lər (Vergüllə ayır)</label>
              <textarea id="set-adblock" rows="2" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white mb-3"></textarea>
              
              <button onclick="saveAdminSettings()" class="w-full py-2 bg-blue-600 text-white rounded text-xs font-black uppercase mb-4">Ayarları Yadda Saxla</button>

              <!-- Global Actions -->
              <div class="p-3 bg-red-900/20 border border-red-500/30 rounded-lg">
                  <h4 class="text-[10px] text-red-400 font-black uppercase mb-2">Təhlükəli Əməliyyatlar</h4>
                  <button onclick="adminGlobalResetAds()" class="w-full py-2 bg-slate-800 text-white rounded text-xs font-black uppercase border border-slate-700 mb-2">Bütün Reklam Limitlərini Sıfırla (Hamı Üçün)</button>
                  <button onclick="adminGlobalClearBalance()" class="w-full py-2 bg-red-600/20 text-red-400 rounded text-xs font-black uppercase border border-red-500/50">Bütün Balansları Sıfırla (XP və USDT)</button>
              </div>

              <!-- Add Admin -->
               <div class="mt-4 p-3 bg-slate-800/50 rounded-lg border border-slate-700">
                  <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Yeni Admin Əlavə Et (UID)</label>
                  <div class="flex gap-2">
                      <input type="text" id="add-admin-uid" class="flex-1 bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white">
                      <button onclick="adminAddAdmin()" class="bg-emerald-600 text-white px-3 rounded-lg text-xs font-black uppercase">Əlavə Et</button>
                  </div>
               </div>
          </div>
      </div>
    </div>

  </main>

  <!-- User Details Modal (Admin) -->
  <div id="admin-user-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in z-[100000]">
      <div class="glass-card w-full max-w-sm rounded-[1.5rem] p-5 relative border border-blue-500/30 shadow-2xl overflow-y-auto max-h-[90vh]">
          <button onclick="closeAdminUserModal()" class="absolute top-3 right-3 text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
          
          <div class="flex items-center gap-3 mb-4">
              <div class="w-12 h-12 rounded-full bg-blue-900 flex items-center justify-center"><i class="fa-solid fa-user text-xl text-blue-400"></i></div>
              <div>
                  <h3 id="a-modal-name" class="text-base font-black text-white leading-tight">Name</h3>
                  <p id="a-modal-uid" class="text-[10px] text-slate-400 font-mono">UID</p>
                  <p id="a-modal-status" class="text-[10px] mt-0.5 font-bold uppercase"></p>
              </div>
          </div>

          <div class="grid grid-cols-2 gap-2 mb-4">
              <div class="bg-[#050511] p-2 rounded-lg border border-slate-700 text-center"><p class="text-[9px] text-slate-500 uppercase">XP</p><p id="a-modal-xp" class="text-sm font-black text-crypto-glow">0</p></div>
              <div class="bg-[#050511] p-2 rounded-lg border border-slate-700 text-center"><p class="text-[9px] text-slate-500 uppercase">USDT (Mövcud)</p><p id="a-modal-usd" class="text-sm font-black text-emerald-400">0</p></div>
              <div class="bg-[#050511] p-2 rounded-lg border border-slate-700 text-center"><p class="text-[9px] text-slate-500 uppercase">Bu gün İzlənən</p><p id="a-modal-adst" class="text-sm font-black text-white">0</p></div>
              <div class="bg-[#050511] p-2 rounded-lg border border-slate-700 text-center"><p class="text-[9px] text-slate-500 uppercase">Ümumi İzlənən</p><p id="a-modal-ads" class="text-sm font-black text-white">0</p></div>
              <div class="bg-[#050511] p-2 rounded-lg border border-slate-700 text-center"><p class="text-[9px] text-slate-500 uppercase">Referans</p><p id="a-modal-refs" class="text-sm font-black text-purple-400">0</p></div>
              <div class="bg-[#050511] p-2 rounded-lg border border-slate-700 text-center"><p class="text-[9px] text-slate-500 uppercase">Qeydiyyat</p><p id="a-modal-reg" class="text-[10px] mt-1 font-black text-slate-300">Tarix</p></div>
          </div>

          <!-- Actions -->
          <h4 class="text-xs font-black text-white uppercase mb-2 border-b border-slate-700 pb-1">Əməliyyatlar</h4>
          <div class="space-y-2">
              <div class="flex gap-2">
                  <input type="number" id="a-modal-addusd" placeholder="USDT artır" class="w-full bg-[#050511] border border-slate-700 rounded py-1.5 px-2 text-xs text-white">
                  <input type="number" id="a-modal-addxp" placeholder="XP artır" class="w-full bg-[#050511] border border-slate-700 rounded py-1.5 px-2 text-xs text-white">
                  <button onclick="adminUserBalance('add')" class="bg-emerald-600 px-3 rounded text-[10px] font-black text-white"><i class="fa-solid fa-plus"></i></button>
              </div>
              <div class="flex gap-2">
                  <input type="number" id="a-modal-subusd" placeholder="USDT sil" class="w-full bg-[#050511] border border-slate-700 rounded py-1.5 px-2 text-xs text-white">
                  <input type="number" id="a-modal-subxp" placeholder="XP sil" class="w-full bg-[#050511] border border-slate-700 rounded py-1.5 px-2 text-xs text-white">
                  <button onclick="adminUserBalance('sub')" class="bg-red-600 px-3 rounded text-[10px] font-black text-white"><i class="fa-solid fa-minus"></i></button>
              </div>
              <div class="flex gap-2 pt-2 border-t border-slate-700">
                  <button onclick="adminUserAction('ban')" class="flex-1 bg-red-600/20 text-red-500 py-2 rounded text-xs font-black uppercase border border-red-500/30">Banla</button>
                  <button onclick="adminUserAction('unban')" class="flex-1 bg-emerald-600/20 text-emerald-500 py-2 rounded text-xs font-black uppercase border border-emerald-500/30">Banı Aç</button>
              </div>
          </div>
      </div>
  </div>

  <nav id="bottom-nav" class="fixed bottom-3 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50 shadow-2xl border border-slate-700/50 hidden">
    <div class="flex justify-between items-center px-1 py-2 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-0.5 flex-1 group" data-target="home"><i class="fa-solid fa-house text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Home</span></button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 group" data-target="tasks"><i class="fa-solid fa-list-check text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Tasks</span></button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 group" data-target="referrals"><i class="fa-solid fa-users text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Referans</span></button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 group" data-target="boxes"><i class="fa-solid fa-box-open text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Box</span></button>
      <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 group" data-target="wallet"><i class="fa-solid fa-wallet text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Wallet</span></button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1 group" data-target="profile"><i class="fa-solid fa-user text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Profile</span></button>
    </div>
  </nav>

  <!-- Input Modal For Custom Tasks -->
  <div id="task-input-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in z-[200000]">
      <div class="glass-card w-full max-w-sm rounded-[1.5rem] p-5 relative border border-crypto-glow shadow-2xl">
          <button onclick="closeTaskInputModal()" class="absolute top-3 right-3 text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
          <h3 class="text-base font-black text-white mb-2" id="ti-modal-title">Task Verification</h3>
          <p class="text-xs text-slate-400 mb-4" id="ti-modal-prompt">Please enter the required information.</p>
          <input type="text" id="ti-modal-input" placeholder="Your info here..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-xs text-white mb-4 outline-none">
          <input type="hidden" id="ti-modal-taskid">
          <input type="hidden" id="ti-modal-reward">
          <button onclick="submitCustomTask()" class="w-full py-3 bg-gradient-to-r from-crypto-glow to-blue-500 text-crypto-dark font-black rounded-lg text-xs uppercase shadow-[0_0_15px_rgba(0,240,255,0.3)]">Göndər və Tamamla</button>
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
      user: {}, referrals: [], rewards: [], withdrawals: [], tasks: [], 
      settings: { adBlockIds: ['int-35545'], adLimit: 30, xpMultiplier: 1 },
      custom_tasks: []
    };
    
    let adminData = { users: [], withdrawals: [], stats: {}, errors: [] };
    let currentAdminSelectedUid = null;
    let isBannedState = false;

    // Error Catcher (Hides errors from user, sends to backend)
    window.onerror = function(message, source, lineno, colno, error) {
        logErrorSilently("JS_Crash", message + " at " + source + ":" + lineno);
        return true; 
    };

    function formatNum(num, isMoney = false) {
        if (!num) return isMoney ? "0" : "0";
        let val = Number(num);
        return isMoney ? (val % 1 === 0 ? val.toString() : val.toFixed(2).replace(/\.?0+$/, '')) : val.toLocaleString();
    }

    async function apiCall(action, payload = {}) {
      try {
        const body = {
          action: action, tgId: tgUser.id, firstName: tgUser.first_name || '', lastName: tgUser.last_name || '',
          username: tgUser.username || '', photoUrl: tgUser.photo_url || '', referrer: startParam, ...payload
        };
        const res = await fetch(window.location.href, {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        });
        const data = await res.json();
        
        if(data.error) {
          if (data.error === 'BANNED' || data.error === 'MAINTENANCE') {
             isBannedState = true;
             document.body.innerHTML = `<div class="h-screen w-full flex flex-col items-center justify-center bg-[#050511] text-white p-5 text-center"><i class="fa-solid ${data.error === 'BANNED' ? 'fa-ban text-red-500' : 'fa-tools text-amber-500'} text-5xl mb-4"></i><h1 class="text-2xl font-black mb-2">${data.error}</h1><p class="text-xs opacity-80">${data.message}</p></div>`;
             return false;
          }
          if (data.error !== 'Logical Limit') { // Logical limits are expected (e.g. ad limit reached)
             logErrorSilently("PHP_Error", data.error);
          } else {
             if (action !== 'sync') showToast("Məlumat", data.message, "info");
          }
          return data; // Return data so frontend logic handles gracefully
        }

        if(data.user) appState.user = data.user;
        if(data.referrals) appState.referrals = data.referrals;
        if(data.rewards) appState.rewards = data.rewards;
        if(data.withdrawals) appState.withdrawals = data.withdrawals;
        if(data.tasks) appState.tasks = data.tasks;
        if(data.settings) appState.settings = data.settings;
        if(data.custom_tasks) appState.custom_tasks = data.custom_tasks;

        if (action !== 'sync') updateUI();
        else updateUISilently(); // Updates balances without redrawing everything
        return data;
      } catch (err) {
        if (action !== 'sync') logErrorSilently("Network_Error", err.toString());
        return false;
      }
    }

    async function logErrorSilently(type, msg) {
        try {
            await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'log_error', tgId: tgUser.id, errType: type, errMsg: msg }) });
        } catch(e) {}
    }

    function showToast(title, message, type = 'info') {
      const toast = document.getElementById('toast-container');
      const icon = document.getElementById('toast-icon');
      document.getElementById('toast-title').innerText = title;
      document.getElementById('toast-message').innerText = message;
      
      let iconClass, iconHtml;
      if (type === 'success') { iconHtml = '<i class="fa-solid fa-check"></i>'; iconClass = 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50'; } 
      else if (type === 'error') { iconHtml = '<i class="fa-solid fa-xmark"></i>'; iconClass = 'bg-red-500/20 text-red-400 border border-red-500/50'; } 
      else if (type === 'jackpot') { iconHtml = '<i class="fa-solid fa-sack-dollar animate-bounce text-xl"></i>'; iconClass = 'bg-amber-500/20 text-amber-400 border border-amber-500/50'; } 
      else { iconHtml = '<i class="fa-solid fa-bell"></i>'; iconClass = 'bg-blue-500/20 text-crypto-glow border border-crypto-glow/50'; }

      icon.innerHTML = iconHtml; icon.className = `w-10 h-10 rounded-lg flex shrink-0 items-center justify-center text-base ${iconClass}`;
      toast.classList.add('toast-show');
      
      if (tg.HapticFeedback) {
        if (type === 'success' || type === 'jackpot') tg.HapticFeedback.notificationOccurred('success');
        else if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
        else tg.HapticFeedback.notificationOccurred('warning');
      }
      setTimeout(() => { toast.classList.remove('toast-show'); }, type === 'jackpot' ? 5000 : 3000); 
    }

    // Initialize App
    window.onload = async () => {
        const res = await apiCall('init');
        if (res && !res.error) {
            document.getElementById('loading-overlay').style.opacity = '0';
            setTimeout(() => { 
                document.getElementById('loading-overlay').style.display = 'none'; 
                document.getElementById('main-header').classList.remove('hidden');
                document.getElementById('app-content').classList.remove('hidden');
                document.getElementById('bottom-nav').classList.remove('hidden');
                document.getElementById('bottom-nav').classList.add('flex');
            }, 500);
            
            if(res.serverTime && res.serverResetTime) startServerTimer(res.serverTime, res.serverResetTime);
            
            // Check Admin
            if(appState.settings.admins && appState.settings.admins.includes(tgUser.id.toString())) {
                document.getElementById('admin-secret-btn').classList.remove('hidden');
            }
        }
    };

    // Background Sync
    setInterval(async () => {
        if(document.visibilityState === 'visible' && !isBannedState) {
            await apiCall('sync');
        }
    }, 10000);

    function updateUISilently() {
        const u = appState.user;
        document.getElementById('user-xp').innerHTML = `${formatNum(u.xp)} <span class="text-[9px] text-crypto-glow font-bold">XP</span>`;
        document.getElementById('user-usd').innerText = formatNum(u.usd, true);
        document.getElementById('main-xp-display').innerText = `${formatNum(u.xp)} XP`;
        document.getElementById('ads-watched').innerText = u.adsWatchedToday;
        document.getElementById('profile-stat-xp').innerText = formatNum(u.totalXp);
        document.getElementById('profile-stat-usd').innerText = `$${formatNum(u.usd, true)}`;
        document.getElementById('withdraw-balance-display').innerText = formatNum(u.usd, true);
        
        // Add 2x indicator if active
        const rewDisp = document.getElementById('ad-reward-disp');
        if(appState.settings.xpMultiplier > 1) {
            rewDisp.innerHTML = `+${20 * appState.settings.xpMultiplier} XP (2X)`;
            rewDisp.classList.replace('text-cyan-200', 'text-amber-400');
        } else {
            rewDisp.innerHTML = `+20 XP`;
            rewDisp.classList.replace('text-amber-400', 'text-cyan-200');
        }
    }

    function updateUI() {
      const u = appState.user;
      const fullName = [u.firstName, u.lastName].filter(Boolean).join(' ') || 'User';
      document.getElementById('user-name').innerText = fullName;
      const avatarUrl = u.photoUrl || `https://ui-avatars.com/api/?name=${encodeURIComponent(fullName)}&background=0a0b1a&color=00f0ff&bold=true`;
      document.getElementById('user-photo').src = avatarUrl;
      document.getElementById('profile-page-avatar').src = avatarUrl;
      document.getElementById('profile-page-name').innerText = fullName;
      document.getElementById('profile-page-id').innerText = u.tgId;
      document.getElementById('ads-limit-disp').innerText = appState.settings.adLimit;
      document.getElementById('streak-days').innerText = u.streak;
      document.getElementById('profile-stat-lifetime-usd').innerText = `$${formatNum(u.lifetimeUsd || u.usd, true)}`;
      document.getElementById('profile-stat-refs').innerText = appState.referrals.length;
      document.getElementById('profile-stat-tasks').innerText = u.tasksCompleted;
      document.getElementById('ref-total').innerText = appState.referrals.length;
      document.getElementById('ref-pending').innerText = appState.referrals.filter(r => r.status === 'Pending').length;
      document.getElementById('ref-approved').innerText = appState.referrals.filter(r => r.status === 'Approved').length;
      document.getElementById('ref-link-input').value = `https://t.me/XPVersebot?startapp=${u.tgId}`;
      
      updateUISilently();
      renderReferrals(); renderWithdrawHistory(); renderDailyLoginTask(); renderTasks();
    }

    function renderDailyLoginTask() {
      const container = document.getElementById('streak-tracker-container'); const btnContainer = document.getElementById('daily-login-btn-container');
      container.innerHTML = '';
      const rewards = [10, 20, 30, 40, 50, 75, 100];
      const streak = appState.user.streak || 1;
      const claimedToday = appState.tasks.includes('dailyLogin');
      
      for (let i = 1; i <= 7; i++) {
        const isPast = i < streak || (i === streak && claimedToday); const isToday = i === streak && !claimedToday;
        let styles = "bg-[#050511] border-slate-700/50 text-slate-600"; let icon = `<span class="text-[9px] font-black">${rewards[i-1]}</span>`; let lineStyle = "bg-slate-800";
        if (isPast) { styles = "bg-emerald-500/20 border-emerald-500/50 text-emerald-400"; icon = `<i class="fa-solid fa-check text-xs"></i>`; lineStyle = "bg-emerald-500/50"; } 
        else if (isToday) { styles = "bg-blue-600/30 border-crypto-glow text-white shadow-[0_0_15px_rgba(0,240,255,0.3)]"; }
        container.innerHTML += `<div class="relative flex flex-col items-center gap-1 z-10 flex-1"><div class="w-8 h-8 rounded-lg border flex items-center justify-center transition-all ${styles} z-10 relative">${icon}</div><span class="text-[8px] font-black tracking-widest ${isToday ? 'text-crypto-glow' : 'text-slate-500'}">D${i}</span>${i < 7 ? `<div class="absolute top-4 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}</div>`;
      }
      
      if (claimedToday) btnContainer.innerHTML = `<button class="bg-emerald-900/50 border border-emerald-500/40 text-emerald-400 px-4 py-2 rounded-lg text-[10px] font-black uppercase opacity-80 cursor-not-allowed"><i class="fa-solid fa-check-double"></i></button>`;
      else btnContainer.innerHTML = `<button onclick="claimTask('dailyLogin', ${rewards[streak - 1]})" class="bg-gradient-to-r from-crypto-glow to-blue-500 text-crypto-dark px-4 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest">CLAIM</button>`;
    }

    function renderTasks() {
      const spContainer = document.getElementById('sponsor-container');
      const msContainer = document.getElementById('missions-container');
      spContainer.innerHTML = ''; msContainer.innerHTML = '';

      // Default Sponsor
      if (appState.user.sponsorAzx) spContainer.innerHTML += `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800"><div class="flex items-center gap-2.5"><div class="w-10 h-10 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center"><i class="fa-brands fa-telegram text-blue-400 text-lg"></i></div><div class="flex flex-col"><span class="text-[13px] font-black text-white">Join @azxcrypto</span><span class="text-emerald-400 text-[9px] font-bold uppercase mt-0.5">Completed</span></div></div><span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-lg border border-emerald-500/30"><i class="fa-solid fa-check-double"></i></span></div>`;
      else spContainer.innerHTML += `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-blue-500/30 shadow-[0_0_15px_rgba(59,130,246,0.15)]"><div class="flex items-center gap-2.5"><div class="w-10 h-10 rounded-lg bg-blue-500/20 border border-blue-500/40 flex items-center justify-center"><i class="fa-brands fa-telegram text-blue-400 text-lg"></i></div><div class="flex flex-col"><span class="text-[13px] font-black text-white">Join @azxcrypto</span><span class="text-crypto-glow text-[9px] font-bold uppercase mt-0.5">+${200 * appState.settings.xpMultiplier} XP</span></div></div><button onclick="claimDefaultSponsor()" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-3 py-1.5 rounded-lg uppercase shadow-lg">Join & Claim</button></div>`;

      // Custom Admin Tasks
      appState.custom_tasks.forEach(t => {
          const claimed = appState.tasks.includes(t.id);
          const limitReached = t.limit !== null && t.completions >= t.limit;
          if(limitReached && !claimed) return; // Hide exhausted tasks if not claimed
          
          let btnHtml = '';
          if (claimed) btnHtml = `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-lg border border-emerald-500/30"><i class="fa-solid fa-check-double"></i></span>`;
          else btnHtml = `<button onclick="initCustomTask('${t.id}')" class="text-[10px] font-black bg-gradient-to-r from-crypto-glow to-blue-500 text-crypto-dark px-3 py-1.5 rounded-lg uppercase shadow-lg">Claim</button>`;
          
          const icon = t.type === 'sponsor' ? 'fa-star text-amber-400' : 'fa-list-check text-blue-400';
          const rText = t.rewardType === 'usd' ? `+$${t.rewardAmount} USDT` : `+${t.rewardAmount * appState.settings.xpMultiplier} XP`;
          
          const html = `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-700/60"><div class="flex items-center gap-2.5"><div class="w-10 h-10 rounded-lg bg-slate-800/60 border border-slate-700 flex items-center justify-center"><i class="fa-solid ${icon} text-lg"></i></div><div class="flex flex-col"><span class="text-[12px] font-black text-white truncate max-w-[150px]">${t.title}</span><span class="text-crypto-glow text-[9px] font-bold uppercase mt-0.5">${rText}</span></div></div>${btnHtml}</div>`;
          
          if(t.type === 'sponsor') spContainer.innerHTML += html;
          else msContainer.innerHTML += html;
      });

      // Default Missions
      const limit = appState.settings.adLimit;
      const missionsList = [
        { id: 'watch5', label: 'Watch 5 Ads', target: 5 }, { id: 'watch15', label: 'Watch 15 Ads', target: 15 },
        { id: 'watch_all', label: `Watch ${limit} Ads`, target: limit }
      ];
      missionsList.forEach(m => {
        if(m.target > limit && m.id !== 'watch_all') return;
        const claimed = appState.tasks.includes(m.id); const canClaim = !claimed && appState.user.adsWatchedToday >= m.target;
        let btnHtml = '';
        if (claimed) btnHtml = `<span class="text-[9px] font-black bg-emerald-500/10 text-emerald-400 px-2.5 py-1.5 rounded-lg border border-emerald-500/30 shadow-inner"><i class="fa-solid fa-check-double"></i></span>`;
        else if (canClaim) btnHtml = `<button onclick="claimTask('${m.id}', 50)" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-3 py-1.5 rounded-lg shadow-lg uppercase tracking-wider">Claim</button>`;
        else btnHtml = `<span class="text-[10px] font-black bg-slate-800/60 text-slate-400 px-3 py-1.5 rounded-lg border border-slate-700 shadow-inner">+${50 * appState.settings.xpMultiplier} XP</span>`;
        msContainer.innerHTML += `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800/80"><div class="flex items-center gap-3"><div class="w-10 h-10 rounded-lg border bg-blue-500/10 border-blue-500/20 flex items-center justify-center"><i class="fa-solid fa-video text-blue-400 text-lg"></i></div><div class="flex flex-col"><span class="text-[13px] font-black text-white">${m.label}</span><span class="text-crypto-glow text-[9px] font-bold uppercase opacity-80 mt-0.5">Prog: ${Math.min(appState.user.adsWatchedToday, m.target)}/${m.target}</span></div></div>${btnHtml}</div>`;
      });
    }

    function initCustomTask(id) {
        const task = appState.custom_tasks.find(t => t.id === id);
        if(!task) return;
        tg.openTelegramLink(task.link);
        setTimeout(() => {
            if (task.requiresInput) {
                document.getElementById('ti-modal-title').innerText = task.title;
                document.getElementById('ti-modal-prompt').innerText = task.inputPrompt || "Provide the required info:";
                document.getElementById('ti-modal-taskid').value = id;
                document.getElementById('ti-modal-input').value = "";
                document.getElementById('task-input-modal').classList.remove('hidden');
                document.getElementById('task-input-modal').classList.add('flex');
            } else {
                claimTask(id, 0);
            }
        }, 1500);
    }
    
    function closeTaskInputModal() {
        document.getElementById('task-input-modal').classList.add('hidden');
        document.getElementById('task-input-modal').classList.remove('flex');
    }

    async function submitCustomTask() {
        const id = document.getElementById('ti-modal-taskid').value;
        const inputData = document.getElementById('ti-modal-input').value;
        if(!inputData || inputData.trim().length < 2) return showToast("Xəta", "Zəhmət olmasa məlumatı daxil edin.", "error");
        
        closeTaskInputModal();
        const res = await apiCall('claim_task', { taskId: id, taskInput: inputData });
        if(res && !res.error) showToast('Mükafat Qazanıldı!', 'Görev tamamlandı.', 'success');
    }

    async function claimDefaultSponsor() {
        tg.openTelegramLink('https://t.me/azxcrypto');
        setTimeout(async () => { 
            const res = await apiCall('claim_task', { taskId: 'sponsor_azx' }); 
            if(res && !res.error) showToast('Sponsor Task', `You earned XP!`, 'success'); 
        }, 1500);
    }

    async function claimTask(taskId, reward) {
        const res = await apiCall('claim_task', { taskId, reward });
        if(res && !res.error) showToast('Completed!', 'Reward claimed.', 'success');
    }

    async function watchAd() {
      const btn = document.getElementById('watch-ad-btn'); 
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-base"></i> <span>Loading...</span>`;
      btn.classList.add('opacity-80', 'pointer-events-none');
      
      let adShown = false;
      const blocks = appState.settings.adBlockIds || ["int-35545"];
      
      for(let block of blocks) {
        try {
          if (window.Adsgram) {
            const ctrl = window.Adsgram.init({ blockId: block.trim() });
            await ctrl.show();
            adShown = true; break;
          }
        } catch (e) { console.warn("Ad skip"); }
      }
      
      if(adShown) {
         const res = await apiCall('watch_ad');
         if(res && res.gained) showToast('Reward Granted!', `You earned +${res.gained} XP.`, 'success');
      } else showToast('Opps', 'No ads available right now.', 'error');
      
      btn.innerHTML = originalHTML; btn.classList.remove('opacity-80', 'pointer-events-none');
    }

    async function openBox(type) {
      if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('heavy');
      const res = await apiCall('open_box', { boxType: type });
      if(res && !res.error) {
        if(res.jackpot) showToast('HUGE JACKPOT! 脂', `You won $${res.reward.toFixed(2)} USDT!`, 'jackpot');
        else showToast('Box Opened!', `You won $${res.reward.toFixed(2)} USDT!`, 'success');
      }
    }

    async function requestWithdrawal() {
      const address = document.getElementById('wallet-address').value; const amount = parseFloat(document.getElementById('withdraw-amount').value);
      if (!address || address.length < 10) return showToast('Error', 'Invalid TON address.', 'error');
      if (isNaN(amount) || amount < 10) return showToast('Error', 'Min withdrawal is $10.', 'error');
      const res = await apiCall('withdraw', { amount, address });
      if(res && !res.error) { showToast('Success', `Withdrawal submitted.`, 'success'); document.getElementById('wallet-address').value = ''; document.getElementById('withdraw-amount').value = ''; }
    }

    function renderWithdrawHistory() {
      const c = document.getElementById('withdraw-history-container');
      if (appState.withdrawals.length === 0) return c.innerHTML = `<p class="text-[10px] text-center font-bold text-slate-500 uppercase py-4">History is Empty</p>`;
      c.innerHTML = appState.withdrawals.map(r => {
          let col = r.status==='Approved'?'text-emerald-400':r.status==='Rejected'?'text-red-400':'text-amber-400';
          return `<div class="glass-card rounded-xl p-3 flex justify-between border border-slate-800"><div><p class="text-xs font-black text-white">${r.id} <span class="text-[9px] text-slate-500">${r.date}</span></p><p class="text-[9px] text-blue-400 font-mono">${r.address}</p></div><div class="text-right"><p class="text-[13px] font-black text-white">-$${formatNum(r.amount, true)}</p><p class="text-[8px] font-black ${col} uppercase">${r.status}</p></div></div>`;
      }).join('');
    }

    function renderReferrals() {
      const c = document.getElementById('referral-list-container');
      if (appState.referrals.length === 0) return c.innerHTML = `<p class="text-[10px] text-center font-bold text-slate-500 uppercase py-4">No referrals yet</p>`;
      c.innerHTML = appState.referrals.map(r => `<div class="glass-card rounded-xl p-3 border border-slate-800"><div class="flex justify-between items-center"><div class="flex gap-2"><div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-xs font-black">${r.name.charAt(0)}</div><div><p class="text-xs font-black text-white">${r.name}</p></div></div><span class="text-[8px] font-black uppercase ${r.status==='Approved'?'text-emerald-400':'text-amber-400'}">${r.status}</span></div></div>`).join('');
    }

    function copyRefLink() { navigator.clipboard.writeText(document.getElementById('ref-link-input').value).then(() => showToast("Success", "Link copied!", "success")); }
    function shareReferralTelegram() { tg.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(`https://t.me/XPVersebot?startapp=${appState.user.tgId}`)}&text=${encodeURIComponent(`Play XPVerse and earn USDT!`)}`); }

    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));
      const targetView = document.getElementById(`view-${tabId}`);
      if(targetView) targetView.classList.remove('hidden');
      const tabTarget = document.querySelector(`[data-target="${tabId}"]`);
      if (tabTarget) tabTarget.classList.add('nav-active');
      
      if(tabId === 'admin') loadAdminDashboard();
      
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // --- ADMIN PANEL FUNCTIONS ---
    async function loadAdminDashboard() {
        const res = await apiCall('admin_dashboard');
        if(res && res.stats) {
            adminData = res;
            document.getElementById('adm-stat-users').innerText = res.stats.users;
            document.getElementById('adm-stat-usd').innerText = `$${formatNum(res.stats.usd, true)}`;
            document.getElementById('adm-stat-ads').innerText = formatNum(res.stats.ads);
            document.getElementById('adm-stat-xp').innerText = formatNum(res.stats.xp);
            
            // Populate Settings
            document.getElementById('set-maintenance').checked = res.settings.maintenance;
            document.getElementById('set-adlimit').value = res.settings.adLimit;
            document.getElementById('set-resethour').value = res.settings.resetHour;
            document.getElementById('set-adblock').value = res.settings.adBlockIds.join(', ');
            document.getElementById('set-2xxp').checked = res.settings.xpMultiplier > 1;
            
            renderAdminUsers(); renderAdminWithdrawals(); renderAdminTasks(); renderAdminErrors();
        }
    }

    function switchAdminTab(tId) {
        document.querySelectorAll('.admin-section').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.admin-tab').forEach(el => { el.classList.remove('bg-red-600/20','text-red-400','border-red-500/50'); el.classList.add('text-slate-400'); });
        document.getElementById(`admin-sec-${tId}`).classList.remove('hidden');
        const tab = document.getElementById(`tab-${tId}`);
        tab.classList.remove('text-slate-400'); tab.classList.add('bg-red-600/20','text-red-400','border-red-500/50');
    }

    function filterAdminUsers() {
        const q = document.getElementById('admin-user-search').value.toLowerCase();
        renderAdminUsers(q);
    }

    function renderAdminUsers(filter = "") {
        const c = document.getElementById('admin-user-list');
        const users = adminData.all_users.filter(u => u.tgId.includes(filter) || (u.username||'').toLowerCase().includes(filter) || u.firstName.toLowerCase().includes(filter));
        
        c.innerHTML = users.map(u => {
            const statColor = u.status_text === 'Aktiv' ? 'text-emerald-400' : 'text-slate-500';
            const banTag = u.banned ? `<span class="bg-red-600 text-white text-[8px] px-1 rounded ml-1">BANNED</span>` : '';
            return `<div onclick="openAdminUserModal('${u.tgId}')" class="bg-[#050511] p-2 rounded-lg border border-slate-700 flex justify-between items-center cursor-pointer hover:bg-slate-800 transition">
                <div><p class="text-[11px] font-black text-white">${u.firstName} ${banTag}</p><p class="text-[9px] text-slate-400 font-mono">${u.tgId}</p></div>
                <div class="text-right"><p class="text-[11px] font-black text-emerald-400">$${formatNum(u.usd, true)}</p><p class="text-[8px] font-bold ${statColor} uppercase">${u.status_text}</p></div>
            </div>`;
        }).join('');
    }

    function openAdminUserModal(uid) {
        const u = adminData.all_users.find(x => x.tgId === uid);
        if(!u) return;
        currentAdminSelectedUid = uid;
        document.getElementById('a-modal-name').innerText = u.firstName;
        document.getElementById('a-modal-uid').innerText = u.tgId;
        const statColor = u.status_text === 'Aktiv' ? 'text-emerald-400' : 'text-slate-500';
        document.getElementById('a-modal-status').innerHTML = `<span class="${statColor}">${u.status_text}</span> - ${u.banned?'<span class="text-red-500">BANNED</span>':'<span class="text-emerald-500">AKTİV HESAB</span>'}`;
        document.getElementById('a-modal-xp').innerText = formatNum(u.xp);
        document.getElementById('a-modal-usd').innerText = formatNum(u.usd, true);
        document.getElementById('a-modal-adst').innerText = u.adsWatchedToday;
        document.getElementById('a-modal-ads').innerText = u.totalAdsWatched;
        
        // Find referrals count for this user
        const refCount = (appState.referrals || []).length; // Need server full data for accurate, simplified here
        document.getElementById('a-modal-reg').innerText = u.lastResetDay;
        
        document.getElementById('admin-user-modal').classList.remove('hidden');
        document.getElementById('admin-user-modal').classList.add('flex');
    }

    function closeAdminUserModal() {
        document.getElementById('admin-user-modal').classList.add('hidden');
        document.getElementById('admin-user-modal').classList.remove('flex');
    }

    async function adminUserBalance(type) {
        const usd = document.getElementById(`a-modal-${type}usd`).value;
        const xp = document.getElementById(`a-modal-${type}xp`).value;
        const res = await apiCall('admin_action_user', { targetUid: currentAdminSelectedUid, userAction: type === 'add' ? 'add_balance' : 'sub_balance', addUsd: usd, addXp: xp, subUsd: usd, subXp: xp });
        if(res && !res.error) { showToast("Uğurlu", res.message, "success"); closeAdminUserModal(); loadAdminDashboard(); }
    }

    async function adminUserAction(act) {
        const res = await apiCall('admin_action_user', { targetUid: currentAdminSelectedUid, userAction: act });
        if(res && !res.error) { showToast("Uğurlu", res.message, "success"); closeAdminUserModal(); loadAdminDashboard(); }
    }
    
    async function adminBanPreemptive() {
        const uid = prompt("Banlamaq istədiyiniz UID-ni daxil edin (İstifadəçi bota girməmiş olsa belə):");
        if(uid && uid.length > 5) {
            const res = await apiCall('admin_ban_uid', { targetUid: uid });
            if(res && !res.error) { showToast("Uğurlu", res.message, "success"); loadAdminDashboard(); }
        }
    }

    function renderAdminWithdrawals() {
        const c = document.getElementById('admin-withdrawal-list');
        c.innerHTML = adminData.all_withdrawals.map(w => {
            let actBtns = w.status === 'Pending' ? `<button onclick="adminApproveWithdraw('${w.user_id}', ${w.idx}, 'approve')" class="text-[9px] bg-emerald-600 px-2 py-1 rounded text-white font-black">Onayla</button><button onclick="adminApproveWithdraw('${w.user_id}', ${w.idx}, 'reject')" class="text-[9px] bg-red-600 px-2 py-1 rounded text-white font-black ml-1">Rədd et</button>` : `<span class="text-[9px] text-slate-500">${w.status}</span>`;
            return `<div class="bg-[#050511] p-2 rounded-lg border border-slate-700 flex justify-between items-center">
                <div><p class="text-[11px] font-black text-white">UID: ${w.user_id}</p><p class="text-[9px] text-slate-400 font-mono">${w.address}</p></div>
                <div class="text-right flex flex-col items-end"><p class="text-[11px] font-black text-emerald-400">$${formatNum(w.amount, true)}</p><div class="mt-1">${actBtns}</div></div>
            </div>`;
        }).join('');
    }

    async function adminApproveWithdraw(uid, idx, act) {
        const res = await apiCall('admin_action_withdraw', { targetUid: uid, idx: idx, withdrawAction: act });
        if(res && !res.error) { showToast("Uğurlu", res.message, "success"); loadAdminDashboard(); }
    }

    async function saveAdminSettings() {
        const blocks = document.getElementById('set-adblock').value.split(',').map(s=>s.trim()).filter(Boolean);
        const maint = document.getElementById('set-maintenance').checked;
        const xpm = document.getElementById('set-2xxp').checked ? 2 : 1;
        const lim = parseInt(document.getElementById('set-adlimit').value);
        const rh = parseInt(document.getElementById('set-resethour').value);
        
        const res = await apiCall('admin_update_settings', { blockIds: blocks, maintenance: maint, adLimit: lim, resetHour: rh, xpMultiplier: xpm });
        if(res && !res.error) showToast("Uğurlu", res.message, "success");
    }

    async function adminGlobalResetAds() {
        if(confirm("Bütün istifadəçilərin günlük reklam limiti sıfırlanacaq. Əminsiniz?")) {
            const res = await apiCall('admin_global_reset_ads');
            if(res && !res.error) showToast("Uğurlu", res.message, "success");
        }
    }

    async function adminGlobalClearBalance() {
        if(confirm("DİQQƏT! Bütün istifadəçilərin XP və USDT balansları SIFIRLANACAQ. Əminsiniz?")) {
            const res = await apiCall('admin_global_clear_balance');
            if(res && !res.error) showToast("Uğurlu", res.message, "success");
        }
    }

    async function adminAddAdmin() {
        const uid = document.getElementById('add-admin-uid').value;
        if(uid.length > 5) {
            const res = await apiCall('admin_add_admin', { targetUid: uid });
            if(res && !res.error) { showToast("Uğurlu", res.message, "success"); document.getElementById('add-admin-uid').value = ''; }
        }
    }

    // Tasks Management
    async function adminAddTask() {
        const payload = {
            title: document.getElementById('nt-title').value,
            type: document.getElementById('nt-type').value,
            rewardType: document.getElementById('nt-rtype').value,
            rewardAmount: document.getElementById('nt-ramount').value,
            link: document.getElementById('nt-link').value,
            limit: document.getElementById('nt-limit').value,
            requiresInput: document.getElementById('nt-reqinput').checked,
            inputPrompt: document.getElementById('nt-prompt').value
        };
        if(!payload.title || !payload.rewardAmount || !payload.link) return showToast("Xəta", "Bütün xanaları doldurun.", "error");
        const res = await apiCall('admin_add_task', payload);
        if(res && !res.error) { showToast("Uğurlu", res.message, "success"); document.getElementById('nt-title').value = ''; loadAdminDashboard(); }
    }

    function renderAdminTasks() {
        const c = document.getElementById('admin-custom-tasks-list');
        c.innerHTML = appState.custom_tasks.map(t => `<div class="bg-[#050511] p-2 rounded-lg border border-slate-700 flex justify-between items-center"><div><p class="text-[11px] font-black text-white">${t.title}</p><p class="text-[9px] text-slate-400">Limit: ${t.limit||'Yoxdur'} | İcra: ${t.completions}</p></div><button onclick="adminDelTask('${t.id}')" class="bg-red-600/20 text-red-500 px-2 py-1 rounded text-[10px] border border-red-500/30 font-black"><i class="fa-solid fa-trash"></i></button></div>`).join('');
    }

    async function adminDelTask(id) {
        if(confirm("Bu görevi silmək istəyirsiniz?")) {
            const res = await apiCall('admin_del_task', { taskId: id });
            if(res && !res.error) { showToast("Uğurlu", res.message, "success"); loadAdminDashboard(); }
        }
    }

    function renderAdminErrors() {
        const c = document.getElementById('admin-error-list');
        if(!adminData.all_errors || adminData.all_errors.length === 0) return c.innerHTML = '<p class="text-[10px] text-slate-500">Xəta tapılmadı.</p>';
        c.innerHTML = adminData.all_errors.map(e => `<div class="bg-[#050511] p-2 rounded-lg border border-red-900/50">
            <div class="flex justify-between"><span class="text-[10px] font-black text-red-400">${e.type}</span><span class="text-[9px] text-slate-500">${e.time}</span></div>
            <p class="text-[9px] text-slate-300 mt-1">UID: ${e.uid}</p>
            <p class="text-[9px] text-white font-mono break-all mt-1 bg-slate-900 p-1 rounded">${e.message}</p>
        </div>`).join('');
    }

    // SERVER-SYNCED TIMER
    let timeRemaining = 0; let timerInterval = null;
    function startServerTimer(currentServer, resetServer) {
        if(timerInterval) clearInterval(timerInterval);
        timeRemaining = resetServer - currentServer;
        
        timerInterval = setInterval(() => {
            if (timeRemaining > 0) timeRemaining--;
            if (timeRemaining <= 0) { 
                document.getElementById('reset-timer').innerText = "00:00:00"; 
                setTimeout(() => { window.location.reload(); }, 2000); return; 
            }
            const h = Math.floor(timeRemaining / 3600); const m = Math.floor((timeRemaining % 3600) / 60); const s = Math.floor(timeRemaining % 60);
            document.getElementById('reset-timer').innerText = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }, 1000);
    }
  </script>
</body>
</html>
