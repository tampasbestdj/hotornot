const API = {
  async request(path, options = {}) {
    const opts = {
      credentials: 'same-origin',
      ...options,
      headers: { ...(options.headers || {}) }
    };
    if (opts.body && !(opts.body instanceof FormData) && typeof opts.body === 'object') {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    if (window.csrfToken && opts.method && opts.method !== 'GET') {
      opts.headers['X-CSRF-Token'] = window.csrfToken;
    }
    const res = await fetch(path, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'Request failed');
    }
    return data;
  },
  me: () => API.request('me.php'),
  register: (formData) => API.request('register.php', { method: 'POST', body: formData }),
  login: (body) => API.request('login.php', { method: 'POST', body }),
  logout: () => API.request('logout.php', { method: 'POST', body: {} }),
  prefs: (body) => API.request('prefs.php', { method: 'POST', body }),
  pool: () => API.request('get_pool.php'),
  rate: (body) => API.request('rate.php', { method: 'POST', body }),
  myPhotos: () => API.request('my_photos.php'),
  upload: (formData) => API.request('upload.php', { method: 'POST', body: formData }),
  deletePhoto: (photo_id) => API.request('photo_delete.php', { method: 'POST', body: { photo_id } }),
  setPrimary: (photo_id) => API.request('photo_primary.php', { method: 'POST', body: { photo_id } }),
  leaderboard: () => API.request('leaderboard.php')
};

let currentUser = null;
let currentPhoto = null;

function showScreen(id) {
  document.querySelectorAll('.screen').forEach((s) => {
    s.classList.remove('active');
    s.classList.add('hidden');
  });
  const el = document.getElementById(id);
  el.classList.add('active');
  el.classList.remove('hidden');
}

function selectedChip(groupId) {
  const sel = document.querySelector(`#${groupId} .chip.selected`);
  return sel ? sel.dataset.value : null;
}

function selectedChips(groupId) {
  return [...document.querySelectorAll(`#${groupId} .chip.selected`)].map((c) => c.dataset.value);
}

function setChipSelection(groupId, values, multi) {
  const set = new Set(Array.isArray(values) ? values : [values]);
  document.querySelectorAll(`#${groupId} .chip`).forEach((chip) => {
    const on = set.has(chip.dataset.value);
    chip.classList.toggle('selected', on);
  });
  if (!multi && set.size === 0) {
    const first = document.querySelector(`#${groupId} .chip`);
    if (first) first.classList.add('selected');
  }
}

function bindExclusiveChips(groupId) {
  document.querySelectorAll(`#${groupId} .chip`).forEach((chip) => {
    chip.addEventListener('click', () => {
      document.querySelectorAll(`#${groupId} .chip`).forEach((c) => c.classList.remove('selected'));
      chip.classList.add('selected');
    });
  });
}

function genderLabel(gender, isCouple) {
  if (isCouple || gender === 'couple') return 'Couple';
  if (gender === 'm') return 'Man';
  if (gender === 'f') return 'Woman';
  return 'Unspecified';
}

function ratingLabel(rating) {
  if (rating === 'suggestive') return 'Suggestive';
  if (rating === 'adult') return 'Adult';
  return 'SFW';
}

function getHotPercent(hots, votes) {
  if (!votes) return 0;
  return Math.round((hots / votes) * 100);
}

function showAuthError(msg) {
  const el = document.getElementById('auth-error');
  if (!msg) {
    el.classList.add('hidden');
    el.textContent = '';
    return;
  }
  el.textContent = msg;
  el.classList.remove('hidden');
}

function applyUser(user) {
  currentUser = user;
  if (user && user.csrf) window.csrfToken = user.csrf;
  if (!user) return;
  setChipSelection('filter-gender', user.filter_gender || 'both');
  setChipSelection('filter-ratings', (user.filter_ratings || 'sfw').split(',').filter(Boolean), true);
  const score = document.getElementById('my-score');
  score.textContent = user.votes ? getHotPercent(user.hots, user.votes) + '%' : '—';
  document.getElementById('subtitle').textContent = 'Rate people. Check your score.';
}

function updateMyScoreFrom(user) {
  if (!user) return;
  document.getElementById('my-score').textContent = user.votes ? getHotPercent(user.hots, user.votes) + '%' : '—';
}

function showEmptyPool(on) {
  document.getElementById('empty-pool').classList.toggle('hidden', !on);
  document.getElementById('current-person').classList.toggle('hidden', on);
  document.querySelector('.actions').classList.toggle('hidden', on);
}

function renderCurrentPhoto(photo) {
  currentPhoto = photo;
  if (!photo) {
    showEmptyPool(true);
    return;
  }
  showEmptyPool(false);
  document.getElementById('person-img').src = photo.url;
  document.getElementById('person-name').textContent = photo.name;
  const agePart = photo.age ? `${photo.age} • ` : '';
  document.getElementById('person-meta').textContent = `${agePart}${genderLabel(photo.gender, photo.is_couple)}`;
  document.getElementById('hot-percent').textContent = getHotPercent(photo.hots, photo.votes) + '%';
  const coupleBadge = document.getElementById('couple-badge');
  coupleBadge.classList.toggle('hidden', !(photo.is_couple || photo.gender === 'couple'));
  const ratingBadge = document.getElementById('rating-badge');
  ratingBadge.textContent = ratingLabel(photo.tr_rating);
  ratingBadge.classList.remove('hidden');
}

async function showNextPerson() {
  const data = await API.pool();
  renderCurrentPhoto(data.photo || null);
}

async function rate(isHot) {
  if (!currentUser || !currentPhoto) return;
  const data = await API.rate({ photo_id: currentPhoto.id, is_hot: isHot ? 1 : 0 });
  if (data.user) {
    currentUser = { ...currentUser, ...data.user };
    updateMyScoreFrom(currentUser);
  }
  await showNextPerson();
}

async function savePrefs(extra = {}) {
  const ratings = selectedChips('filter-ratings');
  const body = {
    filter_gender: selectedChip('filter-gender') || 'both',
    filter_ratings: (ratings.length ? ratings : ['sfw']).join(','),
    ...extra
  };
  const data = await API.prefs(body);
  if (data.user) applyUser({ ...currentUser, ...data.user, csrf: window.csrfToken });
  await showNextPerson();
}

function askAdultConfirm() {
  return new Promise((resolve) => {
    const modal = document.getElementById('adult-modal');
    const check = document.getElementById('adult-confirm-check');
    const ok = document.getElementById('adult-confirm-btn');
    const cancel = document.getElementById('adult-cancel-btn');
    check.checked = false;
    ok.disabled = true;
    modal.classList.remove('hidden');

    const cleanup = (result) => {
      modal.classList.add('hidden');
      check.removeEventListener('change', onCheck);
      ok.removeEventListener('click', onOk);
      cancel.removeEventListener('click', onCancel);
      resolve(result);
    };
    const onCheck = () => { ok.disabled = !check.checked; };
    const onOk = () => cleanup(true);
    const onCancel = () => cleanup(false);
    check.addEventListener('change', onCheck);
    ok.addEventListener('click', onOk);
    cancel.addEventListener('click', onCancel);
  });
}

async function showLeaderboard() {
  const data = await API.leaderboard();
  const list = document.getElementById('leaderboard-list');
  const rows = data.users || [];
  list.innerHTML = rows.length ? rows.map((u, i) => `
    <div class="lb-item">
      <span>${i + 1}. ${escapeHtml(u.name)}${u.is_couple ? ' <span class="badge">Couple</span>' : ''}</span>
      <strong>${getHotPercent(u.hots, u.votes)}%</strong>
    </div>
  `).join('') : '<p class="muted">No ratings yet.</p>';
  showScreen('leaderboard-screen');
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[ch]));
}

async function showMyPhotos() {
  const data = await API.myPhotos();
  const photos = data.photos || [];
  const grid = document.getElementById('photos-grid');
  document.getElementById('photos-summary').textContent =
    `${photos.length} of 10 photos` + (data.score_percent != null ? ` • Overall ${data.score_percent}%` : '');
  grid.innerHTML = photos.map((p) => `
    <div class="photo-tile" data-id="${p.id}">
      ${p.is_best ? '<span class="best-badge">Your best photo</span>' : ''}
      <img src="${p.url}" alt="${escapeHtml(p.tr_rating)} photo">
      <div class="meta">
        ${p.is_couple ? '<span class="badge">Couple</span> ' : ''}
        ${escapeHtml(ratingLabel(p.tr_rating))}<br>
        <strong>${p.hot_percent}%</strong> Hot • ${p.votes} vote${p.votes === 1 ? '' : 's'}
        ${p.is_primary ? '<div>Primary</div>' : ''}
      </div>
      <div class="tile-actions">
        ${p.is_primary ? '' : `<button type="button" class="set-primary" data-id="${p.id}">Make primary</button>`}
        <button type="button" class="delete-photo not" data-id="${p.id}">Delete</button>
      </div>
    </div>
  `).join('') || '<p class="muted">No photos yet.</p>';
  showScreen('photos-screen');
}

function compressImage(file, maxWidth, quality) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      const canvas = document.createElement('canvas');
      const scale = Math.min(1, maxWidth / img.width);
      canvas.width = Math.max(1, Math.round(img.width * scale));
      canvas.height = Math.max(1, Math.round(img.height * scale));
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      URL.revokeObjectURL(url);
      resolve(canvas.toDataURL('image/jpeg', quality));
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error('Could not read image'));
    };
    img.src = url;
  });
}

function dataUrlToBlob(dataUrl) {
  const [header, body] = dataUrl.split(',');
  const mime = (header.match(/:(.*?);/) || [])[1] || 'image/jpeg';
  const bytes = atob(body);
  const arr = new Uint8Array(bytes.length);
  for (let i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
  return new Blob([arr], { type: mime });
}

async function fileToCompressedBlob(file) {
  const compressed = await compressImage(file, 800, 0.7);
  return { blob: dataUrlToBlob(compressed), preview: compressed };
}

function wirePreview(inputId, previewId, buttonId) {
  const input = document.getElementById(inputId);
  const preview = document.getElementById(previewId);
  const button = document.getElementById(buttonId);
  input.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    try {
      const { blob, preview: src } = await fileToCompressedBlob(file);
      preview.innerHTML = `<img src="${src}" alt="preview">`;
      button.disabled = false;
      button.dataset.ready = '1';
      input._compressedBlob = blob;
    } catch (err) {
      console.error('Image compress failed:', err);
      alert('Could not process that photo. Try another one.');
      button.disabled = true;
      input._compressedBlob = null;
    }
  });
}

async function startMain() {
  showScreen('main-screen');
  try {
    await showNextPerson();
  } catch (err) {
    console.error(err);
    renderCurrentPhoto(null);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  bindExclusiveChips('reg-gender');
  bindExclusiveChips('reg-rating');
  bindExclusiveChips('filter-gender');
  bindExclusiveChips('add-rating');

  document.querySelectorAll('#filter-ratings .chip').forEach((chip) => {
    chip.addEventListener('click', async () => {
      if (chip.dataset.value === 'adult' && !chip.classList.contains('selected')) {
        if (!(currentUser && currentUser.adult_confirmed)) {
          const ok = await askAdultConfirm();
          if (!ok) return;
          chip.classList.add('selected');
          try {
            await savePrefs({ adult_confirmed: 1 });
          } catch (err) {
            alert(err.message);
          }
          return;
        }
      }
      chip.classList.toggle('selected');
      if (selectedChips('filter-ratings').length === 0) {
        setChipSelection('filter-ratings', ['sfw'], true);
      }
      try {
        await savePrefs();
      } catch (err) {
        alert(err.message);
      }
    });
  });

  document.querySelectorAll('#filter-gender .chip').forEach((chip) => {
    chip.addEventListener('click', async () => {
      document.querySelectorAll('#filter-gender .chip').forEach((c) => c.classList.remove('selected'));
      chip.classList.add('selected');
      try {
        await savePrefs();
      } catch (err) {
        alert(err.message);
      }
    });
  });

  document.getElementById('tab-register').addEventListener('click', () => {
    document.getElementById('tab-register').classList.add('active');
    document.getElementById('tab-login').classList.remove('active');
    document.getElementById('register-form').classList.remove('hidden');
    document.getElementById('login-form').classList.add('hidden');
    showAuthError('');
  });
  document.getElementById('tab-login').addEventListener('click', () => {
    document.getElementById('tab-login').classList.add('active');
    document.getElementById('tab-register').classList.remove('active');
    document.getElementById('login-form').classList.remove('hidden');
    document.getElementById('register-form').classList.add('hidden');
    showAuthError('');
  });

  const enterBtn = document.getElementById('enter-btn');
  const addBtn = document.getElementById('add-photo-btn');
  wirePreview('photo-upload', 'preview', 'enter-btn');
  wirePreview('add-photo-upload', 'add-preview', 'add-photo-btn');

  document.getElementById('register-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const blob = document.getElementById('photo-upload')._compressedBlob;
    if (!blob) return;
    const fd = new FormData();
    fd.append('name', document.getElementById('reg-name').value.trim());
    fd.append('password', document.getElementById('reg-password').value);
    fd.append('gender', selectedChip('reg-gender') || 'm');
    fd.append('tr_rating', selectedChip('reg-rating') || 'sfw');
    fd.append('is_couple', (selectedChip('reg-gender') === 'couple') ? '1' : '0');
    fd.append('photo', blob, 'photo.jpg');
    enterBtn.disabled = true;
    try {
      showAuthError('');
      const data = await API.register(fd);
      applyUser(data.user);
      await startMain();
    } catch (err) {
      showAuthError(err.message);
      enterBtn.disabled = false;
    }
  });

  document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      showAuthError('');
      const data = await API.login({
        name: document.getElementById('login-name').value.trim(),
        password: document.getElementById('login-password').value
      });
      applyUser(data.user);
      await startMain();
    } catch (err) {
      showAuthError(err.message);
    }
  });

  document.getElementById('hot-btn').addEventListener('click', () => rate(true).catch((err) => alert(err.message)));
  document.getElementById('not-btn').addEventListener('click', () => rate(false).catch((err) => alert(err.message)));
  document.getElementById('leaderboard-btn').addEventListener('click', () => showLeaderboard().catch((err) => alert(err.message)));
  document.getElementById('back-btn').addEventListener('click', () => showScreen('main-screen'));
  document.getElementById('photos-btn').addEventListener('click', () => showMyPhotos().catch((err) => alert(err.message)));
  document.getElementById('photos-back-btn').addEventListener('click', async () => {
    showScreen('main-screen');
    try { await showNextPerson(); } catch (err) { alert(err.message); }
  });
  document.getElementById('logout-btn').addEventListener('click', async () => {
    try { await API.logout(); } catch (e) { /* still leave */ }
    currentUser = null;
    currentPhoto = null;
    window.csrfToken = null;
    document.getElementById('subtitle').textContent = 'Upload a photo to start rating';
    showScreen('upload-screen');
  });
  document.getElementById('gopro-btn').addEventListener('click', () => {
    alert('Go Pro — $4.99/month\n\n• No ads\n• See exactly who rated you Hot or Not\n• Boost your photo for more ratings\n\n(Coming soon — Stripe checkout will go here)');
  });

  addBtn.addEventListener('click', async () => {
    const input = document.getElementById('add-photo-upload');
    const blob = input._compressedBlob;
    if (!blob) return;
    const fd = new FormData();
    fd.append('tr_rating', selectedChip('add-rating') || 'sfw');
    fd.append('is_couple', document.getElementById('add-couple').checked ? '1' : '0');
    fd.append('photo', blob, 'photo.jpg');
    addBtn.disabled = true;
    try {
      await API.upload(fd);
      input.value = '';
      input._compressedBlob = null;
      document.getElementById('add-preview').innerHTML = '';
      await showMyPhotos();
    } catch (err) {
      alert(err.message);
      addBtn.disabled = false;
    }
  });

  document.getElementById('photos-grid').addEventListener('click', async (e) => {
    const del = e.target.closest('.delete-photo');
    const prim = e.target.closest('.set-primary');
    try {
      if (del) {
        if (!confirm('Delete this photo?')) return;
        await API.deletePhoto(Number(del.dataset.id));
        await showMyPhotos();
      } else if (prim) {
        await API.setPrimary(Number(prim.dataset.id));
        await showMyPhotos();
      }
    } catch (err) {
      alert(err.message);
    }
  });

  API.me().then(async (data) => {
    if (data.user) {
      applyUser(data.user);
      await startMain();
    } else {
      if (data.csrf) window.csrfToken = data.csrf;
      showScreen('upload-screen');
    }
  }).catch(() => showScreen('upload-screen'));
});
