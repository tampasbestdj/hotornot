// Seed accounts — AI-generated realistic portraits for cold start.
// These will be removed once 20+ real users join.
const SEED_USERS = [
  { id: 'seed-m1', name: 'Alex', gender: 'm', age: 28, photo: 'images/cXOJh.jpg', votes: 42, hots: 28 },
  { id: 'seed-m2', name: 'Jamal', gender: 'm', age: 32, photo: 'images/kJxZV.jpg', votes: 38, hots: 22 },
  { id: 'seed-m3', name: 'Tyler', gender: 'm', age: 22, photo: 'images/BqJRZ.jpg', votes: 51, hots: 35 },
  { id: 'seed-m4', name: 'Omar', gender: 'm', age: 40, photo: 'images/Mw6Y9.jpg', votes: 29, hots: 14 },
  { id: 'seed-m5', name: 'Raj', gender: 'm', age: 26, photo: 'images/INakS.jpg', votes: 45, hots: 27 },
  { id: 'seed-m6', name: 'Eric', gender: 'm', age: 33, photo: 'images/gYhap.jpg', votes: 33, hots: 18 },
  { id: 'seed-m7', name: 'Kenji', gender: 'm', age: 21, photo: 'images/PriBf.jpg', votes: 47, hots: 31 },
  { id: 'seed-m8', name: 'Marcus', gender: 'm', age: 29, photo: 'images/UEDKc.jpg', votes: 56, hots: 41 },
  { id: 'seed-m9', name: 'Carlos', gender: 'm', age: 38, photo: 'images/VPKeW.jpg', votes: 24, hots: 9 },
  { id: 'seed-m10', name: 'Jake', gender: 'm', age: 31, photo: 'images/gnCE6.jpg', votes: 39, hots: 23 },
  { id: 'seed-m11', name: 'Ben', gender: 'm', age: 28, photo: 'images/HNtQf.jpg', votes: 36, hots: 19 },
  { id: 'seed-w1', name: 'Mei', gender: 'f', age: 24, photo: 'images/HKTsb.jpg', votes: 62, hots: 48 },
  { id: 'seed-w2', name: 'Sofia', gender: 'f', age: 35, photo: 'images/eslxd.jpg', votes: 41, hots: 26 },
  { id: 'seed-w3', name: 'Aisha', gender: 'f', age: 27, photo: 'images/caR1Y.jpg', votes: 55, hots: 39 },
  { id: 'seed-w4', name: 'Luna', gender: 'f', age: 19, photo: 'images/FTcHV.jpg', votes: 68, hots: 52 },
  { id: 'seed-w5', name: 'Claire', gender: 'f', age: 30, photo: 'images/MBZRu.jpg', votes: 37, hots: 21 },
  { id: 'seed-w6', name: 'Emma', gender: 'f', age: 25, photo: 'images/Lx1Zn.jpg', votes: 59, hots: 44 },
  { id: 'seed-w7', name: 'Maya', gender: 'f', age: 23, photo: 'images/Ya6uo.jpg', votes: 49, hots: 33 },
  { id: 'seed-w8', name: 'Yuki', gender: 'f', age: 26, photo: 'images/k7PD1.jpg', votes: 52, hots: 36 },
  { id: 'seed-w9', name: 'Zara', gender: 'f', age: 34, photo: 'images/AD3fr.jpg', votes: 44, hots: 29 }
];

let users = [];
let currentUser = null;
let currentIndex = 0;
let ratedIds = new Set();

function loadState() {
  const saved = localStorage.getItem('hotornot-state');
  if (saved) {
    const data = JSON.parse(saved);
    users = data.users || [];
    currentUser = data.currentUser;
    ratedIds = new Set(data.ratedIds || []);
  }
  if (users.length === 0) {
    users = JSON.parse(JSON.stringify(SEED_USERS));
  }
  if (currentUser && !users.find(u => u.id === currentUser.id)) {
    users.push(currentUser);
  }
}

function saveState() {
  localStorage.setItem('hotornot-state', JSON.stringify({
    users,
    currentUser,
    ratedIds: Array.from(ratedIds)
  }));
}

function getHotPercent(user) {
  if (!user.votes) return 0;
  return Math.round((user.hots / user.votes) * 100);
}

function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  document.getElementById(id).classList.remove('hidden');
}

function updateMyScore() {
  if (!currentUser) return;
  document.getElementById('my-score').textContent = getHotPercent(currentUser) + '%';
}

function showNextPerson() {
  const available = users.filter(u => u.id !== currentUser?.id && !ratedIds.has(u.id));
  if (available.length === 0) {
    ratedIds.clear();
    saveState();
    return showNextPerson();
  }
  const person = available[Math.floor(Math.random() * available.length)];
  document.getElementById('person-img').src = person.photo;
  document.getElementById('person-name').textContent = person.name;
  document.getElementById('person-meta').textContent = `${person.age} • ${person.gender === 'm' ? 'Man' : 'Woman'}`;
  document.getElementById('hot-percent').textContent = getHotPercent(person) + '%';
  currentIndex = users.findIndex(u => u.id === person.id);
}

function rate(isHot) {
  if (!currentUser || currentIndex < 0) return;
  const person = users[currentIndex];
  person.votes = (person.votes || 0) + 1;
  if (isHot) person.hots = (person.hots || 0) + 1;
  ratedIds.add(person.id);
  if (Math.random() > 0.6) {
    currentUser.votes = (currentUser.votes || 0) + 1;
    if (Math.random() > 0.45) currentUser.hots = (currentUser.hots || 0) + 1;
  }
  saveState();
  updateMyScore();
  showNextPerson();
}

function showLeaderboard() {
  const sorted = [...users].sort((a, b) => getHotPercent(b) - getHotPercent(a)).slice(0, 10);
  const list = document.getElementById('leaderboard-list');
  list.innerHTML = sorted.map((u, i) => `
    <div class="lb-item">
      <span>${i+1}. ${u.name}</span>
      <strong>${getHotPercent(u)}%</strong>
    </div>
  `).join('');
  showScreen('leaderboard-screen');
}

document.addEventListener('DOMContentLoaded', () => {
  loadState();

  const upload = document.getElementById('photo-upload');
  const enterBtn = document.getElementById('enter-btn');
  const preview = document.getElementById('preview');

  upload.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
      preview.innerHTML = `<img src="${ev.target.result}" alt="preview">`;
      enterBtn.disabled = false;
      enterBtn.dataset.photo = ev.target.result;
    };
    reader.readAsDataURL(file);
  });

  enterBtn.addEventListener('click', () => {
    const photo = enterBtn.dataset.photo;
    if (!photo) return;
    currentUser = {
      id: 'user-' + Date.now(),
      name: 'You',
      gender: '?',
      age: 25,
      photo: photo,
      votes: 5,
      hots: 2,
      isReal: true
    };
    users.push(currentUser);
    saveState();
    document.getElementById('subtitle').textContent = 'Rate people. Check your score.';
    showScreen('main-screen');
    updateMyScore();
    showNextPerson();
  });

  document.getElementById('hot-btn').addEventListener('click', () => rate(true));
  document.getElementById('not-btn').addEventListener('click', () => rate(false));
  document.getElementById('leaderboard-btn').addEventListener('click', showLeaderboard);
  document.getElementById('back-btn').addEventListener('click', () => showScreen('main-screen'));
  document.getElementById('reset-btn').addEventListener('click', () => {
    localStorage.removeItem('hotornot-state');
    location.reload();
  });

  if (currentUser) {
    showScreen('main-screen');
    updateMyScore();
    showNextPerson();
  } else {
    showScreen('upload-screen');
  }
});
