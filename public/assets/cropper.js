// Face cropper: drag a square over an image, see a live 512x512 preview on the bot's
// background color, and export it as a PNG. Pixel art is scaled with nearest-neighbor.
(function () {
  'use strict';

  class Cropper {
    constructor(srcCanvas, outCanvas, swatchesEl, onPickColor) {
      this.src = srcCanvas;
      this.out = outCanvas;
      this.swatchesEl = swatchesEl;
      this.onPickColor = onPickColor;
      this.img = null;
      this.background = '#1b2440';
      this.evenPixels = true;
      this.drag = null;
      this.src.width = this.src.height = 480;
      this.out.width = this.out.height = 512;
      this.bindPointer();
    }

    // box: a saved crop {x, y, s, even}, 'full' to keep the whole image, or nothing for a fresh centered crop.
    load(url, box) {
      return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
          this.img = img;
          this.w = img.naturalWidth || 512;
          this.h = img.naturalHeight || 512;
          this.pixelated = Math.max(this.w, this.h) <= 128;
          this.pad = Math.round(Math.max(this.w, this.h) * 0.125); // room to crop past the edges
          if (box && typeof box === 'object') {
            this.box = { x: Number(box.x) || 0, y: Number(box.y) || 0, s: Number(box.s) || this.w };
            if (typeof box.even === 'boolean') this.evenPixels = box.even;
          } else if (box === 'full') {
            const s = Math.max(this.w, this.h);
            this.box = { x: Math.round((this.w - s) / 2), y: Math.round((this.h - s) / 2), s };
          } else {
            const size = Math.round(Math.min(this.w, this.h) * (this.pixelated ? 0.75 : 0.8));
            this.box = { x: Math.round((this.w - size) / 2), y: Math.round((this.h - size) / 2), s: size };
          }
          this.renderSwatches();
          this.draw();
          resolve();
        };
        img.onerror = () => reject(new Error('Could not load that image.'));
        img.src = url;
      });
    }

    setBackground(color) { this.background = color; this.draw(); }
    setEvenPixels(on) { this.evenPixels = on; this.draw(); }

    // Display mapping: image (plus padding) fits in the 480px canvas.
    get scale() { return this.src.width / (Math.max(this.w, this.h) + this.pad * 2); }
    toPx(v) { return (v + this.pad) * this.scale; }
    fromPx(p) { return Math.round(p / this.scale - this.pad); }

    clamp() {
      const min = -this.pad, maxW = this.w + this.pad, maxH = this.h + this.pad;
      const minSize = Math.max(4, Math.round(Math.min(this.w, this.h) * 0.1));
      this.box.s = Math.max(minSize, Math.min(this.box.s, maxW - min, maxH - min));
      this.box.x = Math.max(min, Math.min(this.box.x, maxW - this.box.s));
      this.box.y = Math.max(min, Math.min(this.box.y, maxH - this.box.s));
    }

    draw() {
      if (!this.img) return;
      this.clamp();
      const c = this.src.getContext('2d');
      c.imageSmoothingEnabled = !this.pixelated;
      c.clearRect(0, 0, this.src.width, this.src.height);
      c.drawImage(this.img, this.toPx(0), this.toPx(0), this.w * this.scale, this.h * this.scale);
      const bx = this.toPx(this.box.x), by = this.toPx(this.box.y), bs = this.box.s * this.scale;
      c.fillStyle = 'rgba(0,0,0,.5)';
      c.beginPath(); c.rect(0, 0, this.src.width, this.src.height); c.rect(bx, by, bs, bs); c.fill('evenodd');
      c.strokeStyle = '#45d68a'; c.lineWidth = 2; c.strokeRect(bx + 1, by + 1, bs - 2, bs - 2);
      c.fillStyle = '#45d68a'; c.fillRect(bx + bs - 12, by + bs - 12, 12, 12);
      this.render();
    }

    render() {
      const o = this.out.getContext('2d');
      o.fillStyle = this.background;
      o.fillRect(0, 0, 512, 512);
      const even = this.pixelated && this.evenPixels;
      const k = even ? Math.floor(512 / this.box.s) : 512 / this.box.s;
      const size = this.box.s * k, off = (512 - size) / 2;
      o.imageSmoothingEnabled = !this.pixelated;
      o.imageSmoothingQuality = 'high';
      // drawImage can't take a source rect outside the image, so draw the whole image offset instead.
      o.save();
      o.beginPath(); o.rect(off, off, size, size); o.clip();
      o.drawImage(this.img, off - this.box.x * k, off - this.box.y * k, this.w * k, this.h * k);
      o.restore();
      if (this.onRender) this.onRender();
    }

    toDataURL() { return this.img ? this.out.toDataURL('image/png') : null; }
    getCrop() { return this.img ? { x: this.box.x, y: this.box.y, s: this.box.s, even: this.evenPixels } : null; }

    // Most common colors in the image, for picking a background that matches.
    renderSwatches() {
      if (!this.swatchesEl) return;
      const n = 64, c = document.createElement('canvas');
      c.width = c.height = n;
      const x = c.getContext('2d');
      x.drawImage(this.img, 0, 0, n, n);
      const d = x.getImageData(0, 0, n, n).data, count = {};
      for (let i = 0; i < d.length; i += 4) {
        if (d[i + 3] < 200) continue;
        const q = (v) => Math.min(255, Math.round(v / 24) * 24);
        const hex = '#' + [q(d[i]), q(d[i + 1]), q(d[i + 2])].map((v) => v.toString(16).padStart(2, '0')).join('');
        count[hex] = (count[hex] || 0) + 1;
      }
      this.swatchesEl.innerHTML = '';
      // White (and near-white) isn't offered as a background color.
      const isWhite = (hex) => [1, 3, 5].every((i) => parseInt(hex.slice(i, i + 2), 16) >= 225);
      this.palette = Object.keys(count).filter((hex) => !isWhite(hex)).sort((a, b) => count[b] - count[a]).slice(0, 18);
      this.palette.forEach((hex) => {
        const b = document.createElement('button');
        b.type = 'button'; b.title = hex; b.style.background = hex;
        b.onclick = () => this.onPickColor && this.onPickColor(hex);
        this.swatchesEl.appendChild(b);
      });
      if (this.onPalette) this.onPalette(this.palette); // other color pickers can offer the same colors
    }

    bindPointer() {
      const pos = (e) => {
        const r = this.src.getBoundingClientRect();
        return { x: (e.clientX - r.left) * this.src.width / r.width, y: (e.clientY - r.top) * this.src.height / r.height };
      };
      this.src.addEventListener('pointerdown', (e) => {
        if (!this.img) return;
        const p = pos(e), bx = this.toPx(this.box.x), by = this.toPx(this.box.y), bs = this.box.s * this.scale;
        this.src.setPointerCapture(e.pointerId);
        if (p.x > bx + bs - 20 && p.x < bx + bs + 8 && p.y > by + bs - 20 && p.y < by + bs + 8) this.drag = { mode: 'resize' };
        else if (p.x > bx && p.x < bx + bs && p.y > by && p.y < by + bs) this.drag = { mode: 'move', dx: p.x - bx, dy: p.y - by };
        else this.drag = { mode: 'new', sx: this.fromPx(p.x), sy: this.fromPx(p.y) };
      });
      this.src.addEventListener('pointermove', (e) => {
        if (!this.drag) return;
        const p = pos(e);
        if (this.drag.mode === 'move') {
          this.box.x = this.fromPx(p.x - this.drag.dx); this.box.y = this.fromPx(p.y - this.drag.dy);
        } else if (this.drag.mode === 'resize') {
          this.box.s = Math.max(this.fromPx(p.x) - this.box.x, this.fromPx(p.y) - this.box.y);
        } else {
          const fx = this.fromPx(p.x), fy = this.fromPx(p.y);
          const s = Math.max(Math.abs(fx - this.drag.sx), Math.abs(fy - this.drag.sy));
          if (s >= 4) this.box = { x: fx < this.drag.sx ? this.drag.sx - s : this.drag.sx, y: fy < this.drag.sy ? this.drag.sy - s : this.drag.sy, s };
        }
        this.draw();
      });
      const end = () => { this.drag = null; };
      this.src.addEventListener('pointerup', end);
      this.src.addEventListener('pointercancel', end);
      this.src.tabIndex = 0;
      this.src.addEventListener('keydown', (e) => {
        if (!this.img) return;
        const step = this.pixelated ? 1 : Math.max(1, Math.round(this.w / 100));
        const m = { ArrowLeft: [-step, 0, 0], ArrowRight: [step, 0, 0], ArrowUp: [0, -step, 0], ArrowDown: [0, step, 0], '+': [0, 0, step], '=': [0, 0, step], '-': [0, 0, -step] }[e.key];
        if (!m) return;
        e.preventDefault();
        this.box.x += m[0]; this.box.y += m[1]; this.box.s += m[2];
        this.draw();
      });
    }
  }

  window.Cropper = Cropper;
})();
