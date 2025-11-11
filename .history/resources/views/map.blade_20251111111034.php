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
  }
</style>
</head>
<body>
  <div class="map-page">
    <div id="map"></div>
    <div id="sidebar">
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
  // Multiple areas instead of a single geometry
  let areas = [];        // each = { id, geometry, roads: [...] }
  let nextAreaId = 1;

  let highlightLayer = null;

  // ---------- draw handler ----------
  map.on(L.Draw.Event.CREATED, async (e) => {
    const layer = e.layer;

    // Keep all drawn areas instead of clearing the old one
    drawnItems.addLayer(layer);

    // Geometry for this new area
    const geometry = layer.toGeoJSON().geometry;

    // Register a new area
    const areaId = nextAreaId++;
    const area = { id: areaId, geometry, roads: [] };
    areas.push(area);

    document.getElementById('stats').innerHTML = '<small>Fetching…</small>';
    const t0 = performance.now();

    try {
      const res = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type':'application/json','Accept':'application/json' },
        body: JSON.stringify({ geometry })   // with_geom is false / omitted
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);

      const data = await res.json();
      const ms = Math.round(performance.now() - t0);

      // Support both old response ({names: [...]}) and new ({roads: [...]})
      const listData = data.roads || (data.names || []);
      area.roads = (listData || []).map(item => {
        if (typeof item === 'string') {
          return { key: item, label: item };   // old style
        }
        return item;                           // { key, label, ... }
      });

      renderAllAreas(ms);
    } catch (err) {
      console.error(err);
      document.getElementById('stats').innerHTML =
        '<small style="color:#b00">API error</small>';
      document.getElementById('list').innerHTML = '';
    }
  });

  // ---------- list rendering (all areas) ----------
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

        // Highlight this road inside THIS area
        pill.onclick = () => highlightRoad(area.id, road.key);

        list.appendChild(pill);
      });
    });

    stats.innerHTML =
      `<small>${areas.length} areas, ${totalRoads} roads total` +
      (ms != null ? ` (${ms} ms last area)` : '') +
      `</small>`;
}

  // ---------- click highlight ----------
  async function highlightRoad(name){
    if(!currentGeometry) return alert('Draw an area first.');
    try {
      const res = await fetch(API_URL, {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({ geometry: currentGeometry, with_geom:true, name })
      });
      if(!res.ok){ alert('API '+res.status); return; }
      const fc = await res.json();
      if(highlightLayer) highlightLayer.remove();
      highlightLayer = L.geoJSON(fc,{style:{weight:4,color:'orange'}}).addTo(map);
      try{ map.fitBounds(highlightLayer.getBounds(),{padding:[20,20]}); }catch(e){}
    } catch(err){
      console.error(err);
      alert('Failed to fetch geometry');
    }
  }
    
  (async function() {
    // --- Elements
    const countrySearch = document.getElementById('countrySearch');
    const btnCountrySearch = document.getElementById('btnCountrySearch');
    const selCountry = document.getElementById('countrySel');
    const selState   = document.getElementById('stateSel');
    const selDistrict= document.getElementById('districtSel');

    // --- Helpers
    function option(lbl, val) {
      const o = document.createElement('option');
      o.textContent = lbl;
      o.value = val;
      return o;
    }
    function resetSelect(sel, placeholder, disabled=true) {
      sel.innerHTML = '';
      sel.appendChild(option(placeholder, ''));
      sel.firstChild.disabled = true;
      sel.firstChild.selected = true;
      sel.disabled = !!disabled;
    }
    function fitBbox(b) {
      if (!b) return;
      const [[s,w],[n,e]] = [[b[0],b[1]],[b[2],b[3]]];
      map.fitBounds([[s,w],[n,e]]);
      // leafet reflow safety if your layout changes
      setTimeout(()=>map.invalidateSize(),0);
    }
    async function api(url) {
      const r = await fetch(url);
      if (!r.ok) throw new Error('HTTP '+r.status);
      return r.json();
    }

    // --- Loaders
    async function searchCountries() {
      const q = countrySearch.value.trim();
      if (!q) return;
      resetSelect(selCountry, 'Choose a country…', false);
      resetSelect(selState, 'Choose a state…');
      resetSelect(selDistrict, 'Choose a district…');

      const url = new URL(ADMIN_AREAS_URL);
      url.searchParams.set('level', 2);
      url.searchParams.set('q', q);
      url.searchParams.set('limit', 50);

      const {items} = await api(url);
      items.forEach(it => selCountry.appendChild(option(it.name, JSON.stringify(it))));

      if (items.length === 1) {
        selCountry.value = JSON.stringify(items[0]);
        selCountry.dispatchEvent(new Event('change'));
      }
    }

    async function loadChildren(parentRelId, level, intoSel, placeholder) {
      resetSelect(intoSel, placeholder, false);
      const url = new URL(ADMIN_AREAS_URL);
      url.searchParams.set('parent_rel', parentRelId);
      url.searchParams.set('level', level);
      url.searchParams.set('limit', 200);
      const {items} = await api(url);
      items.forEach(it => intoSel.appendChild(option(it.name, JSON.stringify(it))));
      return items;
    }

    // --- Events
    btnCountrySearch.addEventListener('click', searchCountries);

    selCountry.addEventListener('change', async () => {
      const meta = JSON.parse(selCountry.value);
      fitBbox(meta.bbox);
      resetSelect(selState, 'Choose a state…', false);
      resetSelect(selDistrict, 'Choose a district…');
      await loadChildren(meta.rel_id, 4, selState, 'Choose a state…'); // admin_level=4
    });

    selState.addEventListener('change', async () => {
      const meta = JSON.parse(selState.value);
      fitBbox(meta.bbox);
      resetSelect(selDistrict, 'Choose a district…', false);
      await loadChildren(meta.rel_id, 6, selDistrict, 'Choose a district…'); // often 6; change to 7 if needed
    });

    selDistrict.addEventListener('change', () => {
      const meta = JSON.parse(selDistrict.value);
      fitBbox(meta.bbox);
    });

    // --- Optional: prefill Malaysia on load
    countrySearch.value = 'Malaysia';
    await searchCountries();
  })();
  </script>
</body>
</html>
