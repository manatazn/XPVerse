const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
  "Access-Control-Allow-Headers": "Content-Type",
};

const ADMIN_ID = "7397122686";

// Server tərəfli missiya təməli
const getDefaultMissions = () => ({
  dailyReward: { completed: true, claimed: false, reward: 5, type: 'boolean' },
  watch5: { target: 5, claimed: false, reward: 20, type: 'progress' },
  watch10: { target: 10, claimed: false, reward: 30, type: 'progress' },
  watch20: { target: 20, claimed: false, reward: 50, type: 'progress' },
  all: { claimed: false, reward: 100, type: 'meta' }
});

export default {
  async fetch(request, env) {
    if (request.method === "OPTIONS") return new Response(null, { headers: corsHeaders });
    const url = new URL(request.url);
    const path = url.pathname;

    try {
      // 1. AUTH (Giriş və Bazadan Məlumat Çəkmək)
      if (path === "/auth" && request.method === "POST") {
        const body = await request.json();
        const tgId = body.tgId.toString();
        let user = await env.XP_DB.get(tgId, { type: "json" });
        
        const currentResetDay = new Date().toISOString().split('T')[0];

        if (!user) {
          user = {
            tgId: tgId,
            firstName: body.firstName || "İstifadəçi",
            username: body.username || "",
            xp: 0,
            totalXp: 0,
            usdBalance: 0.00,
            level: 1,
            adsWatchedToday: 0,
            boxesOpened: 0,
            streak: 1,
            lastResetDay: currentResetDay,
            withdrawHistory: [],
            refsApproved: 0,
            refsPending: 0,
            referrerId: null,
            banned: false,
            missions: getDefaultMissions()
          };
          
          // Referans Sistemi
          if (body.referrerId && body.referrerId.toString() !== tgId) {
            user.referrerId = body.referrerId.toString();
            let ref = await env.XP_DB.get(user.referrerId, { type: "json" });
            if (ref) {
              ref.refsPending = (ref.refsPending || 0) + 1;
              await env.XP_DB.put(user.referrerId, JSON.stringify(ref));
            }
          }
        } else {
          // Gündəlik Sıfırlanma (Server tərəfindən idarə olunur)
          if (user.lastResetDay !== currentResetDay) {
            user.streak = (user.streak || 1) + 1;
            if (user.streak > 7) user.streak = 1;
            user.adsWatchedToday = 0;
            user.lastResetDay = currentResetDay;
            user.missions = getDefaultMissions();
            const streakRewards = [5, 10, 15, 20, 25, 30, 50];
            user.missions.dailyReward.reward = streakRewards[user.streak - 1];
          }
          if (!user.missions) user.missions = getDefaultMissions();
        }

        if (user.banned) return Response.json({ success: false, isBanned: true }, { headers: corsHeaders });
        await env.XP_DB.put(tgId, JSON.stringify(user));
        return Response.json({ success: true, user: user }, { headers: corsHeaders });
      }

      // 2. REKLAM İZLƏMƏ
      if (path === "/watchAd" && request.method === "POST") {
        const body = await request.json();
        const tgId = body.tgId.toString();
        let user = await env.XP_DB.get(tgId, { type: "json" });
        
        if (!user || user.adsWatchedToday >= 30) return Response.json({ success: false, error: "Limit" }, { headers: corsHeaders });
        
        user.xp += 10;
        user.totalXp = (user.totalXp || 0) + 10;
        user.adsWatchedToday += 1;
        
        // 25 Reklam Şərti ilə Referans Təsdiqi
        if (user.adsWatchedToday === 25 && user.referrerId) {
             let ref = await env.XP_DB.get(user.referrerId, { type: "json" });
             if (ref) {
                ref.refsPending = Math.max(0, (ref.refsPending || 0) - 1);
                ref.refsApproved = (ref.refsApproved || 0) + 1;
                ref.xp += 50; 
                ref.totalXp += 50;
                await env.XP_DB.put(user.referrerId, JSON.stringify(ref));
             }
        }
        await env.XP_DB.put(tgId, JSON.stringify(user));
        return Response.json({ success: true, user: user }, { headers: corsHeaders });
      }

      // 3. MİSSİYA TƏSDİQLƏMƏK
      if (path === "/claimMission" && request.method === "POST") {
        const { tgId, missionKey } = await request.json();
        let user = await env.XP_DB.get(tgId.toString(), { type: "json" });
        if(!user) return Response.json({ success: false, error: "User not found" }, { headers: corsHeaders });
        
        let mission = user.missions[missionKey];
        if(mission && !mission.claimed) {
            mission.claimed = true;
            user.xp += mission.reward;
            user.totalXp += mission.reward;
            
            // "Hamısını et" missiyasını yoxla
            const allDone = ['dailyReward', 'watch5', 'watch10', 'watch20'].every(k => user.missions[k].claimed);
            if (allDone && !user.missions.all.claimed) {
               user.missions.all.claimed = true;
               user.xp += user.missions.all.reward;
               user.totalXp += user.missions.all.reward;
            }
            
            await env.XP_DB.put(tgId.toString(), JSON.stringify(user));
            return Response.json({ success: true, user: user }, { headers: corsHeaders });
        }
        return Response.json({ success: false, error: "Artıq götürülüb və ya xəta var" }, { headers: corsHeaders });
      }

      // 4. QUTU AÇMA
      if (path === "/openBox" && request.method === "POST") {
        const body = await request.json();
        let user = await env.XP_DB.get(body.tgId.toString(), { type: "json" });
        if (!user) return Response.json({ success: false, error: "User not found" }, { headers: corsHeaders });
        
        const boxCosts = { bronze: 1000, silver: 5000, gold: 10000 };
        const cost = boxCosts[body.boxType];
        
        if (user.xp < cost) return Response.json({ success: false, error: "Kifayət qədər XP yoxdur" }, { headers: corsHeaders });
        
        user.xp -= cost;
        user.boxesOpened = (user.boxesOpened || 0) + 1;
        
        const isJackpot = Math.random() >= 0.99;
        let reward = body.boxType === 'bronze' ? (isJackpot ? 1.00 : 0.10) :
                     body.boxType === 'silver' ? (isJackpot ? 7.00 : 0.50) : (isJackpot ? 15.00 : 1.00);
        
        user.usdBalance = parseFloat((parseFloat(user.usdBalance || 0) + reward).toFixed(2));
        await env.XP_DB.put(body.tgId.toString(), JSON.stringify(user));
        return Response.json({ success: true, user: user, reward: reward, isJackpot: isJackpot }, { headers: corsHeaders });
      }

      // 5. ÇIXARIŞ ETMƏK
      if (path === "/withdraw" && request.method === "POST") {
        const body = await request.json();
        let user = await env.XP_DB.get(body.tgId.toString(), { type: "json" });
        const amount = parseFloat(body.amount);
        
        if (!user || user.usdBalance < amount) return Response.json({ success: false, error: "Kifayət qədər balans yoxdur" }, { headers: corsHeaders });
        
        user.usdBalance = parseFloat((user.usdBalance - amount).toFixed(2));
        if (!user.withdrawHistory) user.withdrawHistory = [];
        
        user.withdrawHistory.unshift({
          id: '#' + Math.random().toString(36).substr(2, 6).toUpperCase(),
          date: new Date().toLocaleDateString('az-AZ'),
          amount: amount,
          address: body.address.substring(0, 6) + '...' + body.address.substring(body.address.length - 4),
          status: 'Pending'
        });
        
        await env.XP_DB.put(body.tgId.toString(), JSON.stringify(user));
        return Response.json({ success: true, user: user }, { headers: corsHeaders });
      }

      return Response.json({ success: false, error: "Not Found" }, { status: 404, headers: corsHeaders });
    } catch (e) {
      return Response.json({ success: false, error: e.message }, { headers: corsHeaders });
    }
  }
};
