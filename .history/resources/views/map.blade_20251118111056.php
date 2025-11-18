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
  .pill {
  display: inline-block;
  padding: 4px 8px;
  margin: 2px 4px 2px 0;
  font-size: 13px;
  border-radius: 999px;
  border: 1px solid #ccc;
  background: #f4f4f4;
  cursor: pointer;
  }
  .pill-active {
    background: #ffebc2;
    border-color: #e67e22;
  }
  .building-list {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 6px;
  margin: 2px 0 6px;
  }

  .building-item {
    font-size: 12px;
    padding: 2px 6px;
    border-radius: 999px;
    border: 1px solid #ddd;
    background: #fafafa;
  }
</style>
</head>
<body>
  <div class="map-page">
    <div id="map"></div>
    <div id="sidebar">
      <button id="clearAllBtn" class="btn-clear">🗑 Clear All Areas</button>
      <div style="margin:8px 0;">
        <label style="font-size:12px; font-weight:600;">
          View
          <select id="viewSelect" style="margin-left:4px; padding:2px 6px; font-size:12px;">
            <option value="view1">View 1</option>
            <option value="view2">View 2</option>
          </select>
        </label>
      </div>
      <div style="display:flex; gap:4px; margin-bottom:8px;">
        <button id="saveViewsBtn" class="btn-clear" style="flex:1;">💾 Save views</button>
        <button id="loadViewsBtn" class="btn-clear" style="flex:1;">⟳ Load saved</button>
      </div>
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

    // --- Base maps ---
  const baseOSM = L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }
  );

  // Esri World Imagery (for satellite view)
  const baseEsriSat = L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { maxZoom: 19, attribution: 'Tiles &copy; Esri' }
  );

  // --- Map init ---
  const map = L.map('map', {
    center: [4.2105, 101.9758/* MY lng */],
    zoom: 6 /* original zoom */,
    layers: [baseOSM] // default base layer
  });

  const drawnItems = new L.FeatureGroup().addTo(map);
  new L.Control.Draw({
    draw: { polygon:true, rectangle:true, circle:false, polyline:false, marker:false, circlemarker:false },
    edit: { featureGroup: drawnItems, remove:true }
  }).addTo(map);

  const API_URL = @json(route('api.roads'));
  const VIEWS_LOAD_URL = @json(route('api.views.load', ['key' => 'default']));
  const VIEWS_SAVE_URL = @json(route('api.views.save', ['key' => 'default']));

  // ---------- multi-view state ----------
  // Define which logical "views" you want; you can add more keys later.
  const VIEW_KEYS = ['view1', 'view2'];
  let currentViewKey = 'view1';

  // Each view keeps its own areas + counter
  const views = {};
  VIEW_KEYS.forEach(key => {
    views[key] = {
      key,
      areas: [],      // [{ id, geometry, roads: [], buildings: [] }, ...]
      nextAreaId: 1,
    };
  });

  function getCurrentView() {
    return views[currentViewKey];
  }

  function getAreas() {
    return getCurrentView().areas;
  }

  function nextAreaId() {
    const v = getCurrentView();
    const id = v.nextAreaId;
    v.nextAreaId += 1;
    return id;
  }

  // Group that contains all highlighted roads
  const highlightGroup  = L.layerGroup().addTo(map);
  // key = `${areaId}::${roadKey}` → Leaflet layer
  const highlightLayers = {};

  // --- View selector element ---
  const viewSelect = document.getElementById('viewSelect');
  if (viewSelect) {
    viewSelect.value = currentViewKey;
    viewSelect.addEventListener('change', () => {
      switchView(viewSelect.value);
    });
  }

  // Rebuild the map + sidebar from whatever is in the current view
  function rebuildCurrentView() {
    // Clear all Leaflet layers
    drawnItems.clearLayers();
    highlightGroup.clearLayers();
    for (const k in highlightLayers) {
      delete highlightLayers[k];
    }

    const areas = getAreas();

    // Re-add each area's geometry
    areas.forEach(area => {
      try {
        const layer = L.geoJSON(area.geometry).getLayers()[0];
        if (layer) {
          drawnItems.addLayer(layer);
        }
      } catch (e) {
        console.error('Failed to restore area geometry', e);
      }
    });

    // Re-render sidebar list (this will also restore pill "active" state)
    renderAllAreas();

    // Rebuild highlighted roads for this view by calling the same API again
    areas.forEach(area => {
      area.roads
        .filter(r => r.isHighlighted)
        .forEach(r => {
          // fire-and-forget; we don't need to await here, and silent so map doesn’t keep re-fitBounds
          toggleHighlightRoad(area.id, r.key, null, { silent: true });
        });
    });
  }

  function switchView(newKey) {
    if (!views[newKey] || newKey === currentViewKey) return;
    currentViewKey = newKey;
    if (viewSelect && viewSelect.value !== newKey) {
      viewSelect.value = newKey;
    }
    rebuildCurrentView();
  }

  L.control.layers(
    {
      'Standard': baseOSM,
      'Satellite (Esri)': baseEsriSat
    },
    {
      'Drawn areas': drawnItems,
      'Highlighted roads': typeof highlightGroup !== 'undefined' ? highlightGroup : undefined
    },
    { collapsed: false }
  ).addTo(map);

  L.control.layers(
  {
    'Standard': baseOSM,
    'Satellite (Esri)': baseEsriSat
  },
  {
    'Drawn areas': drawnItems,
    'Highlighted roads': typeof highlightGroup !== 'undefined' ? highlightGroup : undefined
  },
  { collapsed: false } // set to true if want it minimized
  ).addTo(map);

  // ---------- draw handler ----------
  map.on(L.Draw.Event.CREATED, async (e) => {
    const layer = e.layer;

    // Keep all drawn areas instead of clearing the old one
    drawnItems.addLayer(layer);

    // Geometry for this new area
    const geometry = layer.toGeoJSON().geometry;

    // Register a new area
    const areaId = nextAreaId();
    const area = { id: areaId, geometry, roads: [], buildings: [] };
    getAreas().push(area);

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

      // NEW: buildings / lots (may be empty)
      area.buildings = Array.isArray(data.buildings) ? data.buildings : [];

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
    const areas = getAreas(); // get areas for current view
    
    // loop over all areas * for current view *
    areas.forEach((area, index) => {
      totalRoads += area.roads.length;

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

        // Restore active state
        if(road.isHighlighted) {
          pill.classList.add('pill-active');
        }

        // Multi-select highlight
        pill.onclick = () => toggleHighlightRoad(area.id, road.key, pill);

        pill.ondblclick = () => {
          const current = pill.dataset.label || pill.textContent;
          const renamed = prompt('Enter a local name for this road:', current);
          if (renamed && renamed.trim()) {
            const clean = renamed.trim();
            pill.dataset.label = clean;
            pill.textContent   = clean;
            const r = area.roads.find(r => r.key === road.key);
            if (r) r.label = clean;
          }
        };

        list.appendChild(pill);
      });

      // Buildings / lots in this area
      if (area.buildings && area.buildings.length) {
        const bHeader = document.createElement('div');
        bHeader.style.marginTop = '4px';
        bHeader.style.fontSize  = '12px';
        bHeader.style.fontWeight = '500';
        bHeader.textContent = `Buildings / lots (${area.buildings.length})`;
        list.appendChild(bHeader);

        // Wrap all buildings for this area in a container
        const bList = document.createElement('div');
        bList.className = 'building-list';
        list.appendChild(bList);

        area.buildings.forEach(b => {
          const row = document.createElement('div');
          row.className = 'building-item';

          const lotLabel  = b.lot_no ? `Lot ${b.lot_no}` : '';
          const nameLabel = b.name   ? b.name : '';

          row.textContent = [lotLabel, nameLabel].filter(Boolean).join(' – ');
          bList.appendChild(row);
        });
      }
    });

      stats.innerHTML =
        `<small>${areas.length} areas, ${totalRoads} roads total` +
        (ms != null ? ` (${ms} ms last area)` : '') +
        `</small>`;
  }

    // ---------- toggle highlight (multi-select) ----------
    async function toggleHighlightRoad(areaId, name, pillEl, options = {}) {
    const areas = getAreas();
    const area = areas.find(a => a.id === areaId);
    if (!area) {
      alert('Area not found (maybe it was cleared?)');
      return;
    }

    const key   = areaId + '::' + name;
    const road  = area.roads.find(r => r.key === name);
    const silent = options.silent === true;

    // 1) If already highlighted → unhighlight & untoggle
    if (highlightLayers[key]) {
      highlightGroup.removeLayer(highlightLayers[key]);
      delete highlightLayers[key];
      if (pillEl) pillEl.classList.remove('pill-active');
      if (road) road.isHighlighted = false;
      return;
    }

    // 2) Not highlighted yet → fetch geometry and add
    try {
      const geometry = area.geometry;

      const res = await fetch(API_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ geometry, with_geom: true, name })
      });

      if (!res.ok) {
        alert('API ' + res.status);
        return;
      }

      const fc = await res.json();

      const layer = L.geoJSON(fc, { style: { weight: 4, color: 'orange' } })
        .addTo(highlightGroup);

      highlightLayers[key] = layer;
      if (pillEl) pillEl.classList.add('pill-active');
      if (road) road.isHighlighted = true;   // <-- remember in current view

      if (!silent) {
        try {
          map.fitBounds(layer.getBounds(), { padding: [20, 20] });
        } catch (e) {}
      }
    } catch (err) {
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

    // --- prefill Malaysia on load
    countrySearch.value = 'Malaysia';
    await searchCountries();
  })();

  // ---------- clear all areas ----------
    document.getElementById('clearAllBtn').addEventListener('click', () => {
      if (areas.length === 0) {
        alert('No areas to clear.');
        return;
      }

      if (!confirm('Clear all drawn areas and sidebar entries?')) return;

      // Remove all drawn shapes
      drawnItems.clearLayers();

      // Reset data
      const v = getCurrentView();
      v.areas = [];
      v.nextAreaId = 1;

      // Remove all highlighted roads
      highlightGroup.clearLayers();
      for (const k in highlightLayers) {
        delete highlightLayers[k];
      }

      // Clear sidebar
      document.getElementById('stats').innerHTML = '';
      document.getElementById('list').innerHTML = '';

      alert('All areas cleared.');
    });
  </script>
</body>
</html>
