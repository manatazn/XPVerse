// config.js
const tg = window.Telegram.WebApp;
tg.expand();
tg.ready();
tg.setHeaderColor('#0a0b1a');
tg.setBackgroundColor('#050511');

const GlobalConfig = {
  defaultUserState: {
    tgId: tg.initDataUnsafe?.user?.id || 123456789,
    firstName: tg.initDataUnsafe?.user?.first_name || "Crypto User",
    username: tg.initDataUnsafe?.user?.username || "xpverse",
    photoUrl: tg.initDataUnsafe?.user?.photo_url || "",
    xp: 0, totalXp: 0, usdBalance: 0.00, level: 1, adsWatchedToday: 0, boxesOpened: 0, streak: 1, lastResetDay: "", withdrawHistory: []
  },
  defaultGameState: {
    seedDate: "",
    levelsCompleted: Array(10).fill(false),
    stars: Array(10).fill(0),
    powerups: { rocket: 1, bomb: 1 }
  },
  getDefaultMissions: () => ({
    dailyReward: { completed: true, claimed: false, reward: 5, type: 'boolean' },
    watch5: { target: 5, claimed: false, reward: 20, type: 'progress' },
    watch30: { target: 30, claimed: false, reward: 50, type: 'progress' },
    all: { claimed: false, reward: 100, type: 'meta' }
  }),
  streakRewards: [5, 10, 15, 20, 25, 30, 50],
  boxCosts: { bronze: 10000, silver: 50000, gold: 100000 }
};

let userState = { ...GlobalConfig.defaultUserState };
let gameState = { ...GlobalConfig.defaultGameState };
let missionsState = GlobalConfig.getDefaultMissions();

function saveState() {
  localStorage.setItem(`xpverse_user_${userState.tgId}`, JSON.stringify(userState));
  localStorage.setItem(`xpverse_missions_${userState.tgId}`, JSON.stringify(missionsState));
  localStorage.setItem(`xpverse_game_${userState.tgId}`, JSON.stringify(gameState));
}

function loadState() {
  const su = localStorage.getItem(`xpverse_user_${userState.tgId}`);
  const sm = localStorage.getItem(`xpverse_missions_${userState.tgId}`);
  const sg = localStorage.getItem(`xpverse_game_${userState.tgId}`);

  if (su) userState = { ...userState, ...JSON.parse(su) };
  if (sm) missionsState = { ...GlobalConfig.getDefaultMissions(), ...JSON.parse(sm) };
  if (sg) gameState = { ...GlobalConfig.defaultGameState, ...JSON.parse(sg) };
}
