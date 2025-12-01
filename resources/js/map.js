  // Read URLs that Blade exposes on window.MapConfig
    const cfg = window.MapConfig || {};

    const ADMIN_AREAS_URL   = cfg.adminAreasUrl;
    const ADMIN_GEOM_TMPL   = cfg.adminGeomTemplateUrl;
    const API_URL           = cfg.apiUrl;
    const VIEWS_LOAD_URL    = cfg.viewsLoadUrl;
    const VIEWS_SAVE_URL    = cfg.viewsSaveUrl;
  
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
    draw: { polygon:true, rectangle:true, circle:false, polyline:true, marker:false, circlemarker:false },
    edit: { featureGroup: drawnItems, remove:true }
  }).addTo(map);

  // ---------- multi-view state ----------
  // Define which logical "views" you want; you can add more keys later.
  const VIEW_KEYS = ['view1', 'view2'];
  let currentViewKey = 'view1';

  // Each view keeps its own areas + counter
  const views = {};
  VIEW_KEYS.forEach(key => {
    views[key] = {
      key,
      areas: [],          // [{ id, geometry, roads: [], buildings: [] }, ...]
      nextAreaId: 1,

      // NEW: user-drawn roads (polylines) that don’t exist in OSM
      customRoads: [],    // [{ id, name, geometry }, ...]
      nextRoadId: 1,
    };
  });

  function getCurrentView() {
    return views[currentViewKey];
  }

  function getAreas() {
    return getCurrentView().areas;
  }

  // helper for custom roads
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

    const areas       = getAreas();
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

    // Re-render sidebar list (this will also restore pill "active" state)
    renderAllAreas();

    // Rebuild highlighted OSM roads for this view
    areas.forEach(area => {
      area.roads
        .filter(r => r.isHighlighted)
        .forEach(r => {
          // fire-and-forget; silent so map doesn’t keep re-fitBounds
          toggleHighlightRoad(area.id, r.key, null, { silent: true });
        });
    });

    // Rebuild highlighted custom roads from stored geometry
    customRoads
      .filter(r => r.isHighlighted)
      .forEach(r => {
        toggleHighlightCustomRoad(r.id, null, { silent: true });
      });
  }

  loadViewsFromServer(); // load saved state on initial load

  function switchView(newKey) {
    if (!views[newKey] || newKey === currentViewKey) return;
    currentViewKey = newKey;
    if (viewSelect && viewSelect.value !== newKey) {
      viewSelect.value = newKey;
    }
    rebuildCurrentView();
  }
  // --- SAVE CURRENT STATE OF ALL VIEWS TO SERVER ---
  async function saveAllViewsToServer() {
    try {
      const res = await fetch(VIEWS_SAVE_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          data: views   // <-- the full views object (view1, view2, etc.)
        })
      });

      if (!res.ok) {
        console.error('Save failed', res.status);
        alert('Failed to save views (HTTP ' + res.status + ')');
        return;
      }

      const json = await res.json();
      console.log('Views saved at', json.updated_at);
      // Optional toast: alert('Saved!');
    } catch (err) {
      console.error(err);
      alert('Error while saving views');
    }
  }

  // --- LOAD SAVED STATE FROM SERVER (ON DEMAND) ---
  async function loadViewsFromServer() {
    try {
      const res = await fetch(VIEWS_LOAD_URL, {
        headers: {
          'Accept': 'application/json'
        }
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

      const data = await res.json(); // should be the saved "views" object

      // Overwrite our local views, but only for keys that exist
      Object.keys(data).forEach(key => {
        if (views[key]) {
          views[key].areas       = data[key].areas       || [];
          views[key].nextAreaId  = data[key].nextAreaId  || 1;
          views[key].customRoads = data[key].customRoads || [];
          views[key].nextRoadId  = data[key].nextRoadId  || 1;
        }
      });

      // After loading, rebuild the current view (view1 by default)
      rebuildCurrentView();
    } catch (err) {
      console.error(err);
      alert('Error while loading saved views');
    }
  }

  // --- Attach to buttons ---
  const saveViewsBtn = document.getElementById('saveViewsBtn');
  if (saveViewsBtn) {
    saveViewsBtn.addEventListener('click', saveAllViewsToServer);
  }

  const loadViewsBtn = document.getElementById('loadViewsBtn');
  if (loadViewsBtn) {
    loadViewsBtn.addEventListener('click', loadViewsFromServer);
  }
  // ---------- layer control ----------
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
    const layerType = e.layerType;   // 'polygon', 'rectangle', 'polyline', etc.
    const layer     = e.layer;

    // All drawn things go into the edit group so user can move/delete them later
    drawnItems.addLayer(layer);

    const geometry = layer.toGeoJSON().geometry;

    // -------------------------------------------------
    //  A) If user drew a POLYLINE → treat as custom road
    // -------------------------------------------------
    if (layerType === 'polyline') {
      let name = prompt('Name this road (e.g. "Jalan Baru 1"):', '');
      if (name) name = name.trim();
      if (!name || !name.length) {
        name = 'Unnamed road';
      }

      const roadId = nextRoadId();
      const roads  = getCustomRoads();
      roads.push({
        id: roadId,
        name,
        geometry
      });

      // Optional: attach id so we can refer back later if needed
      layer._customRoadId = roadId;

      // Re-render sidebar + persist
      renderAllAreas();
      // (optional but recommended) auto-save state
      // saveAllViewsToServer();

      return; // IMPORTANT: do NOT call Overpass for custom road
    }

    // -------------------------------------------------
    //  B) Otherwise → treat as AREA (polygon/rectangle)
    // -------------------------------------------------
    // Register a new area
    const areaId = nextAreaId();
    const area   = { id: areaId, geometry, roads: [], buildings: [] };
    getAreas().push(area);

    document.getElementById('stats').innerHTML = '<small>Fetching…</small>';
    const t0 = performance.now();

    try {
      const res = await fetch(API_URL, {
        method: 'POST',
        headers: {
          'Content-Type':'application/json',
          'Accept':'application/json'
        },
        body: JSON.stringify({ geometry })   // Overpass query
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);

      const data = await res.json();
      const ms   = Math.round(performance.now() - t0);

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

      // Build the label: Area X – [Name] – N roads
      let label = `Area ${index + 1}`;
      if (area.customName) {
        label += ` – ${area.customName}`;
      }
      label += ` – ${area.roads.length} roads`;

      header.textContent = label;
      header.title = 'Double-click to rename this area';
      list.appendChild(header);

      // Allow renaming the area itself
      header.ondblclick = () => {
        const current = area.customName || '';
        const renamed = prompt('New name for this area:', current);
        if (renamed === null) return; // user cancelled

        const clean = renamed.trim();
        area.customName = clean.length ? clean : null;

        renderAllAreas();        // refresh the list
        saveAllViewsToServer();  // keep DB in sync
      };

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

    // ---- AFTER we listed all areas, show custom roads for this view ----
    const v           = getCurrentView();
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

        // Restore active (highlighted) state after reload
        if (road.isHighlighted) {
          pill.classList.add('pill-active');
        }

        // Click = toggle yellow highlight on/off
        pill.onclick = () => toggleHighlightCustomRoad(road.id, pill);

        // OPTIONAL: double-click to rename
        pill.ondblclick = () => {
          const current = road.name || '';
          const renamed = prompt('Rename this custom road:', current);
          if (!renamed || !renamed.trim()) return;
          const clean = renamed.trim();
          road.name   = clean;
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

      // ---------- toggle highlight for custom user-drawn roads ----------
    function toggleHighlightCustomRoad(roadId, pillEl, options = {}) {
      const roads  = getCustomRoads();
      const road   = roads.find(r => r.id === roadId);
      if (!road) {
        alert('Custom road not found (maybe it was cleared?)');
        return;
      }

      const key    = 'custom::' + roadId;
      const silent = options.silent === true;

      // Already highlighted → unhighlight
      if (highlightLayers[key]) {
        highlightGroup.removeLayer(highlightLayers[key]);
        delete highlightLayers[key];

        if (pillEl) pillEl.classList.remove('pill-active');
        road.isHighlighted = false;
        return;
      }

      // Not highlighted yet → add yellow overlay using stored geometry
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
    const v     = getCurrentView();  // current view object
    const areas = v.areas;
    const customRoads = v.customRoads;

    if (!areas || areas.length === 0) {
      alert('No areas to clear in this view.');
      return;
    }
    if (!confirm('Clear all drawn areas, custom roads and highlights for ' + currentViewKey + '?')) {
      return;
    }
    // 1) Remove all drawn shapes from the map
    drawnItems.clearLayers();

    // 2) Reset the current view's data
    v.areas = [];
    v.nextAreaId = 1;
    v.customRoads = [];
    v.nextRoadId  = 1;

    // 3) Remove all highlighted roads (for this view)
    highlightGroup.clearLayers();
    for (const k in highlightLayers) {
      delete highlightLayers[k];
    }
    // 4) Re-render sidebar (stats + list) for the now-empty view
    renderAllAreas();
  });
