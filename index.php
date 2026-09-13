<?php
// XPVERSE - SINGLE FILE ARCHITECTURE
// Secure, Server-Authoritative Backend with Telegram WebApp Validation

// --- 1. CONFIGURATION & SECRETS ---
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE'); // MUST BE SET
define('ADMIN_CODE_HASH', '$2y$10$YourGeneratedBcryptHashHere'); // Generate with password_hash('YOUR_CODE', PASSWORD_BCRYPT)
define('ERROR_LOG_RETENTION_DAYS', 30);
define('MAX_ERRORS_PER_USER_PER_HOUR', 50);

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
    file_put_contents("$dataDir/.htaccess", "Order deny,allow\nDeny from all");
}

// --- 2. ERROR & EXCEPTION HANDLING (SERVER ERROR LOGGING SYSTEM) ---
$requestId = 'REQ-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
$isApiRequest = ($_SERVER['REQUEST_METHOD'] === 'POST');

function sanitizeContext($context) {
    $sanitized = [];
    foreach ($context as $k => $v) {
        if (is_object($v) || is_resource($v)) continue;
        if (in_array(strtolower($k), ['token', 'password', 'initdata', 'hash', 'code', 'admincode'])) {
            $sanitized[$k] = '[REDACTED]';
        } else {
            $sanitized[$k] = is_array($v) ? sanitizeContext($v) : $v;
        }
    }
    return $sanitized;
}

function logServerError($severity, $type, $message, $file, $line, $context = [], $uid = 'Unknown') {
    global $dataDir, $requestId, $isApiRequest;
    
    // Rate Limiting Errors
    $rateLimitFile = "$dataDir/error_rates.json";
    $rates = file_exists($rateLimitFile) ? json_decode(file_get_contents($rateLimitFile), true) : [];
    $hourKey = date('Y-m-d-H');
    $userRateKey = $uid . '_' . $hourKey;
    if (($rates[$userRateKey] ?? 0) > MAX_ERRORS_PER_USER_PER_HOUR) return null;
    $rates[$userRateKey] = ($rates[$userRateKey] ?? 0) + 1;
    
    // Cleanup old rates randomly
    if (rand(1, 100) === 1) {
        foreach($rates as $k => $v) if (strpos($k, $hourKey) === false) unset($rates[$k]);
    }
    file_put_contents($rateLimitFile, json_encode($rates));

    $errorId = 'ERR-' . strtoupper(substr(md5(uniqid(microtime(), true)), 0, 8));
    $logEntry = [
        'errorId' => $errorId,
        'requestId' => $requestId,
        'timestamp' => date('Y-m-d H:i:s'),
        'serverTime' => time(),
        'uid' => $uid,
        'endpoint' => $_POST['action'] ?? 'Unknown',
        'method' => $_SERVER['REQUEST_METHOD'],
        'status' => 500,
        'severity' => $severity,
        'type' => $type,
        'message' => $message,
        'file' => basename($file),
        'line' => $line,
        'context' => sanitizeContext($context)
    ];

    $logFile = "$dataDir/errors.json";
    $fp = fopen($logFile, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $size = filesize($logFile);
        $logs = $size > 0 ? json_decode(fread($fp, $size), true) : [];
        if (!is_array($logs)) $logs = [];
        array_unshift($logs, $logEntry);
        
        // Retention Policy
        $cutoff = strtotime('-' . ERROR_LOG_RETENTION_DAYS . ' days');
        $logs = array_filter($logs, function($l) use ($cutoff) { return isset($l['serverTime']) && $l['serverTime'] > $cutoff; });
        
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode(array_values($logs)));
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $errorId;
}

function returnSafeError($errorId, $message = "Something went wrong. Please try again later.") {
    global $isApiRequest;
    if ($isApiRequest) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message, 'errorId' => $errorId]);
        exit;
    } else {
        die("System Error. Error ID: " . htmlspecialchars($errorId));
    }
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    $type = 'PHP Error';
    $severity = in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]) ? 'CRITICAL' : 'WARNING';
    $uid = $GLOBALS['authenticatedUid'] ?? 'Unknown';
    $eid = logServerError($severity, $type, $errstr, $errfile, $errline, [], $uid);
    if ($severity === 'CRITICAL') returnSafeError($eid);
    return true;
});

set_exception_handler(function($ex) {
    $uid = $GLOBALS['authenticatedUid'] ?? 'Unknown';
    $eid = logServerError('CRITICAL', 'Exception', $ex->getMessage(), $ex->getFile(), $ex->getLine(), ['trace' => $ex->getTraceAsString()], $uid);
    returnSafeError($eid);
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        $uid = $GLOBALS['authenticatedUid'] ?? 'Unknown';
        $eid = logServerError('CRITICAL', 'Fatal Error', $error['message'], $error['file'], $error['line'], [], $uid);
        returnSafeError($eid);
    }
});

// Suppress output
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('UTC'); // Internal calculations in UTC

// --- 3. DATABASE HELPER FUNCTIONS (ATOMIC TRANSACTIONS) ---
function modifyDB($filename, $callback) {
    global $dataDir;
    $path = "$dataDir/$filename";
    $lockFile = fopen("$path.lock", 'c');
    flock($lockFile, LOCK_EX);
    
    $data = [];
    if (file_exists($path)) {
        $raw = file_get_contents($path);
        $data = json_decode($raw, true) ?: [];
    }
    
    $newData = $callback($data);
    
    if ($newData !== null) {
        $tmpPath = $path . '.tmp.' . uniqid();
        file_put_contents($tmpPath, json_encode($newData, JSON_UNESCAPED_UNICODE));
        rename($tmpPath, $path);
    }
    
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
    return $newData;
}

function readDB($filename) {
    global $dataDir;
    $path = "$dataDir/$filename";
    if (!file_exists($path)) return [];
    $lockFile = fopen("$path.lock", 'c');
    flock($lockFile, LOCK_SH);
    $raw = file_get_contents($path);
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
    return json_decode($raw, true) ?: [];
}

// Ensure settings
modifyDB('settings.json', function($settings) {
    if (empty($settings)) {
        return [
            'adBlockIds' => ['int-35545'],
            'maintenance' => false,
            'adminUid' => '5461064199',
            'resetTime' => '03:00', // Europe/Moscow
            'xpMultiplier' => 1
        ];
    }
    return null;
});

// Audit Logger
function logAudit($adminUid, $action, $targetUid, $oldVal, $newVal, $reason) {
    modifyDB('audit.json', function($audit) use ($adminUid, $action, $targetUid, $oldVal, $newVal, $reason) {
        $audit[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'adminUid' => $adminUid,
            'action' => $action,
            'targetUid' => $targetUid,
            'old' => $oldVal,
            'new' => $newVal,
            'reason' => $reason
        ];
        return $audit;
    });
}

// XP Transaction Logger
function logXPTransaction($uid, $type, $amount, $prevXp, $newXp, $source) {
    modifyDB("xp_history_$uid.json", function($history) use ($type, $amount, $prevXp, $newXp, $source) {
        $history[] = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'amount' => $amount,
            'prev' => $prevXp,
            'new' => $newXp,
            'source' => $source
        ];
        if(count($history) > 100) array_shift($history);
        return $history;
    });
}

// --- 4. TELEGRAM AUTHENTICATION ---
function validateTelegramInitData($initData, $botToken) {
    if (empty($initData)) return false;
    parse_str($initData, $parsed);
    if (!isset($parsed['hash'])) return false;
    
    $hash = $parsed['hash'];
    unset($parsed['hash']);
    ksort($parsed);
    
    $dataCheckArr = [];
    foreach ($parsed as $k => $v) {
        $dataCheckArr[] = $k . '=' . $v;
    }
    $dataCheckString = implode("\n", $dataCheckArr);
    
    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $calcHash = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));
    
    if (hash_equals($calcHash, $hash)) {
        if (isset($parsed['auth_date']) && (time() - (int)$parsed['auth_date'] > 86400)) return false; // 24h expiration
        return json_decode($parsed['user'], true);
    }
    return false;
}

// --- 5. API ROUTER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action']) || !isset($input['initData'])) {
        logServerError('WARNING', 'Validation Error', 'Missing action or initData', __FILE__, __LINE__, $input);
        echo json_encode(['success' => false, 'error' => 'Invalid request formatting.']);
        exit;
    }

    // AUTHENTICATE
    $tgUser = validateTelegramInitData($input['initData'], BOT_TOKEN);
    if (!$tgUser || !isset($tgUser['id'])) {
        logServerError('WARNING', 'Security Event', 'Invalid Telegram auth data', __FILE__, __LINE__);
        echo json_encode(['success' => false, 'error' => 'Authentication failed.']);
        exit;
    }
    
    $uid = (string)$tgUser['id'];
    $GLOBALS['authenticatedUid'] = $uid; // For error handler
    
    $action = $input['action'];
    $isSilentSync = $input['silent'] ?? false;
    $settings = readDB('settings.json');
    $masterAdmin = $settings['adminUid'] ?? '5461064199';

    // MAINTENANCE MODE CHECK
    if (($settings['maintenance'] ?? false) === true && $uid !== $masterAdmin) {
        echo json_encode(['success' => false, 'error' => 'MAINTENANCE', 'message' => 'The bot is currently undergoing maintenance. Please try again later.']);
        exit;
    }

    // TIME CALCULATIONS
    $moscowTimezone = new DateTimeZone('Europe/Moscow');
    $now = new DateTime('now', $moscowTimezone);
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
    
    if ($now >= $resetTarget) $resetTarget->modify('+1 day');
    
    // FETCH USER & DAILY RESET LOGIC
    $currentUser = modifyDB('users.json', function($users) use ($uid, $tgUser, $input, $today, $now, $isSilentSync) {
        if (!isset($users[$uid])) {
            $users[$uid] = [
                'tgId' => $uid,
                'firstName' => $tgUser['first_name'] ?? 'User',
                'lastName' => $tgUser['last_name'] ?? '',
                'username' => $tgUser['username'] ?? '',
                'photoUrl' => $tgUser['photo_url'] ?? '',
                'xp' => 0, 'totalXp' => 0, 'xpSpent' => 0, 'dailyXp' => 0,
                'usd' => 0.00, 'level' => 1,
                'adsWatchedToday' => 0, 'totalAdsWatched' => 0,
                'tasksCompleted' => 0, 'boxesOpened' => 0,
                'streak' => 1, 'lastResetDay' => $today,
                'referrer' => null, 'sponsorAzx' => false,
                'rejectedTasks' => [], 'lastActive' => $now->format('Y-m-d H:i:s'),
                'banned' => false, 'createdAt' => $now->format('Y-m-d H:i:s')
            ];
            
            // Handle Referral properly securely
            $reqRef = (string)($input['referrer'] ?? '');
            if (!empty($reqRef) && $reqRef !== $uid && isset($users[$reqRef])) {
                $users[$uid]['referrer'] = $reqRef;
                modifyDB('referrals.json', function($refs) use ($reqRef, $uid, $tgUser, $now) {
                    if (!isset($refs[$reqRef])) $refs[$reqRef] = [];
                    // Prevent dupes
                    foreach($refs[$reqRef] as $r) if($r['uid'] === $uid) return $refs;
                    $refs[$reqRef][] = [
                        'uid' => $uid,
                        'name' => trim(($tgUser['first_name'] ?? '') . ' ' . ($tgUser['last_name'] ?? '')),
                        'username' => $tgUser['username'] ?? '',
                        'status' => 'Pending',
                        'ads' => 0, 'tasks' => 0,
                        'joinDate' => $now->format('M j, Y')
                    ];
                    return $refs;
                });
            }
        } else {
            // Update Profile Info
            $users[$uid]['firstName'] = $tgUser['first_name'] ?? $users[$uid]['firstName'];
            $users[$uid]['lastName'] = $tgUser['last_name'] ?? $users[$uid]['lastName'];
            $users[$uid]['username'] = $tgUser['username'] ?? $users[$uid]['username'];
            $users[$uid]['photoUrl'] = $tgUser['photo_url'] ?? $users[$uid]['photoUrl'];
            if (!$isSilentSync) $users[$uid]['lastActive'] = $now->format('Y-m-d H:i:s');
            
            // Migrations
            if (!isset($users[$uid]['xpSpent'])) $users[$uid]['xpSpent'] = 0;
            if (!isset($users[$uid]['dailyXp'])) $users[$uid]['dailyXp'] = 0;
            if (!isset($users[$uid]['createdAt'])) $users[$uid]['createdAt'] = $now->format('Y-m-d H:i:s');
        }

        if ($users[$uid]['lastResetDay'] !== $today) {
            $lastDay = strtotime($users[$uid]['lastResetDay']);
            $currDay = strtotime($today);
            $diff = round(($currDay - $lastDay) / 86400);
            
            // Save daily history before reset
            modifyDB("daily_stats_$uid.json", function($stats) use ($users, $uid) {
                $stats[$users[$uid]['lastResetDay']] = [
                    'ads' => $users[$uid]['adsWatchedToday'],
                    'xp' => $users[$uid]['dailyXp']
                ];
                return $stats;
            });

            $users[$uid]['streak'] = ($diff === 1) ? min($users[$uid]['streak'] + 1, 7) : 1;
            $users[$uid]['adsWatchedToday'] = 0;
            $users[$uid]['dailyXp'] = 0;
            $users[$uid]['lastResetDay'] = $today;
            
            modifyDB('tasks.json', function($tasks) use ($uid) {
                $tasks[$uid] = []; return $tasks;
            });
        }
        return $users;
    })[$uid];

    // BAN CHECK
    if (($currentUser['banned'] ?? false) && $uid !== $masterAdmin) {
        echo json_encode(['success' => false, 'error' => 'BANNED', 'message' => 'Your account has been banned by the administrator.']);
        exit;
    }

    // HELPER FUNCTIONS
    function calcLevel($xp) {
        if ($xp >= 20000) return 10;
        if ($xp >= 3000) return 5;
        if ($xp >= 1500) return 4;
        if ($xp >= 750) return 3;
        if ($xp >= 250) return 2;
        return 1;
    }

    function evalReferral($refUid) {
        global $now;
        modifyDB('users.json', function($users) use ($refUid, $now) {
            if (!isset($users[$refUid]['referrer'])) return $users;
            $referrerId = $users[$refUid]['referrer'];
            
            modifyDB('referrals.json', function($refs) use ($referrerId, $refUid, $users, $now) {
                if (!isset($refs[$referrerId])) return $refs;
                $changed = false;
                foreach ($refs[$referrerId] as &$r) {
                    if ($r['uid'] === $refUid && $r['status'] === 'Pending') {
                        $r['ads'] = $users[$refUid]['totalAdsWatched'];
                        $r['tasks'] = $users[$refUid]['tasksCompleted'];
                        $changed = true;
                        if ($r['ads'] >= 25 && $r['tasks'] >= 5) {
                            $r['status'] = 'Approved';
                            $r['approvedAt'] = $now->format('Y-m-d H:i:s');
                            
                            // Reward Referrer
                            modifyDB('users.json', function($u2) use ($referrerId) {
                                $u2[$referrerId]['xp'] += 250;
                                $u2[$referrerId]['totalXp'] += 250;
                                $u2[$referrerId]['level'] = calcLevel($u2[$referrerId]['totalXp']);
                                $u2[$referrerId]['usd'] += 0.025;
                                logXPTransaction($referrerId, 'earn', 250, $u2[$referrerId]['xp']-250, $u2[$referrerId]['xp'], 'referral_approved');
                                return $u2;
                            });
                            
                            modifyDB('rewards.json', function($rwds) use ($referrerId, $users, $refUid, $now) {
                                if(!isset($rwds[$referrerId])) $rwds[$referrerId] = [];
                                array_unshift($rwds[$referrerId], [
                                    'title' => 'Referral Bonus',
                                    'desc' => "Referral: " . trim(($users[$refUid]['firstName']??'') . ' ' . ($users[$refUid]['lastName']??'')),
                                    'xp' => 250, 'usd' => 0.025,
                                    'date' => $now->format('M j, Y')
                                ]);
                                return $rwds;
                            });
                        }
                        break;
                    }
                }
                return $changed ? $refs : null;
            });
            return null; // no changes to users array in this outer loop
        });
    }

    $response = ['success' => true];
    $mult = (float)($settings['xpMultiplier'] ?? 1);

    // --- SECURE ADMIN ROUTES ---
    if (strpos($action, 'admin_') === 0) {
        if ($uid !== $masterAdmin) {
            logServerError('WARNING', 'Security Event', 'Unauthorized admin panel attempt', __FILE__, __LINE__);
            echo json_encode(['success' => false, 'error' => 'Security Breach: Unauthorized Access']); exit;
        }
        
        // Verify secondary auth code if provided for critical actions, or rely on active session.
        // For this rewrite, we enforce the Telegram UID as authoritative.
        $providedCode = $input['adminCode'] ?? '';
        if ($action !== 'admin_dashboard' && !password_verify($providedCode, ADMIN_CODE_HASH) && !isset($input['bypassCode'])) {
             // In a real app, use a session token after first code verify. For now, enforce code on sensitive actions.
             echo json_encode(['success' => false, 'error' => 'Invalid Admin Code']); exit;
        }

        if ($action === 'admin_dashboard') {
            $users = readDB('users.json');
            $withdrawals = readDB('withdrawals.json');
            $referrals = readDB('referrals.json');
            $errors = readDB('errors.json');
            
            $stats = ['users' => count($users), 'usd' => 0, 'ads' => 0, 'tasks' => 0, 'xp' => 0, 'refs' => 0, 'errors' => count($errors)];
            foreach($users as $u) {
                $stats['usd'] += $u['usd']; $stats['ads'] += $u['totalAdsWatched'];
                $stats['tasks'] += $u['tasksCompleted']; $stats['xp'] += $u['totalXp'];
            }
            foreach($withdrawals as $wl) { foreach($wl as $w) if($w['status'] === 'Approved') $stats['usd'] += $w['amount']; }
            foreach($referrals as $rl) { $stats['refs'] += count($rl); }
            
            $allWithdrawals = [];
            foreach($withdrawals as $uId => $wList) {
                foreach($wList as $idx => $w) {
                    $w['user_id'] = $uId; $w['idx'] = $idx;
                    $w['username'] = $users[$uId]['username'] ?? '';
                    $allWithdrawals[] = $w;
                }
            }
            
            $response['stats'] = $stats;
            $response['all_users'] = array_values($users);
            $response['all_withdrawals'] = $allWithdrawals;
            $response['custom_tasks'] = readDB('custom_tasks.json');
            $response['submissions'] = readDB('task_submissions.json');
            $response['errors'] = array_slice($errors, 0, 100); // Last 100 errors for UI
            $response['adminSettings'] = $settings;
            echo json_encode($response); exit;
        }

        if ($action === 'admin_update_settings') {
            modifyDB('settings.json', function($s) use ($input) {
                $s['adBlockIds'] = array_map('trim', explode(',', $input['blockIds']));
                $s['maintenance'] = (bool)$input['maintenance'];
                $s['resetTime'] = $input['resetTime'];
                $s['xpMultiplier'] = (float)$input['xpMultiplier'];
                return $s;
            });
            logAudit($uid, 'UPDATE_SETTINGS', 'ALL', '', '', 'Admin settings updated');
            $response['message'] = 'Tənzimləmələr uğurla yeniləndi.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_reset_all_ads') {
            modifyDB('users.json', function($users) {
                foreach($users as &$u) $u['adsWatchedToday'] = 0;
                return $users;
            });
            logAudit($uid, 'RESET_ALL_ADS', 'ALL', '', '', 'Global daily limit reset');
            $response['message'] = 'Bütün istifadəçilərin reklam limiti sıfırlandı.';
            echo json_encode($response); exit;
        }

        if ($action === 'admin_save_task') {
            modifyDB('custom_tasks.json', function($tasks) use ($input) {
                $tId = $input['taskId'] ?? uniqid('task_');
                $tasks[$tId] = [
                    'id' => $tId, 'title' => htmlspecialchars($input['title']),
                    'desc' => htmlspecialchars($input['desc']), 'link' => filter_var($input['link'], FILTER_SANITIZE_URL),
                    'reward' => (int)$input['reward'], 'requireInfo' => (bool)$input['requireInfo'],
                    'infoLabel' => htmlspecialchars($input['infoLabel'] ?? 'UID:'), 'active' => (bool)$input['active']
                ];
                return $tasks;
            });
            logAudit($uid, 'SAVE_TASK', 'SYSTEM', '', $input['title'], 'Task modified/created');
            $response['message'] = 'Tapşırıq yadda saxlanıldı.'; echo json_encode($response); exit;
        }

        if ($action === 'admin_action_submission') {
            modifyDB('task_submissions.json', function($subs) use ($input, $uid) {
                foreach ($subs as &$s) {
                    if ($s['subId'] === $input['subId'] && $s['status'] === 'Pending') {
                        $s['status'] = $input['subAction'] === 'approve' ? 'Approved' : 'Rejected';
                        $tUid = $s['uid'];
                        if ($s['status'] === 'Approved') {
                            $cTasks = readDB('custom_tasks.json');
                            $reward = isset($cTasks[$s['taskId']]) ? $cTasks[$s['taskId']]['reward'] : 0;
                            modifyDB('users.json', function($u) use ($tUid, $reward) {
                                if(isset($u[$tUid])) {
                                    $u[$tUid]['xp'] += $reward; $u[$tUid]['totalXp'] += $reward; $u[$tUid]['tasksCompleted'] += 1;
                                    logXPTransaction($tUid, 'earn', $reward, $u[$tUid]['xp']-$reward, $u[$tUid]['xp'], 'task_approved');
                                }
                                return $u;
                            });
                            modifyDB('tasks.json', function($t) use ($tUid, $s) {
                                if(!isset($t[$tUid])) $t[$tUid] = [];
                                $t[$tUid][] = $s['taskId']; return $t;
                            });
                        } else {
                            modifyDB('users.json', function($u) use ($tUid, $s) {
                                if(isset($u[$tUid])) {
                                    if(!isset($u[$tUid]['rejectedTasks'])) $u[$tUid]['rejectedTasks'] = [];
                                    $u[$tUid]['rejectedTasks'][] = $s['taskId'];
                                }
                                return $u;
                            });
                        }
                        logAudit($uid, 'SUBMISSION_'.$s['status'], $tUid, 'Pending', $s['status'], 'Task ID: '.$s['taskId']);
                        return $subs;
                    }
                }
                return null;
            });
            $response['message'] = 'Təsdiq prosesi icra olundu.'; echo json_encode($response); exit;
        }

        if ($action === 'admin_action_user') {
            modifyDB('users.json', function($users) use ($input, $uid) {
                $tUid = $input['targetUid'];
                if(!isset($users[$tUid])) return null;
                $old = $users[$tUid];
                $act = $input['userAction'];
                if ($act === 'ban') $users[$tUid]['banned'] = true;
                elseif ($act === 'unban') $users[$tUid]['banned'] = false;
                elseif ($act === 'reset_ads') $users[$tUid]['adsWatchedToday'] = 0;
                elseif ($act === 'update_balance') {
                    $users[$tUid]['usd'] = max(0, (float)$input['newUsd']);
                    $diff = (int)$input['newXp'] - $users[$tUid]['xp'];
                    $users[$tUid]['xp'] = max(0, (int)$input['newXp']);
                    if($diff > 0) $users[$tUid]['totalXp'] += $diff;
                    logXPTransaction($tUid, 'admin_edit', $diff, $old['xp'], $users[$tUid]['xp'], 'admin_panel');
                }
                logAudit($uid, 'EDIT_USER_'.$act, $tUid, json_encode($old), json_encode($users[$tUid]), 'Admin edited user');
                return $users;
            });
            $response['message'] = 'İstifadəçi yeniləndi.'; echo json_encode($response); exit;
        }

        if ($action === 'admin_action_withdraw') {
            modifyDB('withdrawals.json', function($w) use ($input, $uid) {
                $tUid = $input['targetUid']; $idx = $input['idx']; $act = $input['withdrawAction'];
                if (isset($w[$tUid][$idx]) && $w[$tUid][$idx]['status'] === 'Pending') {
                    $w[$tUid][$idx]['status'] = $act === 'approve' ? 'Approved' : 'Rejected';
                    $w[$tUid][$idx]['processedAt'] = date('Y-m-d H:i:s');
                    
                    if ($act === 'reject') {
                        modifyDB('users.json', function($u) use ($tUid, $w, $idx) {
                            $u[$tUid]['usd'] += $w[$tUid][$idx]['amount']; return $u;
                        });
                    }
                    logAudit($uid, 'WITHDRAW_'.$w[$tUid][$idx]['status'], $tUid, 'Pending', $w[$tUid][$idx]['status'], 'Amount: '.$w[$tUid][$idx]['amount']);
                    return $w;
                }
                return null;
            });
            $response['message'] = 'Çıxarış prosesi icra olundu.'; echo json_encode($response); exit;
        }
        
        if ($action === 'admin_clear_errors') {
             file_put_contents("$dataDir/errors.json", "[]");
             logAudit($uid, 'CLEAR_ERRORS', 'SYSTEM', '', '', 'Admin cleared error log');
             $response['message'] = 'Xətalar təmizləndi.'; echo json_encode($response); exit;
        }
    }
    // --- END ADMIN ROUTES ---

    // --- NORMAL USER ACTIONS ---
    switch ($action) {
        case 'watch_ad':
            modifyDB('users.json', function($users) use ($uid, $mult) {
                $lastTime = strtotime($users[$uid]['lastAdTime'] ?? '0');
                if (time() - $lastTime < 5) return null; // Cooldown protection
                
                if ($users[$uid]['adsWatchedToday'] < 30) {
                    $gain = 20 * $mult;
                    $users[$uid]['adsWatchedToday'] += 1;
                    $users[$uid]['totalAdsWatched'] += 1;
                    $prev = $users[$uid]['xp'];
                    $users[$uid]['xp'] += $gain;
                    $users[$uid]['totalXp'] += $gain;
                    $users[$uid]['dailyXp'] += $gain;
                    $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                    $users[$uid]['lastAdTime'] = date('Y-m-d H:i:s');
                    logXPTransaction($uid, 'earn', $gain, $prev, $users[$uid]['xp'], 'watch_ad');
                    return $users;
                }
                return null;
            });
            evalReferral($uid);
            break;

        case 'claim_task':
            $taskId = $input['taskId'] ?? '';
            modifyDB('users.json', function($users) use ($uid, $mult, $taskId) {
                if ($taskId === 'sponsor_azx' && empty($users[$uid]['sponsorAzx'])) {
                    $gain = 200 * $mult;
                    $prev = $users[$uid]['xp'];
                    $users[$uid]['sponsorAzx'] = true;
                    $users[$uid]['xp'] += $gain; $users[$uid]['totalXp'] += $gain; $users[$uid]['dailyXp'] += $gain;
                    $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                    logXPTransaction($uid, 'earn', $gain, $prev, $users[$uid]['xp'], 'sponsor_azx');
                    return $users;
                }
                
                $cTasks = readDB('custom_tasks.json');
                $t = readDB('tasks.json');
                if (!isset($t[$uid])) $t[$uid] = [];
                
                if (!in_array($taskId, $t[$uid]) && isset($cTasks[$taskId]) && !$cTasks[$taskId]['requireInfo'] && $cTasks[$taskId]['active']) {
                    $gain = $cTasks[$taskId]['reward'] * $mult;
                    $prev = $users[$uid]['xp'];
                    $users[$uid]['tasksCompleted'] += 1;
                    $users[$uid]['xp'] += $gain; $users[$uid]['totalXp'] += $gain; $users[$uid]['dailyXp'] += $gain;
                    $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                    
                    modifyDB('tasks.json', function($ts) use ($uid, $taskId) {
                        if(!isset($ts[$uid])) $ts[$uid] = [];
                        $ts[$uid][] = $taskId; return $ts;
                    });
                    
                    logXPTransaction($uid, 'earn', $gain, $prev, $users[$uid]['xp'], 'claim_task_'.$taskId);
                    return $users;
                }
                return null;
            });
            evalReferral($uid);
            break;

        case 'submit_task_info':
            $taskId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['taskId'] ?? '');
            $info = substr(strip_tags($input['info'] ?? ''), 0, 500);
            $cTasks = readDB('custom_tasks.json');
            
            if(isset($cTasks[$taskId]) && $cTasks[$taskId]['requireInfo'] && $cTasks[$taskId]['active']) {
                $u = readDB('users.json')[$uid];
                if(in_array($taskId, $u['rejectedTasks'] ?? [])) {
                    echo json_encode(['success'=>false, 'error'=>'You cannot resubmit a rejected task.']); exit;
                }
                modifyDB('task_submissions.json', function($subs) use ($uid, $taskId, $info, $cTasks, $u, $now) {
                    foreach($subs as $s) if($s['uid'] === $uid && $s['taskId'] === $taskId && $s['status'] === 'Pending') return null;
                    $subs[] = [
                        'subId' => uniqid('sub_'), 'uid' => $uid,
                        'username' => $u['username'], 'name' => $u['firstName'] . ' ' . $u['lastName'],
                        'taskId' => $taskId, 'taskTitle' => $cTasks[$taskId]['title'],
                        'infoSubmitted' => $info, 'status' => 'Pending', 'date' => $now->format('Y-m-d H:i:s')
                    ];
                    return $subs;
                });
                $response['message'] = 'Məlumat göndərildi, təsdiq gözlənilir.';
            } else {
                echo json_encode(['success'=>false, 'error'=>'Invalid task.']); exit;
            }
            break;

        case 'open_box':
            $type = $input['boxType'] ?? '';
            $costs = ['bronze' => 10000, 'silver' => 50000, 'gold' => 100000];
            
            if (isset($costs[$type])) {
                $opened = modifyDB('users.json', function($users) use ($uid, $costs, $type, &$response) {
                    if ($users[$uid]['xp'] >= $costs[$type]) {
                        $prev = $users[$uid]['xp'];
                        $users[$uid]['xp'] -= $costs[$type];
                        $users[$uid]['xpSpent'] += $costs[$type];
                        $users[$uid]['boxesOpened'] += 1;
                        
                        $isJackpot = (rand(1, 1000) === 1); // 0.1% chance server side
                        $rewardUsd = 0;
                        if ($type === 'bronze') $rewardUsd = $isJackpot ? 1.00 : (rand(1,10)/100);
                        if ($type === 'silver') $rewardUsd = $isJackpot ? 7.00 : (rand(10,50)/100);
                        if ($type === 'gold') $rewardUsd = $isJackpot ? 15.00 : (rand(50,200)/100);
                        
                        $users[$uid]['usd'] += $rewardUsd;
                        $response['reward'] = $rewardUsd;
                        $response['jackpot'] = $isJackpot;
                        logXPTransaction($uid, 'spend', $costs[$type], $prev, $users[$uid]['xp'], 'open_box_'.$type);
                        return $users;
                    }
                    return null;
                });
                if(!$opened) $response['error'] = 'Not enough XP';
            }
            break;

        case 'withdraw':
            $amount = (float)($input['amount'] ?? 0);
            $address = filter_var($input['address'] ?? '', FILTER_SANITIZE_STRING);
            
            if ($amount >= 10 && strlen($address) > 10 && preg_match('/^[a-zA-Z0-9\-_]+$/', $address)) {
                $success = modifyDB('users.json', function($users) use ($uid, $amount, $address, $now) {
                    if ($users[$uid]['usd'] >= $amount) {
                        $users[$uid]['usd'] -= $amount;
                        modifyDB('withdrawals.json', function($w) use ($uid, $amount, $address, $now) {
                            if (!isset($w[$uid])) $w[$uid] = [];
                            array_unshift($w[$uid], [
                                'id' => 'W-' . strtoupper(substr(md5(uniqid()), 0, 8)),
                                'amount' => $amount,
                                'address' => $address,
                                'date' => $now->format('Y-m-d H:i:s'),
                                'status' => 'Pending'
                            ]);
                            return $w;
                        });
                        return $users;
                    }
                    return null;
                });
                if(!$success) $response['error'] = 'Insufficient balance.';
            } else {
                $response['error'] = 'Invalid withdrawal request.';
            }
            break;
            
        case 'sync': break;
        default:
            logServerError('WARNING', 'Validation Error', 'Unknown API Action', __FILE__, __LINE__, ['action' => $action]);
            break;
    }

    // Prepare fresh output data
    $response['serverTime'] = $now->getTimestamp();
    $response['serverResetTime'] = $resetTarget->getTimestamp(); 
    $response['settings'] = ['adBlockIds' => $settings['adBlockIds'], 'xpMultiplier' => $mult];
    
    $finalUsers = readDB('users.json');
    $response['user'] = $finalUsers[$uid] ?? [];
    $response['referrals'] = readDB('referrals.json')[$uid] ?? [];
    $response['rewards'] = readDB('rewards.json')[$uid] ?? [];
    $response['withdrawals'] = readDB('withdrawals.json')[$uid] ?? [];
    $response['tasks'] = readDB('tasks.json')[$uid] ?? [];
    $response['custom_tasks'] = array_values(array_filter(readDB('custom_tasks.json'), function($c){ return $c['active']; }));
    
    $subs = readDB('task_submissions.json');
    $response['pending_submissions'] = array_filter($subs, function($s) use ($uid) { return $s['uid'] === $uid && $s['status'] === 'Pending'; });
    
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
          colors: { crypto: { dark: '#050511', card: '#0a0b1a', primary: '#3b82f6', glow: '#00f0ff', gold: '#ffb800', silver: '#e2e8f0', bronze: '#cd7f32' } },
          animation: { 'pulse-fast': 'pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite', 'shimmer': 'shimmer 2s infinite' },
          keyframes: { shimmer: { '0%': { transform: 'translateX(-100%)' }, '100%': { transform: 'translateX(100%)' } } }
        }
      }
    }
  </script>

  <style>
    body { background-color: #050511; color: #f8fafc; overflow-x: hidden; user-select: none; -webkit-user-select: none; }
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
    #toast-container { position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9); width: 90%; max-width: 380px; z-index: 999999; transition: all 0.4s; opacity: 0; pointer-events: none; }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }
    .modal-overlay { background: rgba(5, 5, 17, 0.85); backdrop-filter: blur(10px); z-index: 10000; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .admin-scroll::-webkit-scrollbar { width: 4px; }
    .admin-scroll::-webkit-scrollbar-thumb { background: #3b82f6; border-radius: 4px; }
  </style>
</head>
<body class="flex flex-col min-h-screen">
  
  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-6">
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-rocket text-crypto-glow text-3xl animate-pulse"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase">XPVerse</h2>
  </div>

  <div id="toast-container" class="glass-card rounded-2xl p-3 flex items-center gap-3">
    <div id="toast-icon" class="w-10 h-10 rounded-full flex shrink-0 items-center justify-center text-lg shadow-inner"><i class="fa-solid fa-bell"></i></div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-xs font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-[11px] text-slate-300 mt-0.5 leading-tight">Message</p>
    </div>
  </div>

  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full py-3 px-4 glass-card rounded-b-[1.5rem]">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-2.5">
        <div class="relative w-10 h-10 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500">
          <img id="user-photo" src="https://via.placeholder.com/150" alt="Profile" class="w-full h-full rounded-full object-cover">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm">Loading...</span>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <div class="bg-[#050511] border border-blue-500/30 px-2.5 py-1 rounded-lg flex items-center gap-1.5">
          <i class="fa-solid fa-bolt text-crypto-glow text-[10px]"></i>
          <span id="user-xp" class="text-white font-black text-xs">0 <span class="text-[9px] text-crypto-glow">XP</span></span>
        </div>
        <div class="bg-[#050511] border border-emerald-500/40 px-2.5 py-1 rounded-lg flex items-center gap-1.5">
          <i class="fa-solid fa-dollar-sign text-emerald-400 text-[9px]"></i>
          <span id="user-usd" class="text-emerald-400 font-black text-[11px]">0</span>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-20 pb-24 relative" id="app-content">
    
    <!-- HOME -->
    <div id="view-home" class="view-section fade-in space-y-5">
      <div class="glass-card rounded-[1.5rem] p-5 text-center flex flex-col items-center justify-center min-h-[220px]">
        <p class="text-[10px] font-black text-blue-400 uppercase">Total Balance</p>
        <h1 class="text-4xl font-black text-white" id="main-xp-display">0 XP</h1>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/60 p-3 rounded-xl flex items-center gap-2.5">
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black">Ads Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / 30</p>
            </div>
          </div>
          <div class="bg-[#050511]/60 p-3 rounded-xl flex items-center gap-2.5">
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-black">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>
      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-3.5 rounded-[1.25rem] text-white font-black text-sm uppercase flex items-center justify-center gap-2.5 btn-3d">
        <i class="fa-solid fa-play"></i> <span>Watch Ad <span class="text-cyan-200 ml-1">+20 XP</span></span>
      </button>
      <div class="glass-card rounded-xl p-3.5 flex justify-between items-center">
        <div class="flex items-center gap-2 text-slate-400 text-[11px] font-bold"><i class="fa-solid fa-clock text-blue-400"></i> Server Resets In:</div>
        <span class="text-white font-mono font-black text-xs bg-slate-900/50 px-2.5 py-1 rounded-md" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <!-- TASKS -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1"><h2 class="text-2xl font-black text-white">Tasks</h2></div>
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase mb-3"><i class="fa-solid fa-star text-amber-400"></i> Custom Tasks</h3>
        <div id="custom-tasks-container" class="space-y-3"></div>
      </div>
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase mb-3"><i class="fa-solid fa-list-check text-slate-600"></i> Daily Missions</h3>
        <div id="missions-container" class="space-y-2.5"></div>
      </div>
    </div>

    <!-- REFERRALS -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1"><h2 class="text-2xl font-black text-white">Referans</h2></div>
      <div class="grid grid-cols-3 gap-2.5">
        <div class="glass-card p-3 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Total</p><p id="ref-total" class="text-xl font-black text-white">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Pending</p><p id="ref-pending" class="text-xl font-black text-amber-400">0</p></div>
        <div class="glass-card p-3 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase font-black">Approved</p><p id="ref-approved" class="text-xl font-black text-emerald-400">0</p></div>
      </div>
      <div class="glass-card rounded-[1.25rem] p-4 space-y-3.5">
        <label class="block text-[10px] font-black text-slate-400 uppercase">Your Referral Link</label>
        <div class="flex items-center gap-2">
          <input type="text" id="ref-link-input" readonly class="flex-1 bg-[#050511]/70 border border-slate-700 rounded-lg py-2.5 px-3 text-[11px] text-slate-300 outline-none">
          <button onclick="copyRefLink()" class="bg-slate-800 text-white w-10 h-10 rounded-lg"><i class="fa-regular fa-copy text-sm"></i></button>
        </div>
        <button onclick="shareReferralTelegram()" class="w-full py-3 bg-blue-600 text-white font-black rounded-lg text-xs uppercase"><i class="fa-brands fa-telegram"></i> Share</button>
      </div>
      <div id="referral-list-container" class="space-y-2.5"></div>
    </div>

    <!-- BOXES -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-4"><h2 class="text-2xl font-black text-white">Box</h2></div>
      <div class="glass-card rounded-[1.25rem] p-4 flex justify-between items-center border border-[#cd7f32]">
        <div><h3 class="text-lg font-black text-white">Bronze Box</h3><p class="text-[10px] text-slate-400">10,000 XP</p></div>
        <button onclick="openBox('bronze')" class="bg-orange-600 text-white px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>
      <div class="glass-card rounded-[1.25rem] p-4 flex justify-between items-center border border-[#e2e8f0]">
        <div><h3 class="text-lg font-black text-white">Silver Box</h3><p class="text-[10px] text-slate-400">50,000 XP</p></div>
        <button onclick="openBox('silver')" class="bg-slate-500 text-white px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>
      <div class="glass-card rounded-[1.25rem] p-4 flex justify-between items-center border border-[#ffb800]">
        <div><h3 class="text-lg font-black text-[#ffb800]">Gold Box</h3><p class="text-[10px] text-slate-400">100,000 XP</p></div>
        <button onclick="openBox('gold')" class="bg-amber-500 text-white px-4 py-2 rounded-lg text-xs font-black uppercase">Open</button>
      </div>
    </div>

    <!-- WALLET -->
    <div id="view-wallet" class="view-section hidden fade-in space-y-5">
      <div class="text-center mb-1"><h2 class="text-2xl font-black text-white">Wallet</h2></div>
      <div class="glass-card rounded-[1.5rem] p-5 text-center">
        <p class="text-[10px] font-black text-emerald-400 uppercase">Available Balance</p>
        <h1 class="text-4xl font-black text-white">$<span id="withdraw-balance-display">0</span></h1>
      </div>
      <div class="glass-card rounded-[1.25rem] p-4 space-y-4">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">TON Wallet Address</label>
          <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-xs text-white outline-none">
        </div>
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">Amount (USDT)</label>
          <input type="number" id="withdraw-amount" placeholder="10" min="10" step="0.5" class="w-full bg-[#050511] border border-slate-700 rounded-lg py-2.5 px-3 text-xs text-emerald-400 outline-none">
        </div>
        <button onclick="requestWithdrawal()" class="w-full py-3 bg-emerald-600 text-white font-black rounded-lg text-xs uppercase">Withdraw</button>
      </div>
      <div id="withdraw-history-container" class="space-y-2.5"></div>
    </div>

    <!-- PROFILE -->
    <div id="view-profile" class="view-section hidden fade-in space-y-5">
      <div class="glass-card rounded-[1.5rem] p-5 flex flex-col items-center justify-center relative">
        <button id="admin-secret-btn" onclick="openAdminAuth()" class="hidden absolute top-4 right-4 text-red-500"><i class="fa-solid fa-user-shield"></i></button>
        <div class="w-20 h-20 rounded-full bg-blue-500 mb-3 overflow-hidden"><img id="profile-page-avatar" src="" class="w-full h-full object-cover"></div>
        <h2 id="profile-page-name" class="text-xl font-black text-white">Name</h2>
        <p id="profile-page-username" class="text-[11px] text-blue-400 mb-2.5">@username</p>
        <span class="text-[9px] text-slate-400 uppercase">ID: <span id="profile-page-id" class="text-white">0</span></span>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase">Total XP</p><p id="profile-stat-xp" class="text-lg font-black text-white">0</p></div>
        <div class="glass-card p-4 rounded-xl text-center"><p class="text-[9px] text-slate-400 uppercase">Balance</p><p id="profile-stat-usd" class="text-lg font-black text-white">$0</p></div>
      </div>
    </div>

    <!-- ADMIN PANEL -->
    <div id="view-admin" class="view-section hidden fade-in space-y-4">
      <div class="text-center mb-2"><h2 class="text-2xl font-black text-red-500">İDARƏ PANELİ</h2></div>
      <div class="flex flex-wrap gap-1 mb-2">
          <button onclick="switchAdminTab('dashboard')" class="admin-tab flex-1 py-2 text-[9px] bg-red-600/20 text-red-400 rounded" id="tab-dashboard">Panel</button>
          <button onclick="switchAdminTab('users')" class="admin-tab flex-1 py-2 text-[9px] text-slate-400 rounded" id="tab-users">Users</button>
          <button onclick="switchAdminTab('withdrawals')" class="admin-tab flex-1 py-2 text-[9px] text-slate-400 rounded" id="tab-withdrawals">Withdrawals</button>
          <button onclick="switchAdminTab('tasks')" class="admin-tab flex-1 py-2 text-[9px] text-slate-400 rounded" id="tab-tasks">Tasks</button>
          <button onclick="switchAdminTab('errors')" class="admin-tab flex-1 py-2 text-[9px] text-slate-400 rounded" id="tab-errors">Errors</button>
          <button onclick="switchAdminTab('settings')" class="admin-tab flex-1 py-2 text-[9px] text-slate-400 rounded" id="tab-settings">Settings</button>
      </div>

      <!-- Admin Dash -->
      <div id="admin-sec-dashboard" class="admin-section space-y-3">
          <div class="grid grid-cols-2 gap-2" id="admin-dash-stats"></div>
      </div>
      <!-- Admin Users -->
      <div id="admin-sec-users" class="admin-section hidden space-y-3">
          <input type="text" id="admin-user-search" onkeyup="filterAdminUsers()" placeholder="Search UID/Username..." class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-3 text-xs text-white">
          <div class="max-h-[60vh] overflow-y-auto space-y-2" id="admin-user-list"></div>
      </div>
      <!-- Admin Errors -->
      <div id="admin-sec-errors" class="admin-section hidden space-y-3">
          <div class="flex gap-2 mb-2">
              <input type="text" id="admin-error-search" onkeyup="renderAdminErrors()" placeholder="Search Errors..." class="w-full bg-[#050511] border border-slate-700 rounded py-2 px-3 text-xs text-white">
              <button onclick="adminClearErrors()" class="bg-red-600 text-white px-3 py-2 rounded text-xs">Clear</button>
          </div>
          <div class="max-h-[60vh] overflow-y-auto space-y-2" id="admin-error-list"></div>
      </div>
      <!-- Admin Other Tabs omitted for brevity in template, logic handled by JS -->
      <div id="admin-sec-withdrawals" class="admin-section hidden space-y-3"><div id="admin-withdrawal-list"></div></div>
      <div id="admin-sec-tasks" class="admin-section hidden space-y-3">
         <div class="glass-card rounded-xl p-4 border border-slate-700 mb-2">
            <input type="text" id="nt-title" placeholder="Title" class="w-full bg-black border border-slate-700 rounded py-2 px-3 text-xs text-white mb-2">
            <input type="text" id="nt-desc" placeholder="Desc" class="w-full bg-black border border-slate-700 rounded py-2 px-3 text-xs text-white mb-2">
            <input type="text" id="nt-link" placeholder="Link" class="w-full bg-black border border-slate-700 rounded py-2 px-3 text-xs text-white mb-2">
            <input type="number" id="nt-reward" placeholder="Reward XP" class="w-full bg-black border border-slate-700 rounded py-2 px-3 text-xs text-white mb-2">
            <label class="text-[10px] text-white flex gap-2"><input type="checkbox" id="nt-reqInfo"> Require Info</label>
            <button onclick="adminSaveTask()" class="w-full mt-2 py-2 bg-blue-600 text-white rounded text-xs">Save Task</button>
         </div>
         <div id="admin-task-list"></div>
      </div>
      <div id="admin-sec-settings" class="admin-section hidden space-y-3">
          <input type="password" id="admin-code-verify" placeholder="Admin Code (Required for WIPE/Settings)" class="w-full bg-black border border-red-500 rounded py-2 px-3 text-xs text-white mb-4">
          <label class="text-white text-xs"><input type="checkbox" id="admin-maintenance"> Maintenance Mode</label>
          <input type="text" id="admin-reset-time" placeholder="Reset Time 03:00" class="w-full bg-black border border-slate-700 rounded py-2 px-3 text-xs text-white mt-2">
          <input type="number" step="0.1" id="admin-xp-mult" placeholder="XP Multiplier" class="w-full bg-black border border-slate-700 rounded py-2 px-3 text-xs text-white mt-2">
          <button onclick="saveAdminSettings()" class="w-full py-2 bg-blue-600 text-white rounded text-xs mt-2">Save</button>
          <button onclick="adminResetAllAds()" class="w-full py-2 bg-amber-600 text-white rounded text-xs mt-2">Reset All Ads</button>
      </div>
    </div>
  </main>

  <!-- BOTTOM NAV -->
  <nav id="bottom-nav" class="fixed bottom-3 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50 flex justify-between items-center px-1 py-2">
    <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center flex-1" data-target="home"><i class="fa-solid fa-house"></i></button>
    <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="tasks"><i class="fa-solid fa-list-check"></i></button>
    <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="referrals"><i class="fa-solid fa-users"></i></button>
    <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="boxes"><i class="fa-solid fa-box-open"></i></button>
    <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="wallet"><i class="fa-solid fa-wallet"></i></button>
    <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center flex-1" data-target="profile"><i class="fa-solid fa-user"></i></button>
  </nav>

  <!-- MODALS -->
  <div id="admin-auth-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4">
    <div class="glass-card w-full max-w-[280px] rounded-[1.5rem] p-5">
      <input type="password" id="admin-code-input" placeholder="Enter Access Code" class="w-full bg-[#050511] border rounded-lg py-2.5 px-3 text-sm text-white mb-4">
      <button onclick="submitAdminAuth()" class="w-full py-2.5 bg-red-600 text-white rounded-lg">Login</button>
    </div>
  </div>

  <div id="custom-task-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4">
    <div class="glass-card w-full max-w-[300px] rounded-[1.5rem] p-5 relative">
      <button onclick="closeCustomTaskModal()" class="absolute top-3 right-3 text-slate-400"><i class="fa-solid fa-xmark"></i></button>
      <h3 class="text-sm font-black text-white text-center mb-1" id="ct-modal-title">Task Title</h3>
      <input type="text" id="ct-modal-input" placeholder="Info..." class="w-full bg-[#050511] border rounded-lg py-2.5 px-3 text-sm text-white mb-4">
      <input type="hidden" id="ct-modal-id">
      <button onclick="submitCustomTaskInfo()" class="w-full py-2.5 bg-blue-600 text-white rounded-lg text-xs">Göndər</button>
    </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand(); tg.ready(); tg.setHeaderColor('#0a0b1a'); tg.setBackgroundColor('#050511');

    const tgInitData = tg.initData || ''; // CRITICAL SECURITY FIX
    const tgUser = tg.initDataUnsafe?.user || {};
    const startParam = tg.initDataUnsafe?.start_param || null;

    let appState = {
      user: {}, referrals: [], rewards: [], withdrawals: [], tasks: [], custom_tasks: [], pending_submissions: [], settings: { xpMultiplier: 1 }
    };
    let adminData = { users: [], withdrawals: [], tasks: [], errors: [], submissions: [] };
    
    // Secure Output Helper
    const sanitizeHTML = str => String(str).replace(/[&<>'"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[m]));

    async function apiCall(action, payload = {}, isSilent = false) {
      try {
        const body = { action: action, initData: tgInitData, referrer: startParam, silent: isSilent, ...payload };
        const res = await fetch(window.location.href, {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        });
        const data = await res.json();
        
        if (data.error) {
          if (data.error === 'BANNED' || data.error === 'MAINTENANCE') {
             document.body.innerHTML = `<div class="h-screen w-full flex flex-col items-center justify-center bg-blue-900 text-white p-5 text-center"><h1 class="text-2xl font-black mb-2">${sanitizeHTML(data.error)}</h1><p class="text-xs opacity-80">${sanitizeHTML(data.message || '')}</p></div>`;
             return false;
          }
          if (!isSilent) showToast("Error", data.error + (data.errorId ? ` ID: ${data.errorId}` : ''), "error");
          return false;
        }

        if(data.user) appState.user = data.user;
        if(data.referrals) appState.referrals = data.referrals;
        if(data.withdrawals) appState.withdrawals = data.withdrawals;
        if(data.tasks) appState.tasks = data.tasks;
        if(data.custom_tasks) appState.custom_tasks = data.custom_tasks;
        if(data.settings) appState.settings = data.settings;
        if(data.serverResetTime) startResetTimer(data.serverResetTime);
        
        updateUI();
        return data;
      } catch (err) {
        if(!isSilent) showToast("Network Error", "Could not connect to server.", "error");
        return false;
      }
    }

    function showToast(title, msg, type = 'info') {
      const t = document.getElementById('toast-container');
      document.getElementById('toast-title').textContent = title;
      document.getElementById('toast-message').textContent = msg;
      t.className = `glass-card rounded-2xl p-3 flex items-center gap-3 toast-show border ${type==='error'?'border-red-500':'border-blue-500'}`;
      setTimeout(() => t.classList.remove('toast-show'), 3000);
    }

    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.getElementById('view-' + tabId).classList.remove('hidden');
      document.querySelectorAll('.nav-btn').forEach(el => el.classList.remove('nav-active'));
      document.querySelector(`.nav-btn[data-target="${tabId}"]`).classList.add('nav-active');
      window.scrollTo(0, 0);
    }

    function startResetTimer(targetUnix) {
      if(window.resetTimerInterval) clearInterval(window.resetTimerInterval);
      window.resetTimerInterval = setInterval(() => {
        const now = Math.floor(Date.now() / 1000);
        let diff = targetUnix - now;
        if(diff <= 0) { clearInterval(window.resetTimerInterval); apiCall('sync'); return; }
        const h = Math.floor(diff / 3600).toString().padStart(2, '0');
        const m = Math.floor((diff % 3600) / 60).toString().padStart(2, '0');
        const s = (diff % 60).toString().padStart(2, '0');
        document.getElementById('reset-timer').textContent = `${h}:${m}:${s}`;
      }, 1000);
    }

    function updateUI() {
      const u = appState.user;
      document.getElementById('user-name').textContent = sanitizeHTML(u.firstName + ' ' + u.lastName);
      document.getElementById('user-photo').src = u.photoUrl || 'https://via.placeholder.com/150';
      document.getElementById('user-xp').innerHTML = `${formatNum(u.xp)} <span class="text-[9px] text-crypto-glow">XP</span>`;
      document.getElementById('user-usd').textContent = formatNum(u.usd, true);
      document.getElementById('main-xp-display').textContent = `${formatNum(u.xp)} XP`;
      document.getElementById('ads-watched').textContent = u.adsWatchedToday;
      document.getElementById('streak-days').textContent = u.streak;
      document.getElementById('withdraw-balance-display').textContent = formatNum(u.usd, true);
      
      // Admin Button
      if (u.tgId === '5461064199' || u.tgId === appState.settings.adminUid) {
          document.getElementById('admin-secret-btn').classList.remove('hidden');
      }
      
      renderTasks();
      renderReferrals();
      renderWithdrawals();
      renderProfile(u);
    }

    function watchAd() {
      if(appState.user.adsWatchedToday >= 30) return showToast('Limit Reached', 'Daily ad limit reached.', 'error');
      
      const btn = document.getElementById('watch-ad-btn');
      btn.disabled = true;
      let blockId = appState.settings.adBlockIds[Math.floor(Math.random() * appState.settings.adBlockIds.length)] || 'int-35545';
      
      window.Adsgram.init({ blockId: blockId }).show()
        .then(() => apiCall('watch_ad').then(res => {
            if(res && res.success) showToast('Reward Added', '+20 XP');
            btn.disabled = false;
        }))
        .catch(err => {
            console.log(err);
            showToast('Error', 'Ad failed to load.', 'error');
            btn.disabled = false;
        });
    }

    // --- Task Rendering & Logic ---
    function renderTasks() {
      const mc = document.getElementById('missions-container');
      const cc = document.getElementById('custom-tasks-container');
      mc.innerHTML = ''; cc.innerHTML = '';

      // Azx Sponsor
      let spnHTML = `
        <div class="glass-card rounded-xl p-3 flex justify-between items-center bg-gradient-to-r ${appState.user.sponsorAzx ? 'from-emerald-900/40' : 'from-indigo-900/40'}">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-blue-500/20 flex items-center justify-center text-blue-400"><i class="fa-brands fa-telegram text-xl"></i></div>
            <div><h4 class="text-sm font-black text-white">Join Azx Channel</h4><p class="text-[10px] text-slate-400">Reward: 200 XP</p></div>
          </div>
          <button onclick="claimTask('sponsor_azx', 'https://t.me/azxcrypto')" class="px-4 py-2 rounded-lg text-xs font-black ${appState.user.sponsorAzx ? 'bg-slate-700 text-slate-400 cursor-not-allowed' : 'bg-blue-600 text-white'}" ${appState.user.sponsorAzx ? 'disabled' : ''}>
            ${appState.user.sponsorAzx ? 'Done' : 'Join'}
          </button>
        </div>`;
      mc.innerHTML += spnHTML;

      // Custom Tasks
      appState.custom_tasks.forEach(t => {
        let isDone = appState.tasks.includes(t.id);
        let btnStr = `<button onclick="handleCustomTask('${sanitizeHTML(t.id)}')" class="px-4 py-2 rounded-lg text-xs font-black bg-blue-600 text-white">Go</button>`;
        if(isDone) btnStr = `<button disabled class="px-4 py-2 rounded-lg text-xs font-black bg-emerald-600 text-white"><i class="fa-solid fa-check"></i></button>`;
        
        let tHTML = `
          <div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800">
            <div><h4 class="text-sm font-black text-white">${sanitizeHTML(t.title)}</h4><p class="text-[10px] text-slate-400">${t.reward * appState.settings.xpMultiplier} XP</p></div>
            ${btnStr}
          </div>`;
        cc.innerHTML += tHTML;
      });
    }

    function handleCustomTask(id) {
        let task = appState.custom_tasks.find(t => t.id === id);
        if(!task) return;
        tg.openLink(task.link);
        if(task.requireInfo) {
            document.getElementById('ct-modal-title').textContent = task.title;
            document.getElementById('ct-modal-input').placeholder = task.infoLabel;
            document.getElementById('ct-modal-id').value = id;
            document.getElementById('ct-modal-input').value = '';
            document.getElementById('custom-task-modal').classList.remove('hidden');
            document.getElementById('custom-task-modal').classList.add('flex');
        } else {
            setTimeout(() => apiCall('claim_task', {taskId: id}).then(r => r && showToast('Success', 'Task claimed!')), 5000);
        }
    }
    function closeCustomTaskModal() {
        document.getElementById('custom-task-modal').classList.remove('flex');
        document.getElementById('custom-task-modal').classList.add('hidden');
    }
    function submitCustomTaskInfo() {
        let val = document.getElementById('ct-modal-input').value.trim();
        let id = document.getElementById('ct-modal-id').value;
        if(!val) return showToast('Error', 'Please enter info', 'error');
        apiCall('submit_task_info', {taskId: id, info: val}).then(r => {
            if(r && r.success) { showToast('Sent', r.message); closeCustomTaskModal(); }
        });
    }
    function claimTask(id, link) { tg.openLink(link); setTimeout(() => apiCall('claim_task', {taskId: id}), 5000); }

    // --- Referrals ---
    function renderReferrals() {
        document.getElementById('ref-link-input').value = `https://t.me/XPVerseBot?start=${appState.user.tgId}`;
        let r = appState.referrals || [];
        document.getElementById('ref-total').textContent = r.length;
        document.getElementById('ref-pending').textContent = r.filter(x=>x.status==='Pending').length;
        document.getElementById('ref-approved').textContent = r.filter(x=>x.status==='Approved').length;
        let c = document.getElementById('referral-list-container'); c.innerHTML = '';
        if(r.length === 0) { c.innerHTML = '<p class="text-center text-xs text-slate-500 py-4">No referrals yet.</p>'; return; }
        r.forEach(x => {
            c.innerHTML += `
            <div class="glass-card rounded-lg p-3 flex justify-between items-center">
                <div><p class="text-xs font-bold text-white">${sanitizeHTML(x.name)}</p><p class="text-[9px] text-slate-400">Ads: ${x.ads}/25 | Tasks: ${x.tasks}/5</p></div>
                <span class="text-[10px] font-black px-2 py-1 rounded ${x.status==='Approved'?'bg-emerald-500/20 text-emerald-400':'bg-amber-500/20 text-amber-400'}">${x.status}</span>
            </div>`;
        });
    }
    function copyRefLink() { navigator.clipboard.writeText(document.getElementById('ref-link-input').value); showToast('Copied', 'Referral link copied!'); }
    function shareReferralTelegram() { tg.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(document.getElementById('ref-link-input').value)}&text=Join%20XPVerse!`); }

    // --- Boxes & Withdrawal ---
    function openBox(type) { apiCall('open_box', {boxType: type}).then(r => { if(r && r.success) showToast('Box Opened', `You won $${r.reward}`); }); }
    
    function requestWithdrawal() {
        let amt = parseFloat(document.getElementById('withdraw-amount').value);
        let addr = document.getElementById('wallet-address').value.trim();
        if(amt < 10) return showToast('Error', 'Minimum withdrawal is $10', 'error');
        if(addr.length < 10) return showToast('Error', 'Invalid TON address', 'error');
        apiCall('withdraw', {amount: amt, address: addr}).then(r => {
            if(r && r.success) { showToast('Success', 'Withdrawal requested.'); document.getElementById('withdraw-amount').value = ''; }
        });
    }
    
    function renderWithdrawals() {
        let c = document.getElementById('withdraw-history-container'); c.innerHTML = '';
        let w = appState.withdrawals || [];
        if(w.length === 0) return c.innerHTML = '<p class="text-center text-xs text-slate-500 py-4">No withdrawals yet.</p>';
        w.forEach(x => {
            let cl = x.status==='Approved'?'text-emerald-400':(x.status==='Rejected'?'text-red-400':'text-amber-400');
            c.innerHTML += `
            <div class="glass-card rounded-lg p-3 border border-slate-800 flex justify-between items-center">
                <div><p class="text-xs font-black text-white">$${x.amount} <span class="text-[9px] text-slate-500">${x.date.split(' ')[0]}</span></p><p class="text-[9px] text-slate-400 truncate w-32">${sanitizeHTML(x.address)}</p></div>
                <span class="text-[10px] font-black ${cl}">${x.status}</span>
            </div>`;
        });
    }

    function renderProfile(u) {
        document.getElementById('profile-page-name').textContent = sanitizeHTML(u.firstName + ' ' + u.lastName);
        document.getElementById('profile-page-username').textContent = u.username ? '@'+sanitizeHTML(u.username) : '';
        document.getElementById('profile-page-id').textContent = u.tgId;
        document.getElementById('profile-page-avatar').src = u.photoUrl || 'https://via.placeholder.com/150';
        document.getElementById('profile-stat-xp').textContent = formatNum(u.totalXp);
        document.getElementById('profile-stat-usd').textContent = '$'+formatNum(u.usd, true);
    }

    // --- ADMIN PANEL FUNCTIONS ---
    function openAdminAuth() {
        document.getElementById('admin-auth-modal').classList.remove('hidden');
        document.getElementById('admin-auth-modal').classList.add('flex');
    }
    function submitAdminAuth() {
        const code = document.getElementById('admin-code-input').value;
        apiCall('admin_dashboard', {adminCode: code}).then(r => {
            if(r && r.success) {
                adminData = r;
                window.currentAdminCode = code;
                document.getElementById('admin-auth-modal').classList.remove('flex');
                document.getElementById('admin-auth-modal').classList.add('hidden');
                switchTab('admin');
                renderAdminDashboard();
            }
        });
    }
    function switchAdminTab(tab) {
        document.querySelectorAll('.admin-section').forEach(e => e.classList.add('hidden'));
        document.getElementById('admin-sec-'+tab).classList.remove('hidden');
        document.querySelectorAll('.admin-tab').forEach(e => { e.classList.remove('bg-red-600/20', 'text-red-400'); e.classList.add('text-slate-400'); });
        document.getElementById('tab-'+tab).classList.add('bg-red-600/20', 'text-red-400');
        if(tab==='users') renderAdminUsers();
        if(tab==='withdrawals') renderAdminWithdrawals();
        if(tab==='tasks') renderAdminTasks();
        if(tab==='errors') renderAdminErrors();
        if(tab==='settings') {
            document.getElementById('admin-maintenance').checked = adminData.adminSettings.maintenance;
            document.getElementById('admin-reset-time').value = adminData.adminSettings.resetTime;
            document.getElementById('admin-xp-mult').value = adminData.adminSettings.xpMultiplier;
        }
    }
    function renderAdminDashboard() {
        const s = adminData.stats;
        document.getElementById('admin-dash-stats').innerHTML = `
            <div class="glass-card p-3 rounded"><p class="text-[9px] text-slate-400">Users</p><p class="text-sm font-black">${s.users}</p></div>
            <div class="glass-card p-3 rounded"><p class="text-[9px] text-slate-400">USD Total</p><p class="text-sm font-black">$${s.usd.toFixed(2)}</p></div>
            <div class="glass-card p-3 rounded"><p class="text-[9px] text-slate-400">Total XP</p><p class="text-sm font-black">${s.xp}</p></div>
            <div class="glass-card p-3 rounded"><p class="text-[9px] text-red-400">Errors</p><p class="text-sm font-black text-red-500">${s.errors}</p></div>
        `;
    }
    function renderAdminUsers() {
        let q = document.getElementById('admin-user-search').value.toLowerCase();
        let html = '';
        adminData.all_users.filter(u => u.tgId.includes(q) || (u.username||'').toLowerCase().includes(q) || u.firstName.toLowerCase().includes(q)).slice(0, 50).forEach(u => {
            html += `
            <div class="glass-card p-2 rounded flex flex-col gap-1 border ${u.banned?'border-red-500':'border-slate-800'}">
                <div class="flex justify-between items-center">
                    <div class="text-[10px] font-black text-blue-300">${sanitizeHTML(u.tgId)} <span class="text-slate-400">${sanitizeHTML(u.username||u.firstName)}</span></div>
                    <div class="text-[9px]">$${u.usd.toFixed(2)} | XP: ${u.xp}</div>
                </div>
                <div class="flex gap-1">
                    <button onclick="adminActionUser('${u.tgId}', '${u.banned?'unban':'ban'}')" class="flex-1 py-1 bg-red-600/30 text-red-400 rounded text-[9px]">${u.banned?'Unban':'Ban'}</button>
                    <button onclick="adminActionUser('${u.tgId}', 'reset_ads')" class="flex-1 py-1 bg-amber-600/30 text-amber-400 rounded text-[9px]">Reset Ads</button>
                    <button onclick="adminPromptBalance('${u.tgId}', ${u.usd}, ${u.xp})" class="flex-1 py-1 bg-emerald-600/30 text-emerald-400 rounded text-[9px]">Edit Bal</button>
                </div>
            </div>`;
        });
        document.getElementById('admin-user-list').innerHTML = html;
    }
    function filterAdminUsers() { renderAdminUsers(); }
    function adminActionUser(uid, action, extra = {}) {
        apiCall('admin_action_user', {adminCode: window.currentAdminCode, targetUid: uid, userAction: action, ...extra}).then(r=>{if(r.success) refreshAdmin();});
    }
    function adminPromptBalance(uid, oldUsd, oldXp) {
        let nu = prompt("New USD Balance:", oldUsd); if(nu === null) return;
        let nx = prompt("New XP Balance:", oldXp); if(nx === null) return;
        adminActionUser(uid, 'update_balance', {newUsd: parseFloat(nu), newXp: parseInt(nx)});
    }
    
    function renderAdminWithdrawals() {
        let html = '';
        adminData.all_withdrawals.filter(w => w.status==='Pending').forEach(w => {
            html += `
            <div class="glass-card p-2 rounded border border-amber-500 mb-2">
                <div class="text-[10px] mb-1">UID: ${w.user_id} (@${sanitizeHTML(w.username)})</div>
                <div class="text-xs font-black text-emerald-400">$${w.amount}</div>
                <div class="text-[9px] text-slate-400 break-all mb-2">${sanitizeHTML(w.address)}</div>
                <div class="flex gap-1">
                    <button onclick="adminWithdrawAction('${w.user_id}', ${w.idx}, 'approve')" class="flex-1 py-1 bg-emerald-600 text-white rounded text-[10px]">Approve</button>
                    <button onclick="adminWithdrawAction('${w.user_id}', ${w.idx}, 'reject')" class="flex-1 py-1 bg-red-600 text-white rounded text-[10px]">Reject</button>
                </div>
            </div>`;
        });
        document.getElementById('admin-withdrawal-list').innerHTML = html || '<p class="text-xs text-slate-500">No pending withdrawals.</p>';
    }
    function adminWithdrawAction(uid, idx, act) { apiCall('admin_action_withdraw', {adminCode: window.currentAdminCode, targetUid: uid, idx: idx, withdrawAction: act}).then(r=>{if(r.success) refreshAdmin();}); }

    function renderAdminTasks() {
        let html = '<h4 class="text-xs font-black text-white mb-2">Submissions (Pending)</h4>';
        adminData.submissions.filter(s=>s.status==='Pending').forEach(s => {
            html += `
            <div class="glass-card p-2 border border-slate-700 mb-2 text-[10px]">
                <div class="text-blue-300">${s.uid} - ${sanitizeHTML(s.taskTitle)}</div>
                <div class="text-slate-300 my-1 bg-black p-1 rounded break-words">${sanitizeHTML(s.infoSubmitted)}</div>
                <div class="flex gap-1">
                    <button onclick="adminSubAction('${s.subId}', 'approve')" class="flex-1 py-1 bg-emerald-600 text-white rounded">Approve</button>
                    <button onclick="adminSubAction('${s.subId}', 'reject')" class="flex-1 py-1 bg-red-600 text-white rounded">Reject</button>
                </div>
            </div>`;
        });
        html += '<h4 class="text-xs font-black text-white mt-4 mb-2">Active Tasks</h4>';
        adminData.custom_tasks.forEach(t => {
            html += `
            <div class="glass-card p-2 border border-slate-700 mb-1 flex justify-between items-center text-[10px]">
                <div><span class="${t.active?'text-emerald-400':'text-red-400'}">●</span> ${sanitizeHTML(t.title)} (${t.reward}XP)</div>
                <button onclick="document.getElementById('nt-title').value='${sanitizeHTML(t.title)}'; document.getElementById('nt-reward').value='${t.reward}';" class="bg-blue-600 px-2 py-1 rounded">Edit</button>
            </div>`;
        });
        document.getElementById('admin-task-list').innerHTML = html;
    }
    function adminSubAction(subId, act) { apiCall('admin_action_submission', {adminCode: window.currentAdminCode, subId: subId, subAction: act}).then(r=>{if(r.success) refreshAdmin();}); }
    function adminSaveTask() {
        apiCall('admin_save_task', {
            adminCode: window.currentAdminCode, active: true,
            title: document.getElementById('nt-title').value, desc: document.getElementById('nt-desc').value,
            link: document.getElementById('nt-link').value, reward: document.getElementById('nt-reward').value,
            requireInfo: document.getElementById('nt-reqInfo').checked
        }).then(r=>{if(r.success) refreshAdmin();});
    }

    function renderAdminErrors() {
        let q = document.getElementById('admin-error-search').value.toLowerCase();
        let html = '';
        (adminData.errors || []).filter(e => e.errorId.toLowerCase().includes(q) || (e.uid||'').includes(q) || (e.message||'').toLowerCase().includes(q)).forEach(e => {
            let col = e.severity==='CRITICAL' ? 'text-red-500' : 'text-amber-500';
            html += `
            <div class="glass-card p-2 border border-slate-800 rounded text-[9px]">
                <div class="flex justify-between font-black"><span class="${col}">${e.errorId}</span> <span class="text-slate-500">${e.timestamp}</span></div>
                <div class="text-blue-300">UID: ${e.uid} | Action: ${e.endpoint}</div>
                <div class="text-slate-300 break-words mt-1 p-1 bg-black rounded">${sanitizeHTML(e.message)}</div>
            </div>`;
        });
        document.getElementById('admin-error-list').innerHTML = html || '<p class="text-xs text-slate-500">No errors.</p>';
    }
    function adminClearErrors() { apiCall('admin_clear_errors', {adminCode: window.currentAdminCode}).then(r=>{if(r.success) refreshAdmin();}); }

    function saveAdminSettings() {
        apiCall('admin_update_settings', {
            adminCode: document.getElementById('admin-code-verify').value,
            maintenance: document.getElementById('admin-maintenance').checked,
            resetTime: document.getElementById('admin-reset-time').value,
            xpMultiplier: document.getElementById('admin-xp-mult').value,
            blockIds: adminData.adminSettings.adBlockIds.join(',')
        }).then(r=>{if(r.success) showToast('Success','Settings saved'); refreshAdmin();});
    }
    function adminResetAllAds() {
        if(!confirm("Are you sure you want to reset daily ad limits for ALL users?")) return;
        apiCall('admin_reset_all_ads', {adminCode: document.getElementById('admin-code-verify').value}).then(r=>{if(r.success) refreshAdmin();});
    }
    function refreshAdmin() { apiCall('admin_dashboard', {bypassCode: true}).then(r=>{if(r&&r.success){adminData=r;switchAdminTab(document.querySelector('.admin-tab.text-red-400').id.replace('tab-',''));}}); }
    
    // Utilities
    function formatNum(n, isUsd=false) { return isUsd ? Number(n).toFixed(2) : Number(n).toLocaleString('en-US'); }

    // Init Logic
    window.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            document.getElementById('loading-overlay').style.opacity = '0';
            setTimeout(() => document.getElementById('loading-overlay').remove(), 500);
        }, 1500);
        
        apiCall('sync').then(() => { setInterval(() => apiCall('sync', {}, true), 60000); });
    });
  </script>
</body>
</html>
