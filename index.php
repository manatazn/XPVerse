<?php
// XPVERSE - SINGLE FILE ARCHITECTURE
// All server-side logic and persistence is handled here.

error_reporting(0); // Suppress errors for clean JSON API responses in production
date_default_timezone_set('Europe/Moscow'); // Server time consistency in MSK (Russia)
$dataDir = __DIR__ . '/data';

// Create data directory if it doesn't exist
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

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

// Admin Action Logger
function logAdminAction($action, $details) {
    $logs = readDB('admin_logs.json');
    array_unshift($logs, [
        'time' => date('Y-m-d H:i:s'),
        'action' => $action,
        'details' => $details
    ]);
    if (count($logs) > 50) $logs = array_slice($logs, 0, 50);
    writeDB('admin_logs.json', $logs);
}

// Ensure settings exist (Multiple APIs Supported)
$settings = readDB('settings.json');
if (empty($settings)) {
    $settings = [
        'adBlockIds' => ['int-35545'],
        'maintenance' => false,
        'doubleXpUntil' => 0,
        'dynamicTasks' => []
    ];
    writeDB('settings.json', $settings);
}

// Check Double XP Status
$isDoubleXp = (isset($settings['doubleXpUntil']) && time() < $settings['doubleXpUntil']);
$xpMult = $isDoubleXp ? 2 : 1;

// Logical Date Calculation: New day starts at 03:00 MSK
$now = new DateTime('now');
$hour = (int)$now->format('H');
$logicalDate = clone $now;
if ($hour < 3) {
    $logicalDate->modify('-1 day');
}
$today = $logicalDate->format('Y-m-d'); // This is the logic "day" for reset tracking

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action']) || !isset($input['tgId'])) {
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    $action = $input['action'];
    $uid = (string)$input['tgId'];
    
    // Check Maintenance Mode
    if (isset($settings['maintenance']) && $settings['maintenance'] === true && $uid !== '5461064199' && strpos($action, 'admin_') !== 0) {
        echo json_encode(['error' => 'MAINTENANCE', 'message' => 'The bot is currently undergoing maintenance. We will be back shortly!']);
        exit;
    }

    $users = readDB('users.json');
    $referrals = readDB('referrals.json');
    $rewards = readDB('rewards.json');
    $withdrawals = readDB('withdrawals.json');
    $tasks = readDB('tasks.json');
    
    // Check Ban Status BEFORE anything else (unless it's the admin)
    if (isset($users[$uid]['banned']) && $users[$uid]['banned'] === true && $uid !== '5461064199') {
        echo json_encode(['error' => 'BANNED', 'message' => 'Your account has been banned by the administrator.']);
        exit;
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
            'dailyXp' => 0,
            'usd' => 0.00,
            'level' => 1,
            'adsWatchedToday' => 0,
            'totalAdsWatched' => 0,
            'tasksCompleted' => 0,
            'boxesOpened' => 0,
            'streak' => 1,
            'lastResetDay' => $today, // Uses logic day
            'referrer' => null,
            'sponsorAzx' => false,
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
        $users[$uid]['lastActive'] = $now->format('Y-m-d H:i:s');
    }

    // Daily Reset Logic based on Logical Date (03:00 MSK)
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
    
    // Ensure dailyXp exists for older users
    if (!isset($users[$uid]['dailyXp'])) $users[$uid]['dailyXp'] = 0;

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
    function evaluateReferralProgress($refUid, &$users, &$referrals, &$rewards, $xpMult) {
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
                    
                    $rewardXp = 250 * $xpMult;
                    $users[$referrerId]['xp'] += $rewardXp;
                    $users[$referrerId]['totalXp'] += $rewardXp;
                    $users[$referrerId]['dailyXp'] += $rewardXp;
                    $users[$referrerId]['level'] = calcLevel($users[$referrerId]['totalXp']);
                    $users[$referrerId]['usd'] += 0.025;
                    
                    if (!isset($rewards[$referrerId])) $rewards[$referrerId] = [];
                    $refName = trim(($refUser['firstName'] ?? '') . ' ' . ($refUser['lastName'] ?? ''));
                    array_unshift($rewards[$referrerId], [
                        'title' => 'Referral Bonus',
                        'desc' => "Referral: " . $refName,
                        'xp' => $rewardXp,
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
    
    // Server time for accurate frontend countdown (Bypasses phone time)
    $resetTarget = clone $now;
    $resetTarget->setTime(3, 0, 0); // Target 03:00 MSK
    if ($hour >= 3) {
        $resetTarget->modify('+1 day');
    }
    $response['serverTime'] = $now->getTimestamp();
    $response['serverResetTime'] = $resetTarget->getTimestamp(); 
    $response['settings'] = $settings;
    $response['isDoubleXp'] = $isDoubleXp;

    // --- ADMIN PANEL SECURE ROUTES ---
    if (strpos($action, 'admin_') === 0) {
        if ($uid !== '5461064199') {
            echo json_encode(['error' => 'Security Breach: Unauthorized Access']); exit;
        }
        if (!isset($input['adminCode']) || $input['adminCode'] !== 'PZX9N4ML2DK') {
            echo json_encode(['error' => 'Security Breach: Invalid Admin Code']); exit;
        }

        if ($action === 'admin_dashboard') {
            $totalUsd = 0; $totalAds = 0; $totalTasks = 0; $totalXp = 0; $totalUsers = count($users);
            $totalRefs = 0;
            foreach($users as $uUid => $u) {
                $totalUsd += $u['usd'];
                $totalAds += $u['totalAdsWatched'];
                $totalTasks += $u['tasksCompleted'];
                $totalXp += $u['totalXp'];
                
                // attach ref count for UI
                $uRefs = isset($referrals[$uUid]) ? count($referrals[$uUid]) : 0;
                $users[$uUid]['refCount'] = $uRefs;
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
                    $allWithdrawals[] = $w;
                }
            }
            
            $response['stats'] = [
                'users' => $totalUsers, 'usd' => $totalUsd, 'ads' => $totalAds, 
                'tasks' => $totalTasks, 'xp' => $totalXp, 'refs' => $totalRefs
            ];
            $response['all_users'] = array_values($users);
            $response['all_withdrawals'] = $allWithdrawals;
            $response['admin_logs'] = readDB('admin_logs.json');
            echo json_encode($response); exit;
        }

        if ($action === 'admin_update_settings') {
            if (isset($input['blockIds'])) $settings['adBlockIds'] = $input['blockIds'];
            if (isset($input['maintenance'])) {
                $settings['maintenance'] = (bool)$input['maintenance'];
                logAdminAction('Toggle Maintenance', 'Maintenance mode set to: ' . ($settings['maintenance'] ? 'ON' : 'OFF'));
            }
            if (isset($input['doubleXpHours'])) {
                $hours = (int)$input['doubleXpHours'];
                if ($hours > 0) {
                    $settings['doubleXpUntil'] = time() + ($hours * 3600);
                    logAdminAction('Double XP', "Activated for $hours hours");
                } else {
                    $settings['doubleXpUntil'] = 0;
                    logAdminAction('Double XP', "Deactivated");
                }
            }
            writeDB('settings.json', $settings);
            $response['message'] = 'Settings updated successfully.';
            $response['settings'] = $settings;
            echo json_encode($response); exit;
        }

        if ($action === 'admin_task_manager') {
            if ($input['taskAction'] === 'add') {
                $newTask = [
                    'id' => 'dt_' . time(),
                    'title' => $input['title'],
                    'url' => $input['url'],
                    'reward' => (int)$input['reward']
                ];
                if (!isset($settings['dynamicTasks'])) $settings['dynamicTasks'] = [];
                $settings['dynamicTasks'][] = $newTask;
                logAdminAction('Task Added', "Title: {$newTask['title']}, Reward: {$newTask['reward']}");
            } else if ($input['taskAction'] === 'delete') {
                $tid = $input['taskId'];
                $settings['dynamicTasks'] = array_values(array_filter($settings['dynamicTasks'], function($t) use ($tid) {
                    return $t['id'] !== $tid;
                }));
                logAdminAction('Task Deleted', "Task ID: $tid");
            }
            writeDB('settings.json', $settings);
            $response['settings'] = $settings;
            $response['message'] = 'Tasks updated.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_reset_ads') {
            foreach($users as $k => $u) {
                $users[$k]['adsWatchedToday'] = 0;
            }
            writeDB('users.json', $users);
            logAdminAction('Global Reset', 'All user daily ad limits reset to 0');
            $response['message'] = 'All Ad Limits Reset';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_wipe_data') {
            // Keep admin user, wipe rest
            $adminUser = $users['5461064199'] ?? null;
            $users = [];
            if ($adminUser) $users['5461064199'] = $adminUser;
            
            writeDB('users.json', $users);
            writeDB('referrals.json', []);
            writeDB('rewards.json', []);
            writeDB('withdrawals.json', []);
            writeDB('tasks.json', []);
            writeDB('admin_logs.json', []);
            
            logAdminAction('System Wipe', 'All user data wiped (except admin)');
            $response['message'] = 'Bot data wiped completely.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_action_user') {
            $targetUid = $input['targetUid'];
            $act = $input['userAction'];
            
            if (!isset($users[$targetUid])) {
                echo json_encode(['error' => 'User not found']); exit;
            }

            if ($act === 'ban') {
                $users[$targetUid]['banned'] = true;
                logAdminAction('User Ban', "Banned UID: $targetUid");
            } elseif ($act === 'unban') {
                $users[$targetUid]['banned'] = false;
                logAdminAction('User Unban', "Unbanned UID: $targetUid");
            } elseif ($act === 'update_balance') {
                $oldUsd = $users[$targetUid]['usd'];
                $oldXp = $users[$targetUid]['xp'];
                
                $users[$targetUid]['usd'] = max(0, (float)$input['newUsd']);
                $users[$targetUid]['xp'] = max(0, (int)$input['newXp']);
                
                // If admin increases XP, add it to totalXp so it looks natural
                if ($users[$targetUid]['xp'] > $oldXp) {
                    $diff = $users[$targetUid]['xp'] - $oldXp;
                    $users[$targetUid]['totalXp'] += $diff;
                }
                
                logAdminAction('Balance Update', "UID: $targetUid | USD: $oldUsd -> {$users[$targetUid]['usd']} | XP: $oldXp -> {$users[$targetUid]['xp']}");
            }
            writeDB('users.json', $users);
            $response['message'] = 'User updated successfully.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_action_withdraw') {
            $targetUid = $input['targetUid'];
            $idx = $input['idx'];
            $wAct = $input['withdrawAction'];

            if (isset($withdrawals[$targetUid][$idx])) {
                if ($withdrawals[$targetUid][$idx]['status'] === 'Pending') {
                    $amount = $withdrawals[$targetUid][$idx]['amount'];
                    if ($wAct === 'approve') {
                        $withdrawals[$targetUid][$idx]['status'] = 'Approved';
                        logAdminAction('Withdraw Approved', "UID: $targetUid | Amount: $$amount");
                    } else if ($wAct === 'reject') {
                        $withdrawals[$targetUid][$idx]['status'] = 'Rejected';
                        $users[$targetUid]['usd'] += $amount;
                        writeDB('users.json', $users);
                        logAdminAction('Withdraw Rejected', "UID: $targetUid | Amount: $$amount returned to balance");
                    }
                    writeDB('withdrawals.json', $withdrawals);
                    $response['message'] = 'Withdrawal processed.';
                } else {
                    echo json_encode(['error' => 'Already processed']); exit;
                }
            } else {
                echo json_encode(['error' => 'Withdrawal not found']); exit;
            }
            echo json_encode($response); exit;
        }
    }
    // --- END ADMIN ROUTES ---

    // NORMAL USER ACTIONS
    switch ($action) {
        case 'sync':
            // Just returning the state without any modification
            break;

        case 'watch_ad':
            if ($users[$uid]['adsWatchedToday'] < 30) {
                $rewardXp = 20 * $xpMult;
                $users[$uid]['adsWatchedToday'] += 1;
                $users[$uid]['totalAdsWatched'] += 1;
                $users[$uid]['xp'] += $rewardXp;
                $users[$uid]['totalXp'] += $rewardXp;
                $users[$uid]['dailyXp'] += $rewardXp;
                $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                evaluateReferralProgress($uid, $users, $referrals, $rewards, $xpMult);
                $response['earned'] = $rewardXp;
            } else {
                $response['error'] = 'Ad limit reached';
            }
            break;

        case 'claim_task':
            $taskId = $input['taskId'] ?? '';
            if ($taskId === 'sponsor_azx') {
                if (empty($users[$uid]['sponsorAzx'])) {
                    $rewardXp = 200 * $xpMult;
                    $users[$uid]['sponsorAzx'] = true;
                    $users[$uid]['xp'] += $rewardXp;
                    $users[$uid]['totalXp'] += $rewardXp;
                    $users[$uid]['dailyXp'] += $rewardXp;
                    $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                    evaluateReferralProgress($uid, $users, $referrals, $rewards, $xpMult);
                    $response['earned'] = $rewardXp;
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
                    $rewardXp = (int)($input['reward'] ?? 0) * $xpMult;
                    if ($rewardXp > 0 && $rewardXp <= 5000) { 
                        $tasks[$uid][] = $taskId;
                        $users[$uid]['tasksCompleted'] += 1;
                        $users[$uid]['xp'] += $rewardXp;
                        $users[$uid]['totalXp'] += $rewardXp;
                        $users[$uid]['dailyXp'] += $rewardXp;
                        $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                        evaluateReferralProgress($uid, $users, $referrals, $rewards, $xpMult);
                        writeDB('tasks.json', $tasks);
                        $response['earned'] = $rewardXp;
                    }
                } else {
                    $response['error'] = 'Task already claimed today.';
                }
            }
            break;

        case 'open_box':
            $type = $input['boxType'] ?? '';
            $costs = ['bronze' => 10000, 'silver' => 50000, 'gold' => 100000];
            
            if (isset($costs[$type]) && $users[$uid]['xp'] >= $costs[$type]) {
                $users[$uid]['xp'] -= $costs[$type]; // Current XP drops, but Total XP stays same
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
                $response['error'] = 'Not enough Current XP';
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
    /* Admin Dashboard Scroll for tables */
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

  <!-- Maintenance Ban overlay handled dynamically in JS -->

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
        <!-- BALANCE: Current Spendable XP -->
        <div class="bg-[#050511] border border-blue-500/30 px-2.5 py-1 rounded-lg flex items-center gap-1.5" onclick="showToast('Balance', 'This is your current spendable XP.', 'info')">
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
      
      <!-- 2x XP Banner -->
      <div id="double-xp-banner" class="hidden bg-gradient-to-r from-purple-600 via-pink-500 to-red-500 text-white font-black text-[10px] uppercase tracking-widest rounded-xl p-2 text-center animate-pulse shadow-[0_0_15px_rgba(236,72,153,0.5)]">
         <i class="fa-solid fa-fire mr-1"></i> 2x XP Event Active! Earn double from Ads & Tasks! <i class="fa-solid fa-fire ml-1"></i>
      </div>

      <div class="relative glass-card rounded-[1.5rem] p-5 text-center border-t border-t-blue-400/20 overflow-hidden flex flex-col items-center justify-center min-h-[220px]">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-48 h-48 bg-blue-500/20 rounded-full filter blur-[40px] pointer-events-none animate-pulse-fast"></div>
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-900/60 to-[#050511] border border-blue-400/40 flex items-center justify-center mb-3 shadow-[0_0_20px_rgba(59,130,246,0.4)]">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow drop-shadow-[0_0_10px_rgba(0,240,255,0.9)]"></i>
          </div>
          <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.25em] mb-1 opacity-90">Current Balance</p>
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
        <span>Watch Ad <span id="ad-reward-text" class="text-cyan-200 ml-1">+20 XP</span></span>
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
            <i class="fa-solid fa-star text-amber-400"></i> Sponsor Task
        </h3>
        <div id="sponsor-container" class="space-y-3"></div>
      </div>
      
      <!-- Dynamic / Admin Tasks -->
      <div id="dynamic-tasks-section" class="mt-6 mb-4 hidden">
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 flex items-center gap-2">
            <i class="fa-solid fa-bolt text-crypto-glow"></i> Special Missions
        </h3>
        <div id="dynamic-tasks-container" class="space-y-3"></div>
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
        <p class="text-[11px] text-amber-400 mt-0.5 uppercase tracking-widest font-bold">Spend Current XP, Win USDT</p>
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
        
        <!-- SECURE ADMIN BUTTON (Injected ONLY for specific UID) -->
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
        <!-- LIFETIME XP -->
        <div class="glass-card p-4 rounded-xl border-t border-t-crypto-glow/40 shadow-md flex flex-col items-center justify-center text-center">
            <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center mb-1.5">
                <i class="fa-solid fa-bolt text-crypto-glow text-sm"></i>
            </div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Lifetime XP (Total)</p>
            <p id="profile-stat-xp" class="text-lg font-black text-white">0</p>
        </div>
        <div class="glass-card p-4 rounded-xl border-t border-t-emerald-500/40 shadow-md flex flex-col items-center justify-center text-center">
            <div class="w-8 h-8 rounded-lg bg-emerald-500/10 flex items-center justify-center mb-1.5">
                <i class="fa-solid fa-wallet text-emerald-400 text-sm"></i>
            </div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Total Balance</p>
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
        <h2 class="text-2xl font-black text-red-500 tracking-tight drop-shadow-lg flex items-center justify-center gap-2"><i class="fa-solid fa-shield-halved"></i> ADMIN PANEL</h2>
      </div>

      <div class="flex gap-2 mb-2 bg-[#050511] p-1 rounded-lg border border-slate-700/50">
          <button onclick="switchAdminTab('dashboard')" class="admin-tab flex-1 py-2 text-[10px] font-black uppercase rounded bg-red-600/20 text-red-400 border border-red-500/50 transition" id="tab-dashboard">Dash</button>
          <button onclick="switchAdminTab('users')" class="admin-tab flex-1 py-2 text-[10px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-users">Users</button>
          <button onclick="switchAdminTab('withdrawals')" class="admin-tab flex-1 py-2 text-[10px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-withdrawals">Withdraw</button>
          <button onclick="switchAdminTab('settings')" class="admin-tab flex-1 py-2 text-[10px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-settings">Settings</button>
      </div>

      <!-- Dashboard Stats -->
      <div id="admin-sec-dashboard" class="admin-section space-y-3">
          <div class="grid grid-cols-2 gap-2">
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Total Users</p>
                  <p id="adm-stat-users" class="text-lg font-black text-blue-400">0</p>
              </div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">USD Generated</p>
                  <p id="adm-stat-usd" class="text-lg font-black text-emerald-400">$0</p>
              </div>
          </div>
          
          <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest pl-1 mt-4 border-t border-slate-800 pt-3">Recent Admin Actions (Logs)</h3>
          <div id="admin-action-logs" class="space-y-2 max-h-[300px] overflow-y-auto admin-scroll">
              <!-- JS Populated -->
          </div>
      </div>

      <!-- Users Management (New Detailed Layout) -->
      <div id="admin-sec-users" class="admin-section hidden space-y-3">
          <input type="text" id="admin-user-search" onkeyup="filterAdminUsers()" placeholder="Search UID or Username..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white focus:border-blue-500 outline-none">
          <div class="max-h-[65vh] overflow-y-auto admin-scroll space-y-3 pr-1" id="admin-user-list">
              <!-- JS Populated with beautiful cards -->
          </div>
      </div>

      <!-- Withdrawals -->
      <div id="admin-sec-withdrawals" class="admin-section hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="admin-withdrawal-list">
              <!-- JS Populated -->
          </div>
      </div>

      <!-- Settings & Tasks -->
      <div id="admin-sec-settings" class="admin-section hidden space-y-3">
          
          <!-- System Toggles -->
          <div class="glass-card rounded-xl p-4 border border-slate-700 space-y-4">
              <div class="flex justify-between items-center bg-[#050511] p-3 rounded-lg border border-slate-800">
                  <div>
                      <h4 class="text-xs font-black text-white">Maintenance Mode</h4>
                      <p class="text-[9px] text-slate-500">Only admin can enter</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="admin-maint-toggle" class="sr-only peer" onchange="toggleMaintenance()">
                    <div class="w-9 h-5 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-red-500"></div>
                  </label>
              </div>

              <div class="bg-[#050511] p-3 rounded-lg border border-slate-800 flex items-center gap-2">
                  <div class="flex-1">
                      <h4 class="text-xs font-black text-crypto-glow">Enable 2x XP Event</h4>
                      <p class="text-[9px] text-slate-500">Duration in hours (0 to disable)</p>
                  </div>
                  <input type="number" id="admin-double-xp-hours" placeholder="Hours" class="w-16 bg-slate-800 border border-slate-600 rounded text-xs text-center text-white py-1">
                  <button onclick="setDoubleXp()" class="bg-blue-600 text-white px-2 py-1 rounded text-[10px] font-black uppercase">Set</button>
              </div>
          </div>

          <!-- Danger Zone -->
          <div class="glass-card rounded-xl p-4 border border-red-900/50 bg-red-900/10">
              <h3 class="text-[10px] font-black text-red-500 uppercase tracking-widest mb-3"><i class="fa-solid fa-triangle-exclamation"></i> Danger Zone</h3>
              <div class="flex gap-2">
                  <button onclick="resetAllAds()" class="flex-1 bg-orange-600 text-white py-2 rounded text-[10px] font-black uppercase tracking-wider shadow-lg">Reset All Daily Ads</button>
                  <button onclick="wipeBotData()" class="flex-1 bg-red-700 text-white py-2 rounded text-[10px] font-black uppercase tracking-wider shadow-lg">Wipe All Data</button>
              </div>
          </div>

          <!-- Dynamic Tasks Manager -->
          <div class="glass-card rounded-xl p-4 border border-slate-700">
              <h3 class="text-[10px] font-black text-crypto-glow uppercase tracking-widest mb-3 border-b border-slate-700 pb-2"><i class="fa-solid fa-list-check"></i> Add Custom Task</h3>
              <div class="space-y-2 mb-3">
                  <input type="text" id="dt-title" placeholder="Task Title (e.g., Join Channel)" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-3 text-xs text-white outline-none">
                  <input type="text" id="dt-url" placeholder="Link (e.g., https://t.me/...)" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-3 text-xs text-white outline-none">
                  <input type="number" id="dt-reward" placeholder="Reward XP (e.g., 500)" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-3 text-xs text-white outline-none">
                  <button onclick="addDynamicTask()" class="w-full bg-emerald-600 text-white py-2 rounded text-xs font-black uppercase tracking-wider">Add Task</button>
              </div>
              <div id="admin-dynamic-tasks-list" class="space-y-2">
                  <!-- Task list -->
              </div>
          </div>

          <div class="glass-card rounded-xl p-4 border border-slate-700">
              <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Adsgram Block IDs (Comma separated)</label>
              <textarea id="admin-ad-sdk" rows="3" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-3 text-xs text-white outline-none mb-2" placeholder="int-35545, int-35546, ..."></textarea>
              <button onclick="saveAdminSettings()" class="w-full bg-blue-600 text-white py-2 rounded text-xs font-black uppercase tracking-wider">Save Ads Config</button>
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
  <div id="admin-auth-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in z-[100000]">
    <div class="glass-card w-full max-w-[280px] rounded-[1.5rem] p-5 relative border border-red-500/50 shadow-[0_0_40px_rgba(239,68,68,0.3)]">
      <button onclick="closeAdminAuth()" class="absolute top-3 right-3 text-slate-400 hover:text-white transition"><i class="fa-solid fa-xmark"></i></button>
      <h3 class="text-lg font-black text-white text-center mb-4"><i class="fa-solid fa-lock text-red-500 mr-1"></i> Admin Access</h3>
      <input type="password" id="admin-code-input" placeholder="Enter Access Code" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-sm text-center font-black text-white focus:border-red-500 outline-none mb-4 tracking-widest">
      <button onclick="submitAdminAuth()" class="w-full py-2.5 bg-red-600 text-white font-black rounded-lg text-xs uppercase tracking-wider">Login</button>
    </div>
  </div>

  <!-- Referral Info Modal -->
  <div id="ref-info-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in z-[100000]">
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
      user: {}, referrals: [], rewards: [], withdrawals: [], tasks: [], settings: { adBlockIds: ['int-35545'], dynamicTasks: [] }
    };
    
    let isDoubleXp = false;
    let isSyncing = false; // Prevents overlapping requests

    // Admin globals
    let adminToken = '';
    let adminUsers = [];
    let adminWithdrawals = [];
    let adminLogs = [];

    function formatNum(num, isMoney = false) {
        if (!num) return isMoney ? "0" : "0";
        let val = Number(num);
        return isMoney ? (val % 1 === 0 ? val.toString() : val.toFixed(2).replace(/\.?0+$/, '')) : val.toLocaleString();
    }

    async function apiCall(action, payload = {}, silent = false) {
      if(isSyncing && silent) return false;
      if(silent) isSyncing = true;
      try {
        const body = {
          action: action, tgId: tgUser.id, firstName: tgUser.first_name || '', lastName: tgUser.last_name || '',
          username: tgUser.username || '', photoUrl: tgUser.photo_url || '', referrer: startParam, ...payload
        };
        const res = await fetch(window.location.href, {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        });
        const data = await res.json();
        
        if(silent) isSyncing = false;

        if(data.error) {
          if (data.error === 'BANNED' || data.error === 'MAINTENANCE') {
             const icon = data.error === 'BANNED' ? 'fa-ban' : 'fa-tools';
             document.body.innerHTML = `<div class="h-screen w-full flex flex-col items-center justify-center bg-red-900/90 backdrop-blur text-white p-5 text-center"><i class="fa-solid ${icon} text-5xl mb-4 text-red-400"></i><h1 class="text-2xl font-black mb-2">${data.error}</h1><p class="text-xs opacity-80 leading-relaxed">${data.message}</p></div>`;
             return false;
          }
          if(!silent) showToast("Error", data.error, "error");
          return false;
        }

        if(data.user) appState.user = data.user;
        if(data.referrals) appState.referrals = data.referrals;
        if(data.rewards) appState.rewards = data.rewards;
        if(data.withdrawals) appState.withdrawals = data.withdrawals;
        if(data.tasks) appState.tasks = data.tasks;
        if(data.settings) appState.settings = data.settings;
        if(data.isDoubleXp !== undefined) isDoubleXp = data.isDoubleXp;

        updateUI();
        
        if (action === 'admin_dashboard') {
            adminUsers = data.all_users || [];
            adminWithdrawals = data.all_withdrawals || [];
            adminLogs = data.admin_logs || [];
            renderAdminDashboard(data.stats);
        }

        return data;
      } catch (err) {
        if(silent) isSyncing = false;
        if(!silent) showToast("Connection Error", "Could not reach server.", "error");
        return false;
      }
    }

    // Auto-Sync loop (Anlıq yenilənmə - çekim, admin balans əlavə etməsi üçün)
    setInterval(() => {
        apiCall('sync', {}, true);
    }, 15000);

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
      
      // Update Stats
      document.getElementById('profile-stat-xp').innerText = formatNum(u.totalXp); // Lifetime
      document.getElementById('profile-stat-usd').innerText = `$${formatNum(u.usd, true)}`;
      document.getElementById('profile-stat-refs').innerText = appState.referrals.length;
      document.getElementById('profile-stat-tasks').innerText = u.tasksCompleted;

      // Ensure Admin Button Appears
      if (u.tgId === '5461064199') {
          document.getElementById('admin-secret-btn').classList.remove('hidden');
      }

      document.getElementById('main-xp-display').innerText = `${formatNum(u.xp)} XP`;
      document.getElementById('ads-watched').innerText = u.adsWatchedToday;
      document.getElementById('streak-days').innerText = u.streak;

      document.getElementById('ref-total').innerText = appState.referrals.length;
      document.getElementById('ref-pending').innerText = appState.referrals.filter(r => r.status === 'Pending').length;
      document.getElementById('ref-approved').innerText = appState.referrals.filter(r => r.status === 'Approved').length;
      document.getElementById('ref-link-input').value = `https://t.me/XPVersebot?startapp=${u.tgId}`;
      
      // Double XP UI Handle
      const banner = document.getElementById('double-xp-banner');
      const adText = document.getElementById('ad-reward-text');
      if (isDoubleXp) {
          banner.classList.remove('hidden');
          adText.innerText = "+40 XP (2x)";
          adText.classList.add('text-pink-400');
      } else {
          banner.classList.add('hidden');
          adText.innerText = "+20 XP";
          adText.classList.remove('text-pink-400');
      }

      renderReferrals(); renderRewardHistory();
      document.getElementById('withdraw-balance-display').innerText = formatNum(u.usd, true);
      renderWithdrawHistory(); renderDailyLoginTask(); renderTasks(); renderDynamicTasks();
    }

    /* All standard functions from existing logic are integrated here cleanly */
    
    function renderDailyLoginTask() {
      const container = document.getElementById('streak-tracker-container'); const btnContainer = document.getElementById('daily-login-btn-container');
      container.innerHTML = '';
      const rewards = [10, 20, 30, 40, 50, 75, 100];
      const streak = appState.user.streak || 1;
      const claimedToday = appState.tasks.includes('dailyLogin');
      
      for (let i = 1; i <= 7; i++) {
        const isPast = i < streak || (i === streak && claimedToday); const isToday = i === streak && !claimedToday;
        let styles = "bg-[#050511] border-slate-700/50 text-slate-600"; let icon = `<span class="text-[9px] font-black">${rewards[i-1]}</span>`; let lineStyle = "bg-slate-800";
        if (isPast) { styles = "bg-emerald-500/20 border-emerald-500/50 text-emerald-400 shadow-[0_0_10px_rgba(16,185,129,0.2)]"; icon = `<i class="fa-solid fa-check text-xs"></i>`; lineStyle = "bg-emerald-500/50 shadow-[0_0_3px_#34d399]"; } 
        else if (isToday) { styles = "bg-blue-600/30 border-crypto-glow shadow-[0_0_15px_rgba(0,240,255,0.4)] text-white"; }
        container.innerHTML += `<div class="relative flex flex-col items-center gap-1 z-10 flex-1"><div class="w-8 h-8 rounded-lg border flex items-center justify-center transition-all duration-300 ${styles} z-10 relative bg-[#0a0b1a]">${icon}</div><span class="text-[8px] font-black tracking-widest ${isToday ? 'text-crypto-glow drop-shadow-[0_0_3px_#00f0ff]' : 'text-slate-500'}">DAY ${i}</span>${i < 7 ? `<div class="absolute top-4 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}</div>`;
      }
      if (!claimedToday) {
        btnContainer.innerHTML = `<button onclick="claimTask('dailyLogin', ${rewards[streak-1]})" class="bg-crypto-glow text-[#050511] px-4 py-1.5 rounded-lg text-xs font-black uppercase tracking-wider shadow-[0_0_15px_rgba(0,240,255,0.5)] hover:scale-105 active:scale-95 transition-all">Claim</button>`;
      } else {
        btnContainer.innerHTML = `<div class="bg-slate-800 text-slate-400 px-4 py-1.5 rounded-lg text-xs font-black uppercase tracking-wider">Done</div>`;
      }
    }

    function renderTasks() {
      // Sponsor Task
      const spContainer = document.getElementById('sponsor-container');
      if (appState.user.sponsorAzx) {
        spContainer.innerHTML = `<div class="bg-emerald-900/20 border border-emerald-500/30 rounded-xl p-3 flex justify-between items-center"><div class="flex items-center gap-2"><i class="fa-brands fa-telegram text-emerald-400 text-lg"></i><div><p class="text-[11px] font-black text-emerald-400">Join AZX Community</p><p class="text-[9px] text-slate-400">+200 XP</p></div></div><i class="fa-solid fa-check text-emerald-400 mr-2"></i></div>`;
      } else {
        spContainer.innerHTML = `<div class="bg-[#050511]/60 border border-amber-500/30 rounded-xl p-3 flex justify-between items-center shadow-[0_0_10px_rgba(251,191,36,0.1)]"><div class="flex items-center gap-2"><i class="fa-brands fa-telegram text-blue-400 text-lg"></i><div><p class="text-[11px] font-black text-white">Join AZX Community</p><p class="text-[9px] text-amber-400 font-bold uppercase tracking-widest mt-0.5">+200 XP</p></div></div><button onclick="tg.openTelegramLink('https://t.me/azxcoin'); setTimeout(() => claimTask('sponsor_azx'), 5000);" class="bg-amber-500/20 text-amber-400 border border-amber-500/50 px-3 py-1.5 rounded text-[10px] font-black uppercase tracking-wider">Start</button></div>`;
      }

      // Hardcoded Basic Missions
      const mContainer = document.getElementById('missions-container');
      const m = [
        { id: 'watch_5', title: 'Watch 5 Ads', target: 5, reward: 50, icon: 'fa-play', color: 'blue' },
        { id: 'watch_15', title: 'Watch 15 Ads', target: 15, reward: 150, icon: 'fa-clapperboard', color: 'purple' },
        { id: 'watch_30', title: 'Watch 30 Ads', target: 30, reward: 300, icon: 'fa-video', color: 'crypto-glow' },
        { id: 'complete_all', title: 'Complete All Tasks', target: 30, reward: 500, icon: 'fa-trophy', color: 'amber' }
      ];
      mContainer.innerHTML = m.map(task => {
        const isDone = appState.tasks.includes(task.id);
        const progress = Math.min(appState.user.adsWatchedToday, task.target);
        const canClaim = progress >= task.target && !isDone;
        let btnHtml = '';
        if (isDone) btnHtml = `<i class="fa-solid fa-check-circle text-emerald-400"></i>`;
        else if (canClaim) btnHtml = `<button onclick="claimTask('${task.id}', ${task.reward})" class="bg-${task.color}-500/20 text-${task.color}-400 border border-${task.color}-500/50 px-3 py-1 rounded text-[10px] font-black uppercase tracking-wider animate-pulse">Claim</button>`;
        else btnHtml = `<span class="text-[10px] font-black text-slate-500">${progress}/${task.target}</span>`;
        return `<div class="bg-[#050511]/60 border border-slate-700/50 rounded-xl p-3 flex justify-between items-center"><div class="flex items-center gap-2.5"><div class="w-8 h-8 rounded-lg bg-${task.color}-500/10 flex items-center justify-center"><i class="fa-solid ${task.icon} text-${task.color}-400 text-xs"></i></div><div><p class="text-[11px] font-black text-white">${task.title}</p><p class="text-[9px] text-${task.color}-400 font-bold uppercase tracking-widest mt-0.5">+${task.reward} XP</p></div></div><div>${btnHtml}</div></div>`;
      }).join('');
    }

    function renderDynamicTasks() {
        const dtContainer = document.getElementById('dynamic-tasks-container');
        const dtSection = document.getElementById('dynamic-tasks-section');
        const dtList = appState.settings?.dynamicTasks || [];
        
        if (dtList.length === 0) {
            dtSection.classList.add('hidden');
            return;
        }
        dtSection.classList.remove('hidden');

        dtContainer.innerHTML = dtList.map(task => {
            const isDone = appState.tasks.includes(task.id);
            if (isDone) {
                return `<div class="bg-emerald-900/10 border border-emerald-500/30 rounded-xl p-3 flex justify-between items-center"><div class="flex items-center gap-2"><i class="fa-solid fa-star text-emerald-400 text-sm"></i><div><p class="text-[11px] font-black text-emerald-400">${task.title}</p><p class="text-[9px] text-slate-400">+${task.reward} XP</p></div></div><i class="fa-solid fa-check text-emerald-400 mr-2"></i></div>`;
            } else {
                return `<div class="bg-[#050511]/60 border border-crypto-glow/30 rounded-xl p-3 flex justify-between items-center"><div class="flex items-center gap-2"><i class="fa-solid fa-star text-crypto-glow text-sm"></i><div><p class="text-[11px] font-black text-white">${task.title}</p><p class="text-[9px] text-crypto-glow font-bold uppercase tracking-widest mt-0.5">+${task.reward} XP</p></div></div><button onclick="window.open('${task.url}', '_blank'); setTimeout(() => claimTask('${task.id}', ${task.reward}), 5000);" class="bg-blue-600 text-white px-3 py-1.5 rounded text-[10px] font-black uppercase tracking-wider">Start</button></div>`;
            }
        }).join('');
    }

    async function claimTask(taskId, reward = 0) {
      const res = await apiCall('claim_task', { taskId, reward });
      if (res && res.success) {
        showToast('Task Complete!', `You earned ${res.earned} XP.`, 'success');
      }
    }

    async function watchAd() {
      if (appState.user.adsWatchedToday >= 30) return showToast('Limit Reached', 'You have watched all 30 ads for today.', 'warning');
      const btn = document.getElementById('watch-ad-btn');
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
      btn.disabled = true;
      try {
        const blockId = appState.settings.adBlockIds[Math.floor(Math.random() * appState.settings.adBlockIds.length)];
        const AdController = window.Adsgram.init({ blockId: blockId });
        await AdController.show();
        const res = await apiCall('watch_ad');
        if (res && res.success) {
          showToast('Reward Earned!', `Ad watched successfully! +${res.earned} XP`, 'success');
        }
      } catch (e) {
        showToast('Ad Failed', 'Could not show ad or you closed it early.', 'error');
      } finally {
        const xpText = isDoubleXp ? '+40 XP (2x)' : '+20 XP';
        const xpClass = isDoubleXp ? 'text-pink-400' : 'text-cyan-200';
        btn.innerHTML = `<i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> <span>Watch Ad <span id="ad-reward-text" class="${xpClass} ml-1">${xpText}</span></span>`;
        btn.disabled = false;
      }
    }

    function renderReferrals() {
      const c = document.getElementById('referral-list-container');
      if (appState.referrals.length === 0) {
        c.innerHTML = '<div class="text-center py-5 bg-[#050511]/60 rounded-xl border border-slate-700/50"><i class="fa-solid fa-user-plus text-2xl text-slate-600 mb-2"></i><p class="text-[11px] text-slate-400 font-bold">No referrals yet.</p></div>'; return;
      }
      c.innerHTML = appState.referrals.map(r => {
        const statusColor = r.status === 'Approved' ? 'emerald' : 'amber';
        return `<div class="bg-[#050511]/60 border border-slate-700/50 rounded-xl p-3 flex flex-col gap-2"><div class="flex justify-between items-center"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded-full bg-slate-800 flex items-center justify-center text-[10px] text-white font-black uppercase border border-slate-600">${r.name.substring(0,2)}</div><div><p class="text-[11px] font-black text-white">${r.name}</p><p class="text-[9px] text-slate-500">${r.joinDate}</p></div></div><span class="bg-${statusColor}-500/10 text-${statusColor}-400 border border-${statusColor}-500/30 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider">${r.status}</span></div><div class="grid grid-cols-2 gap-2 mt-1"><div class="bg-slate-900/50 p-1.5 rounded-lg text-center border border-slate-800"><p class="text-[8px] text-slate-500 uppercase font-black">Ads Watched</p><p class="text-[10px] font-black text-blue-400">${r.ads} / 25</p></div><div class="bg-slate-900/50 p-1.5 rounded-lg text-center border border-slate-800"><p class="text-[8px] text-slate-500 uppercase font-black">Tasks Done</p><p class="text-[10px] font-black text-crypto-glow">${r.tasks} / 5</p></div></div></div>`;
      }).join('');
    }

    function renderRewardHistory() {
      const c = document.getElementById('referral-rewards-container');
      if (appState.rewards.length === 0) {
        c.innerHTML = '<div class="text-center py-4 bg-[#050511]/60 rounded-xl border border-slate-700/50"><p class="text-[10px] text-slate-500 font-bold">No rewards yet.</p></div>'; return;
      }
      c.innerHTML = appState.rewards.map(r => `<div class="bg-emerald-900/10 border border-emerald-500/20 rounded-xl p-2.5 flex justify-between items-center"><div class="flex flex-col"><p class="text-[10px] font-black text-emerald-400">${r.title}</p><p class="text-[9px] text-slate-400">${r.desc}</p></div><div class="text-right"><p class="text-[11px] font-black text-white">+${r.xp} XP</p><p class="text-[9px] text-emerald-400 font-bold">+$${r.usd}</p></div></div>`).join('');
    }

    async function openBox(type) {
      const costs = { bronze: 10000, silver: 50000, gold: 100000 };
      if (appState.user.xp < costs[type]) { return showToast('Insufficient XP', `You need ${formatNum(costs[type])} Current XP to open this box.`, 'error'); }
      tg.showConfirm(`Open ${type} box for ${formatNum(costs[type])} XP?`, async (confirmed) => {
        if(confirmed) {
            const res = await apiCall('open_box', { boxType: type });
            if (res && res.success) {
                if (res.jackpot) showToast('JACKPOT!', `Incredible! You won $${res.reward.toFixed(2)} USDT!`, 'jackpot');
                else showToast('Box Opened!', `You won $${res.reward.toFixed(2)} USDT.`, 'success');
            }
        }
      });
    }

    async function requestWithdrawal() {
      const amount = parseFloat(document.getElementById('withdraw-amount').value);
      const address = document.getElementById('wallet-address').value.trim();
      if (amount < 10) return showToast('Error', 'Minimum withdrawal is $10.', 'error');
      if (amount > appState.user.usd) return showToast('Error', 'Insufficient balance.', 'error');
      if (address.length < 10) return showToast('Error', 'Enter a valid TON wallet address.', 'error');
      
      const btn = document.getElementById('withdraw-btn');
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...'; btn.disabled = true;
      const res = await apiCall('withdraw', { amount, address });
      btn.innerHTML = '<i class="fa-solid fa-money-bill-transfer text-base"></i> Request Withdrawal'; btn.disabled = false;
      
      if (res && res.success) {
        showToast('Success', 'Withdrawal request submitted successfully.', 'success');
        document.getElementById('withdraw-amount').value = ''; document.getElementById('wallet-address').value = '';
      }
    }

    function renderWithdrawHistory() {
      const c = document.getElementById('withdraw-history-container');
      if (appState.withdrawals.length === 0) {
        c.innerHTML = '<div class="text-center py-5 bg-[#050511]/60 rounded-xl border border-slate-700/50"><i class="fa-solid fa-clock-rotate-left text-2xl text-slate-600 mb-2"></i><p class="text-[11px] text-slate-400 font-bold">No withdrawal history.</p></div>'; return;
      }
      c.innerHTML = appState.withdrawals.map(w => {
        let sc = 'amber'; let si = 'fa-clock';
        if (w.status === 'Approved') { sc = 'emerald'; si = 'fa-check-double'; }
        else if (w.status === 'Rejected') { sc = 'red'; si = 'fa-xmark'; }
        return `<div class="bg-[#050511]/60 border border-slate-700/50 rounded-xl p-3 flex justify-between items-center"><div class="flex items-center gap-3"><div class="w-9 h-9 rounded-lg bg-${sc}-500/10 flex items-center justify-center border border-${sc}-500/30"><i class="fa-solid ${si} text-${sc}-400 text-sm"></i></div><div><p class="text-[11px] font-black text-white">${w.id}</p><p class="text-[9px] text-slate-500 font-mono">${w.address}</p><p class="text-[8px] text-slate-600">${w.date}</p></div></div><div class="text-right"><p class="text-[12px] font-black text-white">$${w.amount.toFixed(2)}</p><span class="text-[9px] text-${sc}-400 font-bold uppercase tracking-widest">${w.status}</span></div></div>`;
      }).join('');
    }

    function copyRefLink() { const el = document.getElementById('ref-link-input'); el.select(); document.execCommand('copy'); showToast('Copied!', 'Referral link copied to clipboard.', 'success'); }
    function shareReferralTelegram() { const url = `https://t.me/share/url?url=https://t.me/XPVersebot?startapp=${appState.user.tgId}&text=Play%20XPVerse%20and%20earn%20USDT!`; tg.openTelegramLink(url); }
    function toggleRefInfo() { const m = document.getElementById('ref-info-modal'); m.classList.toggle('hidden'); m.classList.toggle('flex'); }

    // Navigation
    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      document.querySelectorAll('.nav-btn').forEach(el => el.classList.remove('nav-active'));
      document.querySelector(`.nav-btn[data-target="${tabId}"]`).classList.add('nav-active');
      window.scrollTo(0,0); tg.HapticFeedback.selectionChanged();
    }

    // Timer Update
    setInterval(() => {
      if (!appState.user) return;
      let diff = appState.serverResetTime - Math.floor(Date.now() / 1000);
      if (diff <= 0) { document.getElementById('reset-timer').innerText = "Resetting..."; setTimeout(() => location.reload(), 2000); return; }
      const h = Math.floor(diff / 3600).toString().padStart(2, '0');
      const m = Math.floor((diff % 3600) / 60).toString().padStart(2, '0');
      const s = Math.floor(diff % 60).toString().padStart(2, '0');
      document.getElementById('reset-timer').innerText = `${h}:${m}:${s}`;
    }, 1000);

    /* ====== ADMIN PANEL LOGIC ====== */
    function openAdminAuth() { document.getElementById('admin-auth-modal').classList.remove('hidden'); document.getElementById('admin-auth-modal').classList.add('flex'); }
    function closeAdminAuth() { document.getElementById('admin-auth-modal').classList.add('hidden'); document.getElementById('admin-auth-modal').classList.remove('flex'); }
    
    async function submitAdminAuth() {
        adminToken = document.getElementById('admin-code-input').value;
        const res = await apiCall('admin_dashboard', { adminCode: adminToken });
        if(res && res.success) {
            closeAdminAuth(); switchTab('admin'); showToast('Admin Auth', 'Access Granted', 'success');
        } else {
            showToast('Auth Failed', 'Invalid Code', 'error');
        }
    }

    function switchAdminTab(tab) {
        document.querySelectorAll('.admin-section').forEach(el => el.classList.add('hidden'));
        document.getElementById(`admin-sec-${tab}`).classList.remove('hidden');
        document.querySelectorAll('.admin-tab').forEach(el => {
            el.classList.remove('bg-red-600/20', 'text-red-400', 'border-red-500/50'); el.classList.add('text-slate-400');
        });
        const activeTab = document.getElementById(`tab-${tab}`);
        activeTab.classList.remove('text-slate-400'); activeTab.classList.add('bg-red-600/20', 'text-red-400', 'border-red-500/50');
        
        if(tab === 'settings') {
            document.getElementById('admin-ad-sdk').value = appState.settings.adBlockIds.join(', ');
            document.getElementById('admin-maint-toggle').checked = appState.settings.maintenance || false;
            renderAdminDynamicTasks();
        } else if (tab === 'dashboard') {
            renderAdminLogs();
        }
    }

    function renderAdminDashboard(stats) {
        if(!stats) return;
        document.getElementById('adm-stat-users').innerText = stats.users;
        document.getElementById('adm-stat-usd').innerText = `$${stats.usd.toFixed(2)}`;
        renderAdminUsers(); renderAdminWithdrawals(); renderAdminLogs();
    }

    function renderAdminLogs() {
        const c = document.getElementById('admin-action-logs');
        if(!adminLogs || adminLogs.length === 0) { c.innerHTML = '<p class="text-[10px] text-slate-500">No recent actions.</p>'; return; }
        c.innerHTML = adminLogs.map(l => `<div class="bg-slate-900/50 border border-slate-700/50 rounded-lg p-2"><div class="flex justify-between items-center mb-1"><span class="text-[9px] font-black text-crypto-glow uppercase tracking-widest">${l.action}</span><span class="text-[8px] text-slate-500">${l.time}</span></div><p class="text-[10px] text-slate-300">${l.details}</p></div>`).join('');
    }

    function filterAdminUsers() { renderAdminUsers(document.getElementById('admin-user-search').value.toLowerCase()); }

    function renderAdminUsers(filter = '') {
        const list = document.getElementById('admin-user-list');
        list.innerHTML = adminUsers.filter(u => u.tgId.includes(filter) || u.username.toLowerCase().includes(filter)).map(u => {
            return `
            <div class="glass-card p-3 rounded-xl border border-slate-700/80">
                <div class="flex justify-between items-start mb-2 border-b border-slate-700 pb-2">
                    <div>
                        <p class="text-xs font-black text-white">${u.firstName} ${u.lastName}</p>
                        <p class="text-[9px] text-blue-400 font-mono">@${u.username} | UID: ${u.tgId}</p>
                        <p class="text-[9px] text-slate-500 mt-0.5"><i class="fa-solid fa-clock mr-1"></i>Last Active: ${u.lastActive}</p>
                    </div>
                    <div class="text-right">
                        ${u.banned ? '<span class="bg-red-500 text-white px-2 py-0.5 rounded text-[8px] font-black uppercase">Banned</span>' : '<span class="bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded text-[8px] font-black uppercase">Active</span>'}
                    </div>
                </div>
                
                <div class="grid grid-cols-3 gap-2 mb-3">
                    <div class="bg-slate-900/50 p-1.5 rounded text-center border border-slate-800"><p class="text-[8px] text-slate-500 uppercase font-black">Daily Ads</p><p class="text-[10px] font-black text-blue-400">${u.adsWatchedToday}</p></div>
                    <div class="bg-slate-900/50 p-1.5 rounded text-center border border-slate-800"><p class="text-[8px] text-slate-500 uppercase font-black">Daily XP</p><p class="text-[10px] font-black text-crypto-glow">${u.dailyXp || 0}</p></div>
                    <div class="bg-slate-900/50 p-1.5 rounded text-center border border-slate-800"><p class="text-[8px] text-slate-500 uppercase font-black">Referrals</p><p class="text-[10px] font-black text-purple-400">${u.refCount || 0}</p></div>
                </div>
                
                <div class="flex gap-2 mb-3">
                    <div class="flex-1">
                        <label class="text-[8px] text-slate-500 uppercase font-black">Current XP</label>
                        <input type="number" id="adm-xp-${u.tgId}" value="${u.xp}" class="w-full bg-[#050511] border border-slate-600 rounded text-xs px-2 py-1 text-white">
                    </div>
                    <div class="flex-1">
                        <label class="text-[8px] text-slate-500 uppercase font-black">USD Balance</label>
                        <input type="number" id="adm-usd-${u.tgId}" value="${u.usd}" class="w-full bg-[#050511] border border-slate-600 rounded text-xs px-2 py-1 text-white">
                    </div>
                </div>
                
                <div class="flex gap-1.5">
                    <button onclick="adminActionUser('${u.tgId}', 'update_balance')" class="flex-1 bg-blue-600 text-white py-1.5 rounded text-[9px] font-black uppercase">Save Balances</button>
                    ${u.banned 
                        ? `<button onclick="adminActionUser('${u.tgId}', 'unban')" class="flex-1 bg-emerald-600 text-white py-1.5 rounded text-[9px] font-black uppercase">Unban</button>`
                        : `<button onclick="adminActionUser('${u.tgId}', 'ban')" class="flex-1 bg-red-600 text-white py-1.5 rounded text-[9px] font-black uppercase">Ban</button>`
                    }
                </div>
            </div>`;
        }).join('');
    }

    function renderAdminWithdrawals() {
        const list = document.getElementById('admin-withdrawal-list');
        list.innerHTML = adminWithdrawals.map(w => `
            <div class="glass-card p-3 rounded-xl border border-slate-700">
                <div class="flex justify-between">
                    <div><p class="text-[10px] font-black text-white">UID: ${w.user_id}</p><p class="text-[9px] text-slate-400">Addr: ${w.address}</p></div>
                    <div class="text-right"><p class="text-xs font-black text-emerald-400">$${w.amount}</p><p class="text-[8px] text-amber-400 font-bold uppercase">${w.status}</p></div>
                </div>
                ${w.status === 'Pending' ? `<div class="flex gap-2 mt-2"><button onclick="adminActionWithdraw('${w.user_id}', ${w.idx}, 'approve')" class="flex-1 bg-emerald-600 text-white py-1 rounded text-[9px] font-black uppercase">Approve</button><button onclick="adminActionWithdraw('${w.user_id}', ${w.idx}, 'reject')" class="flex-1 bg-red-600 text-white py-1 rounded text-[9px] font-black uppercase">Reject</button></div>` : ''}
            </div>
        `).join('');
    }

    async function adminActionUser(targetUid, action) {
        let payload = { adminCode: adminToken, targetUid: targetUid, userAction: action };
        if (action === 'update_balance') {
            payload.newXp = document.getElementById(`adm-xp-${targetUid}`).value;
            payload.newUsd = document.getElementById(`adm-usd-${targetUid}`).value;
        }
        await apiCall('admin_action_user', payload);
        await apiCall('admin_dashboard', { adminCode: adminToken }); // Refresh
        showToast('Success', 'User updated.', 'success');
    }

    async function adminActionWithdraw(targetUid, idx, action) {
        await apiCall('admin_action_withdraw', { adminCode: adminToken, targetUid, idx, withdrawAction: action });
        await apiCall('admin_dashboard', { adminCode: adminToken }); // Refresh
        showToast('Success', 'Withdrawal processed.', 'success');
    }

    async function saveAdminSettings() {
        const ids = document.getElementById('admin-ad-sdk').value.split(',').map(s => s.trim()).filter(Boolean);
        await apiCall('admin_update_settings', { adminCode: adminToken, blockIds: ids });
        showToast('Success', 'Ads Config Saved.', 'success');
    }
    
    async function toggleMaintenance() {
        const isMaint = document.getElementById('admin-maint-toggle').checked;
        await apiCall('admin_update_settings', { adminCode: adminToken, maintenance: isMaint });
        showToast('System', `Maintenance is now ${isMaint ? 'ON' : 'OFF'}`, 'success');
    }

    async function setDoubleXp() {
        const hours = document.getElementById('admin-double-xp-hours').value;
        await apiCall('admin_update_settings', { adminCode: adminToken, doubleXpHours: hours });
        showToast('System', hours > 0 ? `2x XP Active for ${hours} hours.` : '2x XP Disabled.', 'success');
        document.getElementById('admin-double-xp-hours').value = '';
    }

    async function resetAllAds() {
        if(confirm("Are you sure you want to reset today's ad limits for ALL users?")) {
            await apiCall('admin_reset_ads', { adminCode: adminToken });
            await apiCall('admin_dashboard', { adminCode: adminToken });
            showToast('System', 'All ad limits reset.', 'success');
        }
    }

    async function wipeBotData() {
        if(confirm("DANGER! This will delete ALL user data, referrals, and withdrawals (except admin). Are you ABSOLUTELY sure?")) {
            await apiCall('admin_wipe_data', { adminCode: adminToken });
            await apiCall('admin_dashboard', { adminCode: adminToken });
            showToast('System', 'Wipe Complete.', 'success');
        }
    }

    async function addDynamicTask() {
        const title = document.getElementById('dt-title').value;
        const url = document.getElementById('dt-url').value;
        const reward = document.getElementById('dt-reward').value;
        
        if(!title || !url || !reward) return showToast('Error', 'Fill all task fields', 'error');
        await apiCall('admin_task_manager', { adminCode: adminToken, taskAction: 'add', title, url, reward });
        document.getElementById('dt-title').value = ''; document.getElementById('dt-url').value = ''; document.getElementById('dt-reward').value = '';
        renderAdminDynamicTasks(); showToast('Success', 'Task Added', 'success');
    }

    async function deleteDynamicTask(tid) {
        await apiCall('admin_task_manager', { adminCode: adminToken, taskAction: 'delete', taskId: tid });
        renderAdminDynamicTasks(); showToast('Success', 'Task Deleted', 'success');
    }

    function renderAdminDynamicTasks() {
        const list = document.getElementById('admin-dynamic-tasks-list');
        const dtList = appState.settings.dynamicTasks || [];
        list.innerHTML = dtList.map(t => `
            <div class="flex justify-between items-center bg-slate-900/50 p-2 rounded border border-slate-700">
                <div><p class="text-[10px] font-black text-white">${t.title}</p><p class="text-[8px] text-crypto-glow">+${t.reward} XP</p></div>
                <button onclick="deleteDynamicTask('${t.id}')" class="text-red-500 hover:text-red-400"><i class="fa-solid fa-trash text-xs"></i></button>
            </div>
        `).join('');
    }

    // Init
    window.addEventListener('load', async () => {
        const res = await apiCall('init');
        if (res !== false) {
            document.getElementById('loading-overlay').style.opacity = '0';
            setTimeout(() => document.getElementById('loading-overlay').remove(), 500);
        }
    });
  </script>
</body>
</html>
