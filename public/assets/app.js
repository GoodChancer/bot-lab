// Bot Lab: a small single-page app over api.php. No build step.
(function () {
  'use strict';

  const $ = (sel, el = document) => el.querySelector(sel);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const app = $('#app');
  let csrf = $('meta[name=csrf]').content;
  let state = null;

  // ---- Helpers ----------------------------------------------------------------------------

  async function api(action, body) {
    const res = await fetch('api.php?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body || {}),
    });
    let data = {};
    try { data = await res.json(); } catch (e) { /* not JSON */ }
    if (!res.ok) throw new Error(data.error || 'Request failed (' + res.status + ').');
    return data;
  }

  let toastTimer;
  function toast(msg, bad) {
    const t = $('#toast');
    t.textContent = msg;
    t.className = 'show' + (bad ? ' bad' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.className = ''; }, bad ? 9000 : 5000);
  }

  // Runs an async action with a spinner on the button and an error toast on failure.
  async function busy(btn, label, fn) {
    const old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> ' + esc(label);
    try { return await fn(); }
    catch (e) { toast(e.message, true); }
    finally { btn.disabled = false; btn.innerHTML = old; }
  }

  // Line icons from Lucide (https://lucide.dev, ISC license).
  const ICONS = {
    eye: '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    sparkles: '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>',
    palette: '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5 5.5 5.5 0 0 1-5.5 5.5H11"/>',
    send: '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.497.497 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    chat: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
  };
  const icon = (name, label) => `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ${label ? `role="img" aria-label="${label}"><title>${label}</title>` : 'aria-hidden="true">'}${ICONS[name]}</svg>`;

  const avatarUrl = (b) => b.has_avatar ? 'api.php?action=avatar&id=' + b.id + '&v=' + encodeURIComponent(b.updated_at) : 'assets/logo.png';
  const statusLabel = { draft: 'Draft', created: 'Not installed', installed: 'Live in Slack' };
  const providerLabel = (id) => ({ anthropic: 'Claude', openai: 'OpenAI' }[id] || id);

  async function loadState() { state = await api('state'); }

  // ---- Router -----------------------------------------------------------------------------

  async function route() {
    if (!csrf) return; // signed out: login form is showing
    const [path, query] = location.hash.replace(/^#/, '').split('?');
    const notice = new URLSearchParams(query || '').get('notice');
    if (notice) {
      toast(notice, /fail|cancel/i.test(notice));
      history.replaceState(null, '', '#' + path);
    }
    try { await loadState(); } catch (e) { app.innerHTML = '<div class="card"><p class="error">' + esc(e.message) + '</p></div>'; return; }
    // The studio was updated while this tab was open: reload so it doesn't run old code.
    if (state.version && state.version !== window.BOTLAB_VERSION) {
      // A new address makes the browser fetch the page fresh instead of reusing its saved copy.
      let tried = null;
      try { tried = sessionStorage.getItem('botlab-reloaded'); sessionStorage.setItem('botlab-reloaded', state.version); } catch (e) { /* storage blocked */ }
      if (tried !== state.version) { location.replace(location.pathname + '?v=' + encodeURIComponent(state.version) + location.hash); return; }
    }
    const parts = (path || '/').split('/').filter(Boolean);
    window.scrollTo(0, 0);
    if (parts[0] === 'new') return editor(null);
    if (parts[0] === 'bot') return editor(state.bots.find((b) => b.id === Number(parts[1])) || null);
    if (parts[0] === 'settings') return settings();
    if (parts[0] === 'import') return importer();
    return dashboard();
  }

  // ---- Dashboard --------------------------------------------------------------------------

  function dashboard() {
    const banners = [];
    if (!state.providers.length) banners.push('<div class="banner"><b>No AI keys yet.</b> Add an Anthropic or OpenAI key to <code>config.php</code> (run <code>php bin/setup.php</code>).</div>');
    if (!state.slack_ready) banners.push('<div class="banner"><b>Connect Slack</b> so the studio can create apps for you. <a href="#/settings">Set it up in Settings</a>.</div>');
    app.innerHTML = `
      <div class="stack">
        <div class="row spread"><h1>Your bots</h1><a class="btn primary" href="#/new">+ New bot</a></div>
        ${banners.join('')}
        <div class="bots">
          ${state.bots.map((b) => {
            // Looks like the bot's own Slack profile card: banner in its card color, avatar overlapping it.
            const color = /^#[0-9a-f]{6}$/i.test(b.background_color) ? b.background_color : '#1b2440';
            const abilities = [
              b.can_see_images && ['eye', 'Sees images'],
              b.can_read_links && ['link', 'Reads links'],
              b.can_search_web && ['search', 'Searches the web'],
            ].filter(Boolean);
            return `
            <a class="bot-card" href="#/bot/${b.id}">
              <div class="bot-banner" style="background:${color}">
                ${abilities.length ? `<span class="bot-abilities">${abilities.map(([name, label]) => icon(name, label)).join('')}</span>` : ''}
              </div>
              <img class="bot-avatar ${b.avatar_style === 'pixel' ? 'pixel' : ''}" src="${avatarUrl(b)}" alt="">
              <div class="bot-body">
                <h3>${esc(b.name)}</h3>
                <div class="bot-meta"><span class="dot ${esc(b.status)}"></span>${statusLabel[b.status] || esc(b.status)} · ${providerLabel(b.provider)}</div>
                <p class="bot-desc">${esc(b.short_description) || '<span class="muted">No description yet</span>'}</p>
              </div>
            </a>`;
          }).join('')}
          <a class="bot-card new-bot" href="#/new"><span>+ Design a new bot</span></a>
        </div>
        <p class="muted small">Already made a Slack app by hand? <a href="#/import">Import it</a>.</p>
      </div>`;
  }

  // ---- Bot editor (new and existing) ------------------------------------------------------

  function editor(existing) {
    const defaults = { name: '', personality: '', short_description: '', long_description: '', background_color: '#1b2440',
      avatar_description: '', avatar_style: 'pixel', provider: (state.providers[0] || {}).id || 'anthropic', model: '',
      can_see_images: false, can_read_links: false, can_search_web: false, icon_background: '' };
    const b = Object.assign({}, defaults, existing || {});
    let avatarChanged = false;
    let chat = [];
    const hasProvider = (id) => state.providers.some((p) => p.id === id);

    app.innerHTML = `
      <div class="stack">
        <div class="row spread">
          <h1>${existing ? esc(existing.name) : 'New bot'}</h1>
          ${existing ? `<span class="badge ${esc(b.status)}">${statusLabel[b.status]}</span>` : ''}
        </div>

        <section class="card" id="start">
          <h2 class="title-icon">${existing ? `${icon('sparkles')} Write with AI` : '1. Start with a role'}</h2>
          <p class="muted">${existing
            ? 'Tell the AI what to change about the name, instructions or descriptions, or describe a role to start over.'
            : 'Describe what you want and AI will fill in everything below. You can edit it all afterwards, or skip this and write it yourself.'}</p>
          <div class="field" id="refine" ${existing ? '' : 'hidden'}>
            <label>What should change?</label>
            <div class="row">
              <input type="text" id="refine-input" style="flex:1" placeholder="e.g. Make the instructions shorter and more sarcastic, and mention it reviews pull requests">
              <button class="btn primary" id="refine-btn" type="button">Apply</button>
            </div>
            <div class="chips" style="margin-top:8px">
              ${['Shorter', 'Funnier', 'More professional', 'More specific instructions', 'Warmer tone', 'Different name'].map((t) => `<button type="button" class="chip quick" data-ask="${esc(t)}">${esc(t)}</button>`).join('')}
            </div>
          </div>
          <div class="field">
            ${existing ? '<label>Or start over from a role</label>' : ''}
            <textarea id="role" placeholder="e.g. A blunt critic who reviews my writing and tells me what's weak, but always says how to fix it."></textarea>
          </div>
          <div class="row" style="margin-top:12px">
            <select id="gen-provider" style="width:auto" title="Who writes it">${state.providers.map((p) => `<option value="${p.id}">${esc(p.label)}</option>`).join('')}</select>
            <button class="btn ${existing ? '' : 'primary'}" id="gen-profile" type="button">${existing ? 'Write from role' : `${icon('sparkles')} Generate bot`}</button>
            <button class="btn" id="undo-profile" type="button" hidden>${icon('undo')} Undo</button>
          </div>
        </section>

        <section class="card">
          <h2>${existing ? 'Identity' : '2. Identity'}</h2>
          <div class="grid2">
            <div class="field"><label>Name <span class="count" data-for="name"></span></label><input type="text" id="name" maxlength="35"></div>
            <div class="field"><label>Short description <span class="count" data-for="short_description"></span></label><input type="text" id="short_description" maxlength="139"></div>
          </div>
          <div class="field"><label>Personality (system prompt)</label><textarea id="personality" class="tall" placeholder="You are ..."></textarea></div>
          <div class="field"><label>Long description <span class="count" data-for="long_description"></span></label><textarea id="long_description"></textarea></div>
          <div class="card-row">
            <div class="field">
              <label>Slack card color</label>
              <p class="muted small" style="margin:0 0 10px">The banner on your bot's profile card in Slack. Its name and description sit on top in white.</p>
              <div class="row color-row"><label class="swatch-input" id="card-swatch" title="Pick a color"><input type="color" id="card-color" aria-label="Slack card color"></label><input type="text" class="hex" id="card-hex" maxlength="7" aria-label="Slack card color hex"></div>
              <p class="error small" id="card-warn" hidden></p>
            </div>
            <div class="slack-card" id="card-preview">
              <canvas id="card-avatar" width="112" height="112"></canvas>
              <div><span class="card-name" id="card-name"></span> <span class="card-tag">APP</span><div class="card-desc" id="card-desc"></div></div>
            </div>
          </div>
        </section>

        <section class="card">
          <h2>${existing ? 'Avatar' : '3. Avatar'}</h2>
          <div class="field"><label>Style</label>
            <div class="chips" id="styles">${state.styles.map((s) => `<button type="button" class="chip" data-style="${s.id}" ${s.needs_openai && !hasProvider('openai') ? 'disabled title="Needs an OpenAI API key"' : ''}>${esc(s.label)}</button>`).join('')}</div>
          </div>
          <div class="field"><label>What the character looks like</label><textarea id="avatar_description" placeholder="e.g. A grumpy white duck with a tiny monocle and a green scarf."></textarea></div>
          <div class="row" style="margin:12px 0 18px">
            <button class="btn primary" type="button" id="gen-avatar">${icon('palette')} Generate avatar</button>
            <select id="art-provider" style="width:auto" title="Who draws it"></select>
            <label class="btn" style="margin:0">${icon('upload')} Upload image<input type="file" id="upload" accept="image/*" hidden></label>
          </div>
          <div class="cropper">
            <div>
              <canvas class="crop-src" id="crop-src" hidden></canvas>
              <div class="empty-art" id="crop-empty">Generate or upload an image, then drag a square around the face.</div>
              <p class="muted small">Drag to draw a square, drag inside to move it, drag the corner to resize. Arrow keys nudge; + and − resize.</p>
            </div>
            <div>
              <canvas class="crop-out" id="crop-out"></canvas>
              <label style="margin-top:12px">Icon background</label>
              <div class="row color-row"><label class="swatch-input" id="icon-swatch" title="Pick a color"><input type="color" id="icon-color" aria-label="Icon background"></label><input type="text" class="hex" id="icon-hex" maxlength="7" aria-label="Icon background hex"></div>
              <p class="muted small" style="margin:6px 0 0">Or pick a color from the art:</p>
              <div class="swatches" id="swatches"></div>
              <label class="small" style="font-weight:400;margin-top:10px"><input type="checkbox" id="even" checked> Even pixel sizes (pixel art)</label>
            </div>
          </div>
        </section>

        <section class="card">
          <h2>${existing ? 'Brain' : '4. Brain'}</h2>
          <div class="grid2">
            <div class="field"><label>AI provider</label>
              <select id="provider">${['anthropic', 'openai'].map((id) => `<option value="${id}" ${hasProvider(id) ? '' : 'disabled'}>${providerLabel(id)}${hasProvider(id) ? '' : ' (no key)'}</option>`).join('')}</select></div>
            <div class="field"><label>Model <span class="count">blank = default</span></label><input type="text" id="model" placeholder=""></div>
          </div>
          <div class="field"><label>Abilities</label>
            <div class="abilities">
              <label class="ability"><input type="checkbox" data-flag="can_see_images"> ${icon('eye')} <span><b>Can see images</b><br><span class="muted small">Looks at pictures people share. Adds Slack's file permission (one-click reinstall).</span></span></label>
              <label class="ability" data-claude-only><input type="checkbox" data-flag="can_read_links"> ${icon('link')} <span><b>Can read links</b><br><span class="muted small">Opens web pages pasted into the chat. Claude only.</span></span></label>
              <label class="ability" data-claude-only><input type="checkbox" data-flag="can_search_web"> ${icon('search')} <span><b>Can search the web</b><br><span class="muted small">Looks things up when it helps. Claude only.</span></span></label>
            </div>
          </div>
          <div class="field">
            <label>Try it</label>
            <div class="chat-window">
              <div class="chat-head">${icon('chat')}<span><b>Test chat</b> <span class="muted">· nothing is saved or sent to Slack</span></span><button class="btn small" id="chat-clear" type="button" hidden>Clear</button></div>
              <div class="chat" id="chat"></div>
              <form class="chat-compose" id="chat-form">
                <input type="text" id="chat-input" placeholder="Message your bot…" autocomplete="off">
                <button class="btn primary icon-btn" type="submit" aria-label="Send">${icon('send')}</button>
              </form>
            </div>
          </div>
        </section>

        <section class="card stack" id="slack-card"></section>
      </div>`;

    // Fill fields
    const fields = ['name', 'short_description', 'long_description', 'personality', 'avatar_description', 'provider', 'model'];
    fields.forEach((f) => { $('#' + f).value = b[f] || ''; });
    // Two different colors: the Slack card banner (white text on top) and the icon's own background.
    const iconBg = () => b.icon_background || b.background_color;
    const setCard = (hex) => {
      b.background_color = hex; $('#card-color').value = hex; $('#card-hex').value = hex;
      $('#card-swatch').style.background = hex;
      updateCardPreview();
    };
    const setIconBg = (hex, byUser) => {
      b.icon_background = hex; $('#icon-color').value = hex; $('#icon-hex').value = hex;
      $('#icon-swatch').style.background = hex;
      cropper.setBackground(hex);
      if (byUser && cropper.img) avatarChanged = true;
    };
    // Contrast of white text on the card color (WCAG); under 3 is hard to read.
    const whiteContrast = (hex) => {
      const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
      const [r, g, bl] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
      return 1.05 / (0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(bl) + 0.05);
    };
    function updateCardPreview() {
      $('#card-preview').style.background = b.background_color;
      $('#card-name').textContent = b.name || 'Bot name';
      $('#card-desc').textContent = b.short_description || 'Short description';
      const c = $('#card-avatar').getContext('2d');
      c.clearRect(0, 0, 112, 112);
      if (typeof cropper !== 'undefined' && cropper.img) c.drawImage($('#crop-out'), 0, 0, 112, 112);
      const warn = whiteContrast(b.background_color) < 3;
      $('#card-warn').textContent = warn ? 'White text is hard to read on this color. Slack shows the name and description in white, so pick a darker color.' : '';
      $('#card-warn').hidden = !warn;
    }
    const counters = { name: [1, 35], short_description: [1, 139], long_description: [174, 4000] };
    const updateCounts = () => Object.entries(counters).forEach(([f, [min, max]]) => {
      const n = $('#' + f).value.length, el = $(`.count[data-for=${f}]`);
      el.textContent = n + (f === 'long_description' ? ` (${min}–${max})` : ` / ${max}`);
      el.classList.toggle('bad', n > max || (f === 'long_description' && n > 0 && n < min));
    });
    fields.forEach((f) => $('#' + f).addEventListener('input', () => { b[f] = $('#' + f).value; updateCounts(); if (f === 'name' || f === 'short_description') updateCardPreview(); }));
    const defaultModel = () => ((state.providers.find((p) => p.id === $('#provider').value) || {}).chat_model || '');
    // Abilities: links and search are Claude-only for now.
    document.querySelectorAll('[data-flag]').forEach((cb) => {
      cb.checked = !!b[cb.dataset.flag];
      cb.addEventListener('change', () => { b[cb.dataset.flag] = cb.checked; });
    });
    const syncAbilities = () => {
      const claude = $('#provider').value === 'anthropic';
      document.querySelectorAll('[data-claude-only]').forEach((el) => {
        const cb = $('input', el);
        cb.disabled = !claude;
        el.classList.toggle('off', !claude);
        if (!claude && cb.checked) { cb.checked = false; b[cb.dataset.flag] = false; }
      });
    };
    $('#provider').addEventListener('change', () => { $('#model').placeholder = defaultModel(); syncAbilities(); });
    $('#model').placeholder = defaultModel();
    syncAbilities();

    // Cropper
    const cropper = new window.Cropper($('#crop-src'), $('#crop-out'), $('#swatches'), (hex) => setIconBg(hex, true));
    cropper.onRender = updateCardPreview;
    setIconBg(iconBg(), false);
    setCard(b.background_color);
    updateCounts();
    const isHex = (v) => /^#[0-9a-f]{6}$/i.test(v);
    $('#card-color').addEventListener('input', (e) => setCard(e.target.value));
    $('#card-hex').addEventListener('change', (e) => { if (isHex(e.target.value)) setCard(e.target.value.toLowerCase()); });
    $('#icon-color').addEventListener('input', (e) => setIconBg(e.target.value, true));
    $('#icon-hex').addEventListener('change', (e) => { if (isHex(e.target.value)) setIconBg(e.target.value.toLowerCase(), true); });
    $('#even').addEventListener('change', (e) => { cropper.setEvenPixels(e.target.checked); if (cropper.img) avatarChanged = true; });
    // The original image is saved with the crop square, so reopening a bot shows your crop exactly and you can recrop from the original.
    let newSource = null; // data URL of a newly generated or uploaded original, sent on the next save
    const showImage = async (url, box) => {
      await cropper.load(url, box);
      $('#even').checked = cropper.evenPixels;
      $('#crop-src').hidden = false; $('#crop-empty').hidden = true;
    };
    const useNewImage = async (dataUrl) => {
      await showImage(dataUrl);
      newSource = dataUrl;
      avatarChanged = true;
    };
    $('#crop-src').addEventListener('pointerup', () => { if (cropper.img) avatarChanged = true; });
    $('#crop-src').addEventListener('keydown', () => { if (cropper.img) avatarChanged = true; });
    if (existing && existing.has_avatar_source) {
      showImage('api.php?action=avatar_source&id=' + existing.id + '&v=' + encodeURIComponent(existing.updated_at), existing.avatar_crop || 'full');
    } else if (existing && existing.has_avatar) {
      // Older bots only have the finished icon: show it whole, and keep it as the original if you recrop.
      fetch(avatarUrl(existing)).then((r) => r.blob()).then((blob) => new Promise((ok) => {
        const reader = new FileReader();
        reader.onload = () => ok(reader.result);
        reader.readAsDataURL(blob);
      })).then(async (dataUrl) => { await showImage(dataUrl, 'full'); newSource = dataUrl; }).catch(() => {});
    }

    // Styles + who draws
    const renderArtProviders = () => {
      const style = state.styles.find((s) => s.id === b.avatar_style) || state.styles[0];
      const opts = [];
      if (!style.needs_openai && hasProvider('anthropic')) opts.push(['anthropic', style.id === 'pixel' ? 'Claude draws a pixel grid' : 'Claude draws SVG']);
      if (hasProvider('openai')) opts.push(['openai', 'OpenAI image model']);
      $('#art-provider').innerHTML = opts.map(([v, l]) => `<option value="${v}">${l}</option>`).join('');
      $('#gen-avatar').disabled = !opts.length;
    };
    const pickStyle = (id) => {
      b.avatar_style = id;
      document.querySelectorAll('#styles .chip').forEach((c) => c.classList.toggle('on', c.dataset.style === id));
      renderArtProviders();
    };
    document.querySelectorAll('#styles .chip').forEach((c) => c.addEventListener('click', () => pickStyle(c.dataset.style)));
    pickStyle(b.avatar_style || 'pixel');

    // Write with AI: generate from a role, tweak with a request, undo the last change.
    const PROFILE_FIELDS = ['name', 'short_description', 'long_description', 'personality', 'avatar_description', 'background_color'];
    let undoStack = [];
    const applyProfile = (profile) => {
      undoStack.push(Object.assign(Object.fromEntries(PROFILE_FIELDS.map((f) => [f, b[f]])), { icon_background: b.icon_background }));
      PROFILE_FIELDS.forEach((f) => {
        if (f === 'background_color') {
          setCard(profile[f]);
          if (!cropper.img) setIconBg(profile[f], false); // no art yet: start the icon on the same color
          return;
        }
        b[f] = profile[f]; $('#' + f).value = profile[f];
      });
      updateCardPreview();
      updateCounts();
      $('#undo-profile').hidden = false;
      $('#refine').hidden = false;
    };
    $('#undo-profile').addEventListener('click', () => {
      const prev = undoStack.pop();
      if (!prev) return;
      PROFILE_FIELDS.forEach((f) => {
        if (f === 'background_color') { setCard(prev[f]); return; }
        b[f] = prev[f]; $('#' + f).value = prev[f];
      });
      setIconBg(prev.icon_background, false);
      updateCounts();
      updateCardPreview();
      $('#undo-profile').hidden = !undoStack.length;
      toast('Undone.');
    });
    $('#gen-profile').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      let done = false;
      await busy(btn, 'Writing…', async () => {
        if (!$('#role').value.trim()) throw new Error('Describe the role first.');
        const { profile } = await api('generate_profile', { role: $('#role').value, provider: $('#gen-provider').value });
        applyProfile(profile);
        done = true;
        toast('Done. Not quite right? Say what to change, or edit anything by hand.');
      });
      if (done) btn.innerHTML = `${icon('sparkles')} Regenerate`; // after busy() restores the old label
    });
    const refine = (request, btn) => busy(btn, 'Rewriting…', async () => {
      if (!request.trim()) throw new Error('Say what to change first.');
      const { profile } = await api('refine_profile', { request, bot: collect(), provider: $('#gen-provider').value });
      applyProfile(profile);
      $('#refine-input').value = '';
      toast('Updated. Use Undo if you liked it better before.');
    });
    $('#refine-btn').addEventListener('click', (e) => refine($('#refine-input').value, e.currentTarget));
    $('#refine-input').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); refine($('#refine-input').value, $('#refine-btn')); } });
    document.querySelectorAll('.quick').forEach((chip) => chip.addEventListener('click', (e) => refine(chip.dataset.ask, e.currentTarget)));

    // Generate avatar
    $('#gen-avatar').addEventListener('click', (e) => busy(e.currentTarget, 'Drawing… (up to a minute)', async () => {
      const { image } = await api('generate_avatar', { style: b.avatar_style, description: $('#avatar_description').value, background: iconBg(), prefer: $('#art-provider').value });
      await useNewImage('data:' + image.mime + ';base64,' + image.data);
    }));

    // Upload
    $('#upload').addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (!file) return;
      if (file.size > 10 * 1024 * 1024) { toast('That image is over 10 MB. Try a smaller one.', true); return; }
      const reader = new FileReader();
      reader.onload = () => useNewImage(reader.result).catch((err) => toast(err.message, true));
      reader.readAsDataURL(file);
    });

    // Chat preview
    $('#chat-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = $('#chat-input').value.trim();
      if (!text) return;
      $('#chat-input').value = '';
      chat.push({ role: 'user', content: text });
      renderChat(true);
      try {
        const { reply } = await api('preview_chat', { bot: collect(), messages: chat });
        chat.push({ role: 'assistant', content: reply });
      } catch (err) { chat.pop(); toast(err.message, true); }
      renderChat(false);
    });
    const renderChat = (thinking) => {
      const box = $('#chat');
      if (!chat.length && !thinking) {
        box.innerHTML = `<div class="chat-empty">Say hi to see how ${esc(b.name || 'your bot')} replies.<br>Try the kind of message you'd send it in Slack.</div>`;
      } else {
        box.innerHTML = chat.map((m) => `<div class="msg ${m.role}">${esc(m.content)}</div>`).join('') + (thinking ? '<div class="msg assistant typing" aria-label="Writing a reply"><span></span><span></span><span></span></div>' : '');
      }
      $('#chat-clear').hidden = !chat.length;
      box.scrollTop = box.scrollHeight;
    };
    $('#chat-clear').addEventListener('click', () => { chat = []; renderChat(false); });
    renderChat(false);

    const collect = () => {
      const out = {};
      Object.keys(defaults).forEach((k) => { out[k] = b[k]; });
      return out;
    };

    // Save / Slack actions
    async function save(sync) {
      const body = { id: existing ? existing.id : null, bot: collect(), sync };
      if (avatarChanged) {
        body.avatar = cropper.toDataURL();
        body.avatar_crop = cropper.getCrop();
        if (newSource) body.avatar_source = newSource;
      }
      const res = await api('save_bot', body);
      avatarChanged = false;
      newSource = null;
      if (res.notice) toast(res.notice, true);
      return res.bot;
    }

    const renderSlack = () => {
      const card = $('#slack-card');
      const status = existing ? existing.status : 'draft';
      const needsIcon = existing && existing.app_id && existing.has_avatar && !Number(existing.icon_synced);
      let actions = '';
      if (status === 'draft') {
        actions = `<button class="btn" id="save" type="button">Save draft</button>
          <button class="btn primary" id="create" type="button" ${state.slack_ready ? '' : 'disabled'}>Save and create in Slack</button>
          ${state.slack_ready ? '' : '<span class="muted small"><a href="#/settings">Connect Slack</a> first.</span>'}`;
      } else if (status === 'created') {
        actions = `<button class="btn" id="save" type="button">Save</button><button class="btn primary" id="install" type="button">Install to Slack</button>`;
      } else {
        actions = `<button class="btn primary" id="save" type="button">Save${state.slack_ready && existing.app_id ? ' and update Slack' : ''}</button>`;
      }
      card.innerHTML = `
        <h2>${existing ? 'Slack' : '5. Put it in Slack'}</h2>
        <p class="muted">${{
          draft: 'Creating the app sets up its name, descriptions, icon, permissions and message address in Slack. Then you approve the install with one click.',
          created: 'The Slack app exists but isn\'t installed in your workspace yet.',
          installed: 'This bot is live. DM it in Slack, or invite it to a channel and @mention it.',
        }[status]}</p>
        ${existing && status === 'installed' && !existing.app_id ? `<form class="banner row" id="app-id-form"><span style="flex:1">To update this app's name, descriptions and icon from here, link its <b>App ID</b> (shown on the app's page at <a href="https://api.slack.com/apps" target="_blank" rel="noopener">api.slack.com/apps</a>).</span><input type="text" id="app-id" placeholder="A0123ABCDEF" style="width:160px"><button class="btn small" type="submit">Link</button></form>` : ''}
        ${existing && existing.needs_reinstall && existing.can_reinstall_here ? `<div class="banner row"><span style="flex:1">To see images, Slack needs you to approve one more permission (reading shared files).</span><button class="btn small primary" id="reinstall" type="button">Reinstall</button></div>` : ''}
        ${existing && existing.needs_reinstall && !existing.can_reinstall_here ? `<div class="banner">To see images, this bot needs Slack's permission to read shared files. ${existing.app_id
          ? `Open its <a href="https://api.slack.com/apps/${esc(existing.app_id)}/install-on-team" target="_blank" rel="noopener">Install App page in Slack</a> and click <b>Reinstall to Workspace</b>, then`
          : `Add the <code>files:read</code> bot scope on its OAuth &amp; Permissions page at api.slack.com/apps, reinstall it, then`}
          <button class="btn small" id="check-scopes" type="button">check again</button></div>` : ''}
        ${existing && existing.can_see_images && existing.scopes_unknown ? `<div class="banner row"><span style="flex:1">Bot Lab hasn't checked this bot's Slack permissions yet.</span><button class="btn small" id="check-scopes" type="button">Check now</button></div>` : ''}
        ${needsIcon ? `<div class="banner">Slack didn't take the icon automatically. <a href="${avatarUrl(existing)}" download="${esc(existing.slug)}-icon.png">Download the icon</a> and upload it on the app's <a href="https://api.slack.com/apps/${esc(existing.app_id)}/general" target="_blank" rel="noopener">Basic Information page</a>, or <button class="btn small" id="retry-icon" type="button">try again</button></div>` : ''}
        <div class="row">${actions}
          ${existing ? '<span style="flex:1"></span><button class="btn danger" id="delete" type="button">Delete</button>' : ''}
        </div>
        ${existing && existing.app_id ? `<p class="muted small">Slack app: <a href="https://api.slack.com/apps/${esc(existing.app_id)}" target="_blank" rel="noopener">${esc(existing.app_id)}</a> · Events URL: <code>${esc(state.base_url)}/events.php?bot=${esc(existing.slug)}</code></p>` : ''}`;

      const on = (id, fn) => { const el = $('#' + id); if (el) el.addEventListener('click', fn); };
      on('save', (e) => busy(e.currentTarget, 'Saving…', async () => {
        const bot = await save(status === 'installed' || status === 'created');
        toast('Saved.');
        if (!existing) location.hash = '#/bot/' + bot.id; else route();
      }));
      on('create', (e) => busy(e.currentTarget, 'Creating the Slack app…', async () => {
        if (!b.name.trim() || !b.personality.trim()) throw new Error('Add a name and a personality first.');
        if (!avatarChanged && !(existing && existing.has_avatar)) throw new Error('Make an avatar first. Slack needs an icon.');
        const bot = await save(false);
        const res = await api('create_app', { id: bot.id });
        if (res.notice) toast(res.notice, true);
        toast('Created! Sending you to Slack to approve the install…');
        setTimeout(() => { location.href = res.install_url; }, 1200);
      }));
      on('check-scopes', (e) => busy(e.currentTarget, 'Checking…', async () => {
        const { bot } = await api('check_scopes', { id: existing.id });
        toast(bot.needs_reinstall ? 'Still missing the file permission. Reinstall the app in Slack first.' : 'All set: this bot can see images now.', bot.needs_reinstall);
        route();
      }));
      on('reinstall', (e) => busy(e.currentTarget, 'Opening Slack…', async () => {
        const { install_url } = await api('install_url', { id: existing.id });
        location.href = install_url;
      }));
      on('install', (e) => busy(e.currentTarget, 'Opening Slack…', async () => {
        const { install_url } = await api('install_url', { id: existing.id });
        location.href = install_url;
      }));
      const appIdForm = $('#app-id-form');
      if (appIdForm) appIdForm.addEventListener('submit', (e) => {
        e.preventDefault();
        busy(e.submitter, 'Linking…', async () => {
          await api('set_app_id', { id: existing.id, app_id: $('#app-id').value });
          toast('Linked. Save to push the name, descriptions and icon to Slack.');
          route();
        });
      });
      on('retry-icon', (e) => busy(e.currentTarget, 'Trying…', async () => {
        const res = await api('retry_icon', { id: existing.id });
        toast(res.notice || 'Icon set in Slack.', !!res.notice);
        route();
      }));
      on('delete', (e) => {
        const msg = existing.app_id
          ? `Delete ${existing.name}? This also deletes its Slack app for everyone in the workspace. This can't be undone.`
          : `Delete ${existing.name}?`;
        if (!confirm(msg)) return;
        busy(e.currentTarget, 'Deleting…', async () => {
          await api('delete_bot', { id: existing.id });
          toast('Deleted.');
          location.hash = '#/';
        });
      });
    };
    renderSlack();
  }

  // ---- Settings ---------------------------------------------------------------------------

  function settings() {
    app.innerHTML = `
      <div class="stack">
        <h1>Settings</h1>
        <section class="card">
          <h2>Slack connection ${state.slack_ready ? '<span class="badge installed">Connected</span>' : '<span class="badge created">Not connected</span>'}</h2>
          <p class="muted">The studio creates Slack apps with an <b>app configuration token</b>. You make one once; the studio keeps it fresh automatically.</p>
          <ol class="steps">
            <li>Open <a href="https://api.slack.com/apps" target="_blank" rel="noopener">api.slack.com/apps</a> and sign in to your workspace.</li>
            <li>Under <b>Your App Configuration Tokens</b> (below your apps list), click <b>Generate Token</b> and pick your workspace.</li>
            <li>Copy the <b>Refresh Token</b> (starts with <code>xoxe-</code>) and paste it here.</li>
          </ol>
          <form class="row" id="slack-form">
            <input type="password" id="refresh" placeholder="xoxe-1-…" style="flex:1" autocomplete="off">
            <button class="btn primary" type="submit">${state.slack_ready ? 'Replace token' : 'Connect'}</button>
          </form>
        </section>
        <section class="card">
          <h2>AI providers</h2>
          <p class="muted">Keys live in <code>config.php</code> on the server (run <code>php bin/setup.php</code> to change them). Models can be changed there too.</p>
          <ul>${['anthropic', 'openai'].map((id) => {
            const p = state.providers.find((x) => x.id === id);
            return `<li><b>${providerLabel(id)}</b>: ${p ? 'ready · chats with <code>' + esc(p.chat_model) + '</code> by default' : '<span class="muted">no key</span>'}</li>`;
          }).join('')}</ul>
        </section>
        <section class="card">
          <div class="row spread"><h2>Activity log</h2><button class="btn small" id="load-log" type="button">Show</button></div>
          <div class="log" id="log" hidden></div>
        </section>
        <section class="card row spread">
          <span class="muted">Signed in as the owner.</span>
          <button class="btn" id="logout" type="button">Sign out</button>
        </section>
      </div>`;
    $('#slack-form').addEventListener('submit', (e) => {
      e.preventDefault();
      busy(e.submitter, 'Checking…', async () => {
        await api('save_slack_token', { refresh_token: $('#refresh').value });
        toast('Slack connected.');
        route();
      });
    });
    $('#load-log').addEventListener('click', (e) => busy(e.currentTarget, 'Loading…', async () => {
      const { lines } = await api('log');
      $('#log').hidden = false;
      $('#log').textContent = lines.length ? lines.join('\n') : 'Nothing yet.';
    }));
    $('#logout').addEventListener('click', async () => { await api('logout'); location.href = './'; });
  }

  // ---- Import an existing app ------------------------------------------------------------

  function importer() {
    app.innerHTML = `
      <div class="stack">
        <h1>Import an existing Slack app</h1>
        <form class="card stack" id="import-form">
          <p class="muted">For apps you made by hand. After importing, set the app's <b>Event Subscriptions</b> Request URL to <code>${esc(state.base_url)}/events.php?bot=SLUG</code> (the slug is shown after import).</p>
          <div class="grid2">
            <div class="field"><label>Name</label><input type="text" name="name" required maxlength="35"></div>
            <div class="field"><label>AI provider</label><select name="provider">${state.providers.map((p) => `<option value="${p.id}">${esc(p.label)}</option>`).join('')}</select></div>
          </div>
          <div class="field"><label>Personality (system prompt)</label><textarea name="personality" required></textarea></div>
          <div class="grid2">
            <div class="field"><label>Bot User OAuth Token (Install App page)</label><input type="password" name="bot_token" placeholder="xoxb-…" required autocomplete="off"></div>
            <div class="field"><label>Signing Secret (Basic Information page)</label><input type="password" name="signing_secret" required autocomplete="off"></div>
          </div>
          <div><button class="btn primary" type="submit">Import</button></div>
        </form>
      </div>`;
    $('#import-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      busy(e.submitter, 'Checking with Slack…', async () => {
        const { bot } = await api('import_bot', {
          bot: { name: f.get('name'), personality: f.get('personality'), provider: f.get('provider') },
          bot_token: f.get('bot_token'), signing_secret: f.get('signing_secret'),
        });
        toast(`Imported. Request URL: ${state.base_url}/events.php?bot=${bot.slug}`);
        location.hash = '#/bot/' + bot.id;
      });
    });
  }

  // ---- Login ------------------------------------------------------------------------------

  const login = $('#login');
  if (login) {
    login.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        const data = await api('login', { password: login.password.value });
        csrf = data.csrf;
        location.reload();
      } catch (err) { $('#login-error').textContent = err.message; }
    });
  }

  window.addEventListener('hashchange', route);
  route();
})();
