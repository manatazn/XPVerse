// game.js
const GAME_COLORS = ['#ef4444', '#3b82f6', '#10b981', '#eab308', '#a855f7', '#06b6d4'];
const LEVEL_REWARDS = [25, 35, 50, 60, 75, 80, 90, 100, 110, 125];

let spriteCache = {};
function preRenderBubbles(radius) {
  spriteCache = {};
  const types = ['normal', 'chained', 'ice', 'virus', 'armored'];
  
  GAME_COLORS.forEach(color => {
    spriteCache[color] = {};
    types.forEach(type => {
      const oc = document.createElement('canvas');
      oc.width = radius * 2.5; oc.height = radius * 2.5;
      const octx = oc.getContext('2d');
      const cx = radius * 1.25, cy = radius * 1.25;
      
      // Draw base bubble logic without runtime shadow drops
      octx.beginPath();
      octx.arc(cx, cy, radius * 0.95, 0, Math.PI * 2);
      
      let fillCol = color;
      if (type === 'armored') fillCol = '#64748b';
      
      let grad = octx.createRadialGradient(cx - radius*0.3, cy - radius*0.3, radius*0.1, cx, cy, radius);
      grad.addColorStop(0, '#ffffff');
      grad.addColorStop(0.7, fillCol);
      grad.addColorStop(1, '#000000');
      octx.fillStyle = grad;
      octx.fill();

      // Highlights
      octx.beginPath();
      octx.ellipse(cx - radius*0.35, cy - radius*0.35, radius*0.4, radius*0.15, Math.PI / -4, 0, Math.PI * 2);
      octx.fillStyle = 'rgba(255, 255, 255, 0.6)'; octx.fill();

      // Overlays
      if (type === 'chained') {
        octx.lineWidth = 3; octx.strokeStyle = '#334155';
        octx.beginPath(); octx.moveTo(cx-radius, cy); octx.lineTo(cx+radius, cy); octx.stroke();
      } else if (type === 'ice') {
        octx.fillStyle = 'rgba(165, 243, 252, 0.4)';
        octx.beginPath(); octx.arc(cx, cy, radius, 0, Math.PI * 2); octx.fill();
        octx.strokeStyle = 'white'; octx.stroke();
      } else if (type === 'virus') {
        octx.fillStyle = '#bef264'; octx.font = `900 ${radius*1.1}px Arial`; 
        octx.textAlign = 'center'; octx.textBaseline = 'middle';
        octx.fillText('V', cx, cy);
      } else if (type === 'armored') {
        octx.fillStyle = '#94a3b8'; octx.beginPath(); octx.arc(cx, cy, radius*0.5, 0, Math.PI*2); octx.fill();
      }
      
      spriteCache[color][type] = oc;
    });
  });
}

const gameEngine = {
  canvas: null, ctx: null, fx: null, isPlaying: false,
  gridCols: 11, ballRadius: 0, grid: [],
  launcherColor: '', nextColor: '', activePowerup: null,
  score: 0, shotsFired: 0, totalTarget: 0, offsetY: 0, limitY: 0,
  mouseX: null, mouseY: null, projectiles: [], fallingBalloons: [],
  
  init(level) {
    this.canvas = document.getElementById('balloon-canvas');
    this.ctx = this.canvas.getContext('2d');
    this.fx = new FXEngine(this.canvas, this.ctx);
    
    this.resizeCanvas();
    preRenderBubbles(this.ballRadius);

    this.isPlaying = true;
    this.score = 0; this.shotsFired = 0; this.offsetY = 0;
    this.projectiles = []; this.fallingBalloons = []; this.activePowerup = null;

    this.generateLevel(level);
    this.launcherColor = GAME_COLORS[Math.floor(Math.random() * GAME_COLORS.length)];
    this.nextColor = GAME_COLORS[Math.floor(Math.random() * GAME_COLORS.length)];

    this.canvas.onmousedown = this.handleInput.bind(this);
    this.canvas.onmousemove = this.handleMove.bind(this);
    this.canvas.onmouseup = this.handleFire.bind(this);
    this.canvas.ontouchstart = (e) => { e.preventDefault(); this.handleInput(e.touches[0]); };
    this.canvas.ontouchmove = (e) => { e.preventDefault(); this.handleMove(e.touches[0]); };
    this.canvas.ontouchend = (e) => { e.preventDefault(); this.handleFire(e); };

    requestAnimationFrame(() => this.loop());
  },

  resizeCanvas() {
    const c = document.getElementById('game-canvas-container');
    this.canvas.width = c.clientWidth;
    this.canvas.height = c.clientHeight;
    this.ballRadius = (this.canvas.width / (this.gridCols + 0.5)) / 2;
    this.limitY = this.canvas.height - 150;
  },

  getGridPos(r, c) {
    let x = c * this.ballRadius * 2 + this.ballRadius;
    if (r % 2 !== 0) x += this.ballRadius;
    let y = r * this.ballRadius * 1.732 + this.ballRadius + this.offsetY;
    return { x, y };
  },

  generateLevel(level) {
    let rows = 4 + Math.floor(level / 2);
    if (rows > 10) rows = 10;
    this.grid = []; this.totalTarget = 0;

    for (let r = 0; r < rows; r++) {
      this.grid[r] = [];
      let colsInRow = (r % 2 === 0) ? this.gridCols : this.gridCols - 1;
      for (let c = 0; c < colsInRow; c++) {
        let type = 'normal';
        let rVal = Math.random();
        
        if (level >= 3 && rVal < 0.1) continue; // Gap
        if (level >= 9 && rVal < 0.1) type = 'armored';
        else if (level >= 7 && rVal < 0.1) type = 'virus';
        else if (level >= 5 && rVal < 0.15) type = 'ice';
        else if (level >= 4 && rVal < 0.2) type = 'chained';

        this.grid[r][c] = {
          color: type === 'armored' ? '#64748b' : GAME_COLORS[Math.floor(Math.random() * GAME_COLORS.length)],
          type: type, r: r, c: c
        };
        if (type !== 'armored') this.totalTarget++;
      }
    }
  },

  drawBubble(x, y, r, color, type, alpha = 1) {
    this.ctx.globalAlpha = alpha;
    if (spriteCache[color] && spriteCache[color][type]) {
        this.ctx.drawImage(spriteCache[color][type], x - r*1.25, y - r*1.25, r*2.5, r*2.5);
    }
    this.ctx.globalAlpha = 1;
  },

  handleInput(e) {
    const rect = this.canvas.getBoundingClientRect();
    this.mouseX = e.clientX - rect.left;
    this.mouseY = e.clientY - rect.top;
    
    // Swap Next Check
    const lx = this.canvas.width / 2;
    const ly = this.canvas.height - 80;
    if (Math.hypot(this.mouseX - (lx - 70), this.mouseY - (ly + 10)) < 40) {
      [this.launcherColor, this.nextColor] = [this.nextColor, this.launcherColor];
      this.mouseX = null;
    }
  },
  
  handleMove(e) {
    const rect = this.canvas.getBoundingClientRect();
    this.mouseX = e.clientX - rect.left;
    this.mouseY = e.clientY - rect.top;
  },
  
  handleFire(e) {
    if (!this.isPlaying || this.projectiles.length > 0 || this.mouseX === null) return;
    
    const lx = this.canvas.width / 2, ly = this.canvas.height - 80;
    let dy = this.mouseY - ly;
    if (dy >= -10) return;

    let angle = Math.atan2(dy, this.mouseX - lx);
    if (angle > -0.15) angle = -0.15;
    if (angle < -Math.PI + 0.15) angle = -Math.PI + 0.15;

    let speed = this.activePowerup === 'rocket' ? 35 : 25;
    let type = this.activePowerup || 'normal';
    let color = this.activePowerup === 'rocket' ? '#ef4444' : (this.activePowerup === 'bomb' ? '#f97316' : this.launcherColor);

    this.projectiles.push({ x: lx, y: ly, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed, radius: this.ballRadius, color, type });
    this.shotsFired++;
    
    if (this.activePowerup) {
      gameState.powerups[this.activePowerup]--;
      this.activePowerup = null;
      saveState(); document.dispatchEvent(new Event('updatePowerupUI'));
    } else {
      this.launcherColor = this.nextColor;
      this.nextColor = GAME_COLORS[Math.floor(Math.random() * GAME_COLORS.length)];
    }
    this.mouseX = null;
  },

  snapProjectile(p) {
     let rHeight = this.ballRadius * 1.732;
     let targetR = Math.round((p.y - this.offsetY - this.ballRadius) / rHeight);
     if (targetR < 0) targetR = 0;
     let targetC = Math.round((p.x - this.ballRadius - (targetR % 2 !== 0 ? this.ballRadius : 0)) / (this.ballRadius * 2));
     
     let maxC = (targetR % 2 === 0) ? this.gridCols - 1 : this.gridCols - 2;
     targetC = Math.max(0, Math.min(targetC, maxC));

     while(this.grid.length <= targetR) this.grid.push([]);

     // Find closest empty adjacent if occupied
     if (this.grid[targetR][targetC]) {
         let bestD = Infinity, br = targetR, bc = targetC;
         const dirs = targetR % 2 === 0 ? [[-1,-1], [-1,0], [0,-1], [0,1], [1,-1], [1,0]] : [[-1,0], [-1,1], [0,-1], [0,1], [1,0], [1,1]];
         dirs.forEach(d => {
             let nr = targetR + d[0], nc = targetC + d[1];
             if(nr >= 0 && nc >= 0 && nc <= ((nr%2===0)?this.gridCols-1:this.gridCols-2)) {
                 if (!this.grid[nr] || !this.grid[nr][nc]) {
                    let nPos = this.getGridPos(nr, nc);
                    let dist = Math.hypot(p.x - nPos.x, p.y - nPos.y);
                    if (dist < bestD) { bestD = dist; br = nr; bc = nc; }
                 }
             }
         });
         targetR = br; targetC = bc;
     }

     while(this.grid.length <= targetR) this.grid.push([]);

     if (p.type === 'bomb') {
        this.fx.createExplosion(p.x, p.y, '#f97316', 30, this.ballRadius);
        for(let ir = targetR-2; ir <= targetR+2; ir++) {
            if(!this.grid[ir]) continue;
            for(let ic = targetC-2; ic <= targetC+2; ic++) {
                if(this.grid[ir][ic]) this.popBalloon(ir, ic);
            }
        }
        this.dropFloaters();
     } else {
        this.grid[targetR][targetC] = { color: p.color, type: 'normal', r: targetR, c: targetC };
        this.checkMatches(targetR, targetC);
     }
  },

  checkMatches(r, c) {
      let startColor = this.grid[r][c].color;
      let matchGroup = [];
      let visited = new Set();
      let q = [{r, c}];
      visited.add(`${r},${c}`);

      while(q.length > 0) {
          let cur = q.shift();
          matchGroup.push(cur);
          this.getNeighbors(cur.r, cur.c).forEach(n => {
              let cell = this.grid[n.r][n.c];
              if (!visited.has(`${n.r},${n.c}`) && cell.type !== 'armored' && cell.color === startColor) {
                  visited.add(`${n.r},${n.c}`); q.push(n);
              }
          });
      }

      if (matchGroup.length >= 3) {
          matchGroup.forEach(m => this.popBalloon(m.r, m.c));
          this.dropFloaters();
      }
  },

  getNeighbors(r, c) {
       let n = [];
       const dirs = r % 2 === 0 ? [[-1,-1], [-1,0], [0,-1], [0,1], [1,-1], [1,0]] : [[-1,0], [-1,1], [0,-1], [0,1], [1,0], [1,1]];
       dirs.forEach(d => {
           let nr = r + d[0], nc = c + d[1];
           if (this.grid[nr] && this.grid[nr][nc]) n.push({r: nr, c: nc});
       });
       return n;
  },

  popBalloon(r, c) {
     let b = this.grid[r][c];
     if(!b) return;
     let pos = this.getGridPos(r, c);
     this.fx.createExplosion(pos.x, pos.y, b.color, 10, this.ballRadius);
     this.score += 10;
     document.getElementById('ingame-score').innerText = this.score;
     this.grid[r][c] = null;
  },

  dropFloaters() {
      let visited = new Set();
      let q = [];
      if(this.grid[0]) {
          for(let c=0; c<this.grid[0].length; c++) {
              if(this.grid[0][c]) { q.push({r:0, c}); visited.add(`0,${c}`); }
          }
      }

      while(q.length > 0) {
          let cur = q.shift();
          this.getNeighbors(cur.r, cur.c).forEach(n => {
              if(!visited.has(`${n.r},${n.c}`)) { visited.add(`${n.r},${n.c}`); q.push(n); }
          });
      }

      for(let r=0; r<this.grid.length; r++) {
          if(!this.grid[r]) continue;
          for(let c=0; c<this.grid[r].length; c++) {
              if(this.grid[r][c] && !visited.has(`${r},${c}`)) {
                  let pos = this.getGridPos(r, c);
                  this.fallingBalloons.push({ x: pos.x, y: pos.y, vx: (Math.random()-0.5)*4, vy: -2, color: this.grid[r][c].color, type: 'normal' });
                  this.grid[r][c] = null;
              }
          }
      }
  },

  drawAimLine() {
    if (this.mouseX === null || this.projectiles.length > 0) return;
    const lx = this.canvas.width / 2, ly = this.canvas.height - 80;
    
    let dy = this.mouseY - ly;
    if (dy >= -10) return;

    let dx = this.mouseX - lx;
    let angle = Math.atan2(dy, dx);
    if (angle > -0.15) angle = -0.15; 
    if (angle < -Math.PI + 0.15) angle = -Math.PI + 0.15;

    let x = lx, y = ly, vx = Math.cos(angle) * 15, vy = Math.sin(angle) * 15;
    
    this.ctx.beginPath();
    this.ctx.moveTo(x, y);

    for(let i=0; i<100; i++) {
        x += vx; y += vy;
        if (x <= this.ballRadius || x >= this.canvas.width - this.ballRadius) vx *= -1;
        this.ctx.lineTo(x, y);
        
        let hit = false;
        for (let r=0; r<this.grid.length; r++) {
            if(!this.grid[r]) continue;
            for (let c=0; c<this.grid[r].length; c++) {
                if(this.grid[r][c] && Math.hypot(x - this.getGridPos(r, c).x, y - this.getGridPos(r, c).y) <= this.ballRadius * 1.8) {
                    hit = true; break;
                }
            }
            if(hit) break;
        }
        if(hit || y < this.offsetY) break;
    }

    this.ctx.strokeStyle = this.activePowerup === 'rocket' ? '#ef4444' : (this.activePowerup === 'bomb' ? '#f97316' : this.launcherColor);
    this.ctx.lineWidth = 4;
    this.ctx.setLineDash([8, 12]);
    this.ctx.lineCap = 'round';
    this.ctx.stroke();
    this.ctx.setLineDash([]);
  },

  update() {
    for (let i = this.projectiles.length - 1; i >= 0; i--) {
      let p = this.projectiles[i];
      p.x += p.vx; p.y += p.vy;

      if (p.x - p.radius < 0) { p.x = p.radius + 0.1; p.vx *= -1; }
      if (p.x + p.radius > this.canvas.width) { p.x = this.canvas.width - p.radius - 0.1; p.vx *= -1; }

      if (p.type === 'rocket') {
         if (p.y < -50 || p.x < -50 || p.x > this.canvas.width + 50) this.projectiles.splice(i, 1);
         for(let r=0; r<this.grid.length; r++) {
            if(!this.grid[r]) continue;
            for(let c=0; c<this.grid[r].length; c++) {
               if(this.grid[r][c] && Math.hypot(p.x - this.getGridPos(r,c).x, p.y - this.getGridPos(r,c).y) < p.radius*2.5) {
                  this.popBalloon(r, c);
               }
            }
         }
         continue;
      }

      let snapped = false;
      if (p.y - p.radius <= this.offsetY) {
        this.snapProjectile(p); snapped = true;
      } else {
         for(let r=0; r<this.grid.length; r++) {
            if(!this.grid[r] || snapped) continue;
            for(let c=0; c<this.grid[r].length; c++) {
               if(this.grid[r][c] && Math.hypot(p.x - this.getGridPos(r, c).x, p.y - this.getGridPos(r, c).y) < p.radius * 1.8) {
                  this.snapProjectile(p); snapped = true; break;
               }
            }
         }
      }
      if (snapped) this.projectiles.splice(i, 1);
    }

    for (let i = this.fallingBalloons.length - 1; i >= 0; i--) {
        let fb = this.fallingBalloons[i];
        fb.vy += 0.5; fb.x += fb.vx; fb.y += fb.vy;
        if (fb.y > this.canvas.height) {
            this.fx.showText("+50", fb.x, this.canvas.height - 20, '#10b981');
            this.score += 50; document.getElementById('ingame-score').innerText = this.score;
            this.fallingBalloons.splice(i, 1);
        }
    }

    this.fx.update();

    if (this.projectiles.length === 0 && this.fallingBalloons.length === 0) {
        let popable = 0, bottomCheck = false;
        for(let r=0; r<this.grid.length; r++) {
            if(!this.grid[r]) continue;
            for(let c=0; c<this.grid[r].length; c++) {
                if(this.grid[r][c]) {
                    popable++;
                    if (this.getGridPos(r, c).y + this.ballRadius > this.limitY) bottomCheck = true;
                }
            }
        }
        if (popable === 0) document.dispatchEvent(new Event('gameWin'));
        else if (bottomCheck) document.dispatchEvent(new Event('gameLoss'));
    }
  },

  loop() {
    if (!this.isPlaying) return;
    this.update();
    
    this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

    for (let r = 0; r < this.grid.length; r++) {
      if (!this.grid[r]) continue;
      for (let c = 0; c < this.grid[r].length; c++) {
        let b = this.grid[r][c];
        if (b) {
          let pos = this.getGridPos(r, c);
          this.drawBubble(pos.x, pos.y, this.ballRadius, b.color, b.type);
        }
      }
    }

    this.drawAimLine();
    
    const lx = this.canvas.width / 2, ly = this.canvas.height - 80;
    this.drawBubble(lx - 70, ly + 10, this.ballRadius * 0.6, this.nextColor, 'normal');
    
    if (this.projectiles.length === 0) {
        if (this.activePowerup === 'rocket') { this.ctx.font = '28px FontAwesome'; this.ctx.fillStyle='#ef4444'; this.ctx.fillText('\uf135', lx, ly); }
        else if (this.activePowerup === 'bomb') { this.ctx.font = '28px FontAwesome'; this.ctx.fillStyle='#f97316'; this.ctx.fillText('\uf1e2', lx, ly); }
        else this.drawBubble(lx, ly, this.ballRadius, this.launcherColor, 'normal');
    }

    this.projectiles.forEach(p => this.drawBubble(p.x, p.y, p.radius, p.color, p.type));
    this.fallingBalloons.forEach(fb => this.drawBubble(fb.x, fb.y, this.ballRadius, fb.color, 'normal'));
    
    this.fx.draw();

    this.ctx.beginPath(); this.ctx.moveTo(0, this.limitY); this.ctx.lineTo(this.canvas.width, this.limitY);
    this.ctx.strokeStyle = 'rgba(239, 68, 68, 0.3)'; this.ctx.stroke();

    requestAnimationFrame(() => this.loop());
  }
};
