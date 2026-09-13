<?php
// XPVERSE - SINGLE FILE ARCHITECTURE (ADVANCED EDITION)
// All server-side logic and persistence is handled here.

error_reporting(0); // Suppress errors for clean JSON API responses in production
date_default_timezone_set('Asia/Baku'); // Server time consistency in Baku (Azerbaijan)
$dataDir = __DIR__ . '/data';

$ADMIN_UIDS = ['5461064199']; // Əsas Admin UID. Zərurət olduqda bura başqa admin UID-ləri də əlavə edilə bilər.

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

// Reliable Time Sync: Fetch from APIs with fallback to Server Time
function getReliableBakuTime() {
    $offsetFile = __DIR__ . '/data/time_offset.txt';
    $cachedOffset = @file_get_contents($offsetFile);
    
    // Update offset every 3 hours
    if ($cachedOffset === false || (time() - @filemtime($offsetFile) > 10800)) {
        $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
        $offset = 0;
        
        // API 1: WorldTimeAPI
        $res1 = @file_get_contents('http://worldtimeapi.org/api/timezone/Asia/Baku', false, $ctx);
        if ($res1) {
            $data = json_decode($res1, true);
            if (isset($data['unixtime'])) $offset = $data['unixtime'] - time();
        } else {
            // API 2 (Fallback): TimeAPI
            $res2 = @file_get_contents('https://timeapi.io/api/Time/current/zone?timeZone=Asia/Baku', false, $ctx);
            if ($res2) {
                $data = json_decode($res2, true);
                if (isset($data['dateTime'])) {
                    $offset = strtotime($data['dateTime']) - time();
                }
            }
        }
        @file_put_contents($offsetFile, $offset);
        return time() + $offset;
    }
    return time() + (int)$cachedOffset;
}

$currentTimestamp = getReliableBakuTime();
$now = new DateTime('@' . $currentTimestamp);
$now->setTimezone(new DateTimeZone('Asia/Baku'));

// Log Admin Actions
function logAdminAction($adminId, $action, $target, $details) {
    global $now;
    $logs = readDB('admin_logs.json');
    array_unshift($logs, [
        'date' => $now->format('Y-m-d H:i:s'),
        'admin' => $adminId,
        'action' => $action,
        'target' => $target,
        'details' => $details
    ]);
    // Keep last 1000 logs
    if (count($logs) > 1000) $logs = array_slice($logs, 0, 1000);
    writeDB('admin_logs.json', $logs);
}

// Ensure settings exist
$settings = readDB('settings.json');
if (empty($settings) || !isset($settings['adBlockIds'])) {
    $settings = [
        'adBlockIds' => ['int-35545'],
        'adLimit' => 30,
        'resetHour' => 3, // Baku time
        'maintenance' => false,
        'xpMultiplier' => [
            'active' => false,
            'val' => 1,
            'start' => 0,
            'end' => 0
        ]
    ];
    writeDB('settings.json', $settings);
}

// XP Multiplier Logic Check
$xpMult = 1;
if (isset($settings['xpMultiplier']) && $settings['xpMultiplier']['active']) {
    if ($currentTimestamp >= $settings['xpMultiplier']['start'] && $currentTimestamp <= $settings['xpMultiplier']['end']) {
        $xpMult = (int)$settings['xpMultiplier']['val'];
    } elseif ($currentTimestamp > $settings['xpMultiplier']['end']) {
        // Auto deactivate
        $settings['xpMultiplier']['active'] = false;
        $settings['xpMultiplier']['val'] = 1;
        writeDB('settings.json', $settings);
    }
}

// Logical Date Calculation based on dynamic Reset Hour
$hour = (int)$now->format('H');
$logicalDate = clone $now;
if ($hour < $settings['resetHour']) {
    $logicalDate->modify('-1 day');
}
$today = $logicalDate->format('Y-m-d');
$currentHourStr = $now->format('Y-m-d H');

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
    $isAdmin = in_array($uid, $ADMIN_UIDS);
    
    // Check Maintenance Mode
    if ($settings['maintenance'] === true && !$isAdmin) {
        echo json_encode(['error' => 'MAINTENANCE', 'message' => 'System is currently under maintenance. Please check back later.']);
        exit;
    }

    $users = readDB('users.json');
    $referrals = readDB('referrals.json');
    $rewards = readDB('rewards.json');
    $withdrawals = readDB('withdrawals.json');
    $tasks = readDB('tasks.json'); // completed tasks tracking
    $dynamicTasks = readDB('dynamic_tasks.json');
    $exchangeUids = readDB('exchange_uids.json');
    
    // Check Ban Status
    if (isset($users[$uid]['banned']) && $users[$uid]['banned'] === true && !$isAdmin) {
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
            'xp' => 0, // Current XP
            'totalXp' => 0, // Earned XP (Never decreases)
            'spentXp' => 0,
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
            'lastActive' => $now->format('Y-m-d H:i:s'),
            'banned' => false,
            'activityStats' => []
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
        // Ensure new fields exist for legacy users
        if(!isset($users[$uid]['spentXp'])) $users[$uid]['spentXp'] = 0;
        if(!isset($users[$uid]['dailyXp'])) $users[$uid]['dailyXp'] = 0;
        if(!isset($users[$uid]['activityStats'])) $users[$uid]['activityStats'] = [];
        
        if (isset($input['firstName'])) $users[$uid]['firstName'] = $input['firstName'];
        if (isset($input['lastName'])) $users[$uid]['lastName'] = $input['lastName'];
        if (isset($input['username'])) $users[$uid]['username'] = $input['username'];
        if (isset($input['photoUrl']) && !empty($input['photoUrl'])) $users[$uid]['photoUrl'] = $input['photoUrl'];
        $users[$uid]['lastActive'] = $now->format('Y-m-d H:i:s');
    }

    // Update Activity Stats (Hourly hit map)
    if (!isset($users[$uid]['activityStats'][$currentHourStr])) {
        $users[$uid]['activityStats'][$currentHourStr] = 0;
    }
    // Only increment activity if it's a real action, not just silent sync
    if ($action !== 'sync_state') {
        $users[$uid]['activityStats'][$currentHourStr] += 1;
    }

    // Daily Reset Logic based on Logical Date
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
        if(isset($tasks[$uid])) {
            // Keep one-time tasks, reset daily ones if implemented as such. 
            // For now, standard tasks might be considered one-time, dynamic tasks handle their own state.
            // In original script it was: $tasks[$uid] = []; 
            // Let's keep it resetting daily for hardcoded, but exclude one-time if needed.
            $tasks[$uid] = [];
        }
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

    // Helper: Evaluate Referral Progress
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
    
    // Calculate Server Reset Time Target
    $resetTarget = clone $now;
    $resetTarget->setTime((int)$settings['resetHour'], 0, 0); 
    if ($hour >= $settings['resetHour']) {
        $resetTarget->modify('+1 day');
    }
    
    $response['serverTime'] = $currentTimestamp;
    $response['serverResetTime'] = $resetTarget->getTimestamp(); 
    $response['settings'] = [
        'adBlockIds' => $settings['adBlockIds'],
        'adLimit' => $settings['adLimit'],
        'xpMultiplier' => $settings['xpMultiplier']
    ];
    $response['dynamicTasks'] = array_filter($dynamicTasks, function($t) { return $t['active']; });

    // --- ADMIN PANEL SECURE ROUTES ---
    if (strpos($action, 'admin_') === 0) {
        if (!$isAdmin) {
            echo json_encode(['error' => 'Security Breach: Unauthorized Access']); exit;
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
            $allWithdrawals = [];
            foreach($withdrawals as $uId => $uWithdrawals) {
                foreach($uWithdrawals as $idx => $w) {
                    $w['user_id'] = $uId; $w['idx'] = $idx;
                    $allWithdrawals[] = $w;
                    if ($w['status'] === 'Approved') $totalUsd += $w['amount']; // Only count approved as finalized outgoing
                }
            }
            foreach($referrals as $rList) { $totalRefs += count($rList); }

            $response['stats'] = [
                'users' => $totalUsers, 'usd' => $totalUsd, 'ads' => $totalAds, 
                'tasks' => $totalTasks, 'xp' => $totalXp, 'refs' => $totalRefs
            ];
            $response['all_users'] = array_values($users);
            $response['all_withdrawals'] = $allWithdrawals;
            $response['admin_logs'] = readDB('admin_logs.json');
            $response['exchange_uids'] = $exchangeUids;
            $response['all_tasks'] = $dynamicTasks;
            $response['full_settings'] = $settings;
            echo json_encode($response); exit;
        }

        if ($action === 'admin_update_settings') {
            $settings['adBlockIds'] = $input['blockIds'];
            $settings['adLimit'] = (int)$input['adLimit'];
            $settings['resetHour'] = (int)$input['resetHour'];
            $settings['maintenance'] = (bool)$input['maintenance'];
            
            if (isset($input['xpMultVal'])) {
                $settings['xpMultiplier']['val'] = (int)$input['xpMultVal'];
                $settings['xpMultiplier']['start'] = strtotime($input['xpStart']);
                $settings['xpMultiplier']['end'] = strtotime($input['xpEnd']);
                $settings['xpMultiplier']['active'] = $settings['xpMultiplier']['end'] > $currentTimestamp;
            }
            
            writeDB('settings.json', $settings);
            logAdminAction($uid, 'UPDATE_SETTINGS', 'System', 'Updated global settings (Maintenance: '.($settings['maintenance']?'ON':'OFF').')');
            $response['message'] = 'Settings updated successfully.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_action_user') {
            $targetUid = $input['targetUid'];
            $act = $input['userAction'];
            
            if (!isset($users[$targetUid])) { echo json_encode(['error' => 'User not found']); exit; }

            if ($act === 'ban') {
                $users[$targetUid]['banned'] = true;
                logAdminAction($uid, 'BAN_USER', $targetUid, 'User banned');
            } elseif ($act === 'unban') {
                $users[$targetUid]['banned'] = false;
                logAdminAction($uid, 'UNBAN_USER', $targetUid, 'User unbanned');
            } elseif ($act === 'reset_ads') {
                $users[$targetUid]['adsWatchedToday'] = 0;
                logAdminAction($uid, 'RESET_ADS', $targetUid, 'Daily ads reset manually');
            } elseif ($act === 'update_balance') {
                $amountDiff = (float)$input['newUsd'] - $users[$targetUid]['usd'];
                $users[$targetUid]['usd'] = max(0, (float)$input['newUsd']);
                $users[$targetUid]['xp'] = max(0, (int)$input['newXp']);
                $users[$targetUid]['totalXp'] = max($users[$targetUid]['totalXp'], $users[$targetUid]['xp']);
                logAdminAction($uid, 'UPDATE_BALANCE', $targetUid, "Bal: {$amountDiff} | Reason: " . ($input['reason'] ?? 'Manual Edit'));
            }
            writeDB('users.json', $users);
            $response['message'] = 'User updated successfully.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_action_withdraw') {
            $targetUid = $input['targetUid'];
            $idx = $input['idx'];
            $wAct = $input['withdrawAction'];
            $reason = $input['reason'] ?? '';

            if (isset($withdrawals[$targetUid][$idx])) {
                if ($withdrawals[$targetUid][$idx]['status'] === 'Pending') {
                    if ($wAct === 'approve') {
                        $withdrawals[$targetUid][$idx]['status'] = 'Approved';
                        logAdminAction($uid, 'APPROVE_WITHDRAW', $targetUid, "Amount: {$withdrawals[$targetUid][$idx]['amount']}");
                    } else if ($wAct === 'reject') {
                        $withdrawals[$targetUid][$idx]['status'] = 'Rejected';
                        $withdrawals[$targetUid][$idx]['rejectReason'] = $reason;
                        $users[$targetUid]['usd'] += $withdrawals[$targetUid][$idx]['amount'];
                        writeDB('users.json', $users);
                        logAdminAction($uid, 'REJECT_WITHDRAW', $targetUid, "Amount: {$withdrawals[$targetUid][$idx]['amount']} | Reason: {$reason}");
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
        
        if ($action === 'admin_task_manage') {
            $taskData = $input['taskData'];
            $op = $input['operation']; // add, edit, delete
            
            if ($op === 'add') {
                $taskData['id'] = 'd_task_' . uniqid();
                $taskData['completedBy'] = 0;
                $dynamicTasks[] = $taskData;
                logAdminAction($uid, 'ADD_TASK', 'System', "Task: {$taskData['title']}");
            } elseif ($op === 'edit' || $op === 'delete') {
                foreach ($dynamicTasks as $k => $t) {
                    if ($t['id'] === $taskData['id']) {
                        if ($op === 'delete') {
                            unset($dynamicTasks[$k]);
                            logAdminAction($uid, 'DELETE_TASK', 'System', "Task ID: {$taskData['id']}");
                        } else {
                            $taskData['completedBy'] = $t['completedBy'] ?? 0;
                            $dynamicTasks[$k] = $taskData;
                            logAdminAction($uid, 'EDIT_TASK', 'System', "Task: {$taskData['title']}");
                        }
                        break;
                    }
                }
                $dynamicTasks = array_values($dynamicTasks);
            }
            writeDB('dynamic_tasks.json', $dynamicTasks);
            $response['message'] = 'Task updated successfully.';
            echo json_encode($response); exit;
        }
        
        if ($action === 'admin_system_reset') {
            $type = $input['resetType'];
            if ($type === 'daily') {
                foreach($users as &$u) { $u['adsWatchedToday'] = 0; $u['dailyXp'] = 0; $u['lastResetDay'] = $today; }
                $tasks = []; writeDB('tasks.json', $tasks);
                logAdminAction($uid, 'RESET_DAILY', 'System', 'Daily stats reset manually.');
            } elseif ($type === 'full') {
                // Keep users but reset all balances and history
                foreach($users as &$u) { 
                    $u['xp'] = 0; $u['totalXp'] = 0; $u['spentXp'] = 0; $u['dailyXp'] = 0; $u['usd'] = 0; $u['tasksCompleted'] = 0; 
                    $u['adsWatchedToday'] = 0; $u['totalAdsWatched'] = 0; $u['boxesOpened'] = 0; $u['activityStats'] = [];
                }
                writeDB('withdrawals.json', []); writeDB('referrals.json', []); writeDB('rewards.json', []);
                writeDB('tasks.json', []); writeDB('exchange_uids.json', []);
                foreach($dynamicTasks as &$dt) { $dt['completedBy'] = 0; }
                writeDB('dynamic_tasks.json', $dynamicTasks);
                logAdminAction($uid, 'RESET_FULL', 'System', 'CRITICAL: Full system reset executed.');
            }
            writeDB('users.json', $users);
            $response['message'] = 'Reset executed.';
            echo json_encode($response); exit;
        }
    }
    // --- END ADMIN ROUTES ---

    // NORMAL USER ACTIONS
    switch ($action) {
        case 'sync_state':
            // Just returns the current state to frontend without incrementing actions
            break;
            
        case 'watch_ad':
            if ($users[$uid]['adsWatchedToday'] < (int)$settings['adLimit']) {
                $users[$uid]['adsWatchedToday'] += 1;
                $users[$uid]['totalAdsWatched'] += 1;
                $reward = 20 * $xpMult;
                $users[$uid]['xp'] += $reward;
                $users[$uid]['totalXp'] += $reward;
                $users[$uid]['dailyXp'] += $reward;
                $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
            } else {
                $response['error'] = 'Ad limit reached for today. Reset at ' . sprintf("%02d:00", $settings['resetHour']) . ' Baku time.';
            }
            break;

        case 'claim_task':
            $taskId = $input['taskId'] ?? '';
            
            if (!isset($tasks[$uid])) $tasks[$uid] = [];
            if (!in_array($taskId, $tasks[$uid])) {
                
                $rewardXp = 0;
                $taskTitle = "Task";
                
                // Check if it's a dynamic task
                $isDynamic = false;
                foreach($dynamicTasks as &$dt) {
                    if ($dt['id'] === $taskId && $dt['active']) {
                        $isDynamic = true;
                        $rewardXp = (int)$dt['reward'] * $xpMult;
                        $taskTitle = $dt['title'];
                        
                        // Handle Exchange UID requirement
                        if ($dt['reqExchangeUid']) {
                            $exName = $input['exchangeName'] ?? '';
                            $exUid = $input['exchangeUid'] ?? '';
                            if (empty($exUid) || empty($exName)) {
                                echo json_encode(['error' => 'Exchange Name and UID are required.']); exit;
                            }
                            $exchangeUids[] = [
                                'tgId' => $uid,
                                'username' => $users[$uid]['username'],
                                'exchangeName' => $exName,
                                'exchangeUid' => $exUid,
                                'taskTitle' => $taskTitle,
                                'date' => $now->format('Y-m-d H:i:s')
                            ];
                            writeDB('exchange_uids.json', $exchangeUids);
                        }
                        $dt['completedBy'] = ($dt['completedBy'] ?? 0) + 1;
                        writeDB('dynamic_tasks.json', $dynamicTasks);
                        break;
                    }
                }
                
                // Fallback for hardcoded missions
                if (!$isDynamic) {
                    if ($taskId === 'complete_all' && $users[$uid]['adsWatchedToday'] < (int)$settings['adLimit']) {
                        $response['error'] = 'Complete all daily tasks first.';
                        break;
                    }
                    $rewardXp = ((int)($input['reward'] ?? 0)) * $xpMult;
                }

                if ($rewardXp > 0) { 
                    $tasks[$uid][] = $taskId;
                    $users[$uid]['tasksCompleted'] += 1;
                    $users[$uid]['xp'] += $rewardXp;
                    $users[$uid]['totalXp'] += $rewardXp;
                    $users[$uid]['dailyXp'] += $rewardXp;
                    $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                    evaluateReferralProgress($uid, $users, $referrals, $rewards);
                    writeDB('tasks.json', $tasks);
                } else {
                    $response['error'] = 'Invalid task or task inactive.';
                }
            } else {
                $response['error'] = 'Task already claimed.';
            }
            break;

        case 'open_box':
            $type = $input['boxType'] ?? '';
            $costs = ['bronze' => 10000, 'silver' => 50000, 'gold' => 100000];
            
            if (isset($costs[$type]) && $users[$uid]['xp'] >= $costs[$type]) {
                $users[$uid]['xp'] -= $costs[$type];
                $users[$uid]['spentXp'] += $costs[$type];
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
                    'status' => 'Pending',
                    'rejectReason' => ''
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
    $response['isAdmin'] = $isAdmin;
    
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
    input, textarea, select { user-select: auto !important; background-color: rgba(5,5,17,0.8); }
    ::-webkit-scrollbar { width: 0px; background: transparent; }
    .glass-card { background: linear-gradient(145deg, rgba(20, 22, 45, 0.7) 0%, rgba(10, 11, 26, 0.85) 100%); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.05); }
    .fade-in { animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .nav-active { color: #00f0ff !important; transform: translateY(-3px); }
    .nav-active i { filter: drop-shadow(0 0 8px rgba(0, 240, 255, 0.8)); }
    .btn-3d { background: linear-gradient(to bottom, #3b82f6, #2563eb); border-bottom: 2px solid #1e3a8a; transition: all 0.1s; }
    .btn-3d:active { transform: translateY(2px); border-bottom-width: 0px; margin-bottom: 2px; }
    #toast-container { position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9); width: 90%; max-width: 380px; z-index: 999999; transition: all 0.4s; opacity: 0; pointer-events: none; }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }
    .modal-overlay { background: rgba(5, 5, 17, 0.85); backdrop-filter: blur(10px); z-index: 10000; }
    .admin-scroll::-webkit-scrollbar { width: 4px; }
    .admin-scroll::-webkit-scrollbar-thumb { background: #3b82f6; border-radius: 4px; }
    .box-bronze { background: linear-gradient(135deg, rgba(205,127,50,0.15), rgba(139,69,19,0.25)); border: 1px solid rgba(205,127,50,0.4); }
    .box-silver { background: linear-gradient(135deg, rgba(226,232,240,0.15), rgba(148,163,184,0.25)); border: 1px solid rgba(226,232,240,0.4); }
    .box-gold { background: linear-gradient(135deg, rgba(255,184,0,0.2), rgba(217,119,6,0.3)); border: 1px solid rgba(255,184,0,0.5); }
    .multiplier-badge { background: linear-gradient(90deg, #ff007f, #7f00ff); padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: bold; color: white; animation: pulse-fast 1s infinite; }
  </style>
</head>
<body class="flex flex-col min-h-screen">
  
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob" style="animation-delay: 2s"></div>

  <!-- Start Screen -->
  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-6">
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-rocket text-crypto-glow text-3xl animate-pulse"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase mb-2">XPVerse</h2>
  </div>

  <!-- Notification Toast -->
  <div id="toast-container" class="glass-card rounded-2xl p-3 flex items-center gap-3">
    <div id="toast-icon" class="w-10 h-10 rounded-full flex shrink-0 items-center justify-center text-lg"></div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-xs font-black text-white">Notification</h4>
      <p id="toast-message" class="text-[11px] text-slate-300 mt-0.5">Message</p>
    </div>
  </div>

  <!-- GLOBAL HEADER -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full py-3 px-4 glass-card rounded-b-[1.5rem] border-b-0 shadow-lg transition-all duration-300">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-2.5">
        <div class="relative w-10 h-10 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 to-indigo-500">
          <img id="user-photo" src="" alt="Profile" class="w-full h-full rounded-full object-cover">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm">Loading...</span>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <div class="bg-[#050511] border border-blue-500/30 px-2.5 py-1 rounded-lg flex items-center gap-1.5">
          <i class="fa-solid fa-bolt text-crypto-glow text-[10px]"></i>
          <span id="user-xp" class="text-white font-black text-xs">0 XP</span>
          <span id="xp-mult-badge" class="hidden multiplier-badge ml-1">x2</span>
        </div>
        <div class="bg-[#050511] border border-emerald-500/40 px-2.5 py-1 rounded-lg flex items-center gap-1.5">
          <i class="fa-solid fa-dollar-sign text-emerald-400 text-[9px]"></i>
          <span id="user-usd" class="text-emerald-400 font-black text-[11px]">0</span>
        </div>
      </div>
    </div>
  </header>

  <!-- MAIN CONTENT CONTAINER -->
  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-20 pb-24 relative" id="app-content">
    
    <!-- HOME PAGE -->
    <div id="view-home" class="view-section fade-in space-y-5">
      <div class="relative glass-card rounded-[1.5rem] p-5 text-center flex flex-col items-center justify-center min-h-[220px]">
        <div class="relative z-10 flex flex-col items-center">
          <h1 class="text-4xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white to-blue-500" id="main-xp-display">0 XP</h1>
        </div>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5">
            <div class="bg-blue-500/10 p-2.5 rounded-lg border border-blue-500/30"><i class="fa-solid fa-clapperboard text-blue-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black">Ads Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / <span id="max-ads">30</span></p>
            </div>
          </div>
          <div class="bg-[#050511]/60 border border-slate-700/60 p-3 rounded-xl flex items-center gap-2.5">
            <div class="bg-amber-500/10 p-2.5 rounded-lg border border-amber-500/30"><i class="fa-solid fa-fire text-amber-400 text-xs"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" class="w-full py-3.5 rounded-[1.25rem] text-white font-black text-sm uppercase flex items-center justify-center gap-2.5 btn-3d">
        <i class="fa-solid fa-play"></i> Watch Ad <span id="ad-reward-txt" class="text-cyan-200 ml-1">+20 XP</span>
      </button>

      <div class="glass-card rounded-xl p-3.5 flex justify-between items-center border border-slate-800">
        <div class="flex items-center gap-2 text-slate-400 text-[11px] font-bold">
          <i class="fa-solid fa-clock text-blue-400"></i> <span>Resets at <span id="reset-hour-display">03:00</span> Baku Time:</span>
        </div>
        <span class="text-white font-mono font-black text-xs bg-slate-900/50 px-2.5 py-1 rounded-md" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <!-- TASKS PAGE -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1">
        <h2 class="text-2xl font-black text-white">Tasks</h2>
      </div>
      <div>
        <div id="missions-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- REFERRANS PAGE -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-5">
      <div class="text-center relative mb-1">
        <h2 class="text-2xl font-black text-white">Referans</h2>
      </div>
      <div class="grid grid-cols-3 gap-2.5">
        <div class="glass-card p-3 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black mb-1">Total</p><p id="ref-total" class="text-xl font-black text-white">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black mb-1">Pending</p><p id="ref-pending" class="text-xl font-black text-amber-400">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black mb-1">Approved</p><p id="ref-approved" class="text-xl font-black text-emerald-400">0</p></div>
      </div>
      <div class="glass-card rounded-[1.25rem] p-4 space-y-3.5 border border-slate-700/50">
        <div class="flex items-center gap-2">
          <input type="text" id="ref-link-input" readonly class="flex-1 bg-[#050511]/70 border border-slate-700 rounded-lg py-2.5 px-3 text-[11px] text-slate-300">
          <button onclick="copyRefLink()" class="bg-slate-800 text-white w-10 h-10 rounded-lg flex items-center justify-center"><i class="fa-regular fa-copy text-sm"></i></button>
        </div>
        <button onclick="shareReferralTelegram()" class="w-full py-3 bg-blue-600 text-white font-black rounded-lg text-xs uppercase flex items-center justify-center gap-2">
          <i class="fa-brands fa-telegram text-base"></i> Share via Telegram
        </button>
      </div>
      <div id="referral-list-container" class="space-y-2.5"></div>
    </div>

    <!-- BOX PAGE -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-4"><h2 class="text-2xl font-black text-white">Boxes</h2></div>
      
      <div class="box-bronze glass-card rounded-[1.25rem] p-4 flex justify-between items-center">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-xl bg-orange-900 border border-crypto-bronze flex items-center justify-center"><i class="fa-solid fa-box text-2xl text-crypto-bronze"></i></div>
          <div>
            <h3 class="text-lg font-black text-white">Bronze Box</h3>
            <span class="text-[10px] text-slate-400 uppercase">10,000 XP | Max: $1</span>
          </div>
        </div>
        <button onclick="openBox('bronze')" class="bg-orange-600 text-white px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>

      <div class="box-silver glass-card rounded-[1.25rem] p-4 flex justify-between items-center">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-xl bg-slate-600 border border-crypto-silver flex items-center justify-center"><i class="fa-solid fa-box-open text-2xl text-crypto-silver"></i></div>
          <div>
            <h3 class="text-lg font-black text-white">Silver Box</h3>
            <span class="text-[10px] text-slate-400 uppercase">50,000 XP | Max: $7</span>
          </div>
        </div>
        <button onclick="openBox('silver')" class="bg-slate-400 text-black px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>

      <div class="box-gold glass-card rounded-[1.25rem] p-4 flex justify-between items-center">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-xl bg-amber-500 border border-crypto-gold flex items-center justify-center"><i class="fa-solid fa-gem text-2xl text-crypto-gold"></i></div>
          <div>
            <h3 class="text-lg font-black text-crypto-gold">Gold Box</h3>
            <span class="text-[10px] text-amber-200/80 uppercase">100,000 XP | Max: $15</span>
          </div>
        </div>
        <button onclick="openBox('gold')" class="bg-yellow-500 text-black px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>
    </div>

    <!-- WALLET PAGE -->
    <div id="view-wallet" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1"><h2 class="text-2xl font-black text-white">Wallet</h2></div>
      <div class="glass-card rounded-[1.5rem] p-5 text-center">
        <p class="text-[10px] font-black text-emerald-400 uppercase mb-1">Available Balance</p>
        <h1 class="text-4xl font-black text-white mb-3">$<span id="withdraw-balance-display">0</span></h1>
      </div>
      <div class="glass-card rounded-[1.25rem] p-4 space-y-4">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">TON Wallet Address</label>
          <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-xs text-white outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">Amount (USDT)</label>
          <input type="number" id="withdraw-amount" placeholder="10" min="10" step="0.5" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-xs text-emerald-400 font-bold outline-none focus:border-emerald-500">
        </div>
        <button onclick="requestWithdrawal()" class="w-full py-3 bg-emerald-600 text-white font-black rounded-lg text-xs uppercase flex items-center justify-center gap-2">
          <i class="fa-solid fa-money-bill-transfer"></i> Request Withdrawal
        </button>
      </div>
      <div id="withdraw-history-container" class="space-y-2.5"></div>
    </div>

    <!-- PROFILE PAGE -->
    <div id="view-profile" class="view-section hidden fade-in space-y-5">
      <div class="glass-card rounded-[1.5rem] p-5 flex flex-col items-center justify-center relative">
        <button id="admin-secret-btn" onclick="openAdmin()" class="hidden absolute top-4 right-4 w-8 h-8 rounded-full bg-red-600/20 text-red-500 flex items-center justify-center"><i class="fa-solid fa-user-shield"></i></button>
        <div class="w-20 h-20 rounded-full p-1 bg-blue-500 mb-3"><img id="profile-page-avatar" src="" class="w-full h-full rounded-full object-cover"></div>
        <h2 id="profile-page-name" class="text-xl font-black text-white">Name</h2>
        <p id="profile-page-username" class="text-[11px] text-blue-400 bg-blue-500/10 px-2 rounded-full mb-2">@username</p>
        <span class="text-[9px] text-slate-400 uppercase font-bold">ID: <span id="profile-page-id" class="text-white">0</span></span>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Current XP</p><p id="profile-stat-xp" class="text-lg font-black text-white">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Total Earned XP</p><p id="profile-stat-totxp" class="text-lg font-black text-crypto-glow">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Spent XP</p><p id="profile-stat-spxp" class="text-lg font-black text-orange-400">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Daily XP</p><p id="profile-stat-dlyxp" class="text-lg font-black text-emerald-400">0</p></div>
      </div>
    </div>

    <!-- ADMIN PANEL -->
    <div id="view-admin" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-2"><h2 class="text-2xl font-black text-red-500 flex items-center justify-center gap-2"><i class="fa-solid fa-shield-halved"></i> ADMIN PANEL</h2></div>
      
      <div class="flex flex-wrap gap-1 mb-2 bg-[#050511] p-1 rounded-lg border border-slate-700">
          <button onclick="sAdT('dashboard')" class="adm-tab flex-1 py-1.5 px-1 text-[9px] font-black uppercase rounded bg-red-600/20 text-red-400 border border-red-500/50" id="tb-dashboard">Dash</button>
          <button onclick="sAdT('users')" class="adm-tab flex-1 py-1.5 px-1 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tb-users">Users</button>
          <button onclick="sAdT('withdrawals')" class="adm-tab flex-1 py-1.5 px-1 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tb-withdrawals">W/D</button>
          <button onclick="sAdT('tasks')" class="adm-tab flex-1 py-1.5 px-1 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tb-tasks">Tasks</button>
          <button onclick="sAdT('settings')" class="adm-tab flex-1 py-1.5 px-1 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tb-settings">Set</button>
          <button onclick="sAdT('logs')" class="adm-tab flex-1 py-1.5 px-1 text-[9px] font-black uppercase rounded text-slate-400 hover:bg-slate-800" id="tb-logs">Logs</button>
      </div>

      <!-- Admin Dash -->
      <div id="as-dashboard" class="as-sec space-y-3">
          <div class="grid grid-cols-2 gap-2">
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase">Users</p><p id="ad-stat-users" class="text-lg font-black text-blue-400">0</p></div>
              <div class="glass-card p-3 rounded-xl border border-slate-700 text-center"><p class="text-[9px] text-slate-400 uppercase">Total Final USD</p><p id="ad-stat-usd" class="text-lg font-black text-emerald-400">0</p></div>
          </div>
      </div>

      <!-- Admin Users -->
      <div id="as-users" class="as-sec hidden space-y-3">
          <input type="text" id="au-search" onkeyup="filterAu()" placeholder="Search UID/User..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2 px-3 text-xs text-white">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="au-list"></div>
      </div>

      <!-- Admin Withdrawals -->
      <div id="as-withdrawals" class="as-sec hidden space-y-3">
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="aw-list"></div>
      </div>

      <!-- Admin Tasks -->
      <div id="as-tasks" class="as-sec hidden space-y-3">
          <button onclick="openTaskModal()" class="w-full py-2 bg-blue-600 text-white font-black text-xs uppercase rounded">+ Add New Task</button>
          <div class="max-h-[60vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="at-list"></div>
          <h3 class="text-xs font-black text-slate-400 uppercase mt-4 border-t border-slate-700 pt-2">Exchange UIDs Submissions</h3>
          <div class="max-h-[30vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="aex-list"></div>
      </div>

      <!-- Admin Settings -->
      <div id="as-settings" class="as-sec hidden space-y-3">
          <div class="glass-card p-3 rounded-xl border border-slate-700 space-y-3">
              <div><label class="text-[10px] text-slate-400">Adsgram IDs</label><input type="text" id="as-adsid" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white"></div>
              <div class="grid grid-cols-2 gap-2">
                  <div><label class="text-[10px] text-slate-400">Daily Ad Limit</label><input type="number" id="as-adlimit" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white"></div>
                  <div><label class="text-[10px] text-slate-400">Reset Hr (Baku)</label><input type="number" id="as-reshour" min="0" max="23" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white"></div>
              </div>
              <div><label class="text-[10px] text-slate-400">Maintenance Mode</label><select id="as-maint" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white"><option value="0">OFF</option><option value="1">ON</option></select></div>
              <div class="border-t border-slate-700 pt-2">
                  <label class="text-[10px] text-crypto-glow font-black uppercase">XP Multiplier</label>
                  <select id="as-xpmult" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-2"><option value="1">1x (Normal)</option><option value="2">2x</option><option value="3">3x</option></select>
                  <label class="text-[10px] text-slate-400">Start Time</label><input type="datetime-local" id="as-xpstart" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-2">
                  <label class="text-[10px] text-slate-400">End Time</label><input type="datetime-local" id="as-xpend" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-2">
              </div>
              <button onclick="saveASettings()" class="w-full py-2 bg-blue-600 text-white font-black text-xs uppercase rounded">Save</button>
          </div>
          
          <div class="glass-card p-3 rounded-xl border border-red-900/50 space-y-2 mt-4">
              <h3 class="text-xs font-black text-red-500 uppercase">System Resets</h3>
              <button onclick="sysReset('daily')" class="w-full py-2 bg-amber-600/20 border border-amber-500/50 text-amber-400 font-black text-xs uppercase rounded">Reset Daily Stats</button>
              <button onclick="sysReset('full')" class="w-full py-2 bg-red-600/20 border border-red-500/50 text-red-500 font-black text-xs uppercase rounded mt-2">FULL WIPE (DANGER)</button>
          </div>
      </div>
      
      <!-- Admin Logs -->
      <div id="as-logs" class="as-sec hidden space-y-2">
          <div class="max-h-[70vh] overflow-y-auto admin-scroll space-y-2 pr-1" id="al-list"></div>
      </div>
    </div>
  </main>

  <!-- BOTTOM NAVIGATION -->
  <nav id="bottom-nav" class="fixed bottom-3 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl z-50 transition-transform duration-300">
    <div class="flex justify-between items-center px-1 py-2 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-0.5 flex-1" data-target="home"><i class="fa-solid fa-house text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Home</span></button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1" data-target="tasks"><i class="fa-solid fa-list-check text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Tasks</span></button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1" data-target="referrals"><i class="fa-solid fa-users text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Referans</span></button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1" data-target="boxes"><i class="fa-solid fa-box-open text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Box</span></button>
      <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1" data-target="wallet"><i class="fa-solid fa-wallet text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Wallet</span></button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-0.5 flex-1" data-target="profile"><i class="fa-solid fa-user text-base"></i><span class="text-[7px] font-black uppercase mt-0.5">Profile</span></button>
    </div>
  </nav>

  <!-- Modals -->
  <!-- Admin User Details Modal -->
  <div id="au-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4">
      <div class="glass-card w-full max-w-sm rounded-xl p-4 relative bg-[#0a0b1a] border border-slate-700 max-h-[90vh] overflow-y-auto">
          <button onclick="document.getElementById('au-modal').classList.add('hidden')" class="absolute top-2 right-2 text-white"><i class="fa-solid fa-xmark"></i></button>
          <h3 id="aum-name" class="text-lg font-black text-white text-center mb-1">User Details</h3>
          <div id="aum-details" class="text-xs text-slate-300 space-y-1 mb-4 bg-[#050511] p-3 rounded border border-slate-800"></div>
          <hr class="border-slate-700 mb-3">
          <h4 class="text-xs font-black text-white mb-2">Edit Balance</h4>
          <input type="number" id="aum-newusd" placeholder="USD" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-2">
          <input type="number" id="aum-newxp" placeholder="XP" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-2">
          <input type="text" id="aum-reason" placeholder="Reason (Required)" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-3">
          <button id="aum-savebtn" class="w-full py-2 bg-blue-600 text-white font-black text-xs uppercase rounded mb-2">Save Balance</button>
          <button id="aum-banbtn" class="w-full py-2 bg-red-600 text-white font-black text-xs uppercase rounded">Ban / Unban</button>
      </div>
  </div>

  <!-- Task Edit/Add Modal -->
  <div id="at-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4">
      <div class="glass-card w-full max-w-sm rounded-xl p-4 relative bg-[#0a0b1a] border border-slate-700">
          <button onclick="document.getElementById('at-modal').classList.add('hidden')" class="absolute top-2 right-2 text-white"><i class="fa-solid fa-xmark"></i></button>
          <h3 class="text-lg font-black text-white text-center mb-4">Manage Task</h3>
          <input type="hidden" id="atm-id">
          <label class="text-[10px] text-slate-400">Title</label><input type="text" id="atm-title" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-2">
          <label class="text-[10px] text-slate-400">Reward XP</label><input type="number" id="atm-reward" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-2">
          <label class="text-[10px] text-slate-400">Require Exchange UID?</label><select id="atm-exreq" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-2"><option value="0">No</option><option value="1">Yes</option></select>
          <label class="text-[10px] text-slate-400">Active</label><select id="atm-active" class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-2 text-xs text-white mb-4"><option value="1">Yes</option><option value="0">No</option></select>
          <button onclick="saveTask()" class="w-full py-2 bg-emerald-600 text-white font-black text-xs uppercase rounded">Save Task</button>
      </div>
  </div>

  <!-- Exchange UID Submit Modal for User -->
  <div id="ex-submit-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 z-[999999]">
      <div class="glass-card w-full max-w-sm rounded-xl p-5 relative bg-[#0a0b1a] border border-blue-500/50 shadow-[0_0_20px_rgba(0,240,255,0.2)]">
          <button onclick="closeExModal()" class="absolute top-3 right-3 text-slate-400"><i class="fa-solid fa-xmark"></i></button>
          <h3 class="text-lg font-black text-white text-center mb-1">Exchange Requirement</h3>
          <p class="text-[10px] text-slate-400 text-center mb-4">This task requires your Exchange UID.</p>
          <input type="hidden" id="exm-taskid">
          <label class="text-[10px] text-slate-400 uppercase font-black">Exchange Name</label>
          <input type="text" id="exm-name" placeholder="e.g. Binance, Bybit" class="w-full bg-[#050511] border border-slate-700 rounded py-2.5 px-3 text-xs text-white mb-3">
          <label class="text-[10px] text-slate-400 uppercase font-black">Your Exchange UID</label>
          <input type="text" id="exm-uid" placeholder="12345678" class="w-full bg-[#050511] border border-slate-700 rounded py-2.5 px-3 text-xs text-white mb-4">
          <button onclick="submitExTask()" class="w-full py-3 bg-blue-600 text-white font-black text-xs uppercase rounded">Submit & Claim</button>
      </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand(); tg.ready(); tg.setHeaderColor('#0a0b1a'); tg.setBackgroundColor('#050511');

    const tgUser = tg.initDataUnsafe?.user || { id: "123", first_name: "Dev", username: "dev" };
    const startParam = tg.initDataUnsafe?.start_param || null;

    let appState = {
      user: {}, referrals: [], rewards: [], withdrawals: [], tasks: [], settings: { adLimit: 30, resetHour: 3 }, dynamicTasks: [], isAdmin: false
    };
    let adminData = {};

    function formatNum(num, isMoney = false) {
        if (!num) return isMoney ? "0" : "0";
        let val = Number(num);
        return isMoney ? (val % 1 === 0 ? val.toString() : val.toFixed(2)) : val.toLocaleString();
    }
    
    function dtLocal(timestamp) {
        if(!timestamp) return '';
        let d = new Date(timestamp * 1000);
        return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    }

    async function apiCall(action, payload = {}, silent = false) {
      try {
        const body = {
          action, tgId: tgUser.id, firstName: tgUser.first_name||'', lastName: tgUser.last_name||'',
          username: tgUser.username||'', photoUrl: tgUser.photo_url||'', referrer: startParam, ...payload
        };
        const res = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const data = await res.json();
        
        if(data.error) {
          if (data.error === 'BANNED' || data.error === 'MAINTENANCE') {
             document.body.innerHTML = `<div class="h-screen w-full flex flex-col items-center justify-center bg-red-900 text-white p-5 text-center"><i class="fa-solid fa-triangle-exclamation text-5xl mb-4"></i><h1 class="text-2xl font-black mb-2">${data.error}</h1><p class="text-xs opacity-80">${data.message}</p></div>`;
             return false;
          }
          if(!silent) showToast("Error", data.error, "error");
          return false;
        }

        if(data.user) appState.user = data.user;
        if(data.settings) appState.settings = data.settings;
        if(data.dynamicTasks) appState.dynamicTasks = data.dynamicTasks;
        if(data.tasks) appState.tasks = data.tasks;
        if(data.withdrawals) appState.withdrawals = data.withdrawals;
        appState.isAdmin = data.isAdmin || false;

        if(!silent) updateUI();
        else updateUISilent();
        
        if (action === 'admin_dashboard') adminData = data;
        
        return data;
      } catch (err) {
        if(!silent) showToast("Network Error", "Could not reach server.", "error");
        return false;
      }
    }

    function showToast(title, message, type = 'info') {
      const toast = document.getElementById('toast-container');
      const icon = document.getElementById('toast-icon');
      document.getElementById('toast-title').innerText = title; document.getElementById('toast-message').innerText = message;
      
      let iC = 'bg-blue-500/20 text-crypto-glow border border-crypto-glow/50', iH = '<i class="fa-solid fa-bell"></i>';
      if (type === 'success') { iH = '<i class="fa-solid fa-check"></i>'; iC = 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50'; } 
      else if (type === 'error') { iH = '<i class="fa-solid fa-xmark"></i>'; iC = 'bg-red-500/20 text-red-400 border border-red-500/50'; } 

      icon.innerHTML = iH; icon.className = `w-10 h-10 rounded-lg flex shrink-0 items-center justify-center text-base ${iC}`;
      toast.classList.add('toast-show');
      if (tg.HapticFeedback) tg.HapticFeedback.notificationOccurred(type === 'success'?'success':(type==='error'?'error':'warning'));
      setTimeout(() => { toast.classList.remove('toast-show'); }, 3000); 
    }

    function updateUISilent() {
        const u = appState.user;
        document.getElementById('user-xp').innerHTML = `${formatNum(u.xp)} XP`;
        document.getElementById('user-usd').innerText = formatNum(u.usd, true);
        document.getElementById('main-xp-display').innerText = `${formatNum(u.xp)} XP`;
        document.getElementById('ads-watched').innerText = u.adsWatchedToday;
        document.getElementById('withdraw-balance-display').innerText = formatNum(u.usd, true);
        
        const mult = appState.settings.xpMultiplier;
        const b = document.getElementById('xp-mult-badge');
        if (mult && mult.active && mult.val > 1) { b.innerText = `x${mult.val}`; b.classList.remove('hidden'); document.getElementById('ad-reward-txt').innerText = `+${20 * mult.val} XP`; }
        else { b.classList.add('hidden'); document.getElementById('ad-reward-txt').innerText = `+20 XP`; }
    }

    function updateUI() {
      const u = appState.user;
      const fN = [u.firstName, u.lastName].filter(Boolean).join(' ') || 'User';
      document.getElementById('user-name').innerText = fN;
      document.getElementById('max-ads').innerText = appState.settings.adLimit;
      document.getElementById('reset-hour-display').innerText = String(appState.settings.resetHour).padStart(2, '0') + ':00';
      
      const aFallback = `https://ui-avatars.com/api/?name=${encodeURIComponent(fN)}&background=0a0b1a&color=00f0ff&bold=true`;
      document.getElementById('user-photo').src = u.photoUrl || aFallback;
      document.getElementById('profile-page-avatar').src = u.photoUrl || aFallback;

      document.getElementById('profile-page-name').innerText = fN;
      document.getElementById('profile-page-username').innerText = u.username ? `@${u.username}` : '';
      document.getElementById('profile-page-id').innerText = u.tgId;
      document.getElementById('profile-stat-xp').innerText = formatNum(u.xp);
      document.getElementById('profile-stat-totxp').innerText = formatNum(u.totalXp);
      document.getElementById('profile-stat-spxp').innerText = formatNum(u.spentXp || 0);
      document.getElementById('profile-stat-dlyxp').innerText = formatNum(u.dailyXp || 0);

      document.getElementById('streak-days').innerText = u.streak;
      if (appState.isAdmin) document.getElementById('admin-secret-btn').classList.remove('hidden');
      
      updateUISilent();
      renderTasks(); renderWithdrawHistory();
    }

    function renderTasks() {
      const c = document.getElementById('missions-container'); c.innerHTML = '';
      const mult = appState.settings.xpMultiplier?.active ? appState.settings.xpMultiplier.val : 1;
      
      appState.dynamicTasks.forEach(t => {
        const claimed = appState.tasks.includes(t.id);
        const rew = t.reward * mult;
        let b = `<button onclick="${t.reqExchangeUid ? `openExModal('${t.id}')` : `claimT('${t.id}')`}" class="text-[10px] font-black bg-blue-600 text-white px-3 py-1.5 rounded uppercase">Claim</button>`;
        if (claimed) b = `<span class="text-[9px] font-black text-emerald-400 px-2 py-1 bg-emerald-900/30 rounded"><i class="fa-solid fa-check"></i></span>`;
        
        c.innerHTML += `<div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-700 mb-2">
            <div><span class="text-[13px] font-black text-white block">${t.title}</span><span class="text-crypto-glow text-[9px] font-bold">+${rew} XP</span></div>${b}</div>`;
      });
    }

    function openExModal(taskId) {
        document.getElementById('exm-taskid').value = taskId;
        document.getElementById('exm-name').value = ''; document.getElementById('exm-uid').value = '';
        document.getElementById('ex-submit-modal').classList.remove('hidden');
        document.getElementById('ex-submit-modal').classList.add('flex');
    }
    function closeExModal() {
        document.getElementById('ex-submit-modal').classList.add('hidden');
        document.getElementById('ex-submit-modal').classList.remove('flex');
    }
    async function submitExTask() {
        const tId = document.getElementById('exm-taskid').value;
        const eN = document.getElementById('exm-name').value.trim();
        const eU = document.getElementById('exm-uid').value.trim();
        if(!eN || !eU) return showToast('Error', 'Please fill all fields', 'error');
        
        const res = await apiCall('claim_task', { taskId: tId, exchangeName: eN, exchangeUid: eU });
        if(res && !res.error) { showToast('Success', 'Task claimed!', 'success'); closeExModal(); }
    }

    async function claimT(taskId) {
        if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
        const res = await apiCall('claim_task', { taskId });
        if(res && !res.error) showToast('Success', `Task completed!`, 'success');
    }

    async function watchAd() {
      if (appState.user.adsWatchedToday >= appState.settings.adLimit) return showToast('Limit Reached', 'Daily ad limit reached.', 'error');
      try {
        const id = appState.settings.adBlockIds[0] || 'int-35545';
        const AdC = window.Adsgram.init({ blockId: id, debug: false });
        AdC.show().then(async (res) => {
          if (res.done) {
            const r = await apiCall('watch_ad');
            if (r && !r.error) showToast('Success', 'Ad watched successfully!', 'success');
          }
        }).catch(err => { showToast('Ad Error', 'Could not load ad.', 'error'); });
      } catch (e) { showToast('Ad Error', 'Failed to initialize.', 'error'); }
    }

    function renderWithdrawHistory() {
      const hc = document.getElementById('withdraw-history-container'); hc.innerHTML = '';
      if(appState.withdrawals.length === 0) { hc.innerHTML = '<p class="text-center text-slate-500 text-xs">No withdrawals yet.</p>'; return; }
      
      appState.withdrawals.forEach(w => {
        let sc = w.status==='Approved'?'text-emerald-400':(w.status==='Rejected'?'text-red-400':'text-amber-400');
        let rj = w.status==='Rejected' && w.rejectReason ? `<p class="text-[9px] text-red-400 mt-1">Reason: ${w.rejectReason}</p>` : '';
        hc.innerHTML += `<div class="bg-[#050511]/60 p-3 rounded-xl border border-slate-800"><div class="flex justify-between items-center mb-1"><span class="text-xs font-black text-white">$${formatNum(w.amount, true)} USDT</span><span class="text-[9px] font-black ${sc} uppercase">${w.status}</span></div><p class="text-[10px] text-slate-500">${w.address}</p><p class="text-[8px] text-slate-600">${w.date}</p>${rj}</div>`;
      });
    }

    async function requestWithdrawal() {
      const amt = parseFloat(document.getElementById('withdraw-amount').value);
      const addr = document.getElementById('wallet-address').value.trim();
      if(amt < 10) return showToast('Error', 'Min $10', 'error');
      if(amt > appState.user.usd) return showToast('Error', 'Insufficient balance', 'error');
      if(addr.length < 5) return showToast('Error', 'Invalid address', 'error');
      
      const res = await apiCall('withdraw', { amount: amt, address: addr });
      if(res && !res.error) {
          showToast('Success', 'Withdrawal requested', 'success');
          document.getElementById('withdraw-amount').value = '';
          document.getElementById('wallet-address').value = '';
      }
    }

    async function openBox(type) {
      const res = await apiCall('open_box', { boxType: type });
      if(res && !res.error) {
          if(res.jackpot) showToast('JACKPOT!', `You won $${res.reward} USDT!`, 'jackpot');
          else showToast('Reward', `You won $${res.reward} USDT!`, 'success');
      }
    }

    // Timer Logic
    setInterval(() => {
      if(!appState.user) return;
      let diff = 0;
      fetch(window.location.href, { method:'POST', body: JSON.stringify({action:'sync_state', tgId: tgUser.id}) }).then(r=>r.json()).then(d=>{
          if(d.serverResetTime && d.serverTime) {
             diff = d.serverResetTime - d.serverTime;
             if(diff < 0) diff = 0;
             let h = Math.floor(diff / 3600), m = Math.floor((diff % 3600)/60), s = diff % 60;
             document.getElementById('reset-timer').innerText = `${h.toString().padStart(2,'0')}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
          }
      });
    }, 60000); // UI timer precision can be handled via JS decrement, but 60s sync is safe enough for mini app. Let's make a precise JS tick.
    
    let serverTimeDelta = 0; let sResetTarget = 0;
    setInterval(() => {
        if(sResetTarget === 0) return;
        const nowSec = Math.floor(Date.now() / 1000) + serverTimeDelta;
        let diff = sResetTarget - nowSec;
        if(diff < 0) diff = 0;
        let h = Math.floor(diff / 3600), m = Math.floor((diff % 3600)/60), s = diff % 60;
        document.getElementById('reset-timer').innerText = `${h.toString().padStart(2,'0')}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
    }, 1000);

    // Live Sync
    setInterval(async () => {
        if(document.visibilityState === 'visible' && appState.user.tgId) {
            const res = await apiCall('sync_state', {}, true);
            if(res && res.serverTime) {
                serverTimeDelta = res.serverTime - Math.floor(Date.now() / 1000);
                sResetTarget = res.serverResetTime;
            }
        }
    }, 15000);

    // Navigation
    function switchTab(t) {
      document.querySelectorAll('.view-section').forEach(v => v.classList.add('hidden'));
      document.getElementById('view-' + t).classList.remove('hidden');
      document.querySelectorAll('.nav-btn').forEach(b => {
        b.classList.remove('nav-active');
        if (b.dataset.target === t) b.classList.add('nav-active');
      });
      if(t === 'admin' && appState.isAdmin) loadAdminDash();
    }

    // --- ADMIN PANEL FUNCTIONS ---
    function openAdmin() { switchTab('admin'); }
    function sAdT(t) {
        document.querySelectorAll('.as-sec').forEach(v => v.classList.add('hidden'));
        document.getElementById('as-' + t).classList.remove('hidden');
        document.querySelectorAll('.adm-tab').forEach(b => { b.classList.remove('bg-red-600/20', 'text-red-400', 'border-red-500/50'); b.classList.add('text-slate-400'); });
        document.getElementById('tb-' + t).classList.add('bg-red-600/20', 'text-red-400', 'border-red-500/50');
        document.getElementById('tb-' + t).classList.remove('text-slate-400');
    }

    async function loadAdminDash() {
        const d = await apiCall('admin_dashboard'); if(!d) return;
        document.getElementById('ad-stat-users').innerText = d.stats.users; document.getElementById('ad-stat-usd').innerText = `$${formatNum(d.stats.usd,true)}`;
        
        // Settings pop
        document.getElementById('as-adsid').value = d.full_settings.adBlockIds.join(',');
        document.getElementById('as-adlimit').value = d.full_settings.adLimit;
        document.getElementById('as-reshour').value = d.full_settings.resetHour;
        document.getElementById('as-maint').value = d.full_settings.maintenance ? "1" : "0";
        if(d.full_settings.xpMultiplier) {
            document.getElementById('as-xpmult').value = d.full_settings.xpMultiplier.val;
            document.getElementById('as-xpstart').value = dtLocal(d.full_settings.xpMultiplier.start);
            document.getElementById('as-xpend').value = dtLocal(d.full_settings.xpMultiplier.end);
        }
        
        renderAUsers(d.all_users); renderAWith(d.all_withdrawals); renderATasks(d.all_tasks, d.exchange_uids); renderALogs(d.admin_logs);
    }

    function renderAUsers(users) {
        const c = document.getElementById('au-list'); c.innerHTML = '';
        users.sort((a,b)=>new Date(b.lastActive)-new Date(a.lastActive)).forEach(u => {
            const act = u.banned ? 'text-red-500' : 'text-slate-400';
            c.innerHTML += `<div class="bg-[#050511] p-2 border border-slate-800 rounded flex justify-between items-center cursor-pointer hover:bg-slate-900" onclick='openAUModal(${JSON.stringify(u).replace(/'/g, "&apos;")})'>
                <div><p class="text-xs text-white font-bold">${u.tgId} <span class="${act} ml-1">${u.banned?'(BANNED)':''}</span></p><p class="text-[10px] text-slate-500">@${u.username||'no_user'} | XP: ${formatNum(u.xp)}</p></div>
                <div class="text-right"><p class="text-xs text-emerald-400 font-bold">$${formatNum(u.usd,true)}</p><p class="text-[8px] text-slate-500">Last: ${u.lastActive.split(' ')[0]}</p></div>
            </div>`;
        });
    }

    let currAu = null;
    function openAUModal(u) {
        currAu = u;
        document.getElementById('aum-name').innerText = (u.firstName||'') + ' ' + (u.lastName||'');
        let ht = `<b>UID:</b> ${u.tgId}<br><b>User:</b> @${u.username||'-'}<br><b>Joined:</b> ${u.lastResetDay}<br>
        <b>Bal:</b> $${formatNum(u.usd,true)} | <b>Curr XP:</b> ${formatNum(u.xp)}<br>
        <b>Total Earned XP:</b> ${formatNum(u.totalXp)}<br><b>Spent XP:</b> ${formatNum(u.spentXp)}<br><b>Daily XP:</b> ${formatNum(u.dailyXp)}<br>
        <b>Daily Ads:</b> ${u.adsWatchedToday} | <b>Total Ads:</b> ${u.totalAdsWatched}<br>
        <b>Tasks Done:</b> ${u.tasksCompleted} | <b>Boxes:</b> ${u.boxesOpened}<br>
        <b>Last Active:</b> ${u.lastActive}`;
        document.getElementById('aum-details').innerHTML = ht;
        document.getElementById('aum-newusd').value = u.usd;
        document.getElementById('aum-newxp').value = u.xp;
        
        document.getElementById('aum-banbtn').innerText = u.banned ? 'Unban User' : 'Ban User';
        document.getElementById('aum-banbtn').onclick = () => actUser(u.tgId, u.banned ? 'unban' : 'ban');
        document.getElementById('aum-savebtn').onclick = () => {
            const nu = parseFloat(document.getElementById('aum-newusd').value);
            const nx = parseInt(document.getElementById('aum-newxp').value);
            const rz = document.getElementById('aum-reason').value.trim();
            if(!rz) return showToast('Error', 'Reason required', 'error');
            actUser(u.tgId, 'update_balance', { newUsd: nu, newXp: nx, reason: rz });
        };
        document.getElementById('au-modal').classList.remove('hidden'); document.getElementById('au-modal').classList.add('flex');
    }

    async function actUser(uid, action, extra = {}) {
        const r = await apiCall('admin_action_user', { targetUid: uid, userAction: action, ...extra });
        if(r && !r.error) { showToast('Success', r.message, 'success'); document.getElementById('au-modal').classList.add('hidden'); loadAdminDash(); }
    }

    function renderAWith(list) {
        const c = document.getElementById('aw-list'); c.innerHTML = '';
        list.reverse().forEach(w => {
            let ac = w.status==='Pending' ? `<div class="mt-2 flex gap-2"><button onclick="actWith('${w.user_id}', ${w.idx}, 'approve')" class="flex-1 bg-emerald-600 text-white text-[10px] py-1 rounded">Approve</button><button onclick="actWith('${w.user_id}', ${w.idx}, 'reject')" class="flex-1 bg-red-600 text-white text-[10px] py-1 rounded">Reject</button></div>` : `<p class="text-[10px] font-bold mt-1 ${w.status==='Approved'?'text-emerald-400':'text-red-400'}">${w.status}</p>`;
            c.innerHTML += `<div class="bg-[#050511] p-2 border border-slate-800 rounded"><p class="text-xs text-white">UID: ${w.user_id}</p><p class="text-xs text-emerald-400 font-bold">$${w.amount} USDT</p><p class="text-[10px] text-slate-400">Addr: ${w.address}</p><p class="text-[8px] text-slate-500">${w.date}</p>${ac}</div>`;
        });
    }

    async function actWith(uid, idx, act) {
        let rz = '';
        if(act === 'reject') {
            rz = prompt("Enter reject reason:");
            if(rz === null) return;
        }
        const r = await apiCall('admin_action_withdraw', { targetUid: uid, idx: idx, withdrawAction: act, reason: rz });
        if(r && !r.error) { showToast('Success', r.message, 'success'); loadAdminDash(); }
    }

    function renderATasks(tasks, exUids) {
        const c = document.getElementById('at-list'); c.innerHTML = '';
        tasks.forEach(t => {
            c.innerHTML += `<div class="bg-[#050511] p-2 border border-slate-800 rounded flex justify-between items-center"><div><p class="text-xs text-white">${t.title} ${t.active?'':'<span class="text-red-500 text-[9px]">(Inact)</span>'}</p><p class="text-[10px] text-crypto-glow">+${t.reward} XP | Done: ${t.completedBy||0}</p></div><div class="flex gap-2"><button onclick='openTaskModal(${JSON.stringify(t).replace(/'/g, "&apos;")})' class="text-[10px] text-blue-400"><i class="fa-solid fa-pen"></i></button><button onclick="delTask('${t.id}')" class="text-[10px] text-red-400"><i class="fa-solid fa-trash"></i></button></div></div>`;
        });
        const exc = document.getElementById('aex-list'); exc.innerHTML = '';
        (exUids||[]).reverse().forEach(e => {
            exc.innerHTML += `<div class="bg-[#050511] p-2 border border-slate-800 rounded"><p class="text-[10px] text-white">UID: ${e.tgId} (@${e.username})</p><p class="text-xs text-emerald-400 font-bold">${e.exchangeName}: ${e.exchangeUid}</p><p class="text-[9px] text-slate-500">Task: ${e.taskTitle} | Date: ${e.date}</p></div>`;
        });
    }

    function openTaskModal(t = null) {
        if(t) {
            document.getElementById('atm-id').value = t.id; document.getElementById('atm-title').value = t.title;
            document.getElementById('atm-reward').value = t.reward; document.getElementById('atm-exreq').value = t.reqExchangeUid ? "1":"0";
            document.getElementById('atm-active').value = t.active ? "1":"0";
        } else {
            document.getElementById('atm-id').value = ''; document.getElementById('atm-title').value = '';
            document.getElementById('atm-reward').value = ''; document.getElementById('atm-exreq').value = "0";
            document.getElementById('atm-active').value = "1";
        }
        document.getElementById('at-modal').classList.remove('hidden'); document.getElementById('at-modal').classList.add('flex');
    }

    async function saveTask() {
        const id = document.getElementById('atm-id').value;
        const d = {
            id: id, title: document.getElementById('atm-title').value, reward: parseInt(document.getElementById('atm-reward').value),
            reqExchangeUid: document.getElementById('atm-exreq').value === "1", active: document.getElementById('atm-active').value === "1"
        };
        const r = await apiCall('admin_task_manage', { operation: id ? 'edit' : 'add', taskData: d });
        if(r && !r.error) { showToast('Success', r.message, 'success'); document.getElementById('at-modal').classList.add('hidden'); loadAdminDash(); }
    }
    async function delTask(id) {
        if(confirm('Delete task?')) {
            const r = await apiCall('admin_task_manage', { operation: 'delete', taskData: {id: id} });
            if(r && !r.error) loadAdminDash();
        }
    }

    async function saveASettings() {
        const d = {
            blockIds: document.getElementById('as-adsid').value.split(',').map(s=>s.trim()),
            adLimit: parseInt(document.getElementById('as-adlimit').value),
            resetHour: parseInt(document.getElementById('as-reshour').value),
            maintenance: document.getElementById('as-maint').value === "1",
            xpMultVal: parseInt(document.getElementById('as-xpmult').value),
            xpStart: document.getElementById('as-xpstart').value,
            xpEnd: document.getElementById('as-xpend').value
        };
        const r = await apiCall('admin_update_settings', d);
        if(r && !r.error) showToast('Success', 'Settings Saved', 'success');
    }

    async function sysReset(type) {
        let msg = type === 'daily' ? 'Reset all daily stats (Ads, Daily XP)?' : 'DANGER: Full wipe (Balance, XP, history)? THIS CANNOT BE UNDONE.';
        if(confirm(msg)) {
            const r = await apiCall('admin_system_reset', { resetType: type });
            if(r && !r.error) { showToast('Success', 'System reset complete', 'success'); loadAdminDash(); }
        }
    }

    function renderALogs(logs) {
        const c = document.getElementById('al-list'); c.innerHTML = '';
        logs.forEach(l => {
            c.innerHTML += `<div class="bg-[#050511] p-2 border border-slate-800 rounded mb-1"><p class="text-[9px] text-slate-400">${l.date} | Admin: ${l.admin}</p><p class="text-xs text-white font-bold">${l.action} <span class="text-[10px] text-slate-300 ml-1">-> ${l.target}</span></p><p class="text-[10px] text-blue-400">${l.details}</p></div>`;
        });
    }

    function filterAu() {
        const v = document.getElementById('au-search').value.toLowerCase();
        if(!adminData || !adminData.all_users) return;
        const f = adminData.all_users.filter(u => u.tgId.includes(v) || (u.username||'').toLowerCase().includes(v));
        renderAUsers(f);
    }

    // Init App
    window.onload = async () => { 
      const res = await apiCall('init'); 
      if(res) {
          document.getElementById('loading-overlay').style.opacity = '0';
          setTimeout(() => { document.getElementById('loading-overlay').style.display = 'none'; }, 500);
      }
    };
  </script>
</body>
</html>
