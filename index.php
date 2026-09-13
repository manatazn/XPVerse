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

// Ensure settings exist (Multiple APIs Supported)
$settings = readDB('settings.json');
if (empty($settings) || !isset($settings['adBlockIds'])) {
    $settings = [
        'adBlockIds' => [
            'int-35545'
        ]
    ];
    writeDB('settings.json', $settings);
}

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
    
    // Server time for accurate frontend countdown (Bypasses phone time)
    $resetTarget = clone $now;
    $resetTarget->setTime(3, 0, 0); // Target 03:00 MSK
    if ($hour >= 3) {
        $resetTarget->modify('+1 day');
    }
    $response['serverTime'] = $now->getTimestamp();
    $response['serverResetTime'] = $resetTarget->getTimestamp(); 
    $response['settings'] = $settings;

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
            foreach($users as $u) {
                $totalUsd += $u['usd'];
                $totalAds += $u['totalAdsWatched'];
                $totalTasks += $u['tasksCompleted'];
                $totalXp += $u['totalXp'];
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
            echo json_encode($response); exit;
        }

        if ($action === 'admin_update_settings') {
            $settings['adBlockIds'] = $input['blockIds'];
            writeDB('settings.json', $settings);
            $response['message'] = 'Settings (APIs) updated successfully.';
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
            $response['message'] = 'User updated successfully.';
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
        case 'watch_ad':
            if ($users[$uid]['adsWatchedToday'] < 30) {
                $users[$uid]['adsWatchedToday'] += 1;
                $users[$uid]['totalAdsWatched'] += 1;
                $users[$uid]['xp'] += 20;
                $users[$uid]['totalXp'] += 20;
                $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
            } else {
                $response['error'] = 'Ad limit reached';
            }
            break;

        case 'claim_task':
            $taskId = $input['taskId'] ?? '';
            if ($taskId === 'sponsor_azx') {
                if (empty($users[$uid]['sponsorAzx'])) {
                    $users[$uid]['sponsorAzx'] = true;
                    $users[$uid]['xp'] += 200;
                    $users[$uid]['totalXp'] += 200;
                    $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                    evaluateReferralProgress($uid, $users, $referrals, $rewards);
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
                    if ($rewardXp > 0 && $rewardXp <= 500) { 
                        $tasks[$uid][] = $taskId;
                        $users[$uid]['tasksCompleted'] += 1;
                        $users[$uid]['xp'] += $rewardXp;
                        $users[$uid]['totalXp'] += $rewardXp;
                        $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                        evaluateReferralProgress($uid, $users, $referrals, $rewards);
                        writeDB('tasks.json', $tasks);
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
                $users[$uid]['xp'] -= $costs[$type];
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
        <span>Watch Ad <span class="text-cyan-200 ml-1">+20 XP</span></span>
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
        <h2 class="text-2xl font-black text-red-500 tracking-tight drop-shadow-lg flex items-center justify-center gap-2"><i class="fa-solid fa-shield-halved"></i> ADMIN PANEL</h2>
      </div>

      <div class="flex gap-2 mb-2 bg-[#050511] p-1 rounded-lg border border-slate-700/50">
          <button onclick="switchAdminTab('dashboard')" class="admin-tab flex-1 py-2 text-[10px] font-black uppercase rounded bg-red-600/20 text-red-400 border border-red-500/50 transition" id="tab-dashboard">Dashboard</button>
          <button onclick="switchAdminTab('users')" class="admin-tab flex-1 py-2 text-[10px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-users">Users</button>
          <button onclick="switchAdminTab('withdrawals')" class="admin-tab flex-1 py-2 text-[10px] font-black uppercase rounded text-slate-400 hover:bg-slate-800 transition" id="tab-withdrawals">Withdraws</button>
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
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Ads Watched</p>
                  <p id="adm-stat-ads" class="text-lg font-black text-white">0</p>
              </div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Tasks Done</p>
                  <p id="adm-stat-tasks" class="text-lg font-black text-white">0</p>
              </div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Total XP</p>
                  <p id="adm-stat-xp" class="text-lg font-black text-crypto-glow">0</p>
              </div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center">
                  <p class="text-[9px] text-slate-400 uppercase font-black">Referrals</p>
                  <p id="adm-stat-refs" class="text-lg font-black text-purple-400">0</p>
              </div>
          </div>
      </div>

      <!-- Users Management -->
      <div id="admin-sec-users" class="admin-section hidden space-y-3">
          <input type="text" id="admin-user-search" onkeyup="filterAdminUsers()" placeholder="Search UID or Username..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white focus:border-blue-500 outline-none">
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

      <!-- Settings -->
      <div id="admin-sec-settings" class="admin-section hidden space-y-3">
          <div class="glass-card rounded-xl p-4 border border-slate-700">
              <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Adsgram Block IDs (Comma separated)</label>
              <textarea id="admin-ad-sdk" rows="4" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-xs text-white focus:border-blue-500 outline-none mb-3" placeholder="int-35545, int-35546, ..."></textarea>
              <button onclick="saveAdminSettings()" class="w-full py-2 bg-blue-600 text-white rounded text-xs font-black uppercase tracking-wider">Save Settings</button>
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
      user: {}, referrals: [], rewards: [], withdrawals: [], tasks: [], settings: { adBlockIds: ['int-35545'] }
    };
    
    // Admin globals
    let adminToken = '';
    let adminUsers = [];
    let adminWithdrawals = [];

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
          if (data.error === 'BANNED') {
             document.body.innerHTML = `<div class="h-screen w-full flex flex-col items-center justify-center bg-red-900 text-white p-5 text-center"><i class="fa-solid fa-ban text-5xl mb-4"></i><h1 class="text-2xl font-black mb-2">ACCOUNT BANNED</h1><p class="text-xs opacity-80">${data.message}</p></div>`;
             return false;
          }
          showToast("Error", data.error, "error");
          return false;
        }

        if(data.user) appState.user = data.user;
        if(data.referrals) appState.referrals = data.referrals;
        if(data.rewards) appState.rewards = data.rewards;
        if(data.withdrawals) appState.withdrawals = data.withdrawals;
        if(data.tasks) appState.tasks = data.tasks;
        if(data.settings) appState.settings = data.settings;

        updateUI();
        return data;
      } catch (err) {
        showToast("Connection Error", "Could not reach server.", "error");
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

      // Show admin button safely
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
      
      renderReferrals(); renderRewardHistory();
      document.getElementById('withdraw-balance-display').innerText = formatNum(u.usd, true);
      renderWithdrawHistory(); renderDailyLoginTask(); renderTasks();
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
        if (isPast) { styles = "bg-emerald-500/20 border-emerald-500/50 text-emerald-400 shadow-[0_0_10px_rgba(16,185,129,0.2)]"; icon = `<i class="fa-solid fa-check text-xs"></i>`; lineStyle = "bg-emerald-500/50 shadow-[0_0_3px_#34d399]"; } 
        else if (isToday) { styles = "bg-blue-600/30 border-crypto-glow shadow-[0_0_15px_rgba(0,240,255,0.4)] text-white"; }
        container.innerHTML += `<div class="relative flex flex-col items-center gap-1 z-10 flex-1"><div class="w-8 h-8 rounded-lg border flex items-center justify-center transition-all duration-300 ${styles} z-10 relative bg-[#0a0b1a]">${icon}</div><span class="text-[8px] font-black tracking-widest ${isToday ? 'text-crypto-glow drop-shadow-[0_0_3px_#00f0ff]' : 'text-slate-500'}">DAY ${i}</span>${i < 7 ? `<div class="absolute top-4 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}</div>`;
      }
      
      if (claimedToday) btnContainer.innerHTML = `<button class="bg-emerald-900/50 border border-emerald-500/40 text-emerald-400 px-4 py-2 rounded-lg text-[10px] font-black uppercase tracking-wider flex items-center gap-1.5 cursor-not-allowed opacity-80 shadow-inner"><i class="fa-solid fa-check-double"></i> Claimed</button>`;
      else btnContainer.innerHTML = `<button onclick="claimTask('dailyLogin', ${rewards[streak - 1]})" class="bg-gradient-to-r from-crypto-glow to-blue-500 text-crypto-dark shadow-[0_3px_15px_rgba(0,240,255,0.4)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest animate-pulse-fast">CLAIM</button>`;
    }

    function renderTasks() {
      const spContainer = document.getElementById('sponsor-container');
      if (appState.user.sponsorAzx) spContainer.innerHTML = `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800 shadow-sm"><div class="flex items-center gap-2.5"><div class="w-10 h-10 rounded-lg border bg-blue-500/10 border-blue-500/20 flex items-center justify-center"><i class="fa-brands fa-telegram text-blue-400 text-lg"></i></div><div class="flex flex-col"><span class="text-[13px] font-black text-white tracking-wide">Join @azxcrypto</span><span class="text-emerald-400 text-[9px] font-bold tracking-widest uppercase mt-0.5">Completed</span></div></div><span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-lg border border-emerald-500/30 flex items-center gap-1"><i class="fa-solid fa-check-double"></i></span></div>`;
      else spContainer.innerHTML = `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-blue-500/30 shadow-[0_0_15px_rgba(59,130,246,0.15)] relative overflow-hidden"><div class="absolute -right-4 -top-4 w-16 h-16 bg-blue-500/20 rounded-full blur-xl"></div><div class="flex items-center gap-2.5 relative z-10"><div class="w-10 h-10 rounded-lg border bg-blue-500/20 border-blue-500/40 flex items-center justify-center shadow-inner"><i class="fa-brands fa-telegram text-blue-400 text-lg drop-shadow-sm"></i></div><div class="flex flex-col"><span class="text-[13px] font-black text-white tracking-wide">Join @azxcrypto</span><span class="text-crypto-glow text-[9px] font-bold tracking-widest uppercase mt-0.5">+200 XP</span></div></div><button onclick="claimSponsorTask()" class="relative z-10 text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-3 py-1.5 rounded-lg shadow-[0_3px_10px_rgba(0,240,255,0.3)] active:scale-95 transition-all uppercase tracking-wider">Join & Claim</button></div>`;

      const container = document.getElementById('missions-container'); container.innerHTML = '';
      const missionsList = [
        { id: 'watch5', label: 'Watch 5 Ads', icon: 'fa-video', color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20', reward: 20, target: 5, current: appState.user.adsWatchedToday },
        { id: 'watch15', label: 'Watch 15 Ads', icon: 'fa-film', color: 'text-indigo-400', bg: 'bg-indigo-500/10 border-indigo-500/20', reward: 40, target: 15, current: appState.user.adsWatchedToday },
        { id: 'watch30', label: 'Watch 30 Ads', icon: 'fa-clapperboard', color: 'text-purple-400', bg: 'bg-purple-500/10 border-purple-500/20', reward: 80, target: 30, current: appState.user.adsWatchedToday },
        { id: 'complete_all', label: 'Complete All Tasks', icon: 'fa-check-to-slot', color: 'text-emerald-400', bg: 'bg-emerald-500/10 border-emerald-500/20', reward: 100, target: 30, current: appState.user.adsWatchedToday }
      ];

      missionsList.forEach(m => {
        const claimed = appState.tasks.includes(m.id); const canClaim = !claimed && m.current >= m.target;
        let btnHtml = '';
        if (claimed) btnHtml = `<span class="text-[9px] font-black bg-emerald-500/10 text-emerald-400 px-2.5 py-1.5 rounded-lg border border-emerald-500/30 flex items-center gap-1 shadow-inner"><i class="fa-solid fa-check-double"></i> Claimed</span>`;
        else if (canClaim) btnHtml = `<button onclick="claimTask('${m.id}', ${m.reward})" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-3 py-1.5 rounded-lg shadow-[0_3px_10px_rgba(0,240,255,0.3)] active:scale-95 transition-all uppercase tracking-wider">Claim</button>`;
        else btnHtml = `<span class="text-[10px] font-black bg-slate-800/60 text-slate-300 px-3 py-1.5 rounded-lg border border-slate-700 shadow-inner">+${m.reward} XP</span>`;
        container.innerHTML += `<div class="glass-card rounded-xl p-3 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-slate-800/80 shadow-sm"><div class="flex items-center gap-3"><div class="w-10 h-10 rounded-lg border ${m.bg} flex items-center justify-center shadow-inner"><i class="fa-solid ${m.icon} ${m.color} text-lg drop-shadow-sm"></i></div><div class="flex flex-col"><span class="text-[13px] font-black text-white tracking-wide">${m.label}</span><span class="text-crypto-glow text-[9px] font-bold tracking-widest uppercase opacity-80 mt-0.5">Progress: ${Math.min(m.current, m.target)}/${m.target}</span></div></div>${btnHtml}</div>`;
      });
    }

    async function claimSponsorTask() {
        if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
        tg.openTelegramLink('https://t.me/azxcrypto');
        setTimeout(async () => { const res = await apiCall('claim_task', { taskId: 'sponsor_azx', reward: 200 }); if(res && !res.error) showToast('Sponsor Task', `You earned +200 XP!`, 'success'); }, 1500);
    }
    async function claimTask(taskId, reward) {
        if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
        const res = await apiCall('claim_task', { taskId, reward });
        if(res && !res.error) showToast('Task Completed!', `You earned +${reward} XP!`, 'success');
    }

    // UPDATED: MULTI-API FALLBACK SYSTEM
    async function watchAd() {
      const btn = document.getElementById('watch-ad-btn'); 
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-base"></i> <span>Loading...</span>`;
      btn.classList.add('opacity-80', 'pointer-events-none');
      
      const blockIds = appState.settings.adBlockIds || ["int-35545"];
      let adShown = false;
      
      // Rotate through APIs if one fails or hits a limit
      for(let i = 0; i < blockIds.length; i++) {
        let currentId = blockIds[i].trim();
        if(!currentId) continue;
        
        try {
          if (window.Adsgram) {
            const AdController = window.Adsgram.init({ blockId: currentId });
            await AdController.show(); // This returns a promise
            adShown = true;
            break; // Ad loaded successfully, exit the loop
          }
        } catch (e) {
          console.warn(`Adsgram API (${currentId}) failed or limited. Trying next API...`);
        }
      }
      
      if(adShown) {
         const res = await apiCall('watch_ad');
         if(res && !res.error) showToast('Reward Granted!', 'You earned +20 XP.', 'success');
      } else {
         showToast('Error', 'No ads available right now across all networks. Please try again later.', 'error');
      }
      
      btn.innerHTML = originalHTML; 
      btn.classList.remove('opacity-80', 'pointer-events-none');
    }

    async function openBox(type) {
      if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('heavy');
      const res = await apiCall('open_box', { boxType: type });
      if(res && !res.error) {
        if(res.jackpot) showToast('HUGE JACKPOT! 🎉', `Incredible! You won $${res.reward.toFixed(2)} USDT!`, 'jackpot');
        else showToast('Box Opened!', `Congratulations! You won $${res.reward.toFixed(2)} USDT!`, 'success');
      }
    }

    async function requestWithdrawal() {
      const address = document.getElementById('wallet-address').value; const amount = parseFloat(document.getElementById('withdraw-amount').value);
      if (!address || address.length < 10) return showToast('Invalid Address', 'Please enter a valid TON wallet address.', 'error');
      if (isNaN(amount) || amount < 10) return showToast('Invalid Amount', 'The minimum withdrawal amount is $10 USDT.', 'error');
      if (amount > appState.user.usd) return showToast('Insufficient Balance', 'You do not have enough USDT available.', 'error');
      if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
      const res = await apiCall('withdraw', { amount, address });
      if(res && !res.error) { showToast('Withdrawal Requested', `Your request for $${amount.toFixed(2)} USDT has been submitted.`, 'success'); document.getElementById('wallet-address').value = ''; document.getElementById('withdraw-amount').value = ''; }
    }

    function renderWithdrawHistory() {
      const container = document.getElementById('withdraw-history-container');
      if (appState.withdrawals.length === 0) return container.innerHTML = `<div class="glass-card rounded-xl p-5 text-center border-dashed border-2 border-slate-700/50"><i class="fa-solid fa-clock-rotate-left text-3xl text-slate-700 mb-2 drop-shadow-md"></i><p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">History is Empty</p></div>`;
      container.innerHTML = appState.withdrawals.map(r => `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800/80"><div class="flex items-center gap-2.5"><div class="w-8 h-8 rounded-full bg-[#050511] border border-slate-700 flex items-center justify-center shadow-inner"><i class="fa-solid fa-arrow-right-arrow-left text-slate-400 text-xs"></i></div><div><p class="text-xs font-black text-white tracking-wide">${r.id} <span class="text-[9px] text-slate-500 ml-1 font-bold">${r.date}</span></p><p class="text-[9px] text-blue-400 mt-0.5 font-mono bg-blue-500/10 inline-block px-1.5 py-0.5 rounded border border-blue-500/20">${r.address}</p></div></div><div class="text-right"><p class="text-[13px] font-black text-emerald-400">-$${formatNum(r.amount, true)}</p><p class="text-[8px] font-black text-amber-400 uppercase tracking-widest mt-1 bg-amber-500/10 inline-block px-2 py-0.5 rounded-full border border-amber-500/20">${r.status}</p></div></div>`).join('');
    }

    function copyRefLink() { navigator.clipboard.writeText(document.getElementById('ref-link-input').value).then(() => showToast("Success", "Referral link copied!", "success")); }
    function shareReferralTelegram() { tg.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(`https://t.me/XPVersebot?startapp=${appState.user.tgId}`)}&text=${encodeURIComponent(`🚀 Complete premium tasks, open boxes, and earn USDT straight to your TON Wallet! 💎 I'm already playing XPVerse, join me now 🔥`)}`); }
    function toggleRefInfo() { document.getElementById('ref-info-modal').classList.toggle('hidden'); document.getElementById('ref-info-modal').classList.toggle('flex'); }

    function renderReferrals() {
      const container = document.getElementById('referral-list-container');
      if (appState.referrals.length === 0) return container.innerHTML = `<div class="glass-card rounded-xl p-5 text-center border-dashed border-2 border-slate-700/50"><i class="fa-solid fa-user-plus text-3xl text-slate-700 mb-2 drop-shadow-md"></i><p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">No referrals yet</p></div>`;
      container.innerHTML = appState.referrals.map(r => {
        const isAppr = r.status === 'Approved'; const statusClass = isAppr ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30' : 'bg-amber-500/10 text-amber-400 border-amber-500/30';
        return `<div class="glass-card rounded-xl p-3.5 flex flex-col gap-3 border border-slate-800/80"><div class="flex justify-between items-center"><div class="flex items-center gap-2.5"><div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-600 to-indigo-600 flex items-center justify-center font-black text-white text-sm">${r.name.charAt(0).toUpperCase()}</div><div class="flex flex-col"><span class="text-xs font-black text-white tracking-wide truncate max-w-[120px]">${r.name}</span>${r.username ? `<span class="text-[9px] text-slate-400 font-mono mt-0.5">@${r.username}</span>` : ''}</div></div><span class="text-[8px] font-black uppercase tracking-widest px-2 py-1 rounded border ${statusClass}">${r.status}</span></div>${!isAppr ? `<div class="bg-[#050511]/60 rounded-lg p-2.5 border border-slate-700/60 grid grid-cols-2 gap-2.5 shadow-inner"><div class="text-center"><p class="text-[8px] font-bold text-slate-500 uppercase tracking-widest mb-1">Ads</p><div class="w-full bg-slate-800 rounded-full h-1 mb-1 overflow-hidden shadow-inner"><div class="bg-blue-500 h-full rounded-full" style="width: ${Math.min((r.ads/25)*100, 100)}%"></div></div><p class="text-[10px] font-black text-white">${r.ads} <span class="text-slate-500 font-bold">/ 25</span></p></div><div class="text-center border-l border-slate-700"><p class="text-[8px] font-bold text-slate-500 uppercase tracking-widest mb-1">Task</p><div class="w-3/4 mx-auto bg-slate-800 rounded-full h-1 mb-1 overflow-hidden shadow-inner"><div class="bg-crypto-glow h-full rounded-full" style="width: ${Math.min((r.tasks/5)*100, 100)}%"></div></div><p class="text-[10px] font-black text-white">${r.tasks} <span class="text-slate-500 font-bold">/ 5</span></p></div></div>` : ''}</div>`;
      }).join('');
    }

    function renderRewardHistory() {
      const container = document.getElementById('referral-rewards-container');
      if (appState.rewards.length === 0) return container.innerHTML = `<div class="glass-card rounded-xl p-4 text-center border-dashed border border-slate-700/50"><p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">No rewards yet</p></div>`;
      container.innerHTML = appState.rewards.map(r => `<div class="glass-card rounded-lg p-3 flex justify-between items-center border border-slate-800/80 shadow-sm"><div class="flex items-center gap-2.5"><div class="w-8 h-8 rounded-full bg-emerald-500/20 border border-emerald-500/50 flex items-center justify-center text-emerald-400 shadow-inner"><i class="fa-solid fa-gift text-sm drop-shadow-sm"></i></div><div><p class="text-[11px] font-black text-white tracking-wide">${r.title}</p><p class="text-[9px] text-slate-400 mt-0.5 truncate max-w-[130px]">${r.desc}</p></div></div><div class="text-right flex flex-col items-end"><span class="text-[10px] font-black text-crypto-glow">+${r.xp} XP</span><span class="text-[10px] font-black text-emerald-400 mt-0.5">+$${formatNum(r.usd, true)}</span></div></div>`).join('');
    }

    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => { el.classList.add('hidden'); el.classList.remove('animate-slide-up'); });
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));
      const targetView = document.getElementById(`view-${tabId}`);
      targetView.classList.remove('hidden'); targetView.classList.add('animate-slide-up');
      
      const tabTarget = document.querySelector(`[data-target="${tabId}"]`);
      if (tabTarget) tabTarget.classList.add('nav-active');
      if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // SERVER-SYNCED TIMER (Independent of Device Time)
    let timeRemaining = 0;
    let timerInterval = null;
    
    function startServerTimer(currentServer, resetServer) {
        if(timerInterval) clearInterval(timerInterval);
        timeRemaining = resetServer - currentServer;
        
        timerInterval = setInterval(() => {
            if (timeRemaining > 0) timeRemaining--;
            if (timeRemaining <= 0) { 
                document.getElementById('reset-timer').innerText = "00:00:00"; 
                // Auto reload to fetch new day data once hit zero
                setTimeout(() => { window.location.reload(); }, 2000);
                return; 
            }
            
            const h = Math.floor(timeRemaining / 3600);
            const m = Math.floor((timeRemaining % 3600) / 60);
            const s = Math.floor(timeRemaining % 60);
            document.getElementById('reset-timer').innerText = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }, 1000);
    }
    
    async function initApp() {
      const res = await apiCall('init');
      if(res && res.serverTime) startServerTimer(res.serverTime, res.serverResetTime);
      setTimeout(() => { document.getElementById('loading-overlay').style.opacity = '0'; setTimeout(() => { document.getElementById('loading-overlay').style.display = 'none'; }, 500); }, 600);
    }

    // --- ADMIN PANEL FUNCTIONS ---
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
        adminToken = code;
        const res = await apiCall('admin_dashboard', { adminCode: code });
        if(res && !res.error) {
            closeAdminAuth();
            switchTab('admin');
            
            document.getElementById('adm-stat-users').innerText = res.stats.users;
            document.getElementById('adm-stat-usd').innerText = `$${res.stats.usd.toFixed(2)}`;
            document.getElementById('adm-stat-ads').innerText = res.stats.ads;
            document.getElementById('adm-stat-tasks').innerText = res.stats.tasks;
            document.getElementById('adm-stat-xp').innerText = res.stats.xp;
            document.getElementById('adm-stat-refs').innerText = res.stats.refs;
            
            document.getElementById('admin-ad-sdk').value = (appState.settings.adBlockIds || []).join(', ');
            adminUsers = res.all_users;
            adminWithdrawals = res.all_withdrawals;
            renderAdminUsers();
            renderAdminWithdrawals();
        }
    }

    function switchAdminTab(tab) {
        document.querySelectorAll('.admin-section').forEach(el => el.classList.add('hidden'));
        document.getElementById(`admin-sec-${tab}`).classList.remove('hidden');
        document.querySelectorAll('.admin-tab').forEach(el => { el.classList.remove('bg-red-600/20', 'text-red-400', 'border', 'border-red-500/50'); el.classList.add('text-slate-400'); });
        document.getElementById(`tab-${tab}`).classList.add('bg-red-600/20', 'text-red-400', 'border', 'border-red-500/50');
        document.getElementById(`tab-${tab}`).classList.remove('text-slate-400');
    }

    function renderAdminUsers() {
        const query = document.getElementById('admin-user-search').value.toLowerCase();
        const list = adminUsers.filter(u => u.tgId.includes(query) || (u.username && u.username.toLowerCase().includes(query)));
        
        document.getElementById('admin-user-list').innerHTML = list.map(u => `
            <div class="glass-card p-3 rounded-lg border border-slate-700 space-y-2">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-xs font-black text-white">${u.firstName} <span class="text-slate-400 font-mono text-[9px]">@${u.username}</span></p>
                        <p class="text-[9px] text-blue-400">ID: ${u.tgId} | Active: ${u.lastActive.substr(0,16)}</p>
                    </div>
                    ${u.banned ? '<span class="text-[8px] bg-red-500/20 text-red-500 px-2 py-1 rounded">BANNED</span>' : '<span class="text-[8px] bg-emerald-500/20 text-emerald-500 px-2 py-1 rounded">ACTIVE</span>'}
                </div>
                <div class="flex gap-2 items-center bg-[#050511] p-2 rounded">
                    <input type="number" id="adm-usd-${u.tgId}" value="${u.usd.toFixed(2)}" class="w-16 bg-transparent text-xs text-emerald-400 outline-none border-b border-slate-600 text-center" title="USD">
                    <input type="number" id="adm-xp-${u.tgId}" value="${u.xp}" class="w-16 bg-transparent text-xs text-crypto-glow outline-none border-b border-slate-600 text-center" title="XP">
                    <button onclick="adminSaveUser('${u.tgId}')" class="bg-blue-600/20 text-blue-400 px-2 py-1 rounded text-[10px]">Save</button>
                </div>
                <div class="flex gap-2">
                    <button onclick="adminActionUser('${u.tgId}', 'reset_ads')" class="flex-1 bg-amber-500/20 text-amber-500 py-1 rounded text-[9px] uppercase font-black">Reset Ads (${u.adsWatchedToday})</button>
                    <button onclick="adminActionUser('${u.tgId}', '${u.banned ? 'unban' : 'ban'}')" class="flex-1 ${u.banned ? 'bg-emerald-500/20 text-emerald-500' : 'bg-red-500/20 text-red-500'} py-1 rounded text-[9px] uppercase font-black">${u.banned ? 'Unban' : 'Ban'}</button>
                </div>
            </div>
        `).join('');
    }

    function filterAdminUsers() { renderAdminUsers(); }

    async function adminSaveUser(tgId) {
        const newUsd = document.getElementById(`adm-usd-${tgId}`).value;
        const newXp = document.getElementById(`adm-xp-${tgId}`).value;
        await adminActionUser(tgId, 'update_balance', { newUsd, newXp });
    }

    async function adminActionUser(targetUid, userAction, extras = {}) {
        const res = await apiCall('admin_action_user', { adminCode: adminToken, targetUid, userAction, ...extras });
        if(res && !res.error) {
            showToast('Success', res.message, 'success');
            submitAdminAuth(); // Refresh data
        }
    }

    function renderAdminWithdrawals() {
        const pending = adminWithdrawals.filter(w => w.status === 'Pending');
        if(pending.length === 0) {
            document.getElementById('admin-withdrawal-list').innerHTML = '<p class="text-center text-xs text-slate-500 py-4">No pending requests</p>';
            return;
        }
        document.getElementById('admin-withdrawal-list').innerHTML = pending.map(w => `
            <div class="glass-card p-3 rounded-lg border border-slate-700">
                <div class="flex justify-between items-center mb-2">
                    <div>
                        <p class="text-xs font-black text-white">ID: ${w.user_id}</p>
                        <p class="text-[9px] text-slate-400">${w.date}</p>
                    </div>
                    <p class="text-sm font-black text-emerald-400">$${w.amount.toFixed(2)}</p>
                </div>
                <div class="bg-[#050511] p-1.5 rounded mb-2 overflow-hidden text-ellipsis">
                    <p class="text-[10px] text-blue-400 font-mono">${w.address}</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="adminWithdrawAction('${w.user_id}', ${w.idx}, 'approve')" class="flex-1 bg-emerald-500/20 text-emerald-500 py-1.5 rounded text-[10px] uppercase font-black">Approve</button>
                    <button onclick="adminWithdrawAction('${w.user_id}', ${w.idx}, 'reject')" class="flex-1 bg-red-500/20 text-red-500 py-1.5 rounded text-[10px] uppercase font-black">Reject</button>
                </div>
            </div>
        `).join('');
    }

    async function adminWithdrawAction(targetUid, idx, action) {
        if(confirm(`Are you sure you want to ${action} this withdrawal?`)) {
            const res = await apiCall('admin_action_withdraw', { adminCode: adminToken, targetUid, idx, withdrawAction: action });
            if(res && !res.error) {
                showToast('Success', res.message, 'success');
                submitAdminAuth(); // Refresh
            }
        }
    }

    async function saveAdminSettings() {
        const blockIdsStr = document.getElementById('admin-ad-sdk').value;
        const blockIds = blockIdsStr.split(',').map(id => id.trim()).filter(id => id.length > 0);
        
        const res = await apiCall('admin_update_settings', { adminCode: adminToken, blockIds });
        if(res && !res.error) {
            showToast('Success', res.message, 'success');
            appState.settings.adBlockIds = blockIds;
        }
    }

    // Start App
    window.addEventListener('load', initApp);
  </script>
</body>
</html>
