// effects.js
class FXEngine {
  constructor(canvas, ctx) {
    this.canvas = canvas;
    this.ctx = ctx;
    this.particles = [];
    this.floatingTexts = [];
  }

  createExplosion(x, y, color, amount = 15, radiusBase = 10) {
    for (let i = 0; i < amount; i++) {
      this.particles.push({
        x: x, y: y,
        vx: (Math.random() - 0.5) * 16,
        vy: (Math.random() - 0.5) * 16,
        life: 1, color: color,
        radius: radiusBase
      });
    }
  }

  showText(text, x, y, color = '#fff') {
    this.floatingTexts.push({ text, x, y, life: 1, color });
  }

  update() {
    for (let i = this.particles.length - 1; i >= 0; i--) {
      let p = this.particles[i];
      p.x += p.vx; p.y += p.vy;
      p.vy += 0.3; p.life -= 0.04;
      if (p.life <= 0) this.particles.splice(i, 1);
    }
    for (let i = this.floatingTexts.length - 1; i >= 0; i--) {
      let ft = this.floatingTexts[i];
      ft.y -= 1.5; ft.life -= 0.03;
      if (ft.life <= 0) this.floatingTexts.splice(i, 1);
    }
  }

  draw() {
    this.particles.forEach(p => {
      let alpha = Math.max(0, p.life);
      this.ctx.globalAlpha = alpha;
      this.ctx.fillStyle = p.color;
      this.ctx.beginPath(); this.ctx.arc(p.x, p.y, p.radius * 0.4 * alpha, 0, Math.PI * 2); this.ctx.fill();
      
      this.ctx.fillStyle = `rgba(255, 255, 255, ${alpha * 0.7})`;
      this.ctx.beginPath(); this.ctx.arc(p.x, p.y, p.radius * 0.2 * alpha, 0, Math.PI * 2); this.ctx.fill();
    });
    
    this.ctx.globalAlpha = 1.0;
    this.ctx.textAlign = 'center';
    this.ctx.font = '900 18px "Outfit"';
    
    this.floatingTexts.forEach(ft => {
      this.ctx.globalAlpha = Math.max(0, ft.life);
      this.ctx.fillStyle = ft.color;
      this.ctx.shadowColor = '#000';
      this.ctx.shadowBlur = 6;
      this.ctx.fillText(ft.text, ft.x, ft.y);
    });
    
    this.ctx.globalAlpha = 1.0;
    this.ctx.shadowBlur = 0;
  }
}
