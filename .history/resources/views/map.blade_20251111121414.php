<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Map Dashboard</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
  <style>
  html, body { height:100%; margin:0; }
  /* Two columns: map grows, sidebar fixed width */
  .map-page {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px; /* change 360px to your sidebar width */
    gap: 0;
    height: 100vh;           /* full height */
    overflow: hidden;        /* prevent horizontal scroll */
  }
  #map { height: 100%; min-width: 0; }  /* min-width:0 is key to prevent overflow */
  #sidebar { overflow: auto; padding: 12px; background:#fff; }
  /* If you have a top navbar, subtract its height (e.g., 56px) */
  .has-navbar .map-page { height: calc(100vh - 56px); }
  /* Optional: stack on small screens */
  @media (max-width: 900px) {
    .map-page { grid-template-columns: 1fr; }
    #sidebar { height: 40vh; border-top: 1px solid #ddd; }
  .area-header {
  font-weight: 600;
  margin-top: 0.75rem;
  margin-bottom: 0.25rem;
  }
  #clearAllBtn {
  display: block;
  width: 100%;
  padding: 6px 10px;
  margin-bottom: 8px;
  font-weight: 600;
  border: none;
  background: #e74c3c;
  color: #fff;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.2s;
  }
  #clearAllBtn:hover {
    background: #c0392b;
  }
  }
</style>
</head>
<body>
  <div class="map-page">
    <div id="map"></div>
    <div id="sidebar">
      <button id="clearAllBtn" class="btn-clear">🗑 Clear All Areas</button>
      <div id="filters" style="display:flex; flex-direction:column; gap:8px; margin-bottom:12px">
        <label>
          Country
          <div style="display:flex; gap:6px; align-items:center;">
            <input id="countrySearch" type="text" placeholder="Type to search (e.g., Malaysia)" style="flex:1; padding:6px;">
            <button id="btnCountrySearch" type="button">Search</button>
          </div>
          <select id="countrySel" style="width:100%; padding:6px; margin-top:6px;">
            <option value="" selected disabled>Choose a country…</option>
          </select>
        </label>

        <label>
          State / Province
          <select id="stateSel" style="width:100%; padding:6px;" disabled>
            <option value="" selected disabled>Choose a state…</option>
          </select>
        </label>

        <label>
          District / Regency
          <select id="districtSel" style="width:100%; padding:6px;" disabled>
            <option value="" selected disabled>Choose a district…</option>
          </select>
        </label>
      </div>

      <h3 style="margin-top:0;">Road Names</h3>
      <div id="stats"><small>Draw an area on the map</small></div>
      <div id="list"></div>
    </div>
  </div>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>

  <script>
  const ADMIN_AREAS_URL = @json(route('api.admin-areas'));
  const ADMIN_GEOM_TMPL = @json(route('api.admin-geometry', ['relId' => 'REL_ID']));
  // ---------- setup map ----------
  const map = L.map('map').setView([4.2105, 101.9758], 6);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);

  const drawnItems = new L.FeatureGroup().addTo(map);
  new L.Control.Draw({
    draw: { polygon:true, rectangle:true, circle:false, polyline:false, marker:false, circlemarker:false },
    edit: { featureGroup: drawnItems, remove:true }
  }).addTo(map);

  const API_URL = @json(route('api.roads'));

// Multiple areas
let areas = [];        // [{ id, geometry, roads: [{key,label,...}] }]
let nextAreaId = 1;

let highlightLayer = null;

// ---------- basic Leaflet + draw setup ----------
const map = L.map('map').setView([5.58, 102.82], 13);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19,
  attribution: '&copy; OpenStreetMap'
}).addTo(map);

const drawnItems = new L.FeatureGroup();
map.addLayer(drawnItems);

const drawControl = new L.Control.Draw({
  draw: {
    polygon: true,
    rectangle: true,
    circle: false,
    circlemarker: false,
    polyline: false,
    marker: false,
  },
  edit: {
    featureGroup: drawnItems,
    edit: false,
    remove: false,
  }
});
map.addControl(drawControl);

// ---------- draw handler (ADD area instead of replacing) ----------
map.on(L.Draw.Event.CREATED, async (e) => {
  const layer = e.layer;
  drawnItems.addLayer(layer);

  const geometry = layer.toGeoJSON().geometry;

  const areaId = nextAreaId++;
  const area = { id: areaId, geometry, roads: [] };
  areas.push(area);

  const statsEl = document.getElementById('stats');
  statsEl.innerHTML = '<small>Fetching…</small>';
  const t0 = performance.now();

  try {
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({ geometry })
    });

    // If response is not JSON or is an error object, handle it gracefully
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (parseErr) {
      console.error('Failed to parse JSON:', text);
      throw new Error('Invalid JSON from API');
    }

    if (data.error) {
      console.error('API returned error:', data);
      statsEl.innerHTML = `<small style="color:#b00">API: ${data.error}</small>`;
      return;
    }

    const ms = Math.round(performance.now() - t0);

    const listData = data.roads || (data.names || []);
    const roads = (listData || []).map(item => {
      if (typeof item === 'string') {
        return { key: item, label: item };
      }
      return item; // {key,label,...}
    });

    area.roads = roads;
    renderAllAreas(ms);
  } catch (err) {
    console.error(err);
    document.getElementById('stats').innerHTML =
      '<small style="color:#b00">API error</small>';
    document.getElementById('list').innerHTML = '';
  }
});

// ---------- list rendering (all areas, with rename) ----------
function renderAllAreas(ms) {
  const stats = document.getElementById('stats');
  const list  = document.getElementById('list');
  list.innerHTML = '';

  let totalRoads = 0;

  areas.forEach((area, index) => {
    totalRoads += area.roads.length;

    // Area header
    const header = document.createElement('div');
    header.className = 'area-header';
    header.textContent = `Area ${index + 1} – ${area.roads.length} roads`;
    list.appendChild(header);

    // Roads under this area
    area.roads.forEach(road => {
      const pill = document.createElement('button');
      pill.className = 'pill';
      pill.textContent = road.label;

      pill.dataset.areaId = area.id;
      pill.dataset.key    = road.key;
      pill.dataset.label  = road.label;

      // Click → highlight this road within this area
      pill.onclick = () => highlightRoad(area.id, road.key);

      // Double-click → local rename (frontend only)
      pill.ondblclick = () => {
        const current = pill.dataset.label || pill.textContent;
        const renamed = prompt('Enter a local name for this road:', current);
        if (renamed && renamed.trim()) {
          const clean = renamed.trim();
          pill.dataset.label = clean;
          pill.textContent   = clean;

          // persist rename inside data structure
          const r = area.roads.find(r => r.key === road.key);
          if (r) r.label = clean;
        }
      };

      list.appendChild(pill);
    });
  });

  stats.innerHTML =
    `<small>${areas.length} areas, ${totalRoads} roads total` +
    (ms != null ? ` (${ms} ms last area)` : '') +
    `</small>`;
}

// ---------- click highlight ----------
async function highlightRoad(areaId, name) {
  const area = areas.find(a => a.id === areaId);
  if (!area) {
    alert('Area not found (maybe it was cleared?)');
    return;
  }

  const geometry = area.geometry;

  try {
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({ geometry, with_geom: true, name })
    });

    const text = await res.text();
    let fc;
    try {
      fc = JSON.parse(text);
    } catch (parseErr) {
      console.error('Failed to parse highlight JSON:', text);
      throw new Error('Invalid JSON from API (highlight)');
    }

    if (fc.error) {
      console.error('Highlight API returned error:', fc);
      alert('API error: ' + fc.error);
      return;
    }

    if (highlightLayer) {
      map.removeLayer(highlightLayer);
      highlightLayer = null;
    }

    highlightLayer = L.geoJSON(fc, {
      style: { weight: 4, color: 'orange' }
    }).addTo(map);

    try {
      map.fitBounds(highlightLayer.getBounds(), { padding: [20, 20] });
    } catch (e) {
      // ignore
    }
  } catch (err) {
    console.error(err);
    alert('Failed to fetch geometry');
  }
}

// ---------- clear all areas ----------
document.getElementById('clearAllBtn').addEventListener('click', () => {
  if (areas.length === 0) {
    alert('No areas to clear.');
    return;
  }

  if (!confirm('Clear all drawn areas and sidebar entries?')) return;

  drawnItems.clearLayers();
  areas = [];
  nextAreaId = 1;

  if (highlightLayer) {
    map.removeLayer(highlightLayer);
    highlightLayer = null;
  }

  document.getElementById('stats').innerHTML = '';
  document.getElementById('list').innerHTML  = '';
});

  </script>
</body>
</html>
