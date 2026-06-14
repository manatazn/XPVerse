// Cloudflare Worker - XPVerse Backend System
const ALLOWED_ORIGINS = "*"; 

const adminId = "7397122686";

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    
    // CORS Tənzimləmələri
    const corsHeaders = {
      "Access-Control-Allow-Origin": ALLOWED_ORIGINS,
      "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
      "Access-Control-Allow-Headers": "Content-Type, Authorization",
    };

    if (request.method === "OPTIONS") {
      return new Response(null, { headers: corsHeaders });
    }

    try {
      // 1. İSTİFADƏÇİ INITIATION (Yüklənmə və Qeydiyyat)
      if (url.pathname === "/api/init" && request.method === "POST") {
        const body = await request.json();
        const { tgId, firstName, username, photoUrl, startApp } = body;
        
        if (!tgId) return errorResponse("Telegram ID tapılmadı", corsHeaders);

        let user = await env.XPVERSE_KV.get(`user:${tgId}`, { type: "json" });
        let isNewUser = false;

        if (!user) {
          isNewUser = true;
          user = {
            tgId: tgId.toString(),
            firstName: firstName || "Crypto User",
            username: username || "",
            photoUrl: photoUrl || "",
            xp: 0,
            totalXp: 0,
            usdBalance: 0.00,
            level: 1,
            adsWatchedToday: 0,
            totalAdsWatched: 0,
            boxesOpened: 0,
            streak: 1,
            lastResetDay: "",
            withdrawHistory: [],
            isBanned: false,
            referredBy: null,
            refCount: 0
          };

          // Əgər dəvət linki ilə gəlibsə
          if (startApp && startApp.toString() !== tgId.toString()) {
            let referrer = await env.XPVERSE_KV.get(`user:${startApp}`, { type: "json" });
            if (referrer) {
              user.referredBy = startApp.toString();
              
              // Referal siyahısını saxla (gözləmədə olan)
              let refList = await env.XPVERSE_KV.get(`refs:${startApp}`, { type: "json" }) || [];
              refList.push({
                tgId: user.tgId,
                firstName: user.firstName,
                totalAdsWatched: 0,
                status: "Pending" // 25 reklam izləyənə qədər gözləyir
              });
              await env.XPVERSE_KV.put(`refs:${startApp}`, JSON.stringify(refList));
              
              referrer.refCount = (referrer.refCount || 0) + 1;
              await env.XPVERSE_KV.put(`user:${startApp}`, JSON.stringify(referrer));
            }
          }
        } else {
          // Əgər istifadəçi artıq mövcuddursa, profil şəklini və adını yenilə
          if (firstName) user.firstName = firstName;
          if (username) user.username = username;
          if (photoUrl) user.photoUrl = photoUrl;
        }

        if (user.isBanned) {
          return new Response(JSON.stringify({ isBanned: true }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
        }

        await env.XPVERSE_KV.put(`user:${tgId}`, JSON.stringify(user));
        await updateLeaderboard(env, user);

        let userRefs = await env.XPVERSE_KV.get(`refs:${tgId}`, { type: "json" }) || [];

        return new Response(JSON.stringify({ user, referrals: userRefs }), {
          headers: { ...corsHeaders, "Content-Type": "application/json" }
        });
      }

      // 2. REKLAM İZLƏMƏ SİSTEMİ
      if (url.pathname === "/api/watch-ad" && request.method === "POST") {
        const { tgId } = await request.json();
        let user = await env.XPVERSE_KV.get(`user:${tgId}`, { type: "json" });
        
        if (!user) return errorResponse("İstifadəçi tapılmadı", corsHeaders);
        if (user.isBanned) return errorResponse("Hesabınız bloklanıb", corsHeaders);
        if (user.adsWatchedToday >= 30) return errorResponse("Günlük limitə çatdınız", corsHeaders);

        user.xp += 10;
        user.totalXp += 10;
        user.adsWatchedToday += 1;
        user.totalAdsWatched = (user.totalAdsWatched || 0) + 1;

        // Səviyyə yoxlanışı
        user.level = calculateLevel(user.totalXp);

        // Əgər bu istifadəçi bir referaldırsa və yeni 25 reklama çatdısa, dəvət edəni mükafatlandır
        if (user.referredBy) {
          let referrerId = user.referredBy;
          let refList = await env.XPVERSE_KV.get(`refs:${referrerId}`, { type: "json" }) || [];
          let targetRef = refList.find(r => r.tgId === user.tgId);
          
          if (targetRef) {
            targetRef.totalAdsWatched = user.totalAdsWatched;
            if (targetRef.status === "Pending" && user.totalAdsWatched >= 25) {
              targetRef.status = "Approved";
              
              let referrer = await env.XPVERSE_KV.get(`user:${referrerId}`, { type: "json" });
              if (referrer) {
                referrer.xp += 50;
                referrer.totalXp += 50;
                referrer.level = calculateLevel(referrer.totalXp);
                await env.XPVERSE_KV.put(`user:${referrerId}`, JSON.stringify(referrer));
                await updateLeaderboard(env, referrer);
              }
            }
            await env.XPVERSE_KV.put(`refs:${referrerId}`, JSON.stringify(refList));
          }
        }

        await env.XPVERSE_KV.put(`user:${tgId}`, JSON.stringify(user));
        await updateLeaderboard(env, user);

        return new Response(JSON.stringify({ success: true, user }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
      }

      // 3. QUTU AÇMA SİSTEMİ
      if (url.pathname === "/api/open-box" && request.method === "POST") {
        const { tgId, boxType, reward, cost } = await request.json();
        let user = await env.XPVERSE_KV.get(`user:${tgId}`, { type: "json" });

        if (!user || user.xp < cost) return errorResponse("Kifayət qədər xal yoxdur", corsHeaders);
        
        user.xp -= cost;
        user.usdBalance += reward;
        user.boxesOpened += 1;

        await env.XPVERSE_KV.put(`user:${tgId}`, JSON.stringify(user));
        return new Response(JSON.stringify({ success: true, user }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
      }

      // 4. PUL ÇIXARIŞI SİSTEMİ
      if (url.pathname === "/api/withdraw" && request.method === "POST") {
        const { tgId, amount, address, recordId, dateStr } = await request.json();
        let user = await env.XPVERSE_KV.get(`user:${tgId}`, { type: "json" });

        if (!user || user.usdBalance < amount) return errorResponse("Balans yetərsizdir", corsHeaders);

        user.usdBalance -= amount;
        
        const newRecord = {
          id: recordId,
          date: dateStr,
          amount: amount,
          address: address.substring(0, 6) + '...' + address.substring(address.length - 4),
          status: 'Pending'
        };

        user.withdrawHistory.unshift(newRecord);
        await env.XPVERSE_KV.put(`user:${tgId}`, JSON.stringify(user));
        
        // Həmçinin qlobal çıxarışlar siyahısına əlavə et (Admin görsün deyə)
        let globalRequests = await env.XPVERSE_KV.get("global_withdrawals", { type: "json" }) || [];
        globalRequests.unshift({ ...newRecord, tgId: user.tgId, fullAddress: address, firstName: user.firstName });
        await env.XPVERSE_KV.put("global_withdrawals", JSON.stringify(globalRequests));

        return new Response(JSON.stringify({ success: true, user }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
      }

      // 5. LİDERLİK LÖVHƏSİNİ GETİR
      if (url.pathname === "/api/leaderboard" && request.method === "GET") {
        let board = await env.XPVERSE_KV.get("leaderboard", { type: "json" }) || [];
        return new Response(JSON.stringify({ leaderboard: board }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
      }

      // 6. GÜNLÜK STRİK VƏ RESET SİSTEMİ SEYXRONİZASİYASI
      if (url.pathname === "/api/sync-reset" && request.method === "POST") {
        const { tgId, currentResetDay, streakAction } = await request.json();
        let user = await env.XPVERSE_KV.get(`user:${tgId}`, { type: "json" });

        if (!user) return errorResponse("İstifadəçi tapılmadı", corsHeaders);

        user.lastResetDay = currentResetDay;
        user.adsWatchedToday = 0;
        
        if (streakAction === "increment") {
          user.streak = (user.streak || 1) + 1;
          if (user.streak > 7) user.streak = 1;
        } else if (streakAction === "reset") {
          user.streak = 1;
        }

        await env.XPVERSE_KV.put(`user:${tgId}`, JSON.stringify(user));
        return new Response(JSON.stringify({ success: true, user }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
      }

      // 7. REKLAM TAPŞIRIQLARINI CLAIM ETMƏK
      if (url.pathname === "/api/claim-mission" && request.method === "POST") {
        const { tgId, reward } = await request.json();
        let user = await env.XPVERSE_KV.get(`user:${tgId}`, { type: "json" });
        if (!user) return errorResponse("İstifadəçi tapılmadı", corsHeaders);

        user.xp += reward;
        user.totalXp += reward;
        user.level = calculateLevel(user.totalXp);

        await env.XPVERSE_KV.put(`user:${tgId}`, JSON.stringify(user));
        await updateLeaderboard(env, user);
        return new Response(JSON.stringify({ success: true, user }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
      }

      // ==========================================
      // ADMİN PANEL ENDPOINTLƏRİ (Təhlükəsizlik Yoxlanışı ilə)
      // ==========================================
      
      if (url.pathname.startsWith("/api/admin/")) {
        const requestHeaders = request.headers;
        const requesterId = requestHeaders.get("X-Admin-ID");
        
        if (requesterId !== adminId) {
          return new Response(JSON.stringify({ error: "Giriş qadağandır! Admin deyilsiniz." }), { status: 403, headers: corsHeaders });
        }

        // Admin: İstifadəçi məlumatlarını axtar
        if (url.pathname === "/api/admin/get-user" && request.method === "GET") {
          const targetUid = url.searchParams.get("uid");
          let targetUser = await env.XPVERSE_KV.get(`user:${targetUid}`, { type: "json" });
          if (!targetUser) return errorResponse("Bu UID-yə sahib istifadəçi verilənlər bazasında tapılmadı.", corsHeaders);
          
          let targetRefs = await env.XPVERSE_KV.get(`refs:${targetUid}`, { type: "json" }) || [];
          return new Response(JSON.stringify({ targetUser, referrals: targetRefs }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
        }

        // Admin: Hərəkətləri yerinə yetir (Ban, Unban, Balans Redaktəsi)
        if (url.pathname === "/api/admin/action" && request.method === "POST") {
          const { targetUid, actionType, val } = await request.json();
          let targetUser = await env.XPVERSE_KV.get(`user:${targetUid}`, { type: "json" });
          if (!targetUser) return errorResponse("İstifadəçi tapılmadı.", corsHeaders);

          if (actionType === "ban") {
            targetUser.isBanned = true;
          } else if (actionType === "unban") {
            targetUser.isBanned = false;
          } else if (actionType === "add_xp") {
            targetUser.xp += parseInt(val);
            targetUser.totalXp += parseInt(val);
            targetUser.level = calculateLevel(targetUser.totalXp);
          } else if (actionType === "sub_xp") {
            targetUser.xp = Math.max(0, targetUser.xp - parseInt(val));
          } else if (actionType === "add_usd") {
            targetUser.usdBalance += parseFloat(val);
          } else if (actionType === "sub_usd") {
            targetUser.usdBalance = Math.max(0, targetUser.usdBalance - parseFloat(val));
          }

          await env.XPVERSE_KV.put(`user:${targetUid}`, JSON.stringify(targetUser));
          await updateLeaderboard(env, targetUser);

          return new Response(JSON.stringify({ success: true, msg: "Hərəkət uğurla yerinə yetirildi!", updatedUser: targetUser }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
        }
      }

      return new Response("Tapılmadı", { status: 404, headers: corsHeaders });

    } catch (err) {
      return new Response(JSON.stringify({ error: err.message }), { status: 500, headers: corsHeaders });
    }
  }
};

// Köməkçi Funksiyalar
function errorResponse(msg, headers) {
  return new Response(JSON.stringify({ error: msg }), { status: 400, headers: { ...headers, "Content-Type": "application/json" } });
}

function calculateLevel(xp) {
  if (xp >= 20000) return 10;
  if (xp >= 3000) return 5;
  if (xp >= 1500) return 4;
  if (xp >= 750) return 3;
  if (xp >= 250) return 2;
  return 1;
}

async function updateLeaderboard(env, user) {
  if (user.isBanned) return;
  let board = await env.XPVERSE_KV.get("leaderboard", { type: "json" }) || [];
  
  // Mövcud olanı sil
  board = board.filter(item => item.tgId !== user.tgId);
  
  // Yenisini əlavə et
  board.push({
    tgId: user.tgId,
    firstName: user.firstName,
    username: user.username || "",
    totalXp: user.totalXp,
    level: user.level
  });
  
  // Sırala və Top 100-ü saxla
  board.sort((a, b) => b.totalXp - a.totalXp);
  board = board.slice(0, 100);
  
  await env.XPVERSE_KV.put("leaderboard", JSON.stringify(board));
}
