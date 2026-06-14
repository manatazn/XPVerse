const tg = window.Telegram.WebApp;
tg.expand(); 
tg.ready();
tg.setHeaderColor('#0a0b1a');
tg.setBackgroundColor('#050511');

// DİQQƏT: Bura öz worker linkini qoyursan.
const WORKER_URL = "https://xpverseapp.xpversegame.workers.dev";
const ADMIN_ID = 7397122686;

let currentUser = {
    tgId: tg.initDataUnsafe?.user?.id || 123456789,
    username: tg.initDataUnsafe?.user?.username || "Player",
    firstName: tg.initDataUnsafe?.user?.first_name || "User",
    photoUrl: tg.initDataUnsafe?.user?.photo_url || "",
    referrerId: tg.initDataUnsafe?.start_param || null // Dəvət edən şəxsin ID-si
};

let userState = null;

async function apiRequest(endpoint, method = 'GET', body = null) {
    try {
        const options = {
            method,
            headers: { 'Content-Type': 'application/json' }
        };
        if (body) options.body = JSON.stringify(body);
        
        const response = await fetch(`${WORKER_URL}/${endpoint}`, options);
        return await response.json();
    } catch (error) {
        console.error("API Error:", error);
        return { success: false, error: error.message };
    }
}

async function initApp() {
    // Admin naviqasiyasını göstər
    if (currentUser.tgId === ADMIN_ID) {
        document.getElementById('admin-nav-btn').classList.remove('hidden');
        document.getElementById('admin-nav-btn').classList.add('flex');
    }

    // İstifadeçi məlumatlarını çək və ya yarat
    const data = await apiRequest('auth', 'POST', currentUser);
    if (!data.success) {
        showToast("Error", "Could not connect to database.", "error");
        if(data.isBanned) showToast("BANNED", "Your account is banned.", "error");
        return;
    }

    userState = data.user;
    updateUI();

    document.getElementById('invite-link').value = `https://t.me/XPVersebot?startapp=${currentUser.tgId}`;
    
    setTimeout(() => {
        document.getElementById('loading-overlay').style.opacity = '0';
        setTimeout(() => document.getElementById('loading-overlay').style.display = 'none', 500); 
    }, 1000);
}

function updateUI() {
    if(!userState) return;
    
    document.getElementById('user-name').innerText = userState.firstName;
    document.getElementById('user-xp').innerHTML = `${userState.xp} <span class="text-[10px] text-crypto-glow font-bold">XP</span>`;
    document.getElementById('user-usd').innerText = `${userState.usdBalance.toFixed(2)}$`;
    document.getElementById('main-xp-display').innerText = `${userState.xp} XP`;
    document.getElementById('ads-watched').innerText = userState.adsWatchedToday;
    
    if (currentUser.photoUrl) {
        document.getElementById('user-photo').src = currentUser.photoUrl;
    }

    document.getElementById('ref-approved').innerText = userState.refsApproved || 0;
    document.getElementById('ref-pending').innerText = userState.refsPending || 0;
}

async function watchAd() {
    if (userState.adsWatchedToday >= 30) {
        showToast("Limit", "Daily limit of 30 ads reached.", "error");
        return;
    }

    const btn = document.getElementById('watch-ad-btn');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading...</span>';
    btn.classList.add('opacity-80', 'pointer-events-none');

    // Adsgram inteqrasiyası (İşlək olması üçün blockId dəqiq olmalıdır)
    if (window.Adsgram) {
        const AdController = window.Adsgram.init({ blockId: "int-34999" });
        AdController.show().then(async () => {
            // Serverə məlumat göndər, 25 reklam tamamlananda referansa avtomatik 50 XP gedəcək (backend tərəfində idarə olunur)
            const result = await apiRequest('watchAd', 'POST', { tgId: currentUser.tgId });
            
            if (result.success) {
                userState = result.user;
                updateUI();
                showToast("Rewarded!", "+10 XP Earned", "success");
            } else {
                showToast("Error", "Could not sync with server.", "error");
            }
            btn.innerHTML = originalHTML;
            btn.classList.remove('opacity-80', 'pointer-events-none');
        }).catch(() => {
            btn.innerHTML = originalHTML;
            btn.classList.remove('opacity-80', 'pointer-events-none');
        });
    } else {
        showToast("Error", "Ads system offline.", "error");
        btn.innerHTML = originalHTML;
        btn.classList.remove('opacity-80', 'pointer-events-none');
    }
}

function copyInviteLink() {
    const link = document.getElementById('invite-link');
    link.select();
    document.execCommand("copy");
    showToast("Copied!", "Invite link copied to clipboard.", "success");
}

async function loadLeaderboard() {
    const container = document.getElementById('leaderboard-container');
    container.innerHTML = '<p class="text-center text-slate-500 text-sm"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</p>';
    
    const data = await apiRequest('leaderboard');
    if(data.success && data.users) {
        container.innerHTML = '';
        data.users.forEach((u, index) => {
            let color = index === 0 ? 'text-crypto-gold' : index === 1 ? 'text-crypto-silver' : index === 2 ? 'text-crypto-bronze' : 'text-white';
            container.innerHTML += `
            <div class="glass-card rounded-xl p-3 flex justify-between items-center">
                <div class="flex items-center gap-3">
                    <span class="font-black ${color} text-lg w-5">${index + 1}</span>
                    <span class="text-white font-bold text-sm">${u.firstName}</span>
                </div>
                <span class="text-crypto-glow font-black text-sm">${u.xp} XP</span>
            </div>`;
        });
    } else {
        container.innerHTML = '<p class="text-center text-red-500 text-sm">Failed to load.</p>';
    }
}

/* ================= ADMIN PANEL MƏNTİQİ ================= */
async function adminCheckUser() {
    const uid = document.getElementById('admin-target-uid').value;
    if(!uid) return;
    
    const res = await apiRequest(`admin/user/${uid}?adminId=${ADMIN_ID}`);
    if(res.success && res.user) {
        document.getElementById('admin-user-details').classList.remove('hidden');
        document.getElementById('ad-u-status').innerText = res.user.banned ? "BANNED" : "Active";
        document.getElementById('ad-u-status').className = res.user.banned ? "text-red-500 font-bold" : "text-emerald-400 font-bold";
        document.getElementById('ad-u-xp').innerText = res.user.xp;
        document.getElementById('ad-u-usd').innerText = res.user.usdBalance.toFixed(2) + "$";
    } else {
        showToast("Admin", "User not found", "error");
    }
}

async function adminModify(action) {
    const targetUid = document.getElementById('admin-target-uid').value;
    const valXp = document.getElementById('admin-val-xp').value;
    const valUsd = document.getElementById('admin-val-usd').value;
    
    let amount = 0;
    if(action.includes('xp')) amount = Number(valXp);
    if(action.includes('usd')) amount = Number(valUsd);

    const res = await apiRequest('admin/action', 'POST', {
        adminId: ADMIN_ID,
        targetUid: targetUid,
        action: action,
        amount: amount
    });

    if(res.success) {
        showToast("Success", "Action applied successfully", "success");
        adminCheckUser(); 
    } else {
        showToast("Error", res.error || "Action failed", "error");
    }
}

function switchTab(tabId) {
    document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));

    document.getElementById(`view-${tabId}`).classList.remove('hidden');
    document.querySelector(`[data-target="${tabId}"]`).classList.add('nav-active');
    
    if(tabId === 'leaderboard') loadLeaderboard();
}

function showToast(title, message, type = 'info') {
    const toast = document.getElementById('toast-container');
    const icon = document.getElementById('toast-icon');
    document.getElementById('toast-title').innerText = title;
    document.getElementById('toast-message').innerText = message;
    
    if (type === 'success') {
        icon.innerHTML = '<i class="fa-solid fa-check"></i>';
        icon.className = 'w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/50';
    } else if (type === 'error') {
        icon.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        icon.className = 'w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl bg-red-500/20 text-red-400 border border-red-500/50';
    } else {
        icon.innerHTML = '<i class="fa-solid fa-bell"></i>';
        icon.className = 'w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl bg-blue-500/20 text-crypto-glow border border-blue-500/50';
    }

    toast.classList.add('toast-show');
    if (tg.HapticFeedback) tg.HapticFeedback.notificationOccurred(type === 'error' ? 'error' : 'success');
    setTimeout(() => toast.classList.remove('toast-show'), 3000);
}

// Klaviatura İzləmə (Sənin istədiyin tərzdə smooth control üçün)
let maxViewportHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
function handleKeyboardState() {
  let currentHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
  if (currentHeight > maxViewportHeight) maxViewportHeight = currentHeight;
  if (maxViewportHeight - currentHeight > 100) {
    document.getElementById('bottom-nav').classList.add('nav-hidden');
  } else {
    document.getElementById('bottom-nav').classList.remove('nav-hidden');
  }
}
if (window.visualViewport) window.visualViewport.addEventListener('resize', handleKeyboardState);
window.addEventListener('resize', handleKeyboardState);

initApp();
