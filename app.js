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
  login: (name, password) => {
    const fd = new FormData();
    fd.append('name', name);
    fd.append('password', password);
    return API.request('login.php', { method: 'POST', body: fd });
  },
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
  window.scrollTo({ top: 0, behavior: 'auto' });
}

function showLoginForm() {
  document.getElementById('tab-login').classList.add('active');
  document.getElementById('tab-register').classList.remove('active');
  document.getElementById('login-form').classList.remove('hidden');
  document.getElementById('register-form').classList.add('hidden');
  showAuthError('');
  showScreen('upload-screen');
  const nameInput = document.getElementById('login-name');
  try { nameInput.focus({ preventScroll: true }); } catch (e) { nameInput.focus(); }
  document.getElementById('login-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function showRegisterForm() {
  document.getElementById('tab-register').classList.add('active');
  document.getElementById('tab-login').classList.remove('active');
  document.getElementById('register-form').classList.remove('hidden');
  document.getElementById('login-form').classList.add('hidden');
  showAuthError('');
  showScreen('upload-screen');
}

function setLoggedInChrome(user) {
  const guest = document.getElementById('header-guest');
  const authed = document.getElementById('header-user');
  if (!user) {
    guest.classList.remove('hidden');
    authed.classList.add('hidden');
    document.getElementById('subtitle').textContent = 'Upload a photo to start rating';
    document.getElementById('header-score').textContent = '—';
    return;
  }
  guest.classList.add('hidden');
  authed.classList.remove('hidden');
  const scoreText = user.votes ? getHotPercent(user.hots, user.votes) + '%' : '—';
  document.getElementById('header-score').textContent = scoreText;
  const mainScore = document.getElementById('my-score');
  if (mainScore) mainScore.textContent = scoreText;
  document.getElementById('subtitle').textContent = 'Rate people. Check your score.';
}

function selectedChip(groupId) {
  const sel = document.querySelector(`#${groupId} .chip.selected`);
  return sel ? sel.dataset.value : null;
}

function selectedChips(groupId) {
  return [...document.querySelectorAll(`#${groupId} .chip.selected`)].map((c) => c.dataset.value);
}

function seeFlagsFromChips(groupId) {
  const sel = selectedChips(groupId);
  return {
    see_men: sel.includes('m') ? 1 : 0,
    see_women: sel.includes('f') ? 1 : 0,
    see_couples: sel.includes('couple') ? 1 : 0,
    see_cougars: sel.includes('cougar') ? 1 : 0,
    see_sugardaddy: sel.includes('sugardaddy') ? 1 : 0
  };
}

function seeSelected(flags) {
  return !!(flags.see_men || flags.see_women || flags.see_couples || flags.see_cougars || flags.see_sugardaddy);
}

function seeValuesFromUser(user) {
  const values = [];
  if (user && user.see_men) values.push('m');
  if (user && user.see_women) values.push('f');
  if (user && user.see_couples) values.push('couple');
  if (user && user.see_cougars) values.push('cougar');
  if (user && user.see_sugardaddy) values.push('sugardaddy');
  return values;
}

function userNeedsPrefs(user) {
  return !user || !seeSelected(user);
}

function parseAgeInput(id) {
  const el = document.getElementById(id);
  if (!el) return null;
  const raw = String(el.value || '').trim();
  if (!raw) return null;
  if (!/^[0-9]{1,3}$/.test(raw)) return null;
  const age = Number(raw);
  if (age < 18 || age > 99) return null;
  return age;
}

function setChipSelection(groupId, values, multi) {
  const set = new Set(Array.isArray(values) ? values : [values]);
  document.querySelectorAll(`#${groupId} .chip`).forEach((chip) => {
    const on = set.has(chip.dataset.value);
    chip.classList.toggle('selected', on);
  });
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
  if (!user) {
    setLoggedInChrome(null);
    return;
  }
  setChipSelection('filter-see', seeValuesFromUser(user), true);
  setChipSelection('filter-ratings', (user.filter_ratings || 'sfw').split(',').filter(Boolean), true);
  setLoggedInChrome(user);
}

function updateMyScoreFrom(user) {
  if (!user) return;
  setLoggedInChrome(user);
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
  const cougarBadge = document.getElementById('cougar-badge');
  const sugarBadge = document.getElementById('sugardaddy-badge');
  if (cougarBadge) cougarBadge.classList.toggle('hidden', !photo.is_cougar);
  if (sugarBadge) sugarBadge.classList.toggle('hidden', !photo.is_sugardaddy);
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
  const see = seeFlagsFromChips('filter-see');
  if (!seeSelected(see) && !('see_men' in extra)) {
    throw new Error('Please pick at least one: Men, Women, Couples, Cougars, or Sugar Daddy.');
  }
  const body = {
    ...see,
    filter_ratings: (ratings.length ? ratings : ['sfw']).join(','),
    ...extra
  };
  const data = await API.prefs(body);
  if (data.user) applyUser({ ...currentUser, ...data.user, csrf: window.csrfToken });
  if (!userNeedsPrefs(currentUser)) {
    await showNextPerson();
  }
}

function updateEnterEnabled() {
  const enterBtn = document.getElementById('enter-btn');
  if (!enterBtn) return;
  const hasPhoto = !!(document.getElementById('photo-upload') && document.getElementById('photo-upload')._compressedBlob);
  const hasGender = !!selectedChip('reg-gender');
  const hasSee = selectedChips('reg-see').length >= 1;
  const hasAge = parseAgeInput('reg-age') !== null;
  enterBtn.disabled = !(hasPhoto && hasGender && hasSee && hasAge);
}

function updateGateEnabled() {
  const btn = document.getElementById('gate-see-btn');
  if (!btn) return;
  btn.disabled = selectedChips('gate-see').length < 1;
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
        ${p.is_cougar ? '<span class="badge badge-cougar">Cougar</span> ' : ''}
        ${p.is_sugardaddy ? '<span class="badge badge-sugardaddy">Sugar Daddy</span> ' : ''}
        ${p.age ? `${p.age} • ` : ''}${escapeHtml(ratingLabel(p.tr_rating))}<br>
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
      button.dataset.ready = '1';
      input._compressedBlob = blob;
      if (button.id === 'enter-btn') updateEnterEnabled();
      else button.disabled = false;
    } catch (err) {
      console.error('Image compress failed:', err);
      alert('Could not process that photo. Try another one.');
      input._compressedBlob = null;
      if (button.id === 'enter-btn') updateEnterEnabled();
      else button.disabled = true;
    }
  });
}

async function startMain() {
  if (userNeedsPrefs(currentUser)) {
    setChipSelection('gate-see', seeValuesFromUser(currentUser), true);
    updateGateEnabled();
    document.getElementById('subtitle').textContent = 'Choose who you want to rate';
    showScreen('rate-prefs-screen');
    return;
  }
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
  bindExclusiveChips('add-rating');
  document.querySelectorAll('#reg-gender .chip').forEach((chip) => {
    chip.addEventListener('click', updateEnterEnabled);
  });
  document.querySelectorAll('#reg-see .chip').forEach((chip) => {
    chip.addEventListener('click', () => {
      chip.classList.toggle('selected');
      updateEnterEnabled();
    });
  });
  document.querySelectorAll('#gate-see .chip').forEach((chip) => {
    chip.addEventListener('click', () => {
      chip.classList.toggle('selected');
      document.getElementById('gate-see-error').classList.add('hidden');
      updateGateEnabled();
    });
  });

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

  document.querySelectorAll('#filter-see .chip').forEach((chip) => {
    chip.addEventListener('click', async () => {
      const already = chip.classList.contains('selected');
      if (already && selectedChips('filter-see').length === 1) {
        alert('Please keep at least one: Men, Women, Couples, Cougars, or Sugar Daddy.');
        return;
      }
      chip.classList.toggle('selected');
      try {
        await savePrefs();
      } catch (err) {
        chip.classList.toggle('selected', already);
        alert(err.message);
      }
    });
  });

  document.getElementById('gate-see-btn').addEventListener('click', async () => {
    const see = seeFlagsFromChips('gate-see');
    if (!seeSelected(see)) {
      document.getElementById('gate-see-error').classList.remove('hidden');
      return;
    }
    const btn = document.getElementById('gate-see-btn');
    btn.disabled = true;
    try {
      setChipSelection('filter-see', selectedChips('gate-see'), true);
      await savePrefs(see);
      await startMain();
    } catch (err) {
      alert(err.message);
      updateGateEnabled();
    }
  });

  document.getElementById('tab-register').addEventListener('click', showRegisterForm);
  document.getElementById('tab-login').addEventListener('click', showLoginForm);
  document.getElementById('header-login-btn').addEventListener('click', showLoginForm);
  document.getElementById('goto-login').addEventListener('click', showLoginForm);
  document.getElementById('goto-register').addEventListener('click', showRegisterForm);
  document.getElementById('header-photos-btn').addEventListener('click', () => {
    showMyPhotos().catch((err) => alert(err.message));
  });

  const enterBtn = document.getElementById('enter-btn');
  const addBtn = document.getElementById('add-photo-btn');
  wirePreview('photo-upload', 'preview', 'enter-btn');
  wirePreview('add-photo-upload', 'add-preview', 'add-photo-btn');
  const regAge = document.getElementById('reg-age');
  if (regAge) regAge.addEventListener('input', updateEnterEnabled);

  document.getElementById('register-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const blob = document.getElementById('photo-upload')._compressedBlob;
    const gender = selectedChip('reg-gender');
    const see = seeFlagsFromChips('reg-see');
    if (!blob) return;
    if (!gender) {
      showAuthError('Please say whether this photo is of a man, a woman, or a couple.');
      return;
    }
    if (!seeSelected(see)) {
      showAuthError('Please pick at least one: Men, Women, Couples, Cougars, or Sugar Daddy.');
      return;
    }
    const age = parseAgeInput('reg-age');
    if (age === null) {
      showAuthError('Please enter your age (18–99).');
      return;
    }
    const fd = new FormData();
    fd.append('name', document.getElementById('reg-name').value.trim());
    fd.append('password', document.getElementById('reg-password').value);
    fd.append('age', String(age));
    fd.append('gender', gender);
    fd.append('tr_rating', selectedChip('reg-rating') || 'sfw');
    fd.append('is_couple', gender === 'couple' ? '1' : '0');
    fd.append('see_men', String(see.see_men));
    fd.append('see_women', String(see.see_women));
    fd.append('see_couples', String(see.see_couples));
    fd.append('see_cougars', String(see.see_cougars));
    fd.append('see_sugardaddy', String(see.see_sugardaddy));
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
      const data = await API.login(
        document.getElementById('login-name').value.trim(),
        document.getElementById('login-password').value
      );
      applyUser(data.user);
      await startMain();
    } catch (err) {
      showAuthError(err.message);
    }
  });

  document.getElementById('hot-btn').addEventListener('click', () => rate(true).catch((err) => alert(err.message)));
  document.getElementById('not-btn').addEventListener('click', () => rate(false).catch((err) => alert(err.message)));
  document.getElementById('leaderboard-btn').addEventListener('click', () => showLeaderboard().catch((err) => alert(err.message)));
  document.getElementById('back-btn').addEventListener('click', () => startMain().catch((err) => alert(err.message)));
  document.getElementById('photos-btn').addEventListener('click', () => showMyPhotos().catch((err) => alert(err.message)));
  document.getElementById('photos-back-btn').addEventListener('click', () => startMain().catch((err) => alert(err.message)));
  async function logOut() {
    try { await API.logout(); } catch (e) { /* still leave */ }
    currentUser = null;
    currentPhoto = null;
    window.csrfToken = null;
    setLoggedInChrome(null);
    showLoginForm();
  }
  document.getElementById('logout-btn').addEventListener('click', logOut);
  document.getElementById('header-logout-btn').addEventListener('click', logOut);
  document.getElementById('gopro-btn').addEventListener('click', () => {
    alert('Go Pro — $4.99/month\n\n• No ads\n• See exactly who rated you Hot or Not\n• Boost your photo for more ratings\n\n(Coming soon — Stripe checkout will go here)');
  });

  addBtn.addEventListener('click', async () => {
    const input = document.getElementById('add-photo-upload');
    const blob = input._compressedBlob;
    if (!blob) return;
    const fd = new FormData();
    const age = parseAgeInput('add-age');
    if (age === null) {
      alert('Please enter the age for this photo (18–99).');
      return;
    }
    fd.append('tr_rating', selectedChip('add-rating') || 'sfw');
    fd.append('is_couple', document.getElementById('add-couple').checked ? '1' : '0');
    fd.append('age', String(age));
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

  const wantLogin = /(?:\?|&)login(?:=|&|$)/.test(location.search) || location.hash === '#login';
  API.me().then(async (data) => {
    if (data.user) {
      applyUser(data.user);
      await startMain();
      return;
    }
    if (data.csrf) window.csrfToken = data.csrf;
    setLoggedInChrome(null);
    if (wantLogin) showLoginForm();
    else showScreen('upload-screen');
  }).catch(() => {
    setLoggedInChrome(null);
    if (wantLogin) showLoginForm();
    else showScreen('upload-screen');
  });
});
