/**
 * Map Dashboard JavaScript
 * Location: public/js/map.js
 * 
 * IMPORTANT: This file expects the following global variables to be defined
 * by the Blade template before this script loads:
 *   - window.MAP_CONFIG.ADMIN_AREAS_URL
 *   - window.MAP_CONFIG.ADMIN_GEOM_TMPL
 *   - window.MAP_CONFIG.API_URL
 *   - window.MAP_CONFIG.VIEWS_LOAD_URL
 *   - window.MAP_CONFIG.VIEWS_SAVE_URL
 */

(function() {
  'use strict';

  // ============================================================
  // Configuration (injected from Blade)
  // ============================================================
  const CONFIG = window.MAP_CONFIG || {};
  const ADMIN_AREAS_URL = CONFIG.ADMIN_AREAS_URL;
  const ADMIN_GEOM_TMPL = CONFIG.ADMIN_GEOM_TMPL;
  const API_URL         = CONFIG.API_URL;
  const VIEWS_LOAD_URL  = CONFIG.VIEWS_LOAD_URL;
  const VIEWS_SAVE_URL  = CONFIG.VIEWS_SAVE_URL;

  // ============================================================
  // Base Map Layers
  // ============================================================
  const baseOSM = L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }
  );

  const baseEsriSat = L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { maxZoom: 19, attribution: 'Tiles &copy; Esri' }
  );

  // ============================================================
  // Map Initialization
  // ============================================================
  const map = L.map('map', {
    center: [4.2105, 101.9758],  // Malaysia
    zoom: 6,
    layers: [baseOSM]
  });

  const drawnItems = new L.FeatureGroup().addTo(map);
  
  new L.Control.Draw({
    draw: {
      polygon: true,
      rectangle: true,
      circle: false,
      polyline: true,
      marker: false,
      circlemarker: false
    },
    edit: {
      featureGroup: drawnItems,
      remove: true
    }
  }).addTo(map);

  // Group for highlighted roads
  const highlightGroup = L.layerGroup().addTo(map);
  const highlightLayers = {};

  // Layer control
  L.control.layers(
    {
      'Standard': baseOSM,
      'Satellite (Esri)': baseEsriSat
    },
    {
      'Drawn areas': drawnItems,
      'Highlighted roads': highlightGroup
    },
    { collapsed: false }
  ).addTo(map);

  // ============================================================
  // Multi-View State Management
  // ============================================================
  const VIEW_KEYS = ['view1', 'view2'];
  let currentViewKey = 'view1';

  const views = {};
  VIEW_KEYS.forEach(key => {
    views[key] = {
      key,
      areas: [],
      nextAreaId: 1,
      customRoads: [],
      nextRoadId: 1,
    };
  });

  function getCurrentView() {
    return views[currentViewKey];
  }

  function getAreas() {
    return getCurrentView().areas;
  }

  function getCustomRoads() {
    const v = getCurrentView();
    if (!v.customRoads) v.customRoads = [];
    return v.customRoads;
  }

  function nextAreaId() {
    const v = getCurrentView();
    const id = v.nextAreaId;
    v.nextAreaId += 1;
    return id;
  }

  function nextRoadId() {
    const v = getCurrentView();
    if (!v.nextRoadId) v.nextRoadId = 1;
    const id = v.nextRoadId;
    v.nextRoadId += 1;
    return id;
  }

  // ============================================================
  // View Selector
  // ============================================================
  const viewSelect = document.getElementById('viewSelect');
  if (viewSelect) {
    viewSelect.value = currentViewKey;
    viewSelect.addEventListener('change', () => {
      switchView(viewSelect.value);
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

  // ============================================================
  // Rebuild Current View
  // ============================================================
  function rebuildCurrentView() {
    // Clear all Leaflet layers
    drawnItems.clearLayers();
    highlightGroup.clearLayers();
    for (const k in highlightLayers) {
      delete highlightLayers[k];
    }

    const areas = getAreas();
    const customRoads = getCustomRoads();

    // Re-add each area's geometry
    areas.forEach(area => {
      try {
        const layer = L.geoJSON(area.geometry).getLayers()[0];
        if (layer) {
          drawnItems.addLayer(layer);

          if (area.customName) {
            layer.bindTooltip(area.customName, {
              permanent: true,
              direction: 'center',
              className: 'area-label'
            });
          }
        }
      } catch (e) {
        console.error('Failed to restore area geometry', e);
      }
    });

    // Re-add custom roads (polylines)
    customRoads.forEach(road => {
      try {
        const feature = {
          type: 'Feature',
          geometry: road.geometry,
          properties: {}
        };
        const layer = L.geoJSON(feature).getLayers()[0];
        if (layer) {
          layer._customRoadId = road.id;
          drawnItems.addLayer(layer);
        }
      } catch (e) {
        console.error('Failed to restore custom road', e);
      }
    });

    // Re-render sidebar list
    renderAllAreas();

    // Rebuild highlighted OSM roads for this view
    areas.forEach(area => {
      area.roads
        .filter(r => r.isHighlighted)
        .forEach(r => {
          toggleHighlightRoad(area.id, r.key, null, { silent: true });
        });
    });

    // Rebuild highlighted custom roads
    customRoads
      .filter(r => r.isHighlighted)
      .forEach(r => {
        toggleHighlightCustomRoad(r.id, null, { silent: true });
      });
  }

  // ============================================================
  // Server Persistence
  // ============================================================
  async function saveAllViewsToServer() {
    try {
      const res = await fetch(VIEWS_SAVE_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ data: views })
      });

      if (!res.ok) {
        console.error('Save failed', res.status);
        alert('Failed to save views (HTTP ' + res.status + ')');
        return;
      }

      const json = await res.json();
      console.log('Views saved at', json.updated_at);
    } catch (err) {
      console.error(err);
      alert('Error while saving views');
    }
  }

  async function loadViewsFromServer() {
    try {
      const res = await fetch(VIEWS_LOAD_URL, {
        headers: { 'Accept': 'application/json' }
      });

      if (!res.ok) {
        if (res.status === 404) {
          console.log('No saved view state yet');
          return;
        }
        console.error('Load failed', res.status);
        alert('Failed to load saved views (HTTP ' + res.status + ')');
        return;
      }

      const data = await res.json();

      Object.keys(data).forEach(key => {
        if (views[key]) {
          views[key].areas       = data[key].areas       || [];
          views[key].nextAreaId  = data[key].nextAreaId  || 1;
          views[key].customRoads = data[key].customRoads || [];
          views[key].nextRoadId  = data[key].nextRoadId  || 1;
        }
      });

      rebuildCurrentView();
    } catch (err) {
      console.error(err);
      alert('Error while loading saved views');
    }
  }

  // Attach to buttons
  const saveViewsBtn = document.getElementById('saveViewsBtn');
  if (saveViewsBtn) {
    saveViewsBtn.addEventListener('click', saveAllViewsToServer);
  }

  const loadViewsBtn = document.getElementById('loadViewsBtn');
  if (loadViewsBtn) {
    loadViewsBtn.addEventListener('click', loadViewsFromServer);
  }

  // ============================================================
  // Draw Handler
  // ============================================================
  map.on(L.Draw.Event.CREATED, async (e) => {
    const layerType = e.layerType;
    const layer = e.layer;

    drawnItems.addLayer(layer);
    const geometry = layer.toGeoJSON().geometry;

    // A) If user drew a POLYLINE → treat as custom road
    if (layerType === 'polyline') {
      let name = prompt('Name this road (e.g. "Jalan Baru 1"):', '');
      if (name) name = name.trim();
      if (!name || !name.length) {
        name = 'Unnamed road';
      }

      const roadId = nextRoadId();
      const roads = getCustomRoads();
      roads.push({ id: roadId, name, geometry });

      layer._customRoadId = roadId;
      renderAllAreas();
      return;
    }

    // B) Otherwise → treat as AREA (polygon/rectangle)
    const areaId = nextAreaId();
    const area = { id: areaId, geometry, roads: [], buildings: [] };
    getAreas().push(area);

    document.getElementById('stats').innerHTML = '<small>Fetching…</small>';
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
      
      if (!res.ok) throw new Error('HTTP ' + res.status);

      const data = await res.json();
      const ms = Math.round(performance.now() - t0);

      const listData = data.roads || (data.names || []);
      area.roads = (listData || []).map(item => {
        if (typeof item === 'string') {
          return { key: item, label: item };
        }
        return item;
      });

      area.buildings = Array.isArray(data.buildings) ? data.buildings : [];
      renderAllAreas(ms);
    } catch (err) {
      console.error(err);
      document.getElementById('stats').innerHTML = '<small style="color:#b00">API error</small>';
      document.getElementById('list').innerHTML = '';
    }
  });

  // ============================================================
  // Render All Areas in Sidebar
  // ============================================================
  function renderAllAreas(ms) {
    const stats = document.getElementById('stats');
    const list = document.getElementById('list');
    list.innerHTML = '';

    let totalRoads = 0;
    const areas = getAreas();

    areas.forEach((area, index) => {
      totalRoads += area.roads.length;

      // Area header
      const header = document.createElement('div');
      header.className = 'area-header';

      let label = `Area ${index + 1}`;
      if (area.customName) {
        label += ` – ${area.customName}`;
      }
      label += ` – ${area.roads.length} roads`;

      header.textContent = label;
      header.title = 'Double-click to rename this area';
      list.appendChild(header);

      // Rename area on double-click
      header.ondblclick = () => {
        const current = area.customName || '';
        const renamed = prompt('New name for this area:', current);
        if (renamed === null) return;

        const clean = renamed.trim();
        area.customName = clean.length ? clean : null;
        renderAllAreas();
        saveAllViewsToServer();
      };

      // Road pills
      area.roads.forEach(road => {
        const pill = document.createElement('button');
        pill.className = 'pill';
        pill.textContent = road.label;

        pill.dataset.areaId = area.id;
        pill.dataset.key = road.key;
        pill.dataset.label = road.label;

        if (road.isHighlighted) {
          pill.classList.add('pill-active');
        }

        pill.onclick = () => toggleHighlightRoad(area.id, road.key, pill);

        pill.ondblclick = () => {
          const current = pill.dataset.label || pill.textContent;
          const renamed = prompt('Enter a local name for this road:', current);
          if (renamed && renamed.trim()) {
            const clean = renamed.trim();
            pill.dataset.label = clean;
            pill.textContent = clean;
            const r = area.roads.find(r => r.key === road.key);
            if (r) r.label = clean;
          }
        };

        list.appendChild(pill);
      });

      // Buildings / lots
      if (area.buildings && area.buildings.length) {
        const bHeader = document.createElement('div');
        bHeader.style.marginTop = '4px';
        bHeader.style.fontSize = '12px';
        bHeader.style.fontWeight = '500';
        bHeader.textContent = `Buildings / lots (${area.buildings.length})`;
        list.appendChild(bHeader);

        const bList = document.createElement('div');
        bList.className = 'building-list';
        list.appendChild(bList);

        area.buildings.forEach(b => {
          const row = document.createElement('div');
          row.className = 'building-item';

          const lotLabel = b.lot_no ? `Lot ${b.lot_no}` : '';
          const nameLabel = b.name ? b.name : '';

          row.textContent = [lotLabel, nameLabel].filter(Boolean).join(' – ');
          bList.appendChild(row);
        });
      }
    });

    // Custom roads section
    const v = getCurrentView();
    const customRoads = (v && v.customRoads) ? v.customRoads : [];

    if (customRoads.length) {
      const crHeader = document.createElement('div');
      crHeader.className = 'area-header';
      crHeader.textContent = `Custom roads – ${customRoads.length}`;
      list.appendChild(crHeader);

      customRoads.forEach(road => {
        const pill = document.createElement('button');
        pill.className = 'pill';
        pill.textContent = road.name || '(unnamed road)';
        pill.dataset.roadId = road.id;

        if (road.isHighlighted) {
          pill.classList.add('pill-active');
        }

        pill.onclick = () => toggleHighlightCustomRoad(road.id, pill);

        pill.ondblclick = () => {
          const current = road.name || '';
          const renamed = prompt('Rename this custom road:', current);
          if (!renamed || !renamed.trim()) return;
          const clean = renamed.trim();
          road.name = clean;
          pill.textContent = clean;
          saveAllViewsToServer();
        };

        list.appendChild(pill);
      });
    }

    stats.innerHTML =
      `<small>${areas.length} areas, ${totalRoads} roads total` +
      (customRoads.length ? `, ${customRoads.length} custom roads` : '') +
      (ms != null ? ` (${ms} ms last area)` : '') +
      `</small>`;
  }

  // ============================================================
  // Toggle Highlight for Custom Roads
  // ============================================================
  function toggleHighlightCustomRoad(roadId, pillEl, options = {}) {
    const roads = getCustomRoads();
    const road = roads.find(r => r.id === roadId);
    if (!road) {
      alert('Custom road not found (maybe it was cleared?)');
      return;
    }

    const key = 'custom::' + roadId;
    const silent = options.silent === true;

    // Already highlighted → unhighlight
    if (highlightLayers[key]) {
      highlightGroup.removeLayer(highlightLayers[key]);
      delete highlightLayers[key];

      if (pillEl) pillEl.classList.remove('pill-active');
      road.isHighlighted = false;
      return;
    }

    // Not highlighted yet → add overlay
    try {
      const feature = {
        type: 'Feature',
        geometry: road.geometry,
        properties: {}
      };

      const layer = L.geoJSON(feature, {
        style: { weight: 4, color: 'orange' }
      }).addTo(highlightGroup);

      highlightLayers[key] = layer;
      if (pillEl) pillEl.classList.add('pill-active');
      road.isHighlighted = true;

      if (!silent) {
        try {
          map.fitBounds(layer.getBounds(), { padding: [20, 20] });
        } catch (e) {}
      }
    } catch (err) {
      console.error('Failed to highlight custom road', err);
    }
  }

  // ============================================================
  // Toggle Highlight for OSM Roads
  // ============================================================
  async function toggleHighlightRoad(areaId, name, pillEl, options = {}) {
    const areas = getAreas();
    const area = areas.find(a => a.id === areaId);
    if (!area) {
      alert('Area not found (maybe it was cleared?)');
      return;
    }

    const key = areaId + '::' + name;
    const road = area.roads.find(r => r.key === name);
    const silent = options.silent === true;

    // If already highlighted → unhighlight
    if (highlightLayers[key]) {
      highlightGroup.removeLayer(highlightLayers[key]);
      delete highlightLayers[key];
      if (pillEl) pillEl.classList.remove('pill-active');
      if (road) road.isHighlighted = false;
      return;
    }

    // Not highlighted yet → fetch geometry and add
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
      if (road) road.isHighlighted = true;

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

  // ============================================================
  // Admin Area Filters (Country/State/District)
  // ============================================================
  (async function initAdminFilters() {
    const countrySearch = document.getElementById('countrySearch');
    const btnCountrySearch = document.getElementById('btnCountrySearch');
    const selCountry = document.getElementById('countrySel');
    const selState = document.getElementById('stateSel');
    const selDistrict = document.getElementById('districtSel');

    function option(lbl, val) {
      const o = document.createElement('option');
      o.textContent = lbl;
      o.value = val;
      return o;
    }

    function resetSelect(sel, placeholder, disabled = true) {
      sel.innerHTML = '';
      sel.appendChild(option(placeholder, ''));
      sel.firstChild.disabled = true;
      sel.firstChild.selected = true;
      sel.disabled = !!disabled;
    }

    function fitBbox(b) {
      if (!b) return;
      const [[s, w], [n, e]] = [[b[0], b[1]], [b[2], b[3]]];
      map.fitBounds([[s, w], [n, e]]);
      setTimeout(() => map.invalidateSize(), 0);
    }

    async function api(url) {
      const r = await fetch(url);
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }

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

      const { items } = await api(url);
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
      const { items } = await api(url);
      items.forEach(it => intoSel.appendChild(option(it.name, JSON.stringify(it))));
      return items;
    }

    // Events
    btnCountrySearch.addEventListener('click', searchCountries);

    selCountry.addEventListener('change', async () => {
      const meta = JSON.parse(selCountry.value);
      fitBbox(meta.bbox);
      resetSelect(selState, 'Choose a state…', false);
      resetSelect(selDistrict, 'Choose a district…');
      await loadChildren(meta.rel_id, 4, selState, 'Choose a state…');
    });

    selState.addEventListener('change', async () => {
      const meta = JSON.parse(selState.value);
      fitBbox(meta.bbox);
      resetSelect(selDistrict, 'Choose a district…', false);
      await loadChildren(meta.rel_id, 6, selDistrict, 'Choose a district…');
    });

    selDistrict.addEventListener('change', () => {
      const meta = JSON.parse(selDistrict.value);
      fitBbox(meta.bbox);
    });

    // Prefill Malaysia on load
    countrySearch.value = 'Malaysia';
    await searchCountries();
  })();

  // ============================================================
  // Clear All Areas Button
  // ============================================================
  document.getElementById('clearAllBtn').addEventListener('click', () => {
    const v = getCurrentView();
    const areas = v.areas;

    if (!areas || areas.length === 0) {
      alert('No areas to clear in this view.');
      return;
    }
    if (!confirm('Clear all drawn areas, custom roads and highlights for ' + currentViewKey + '?')) {
      return;
    }

    drawnItems.clearLayers();

    v.areas = [];
    v.nextAreaId = 1;
    v.customRoads = [];
    v.nextRoadId = 1;

    highlightGroup.clearLayers();
    for (const k in highlightLayers) {
      delete highlightLayers[k];
    }

    renderAllAreas();
  });

  // ============================================================
  // Initial Load
  // ============================================================
  loadViewsFromServer();

})();
