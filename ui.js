// ui.js
document.addEventListener("DOMContentLoaded", () => {
  loadState();
  updateUI();
  
  setTimeout(() => {
    document.getElementById('loading-overlay').style.opacity = '0';
    setTimeout(() => { document.getElementById('loading-overlay').style.display = 'none'; }, 500); 
  }, 1000);

  // Nav Binding
  document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      switchTab(e.currentTarget.getAttribute('data-target'));
    });
  });

  document.getElementById('watch-ad-btn').onclick = watchAd;
  document.querySelectorAll('.btn-open-box').forEach(btn => btn.onclick = () => openBox(btn.dataset.type));
  document.getElementById('withdraw-btn').onclick = requestWithdrawal;

  document.getElementById('btn-power-bomb').onclick = () => activatePowerup('bomb');
  document.getElementById('btn-power-rocket').onclick = () => activatePowerup('rocket');
  document.getElementById('btn-exit-game').onclick = exitGame;

  document.addEventListener('updatePowerupUI', updatePowerupUI);
  document.addEventListener('gameWin', () => handleGameEnd(true));
  document.addEventListener('gameLoss', () => handleGameEnd(false));
  
  renderGameMap();
});

function showToast(title, message, type = 'info') {
  const toast = document.getElementById('toast-container');
  const icon = document.getElementById('toast-icon');
  document.getElementById('toast-title').innerText = title;
  document.getElementById('toast-message').innerText = message;

  if (type === 'success') {
    icon.innerHTML = '<i class="fa-solid fa-check"></i>';
    icon.className = 'w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl bg-emerald-500/20 text-emerald-400';
  } else if (type === 'error') {
    icon.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    icon.className = 'w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl bg-red-500/20 text-red-400';
  } else {
    icon.innerHTML = '<i class="fa-solid fa-bell"></i>';
    icon.className = 'w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl bg-blue-500/20 text-blue-400';
  }
  toast.classList.add('toast-show');
  setTimeout(() => toast.classList.remove('toast-show'), 3000);
}

function updateUI() {
  document.getElementById('user-name').innerText = userState.firstName;
  document.getElementById('user-xp').innerText = userState.xp.toLocaleString();
  document.getElementById('user-usd').innerText = userState.usdBalance.toFixed(2);
  document.getElementById('main-xp-display').innerText = `${userState.xp.toLocaleString()} XP`;
  document.getElementById('ads-watched').innerText = userState.adsWatchedToday;
  document.getElementById('streak-days').innerText = userState.streak;
  document.getElementById('withdraw-balance-display').innerText = userState.usdBalance.toFixed(2);
  
  updatePowerupUI();
}

function switchTab(tabId) {
  if(gameEngine.isPlaying && tabId !== 'games') exitGame();
  
  document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
  document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));
  
  document.getElementById(`view-${tabId}`).classList.remove('hidden');
  document.querySelector(`[data-target="${tabId}"]`).classList.add('nav-active');
  
  const header = document.getElementById('main-header');
  const mainContent = document.getElementById('app-content');

  if (['withdraw', 'profile', 'games'].includes(tabId)) {
    header.style.transform = 'translateY(-100%)';
    mainContent.classList.replace('pt-24', 'pt-4');
  } else {
    header.style.transform = 'translateY(0)';
    mainContent.classList.replace('pt-4', 'pt-24');
  }
}

// Unlimited Powerup Ads (Limit condition explicitly removed)
function activatePowerup(type) {
  if (gameState.powerups[type] > 0) {
      gameEngine.activePowerup = type;
      updatePowerupUI();
  } else {
      if (window.Adsgram) {
          const AdController = window.Adsgram.init({ blockId: "int-34999" });
          AdController.show().then(() => {
              gameState.powerups[type]++;
              saveState(); updatePowerupUI();
              showToast("Powerup Received!", `You got 1 ${type.toUpperCase()}!`, "success");
          }).catch(() => showToast("Error", "Ads unavailable.", "error"));
      } else {
          showToast("Error", "Ad system offline.", "error");
      }
  }
}

function updatePowerupUI() {
  ['rocket', 'bomb'].forEach(type => {
      let el = document.getElementById(`count-${type}`);
      let btn = document.getElementById(`btn-power-${type}`);
      if(el && btn) {
         let count = gameState.powerups[type];
         el.innerText = count > 0 ? count : '+';
         if(gameEngine.activePowerup === type) btn.classList.add('powerup-active-glow');
         else btn.classList.remove('powerup-active-glow');
      }
  });
}

function renderGameMap() {
  const container = document.getElementById('level-map');
  let html = '';
  const positions = [{left: '50%'}, {left: '70%'}, {left: '80%'}, {left: '60%'}, {left: '30%'}, {left: '20%'}, {left: '40%'}, {left: '60%'}, {left: '80%'}, {left: '50%'}];

  for (let i = 0; i < 10; i++) {
    let isComp = gameState.levelsCompleted[i];
    let isCurr = !isComp && (i === 0 || gameState.levelsCompleted[i-1]);
    let cls = isComp ? 'completed' : (isCurr ? 'current cursor-pointer' : 'locked');
    let onClick = (isCurr || isComp) ? `onclick="startGame(${i + 1})"` : `onclick="showToast('Locked', 'Complete previous levels!', 'error')"`;
    
    html += `
      <div class="relative w-full flex justify-center mb-10 group" style="transform: translateX(calc(${positions[i].left} - 50%))">
         <div class="level-node ${cls} shadow-lg" ${onClick}>${isCurr || isComp ? i+1 : '<i class="fa-solid fa-lock"></i>'}</div>
      </div>
    `;
  }
  container.innerHTML = html;
}

window.startGame = function(level) {
  document.body.classList.add('in-game');
  document.getElementById('game-play-container').classList.add('fullscreen-mode');
  document.getElementById('game-menu-container').classList.add('hidden');
  document.getElementById('game-play-container').classList.remove('hidden');
  document.getElementById('game-over-modal').classList.add('hidden');
  document.getElementById('ingame-level-display').innerText = level;
  
  gameEngine.init(level);
};

window.exitGame = function() {
  gameEngine.isPlaying = false;
  document.body.classList.remove('in-game');
  document.getElementById('game-play-container').classList.remove('fullscreen-mode');
  document.getElementById('game-play-container').classList.add('hidden');
  document.getElementById('game-menu-container').classList.remove('hidden');
  renderGameMap();
};

function handleGameEnd(isWin) {
    gameEngine.isPlaying = false;
    const modal = document.getElementById('game-over-modal');
    modal.classList.remove('hidden');
    document.getElementById('end-title').innerText = isWin ? "Level Clear!" : "Level Failed";
    document.getElementById('end-title').className = isWin ? "text-4xl font-black text-white mb-2 drop-shadow-[0_0_15px_#00f0ff]" : "text-4xl font-black text-red-400 mb-2 drop-shadow-[0_0_15px_#ef4444]";
    
    document.getElementById('end-next-btn').innerText = isWin ? "Next" : "Retry";
    document.getElementById('end-next-btn').onclick = () => isWin ? startGame(gameEngine.currentLevel + 1) : startGame(gameEngine.currentLevel);
    document.getElementById('end-back-btn').onclick = exitGame;
}

function watchAd() {
  if (window.Adsgram) {
      window.Adsgram.init({ blockId: "int-34999" }).show().then(() => {
          userState.xp += 10; userState.adsWatchedToday++; saveState(); updateUI();
          showToast("Reward!", "+10 XP Earned", "success");
      });
  }
}

function openBox(type) {
  const cost = GlobalConfig.boxCosts[type];
  if (userState.xp < cost) return showToast("Error", "Not enough XP.", "error");
  userState.xp -= cost;
  let reward = Math.random() > 0.99 ? (type==='gold'?15:type==='silver'?7:1) : (type==='gold'?1:type==='silver'?0.5:0.1);
  userState.usdBalance += reward;
  saveState(); updateUI();
  showToast("Box Opened!", `You won $${reward.toFixed(2)}`, "success");
}

function requestWithdrawal() {
  const amount = parseFloat(document.getElementById('withdraw-amount').value);
  if (isNaN(amount) || amount < 5) return showToast("Error", "Min withdrawal is $5.00.", "error");
  if (amount > userState.usdBalance) return showToast("Error", "Insufficient funds.", "error");
  userState.usdBalance -= amount;
  saveState(); updateUI();
  showToast("Success", `Requested $${amount.toFixed(2)} withdrawal.`, "success");
}
