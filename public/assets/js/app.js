'use strict';
let csrf = document.querySelector('meta[name="csrf-token"]').content;
const toast = (message) => { const el = document.querySelector('#toast'); el.textContent = message; el.hidden = false; };
async function request(url, data) {
  const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: {...(data instanceof FormData ? {} : {'Content-Type': 'application/json'}), 'X-CSRF-Token': csrf}, body: data instanceof FormData ? data : JSON.stringify(data) });
  const result = await response.json();
  if (!response.ok || !result.ok) throw new Error(result.error?.message || 'Unable to complete your request.');
  if (result.csrf) csrf = result.csrf;
  return result.data;
}
document.querySelectorAll('form[data-api]').forEach(form => form.addEventListener('submit', async event => {
  event.preventDefault();
  if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
  const button = form.querySelector('button[type="submit"]');
  const message = form.querySelector('.form-message');
  const label = button.textContent;
  form.setAttribute('aria-busy', 'true');
  button.disabled = true; button.textContent = 'Please wait…'; message.hidden = true;
  try {
    let payload;
    if (form.enctype === 'multipart/form-data') payload = new FormData(form);
    else {
      payload = {};
      for (const [key, value] of new FormData(form)) {
        if (key.endsWith('[]')) (payload[key.slice(0, -2)] ??= []).push(value);
        else payload[key] = value;
      }
      form.querySelectorAll('input[type="datetime-local"]').forEach(input => {
        if (input.value) payload[input.name] = new Date(input.value).toISOString();
      });
    }
    const result = await request(form.dataset.api, payload);
    if (result.redirect) { window.location.assign(result.redirect); return; }
    if (result.reload) { window.location.reload(); return; }
    if (result.token) {
      let output = form.parentElement.querySelector('.token-output');
      if (!output) { output = document.createElement('div'); output.className = 'token-output'; form.after(output); }
      output.replaceChildren(); output.hidden = false;
      const image = document.createElement('img'); image.src = result.qr; image.alt = 'One-use pickup QR code';
      const token = document.createElement('code'); token.textContent = result.token;
      const download = document.createElement('a'); download.href = result.qr; download.download = 'foodrescue-pickup-qr.png'; download.textContent = 'Download QR code'; download.className = 'underlined';
      const refresh = document.createElement('a'); refresh.href = '/pickups'; refresh.textContent = 'Saved the token? Return to pickups →'; refresh.className = 'underlined';
      output.append(image, token, download, refresh);
    }
    message.textContent = result.message; message.hidden = false; message.classList.add('success'); message.focus();
  } catch (error) {
    message.textContent = error.message; message.hidden = false; message.classList.remove('success'); message.focus();
  } finally { form.setAttribute('aria-busy', 'false'); button.disabled = false; button.textContent = label; }
}));
document.querySelectorAll('[data-logout]').forEach(button => button.addEventListener('click', async () => {
  button.disabled = true;
  try { const result = await request('/api/auth/logout', {}); window.location.assign(result.redirect); }
  catch (error) { toast(error.message); button.disabled = false; }
}));
const tokenField = document.querySelector('#account-token');
if (tokenField) { tokenField.value = new URLSearchParams(location.hash.slice(1)).get('token') || ''; history.replaceState(null, '', location.pathname); }
document.querySelectorAll('[data-local-time]').forEach(el => {
  const date = new Date(el.dateTime.replace(' ', 'T'));
  if (!Number.isNaN(date.getTime())) el.textContent = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
});

document.querySelectorAll('input[type="datetime-local"][data-utc]').forEach(input => {
  if (!input.dataset.utc) return;
  const date = new Date(input.dataset.utc.replace(' ', 'T') + 'Z');
  if (!Number.isNaN(date.getTime())) {
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    input.value = date.toISOString().slice(0, 16);
  }
});
document.querySelectorAll('[data-location]').forEach(button => button.addEventListener('click', () => {
  if (!navigator.geolocation) { toast('Location is unavailable. Enter coordinates manually.'); return; }
  button.disabled = true;
  navigator.geolocation.getCurrentPosition(position => {
    const form = button.closest('form');
    form.elements.latitude.value = position.coords.latitude.toFixed(7);
    form.elements.longitude.value = position.coords.longitude.toFixed(7);
    button.disabled = false;
  }, () => { toast('Could not read your location. Enter coordinates manually.'); button.disabled = false; }, {timeout: 10000});
}));
document.querySelectorAll('.map[data-lat]').forEach(element => {
  if (!window.L) { element.textContent = 'Map unavailable. Coordinates: ' + element.dataset.lat + ', ' + element.dataset.lng; return; }
  const lat = Number(element.dataset.lat), lng = Number(element.dataset.lng);
  const map = L.map(element).setView([lat, lng], 14);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'}).addTo(map);
  L.circleMarker([lat, lng], {radius: 9, color: '#245a40', fillColor: '#d3eaa2', fillOpacity: 1}).addTo(map);
});
document.querySelectorAll('canvas[data-chart]').forEach(canvas => {
  if (!window.Chart) return;
  const type = canvas.dataset.chart;
  new Chart(canvas, {type, data: {labels: JSON.parse(canvas.dataset.labels), datasets: [{label: canvas.dataset.series, data: JSON.parse(canvas.dataset.values), backgroundColor: ['#245a40','#87a75b','#d89a58','#658c83','#b6c68e'], borderWidth: 0}]}, options: {responsive: true, maintainAspectRatio: false, ...(type === 'bar' ? {scales: {y: {beginAtZero: true}}} : {})}});
});
document.querySelectorAll('[data-scan]').forEach(button => {
  let stream = null, running = false;
  const form = button.closest('form'), video = form.querySelector('video'), status = form.querySelector('.scan-status');
  const stop = () => { running = false; stream?.getTracks().forEach(track => track.stop()); stream = null; video.hidden = true; button.textContent = 'Scan QR with camera'; };
  button.addEventListener('click', async () => {
    if (running) { stop(); return; }
    if (!navigator.mediaDevices?.getUserMedia || !window.jsQR) { status.textContent = 'Camera scanning is unavailable. Paste the pickup token instead.'; return; }
    try {
      stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}});
      video.srcObject = stream; video.hidden = false; await video.play(); running = true; button.textContent = 'Stop camera'; status.textContent = 'Point the camera at the pickup QR.';
      const canvas = document.createElement('canvas'), context = canvas.getContext('2d', {willReadFrequently: true});
      const scan = () => {
        if (!running) return;
        if (video.readyState >= 2) {
          canvas.width = Math.min(video.videoWidth, 800); canvas.height = video.videoHeight * canvas.width / video.videoWidth;
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
          const code = jsQR(pixels.data, canvas.width, canvas.height);
          if (code && /^[a-f0-9]{64}$/.test(code.data)) { form.elements.token.value = code.data; status.textContent = 'Token scanned. Enter collected weight and submit.'; stop(); return; }
        }
        requestAnimationFrame(scan);
      };
      scan();
    } catch { stop(); status.textContent = 'Camera access failed. Paste the pickup token instead.'; }
  });
  window.addEventListener('pagehide', stop);
});

document.querySelectorAll('[data-download-chart]').forEach(button => button.addEventListener('click', () => {
  const canvas = button.closest('article').querySelector('canvas');
  if (!canvas) return;
  const link = document.createElement('a'); link.href = canvas.toDataURL('image/png'); link.download = 'foodrescue-comparison.png'; link.click();
}));

document.querySelectorAll('.map[data-points]').forEach(element => {
  const points = JSON.parse(element.dataset.points); if (!window.L || !points.length) return;
  const map = L.map(element).setView([Number(points[0].latitude), Number(points[0].longitude)], 11);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'}).addTo(map);
  const bounds=[]; for (const point of points) {
    const coords=[Number(point.latitude),Number(point.longitude)]; bounds.push(coords);
    L.circleMarker(coords,{radius:Math.min(24,6+Math.sqrt(Number(point.offered_kg))),color:'#245a40',fillOpacity:0.6}).bindTooltip(String(point.offered_kg)+' kg offered').addTo(map);
  }
  if (bounds.length>1) map.fitBounds(bounds,{padding:[20,20]});
});
