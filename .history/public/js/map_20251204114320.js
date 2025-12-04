/**
 * Map Dashboard JavaScript - PostGIS Version
 * Location: public/js/map.js
 * 
 * This version saves spatial data to PostGIS via the SpatialController.
 * Updated: Added basemap switcher like VM4
 */

(function() {
  'use strict';

  // ============================================================
  // Configuration (injected from Blade)
  // ============================================================
  const CONFIG = window.MAP_CONFIG || {};
  const ADMIN_AREAS_URL = CONFIG.ADMIN_AREAS_URL;
  const API_URL = CONFIG.API_URL;  // Overpass endpoint for road queries
  
  // PostGIS endpoints
  const SPATIAL_VIEWS_URL = CONFIG.SPATIAL_VIEWS_URL;   // GET list, POST create
  const SPATIAL_VIEW_URL = CONFIG.SPATIAL_VIEW_URL;     // Base for /views/{key}
  const SPATIAL_AREAS_URL = CONFIG.SPATIAL_AREAS_URL;   // Base for /views/{key}/areas
  const SPATIAL_ROADS_URL = CONFIG.SPATIAL_ROADS_URL;   // Base for /views/{key}/roads

  // ============================================================
  // Base Map Layers (Updated with more options like VM4)
  // ============================================================
  
  // OpenStreetMap
  const baseOSM = L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }
  );

  // Google Satellite (pure imagery)
  const googleSat = L.tileLayer(
    'http://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}&hl=en',
    { maxZoom: 20, subdomains: ['mt0', 'mt1', 'mt2', 'mt3'], attribution: 'Google' }
  );

  // Google Hybrid (satellite + labels)
  const googleHybrid = L.tileLayer(
    'http://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}&hl=en',
    { maxZoom: 20, subdomains: ['mt0', 'mt1', 'mt2', 'mt3'], attribution: 'Google' }
  );

  // Carto Light
  const cartoLight = L.tileLayer(
    'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
    { maxZoom: 20, attribution: '&copy; CARTO' }
  );

  // Esri Satellite
  const baseEsriSat = L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { maxZoom: 19, attribution: 'Tiles &copy; Esri' }
  );

  // Basemap dictionary (matches data-basemap values in HTML)
  const basemaps = {
    'osm': baseOSM,
    'satellite': googleSat,
    'hybrid': googleHybrid,
    'carto': cartoLight,
    'esri': baseEsriSat
  };

  // Track current basemap
  let currentBasemap = 'osm';

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

  // Simplified layer control (only for drawn items, not basemaps)
  L.control.layers(
    null,  // No basemap options here - we use buttons instead
    {
      'Drawn areas': drawnItems,
      'Highlighted roads': highlightGroup
    },
    { collapsed: false }
  ).addTo(map);

  // ============================================================
  // Basemap Switcher (NEW - like VM4)
  // ============================================================
  function initBasemapSwitcher() {
    const buttons = document.querySelectorAll('.basemap-btn');
    
    buttons.forEach(btn => {
      btn.addEventListener('click', () => {
        const basemapKey = btn.dataset.basemap;
        
        // Skip if already active
        if (basemapKey === currentBasemap) return;
        
        // Remove current basemap from map
        if (basemaps[currentBasemap]) {
          map.removeLayer(basemaps[currentBasemap]);
        }
        
        // Add new basemap and send to back
        if (basemaps[basemapKey]) {
          basemaps[basemapKey].addTo(map);
          basemaps[basemapKey].bringToBack();
          currentBasemap = basemapKey;
        }
        
        // Update UI - remove active from all, add to clicked
        buttons.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        console.log('Switched basemap to:', basemapKey);
      });
    });
  }

  // Initialize basemap switcher when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBasemapSwitcher);
  } else {
    initBasemapSwitcher();
  }

  // ============================================================
  // Multi-View State Management
  // ============================================================
  
  // Local cache of views (mirrors PostGIS data)
  const views = {};
  let currentViewKey = null;

  function getCurrentView() {
    return views[currentViewKey];
  }

  function getAreas() {
    const v = getCurrentView();
    return v ? v.areas : [];
  }

  function getCustomRoads() {
    const v = getCurrentView();
    if (!v) return [];
    if (!v.customRoads) v.customRoads = [];
    return v.customRoads;
  }

  function getViewName(key) {
    if (!views[key]) return key;
    return views[key].name || key;
  }

  // ============================================================
  // View Selector UI
  // ============================================================
  const viewSelect = document.getElementById('viewSelect');
  const addViewBtn = document.getElementById('addViewBtn');
  const renameViewBtn = document.getElementById('renameViewBtn');
  const deleteViewBtn = document.getElementById('deleteViewBtn');

  function rebuildViewSelector() {
    if (!viewSelect) return;
    
    viewSelect.innerHTML = '';
    
    Object.keys(views).forEach(key => {
      const opt = document.createElement('option');
      opt.value = key;
      opt.textContent = getViewName(key);
      viewSelect.appendChild(opt);
    });
    
    if (currentViewKey && views[currentViewKey]) {
      viewSelect.value = currentViewKey;
    }
    
    if (deleteViewBtn) {
      deleteViewBtn.disabled = Object.keys(views).length <= 1;
    }
  }

  if (viewSelect) {
    viewSelect.addEventListener('change', () => {
      switchView(viewSelect.value);
    });
  }

  // Add View button - creates in PostGIS
  if (addViewBtn) {
    addViewBtn.addEventListener('click', async () => {
      const name = prompt('Enter a name for the new view:', '');
      if (name === null) return;
      
      const trimmed = name.trim();
      const viewName = trimmed.length > 0 ? trimmed : 'New View';
      
      try {
        const res = await fetch(SPATIAL_VIEWS_URL, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ name: viewName })
        });
        
        if (!res.ok) {
          alert('Failed to create view (HTTP ' + res.status + ')');
          return;
        }
        
        const data = await res.json();
        console.log('Created view:', data);
        
        // Add to local cache
        views[data.key] = {
          id: data.id,
          key: data.key,
          name: viewName,
          areas: [],
          customRoads: []
        };
        
        rebuildViewSelector();
        switchView(data.key);
        
      } catch (err) {
        console.error(err);
        alert('Error creating view');
      }
    });
  }

  // Rename View button - updates in PostGIS
  if (renameViewBtn) {
    renameViewBtn.addEventListener('click', async () => {
      if (!currentViewKey || !views[currentViewKey]) return;
      
      const currentName = getViewName(currentViewKey);
      const newName = prompt('Enter a new name for this view:', currentName);
      if (newName === null) return;
      
      const trimmed = newName.trim();
      if (trimmed.length === 0) return;
      
      try {
        const res = await fetch(`${SPATIAL_VIEW_URL}/${currentViewKey}`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ name: trimmed })
        });
        
        if (!res.ok) {
          alert('Failed to rename view (HTTP ' + res.status + ')');
          return;
        }
        
        views[currentViewKey].name = trimmed;
        rebuildViewSelector();
        
      } catch (err) {
        console.error(err);
        alert('Error renaming view');
      }
    });
  }

  // Delete View button - deletes from PostGIS
  if (deleteViewBtn) {
    deleteViewBtn.addEventListener('click', async () => {
      if (!currentViewKey || !views[currentViewKey]) return;
      
      const viewKeys = Object.keys(views);
      if (viewKeys.length <= 1) {
        alert('Cannot delete the last view.');
        return;
      }
      
      const viewName = getViewName(currentViewKey);
      if (!confirm(`Are you sure you want to delete "${viewName}"? This will delete all areas and roads in this view from the database.`)) {
        return;
      }
      
      try {
        const res = await fetch(`${SPATIAL_VIEW_URL}/${currentViewKey}`, {
          method: 'DELETE',
          headers: { 'Accept': 'application/json' }
        });
        
        if (!res.ok) {
          alert('Failed to delete view (HTTP ' + res.status + ')');
          return;
        }
        
        // Switch to another view first
        const otherKey = viewKeys.find(k => k !== currentViewKey);
        delete views[currentViewKey];
        
        rebuildViewSelector();
        switchView(otherKey);
        
      } catch (err) {
        console.error(err);
        alert('Error deleting view');
      }
    });
  }

  function switchView(newKey) {
    if (!views[newKey]) return;
    if (newKey === currentViewKey) return;
    
    currentViewKey = newKey;
    if (viewSelect && viewSelect.value !== newKey) {
      viewSelect.value = newKey;
    }
    rebuildCurrentView();
  }

  // ============================================================
  // Rebuild Current View on Map
  // ============================================================
  function rebuildCurrentView() {
    drawnItems.clearLayers();
    highlightGroup.clearLayers();
    for (const k in highlightLayers) {
      delete highlightLayers[k];
    }

    const areas = getAreas();
    const customRoads = getCustomRoads();

    // Re-add areas
    areas.forEach(area => {
      try {
        if (!area.geometry) return;
        const layer = L.geoJSON(area.geometry).getLayers()[0];
        if (layer) {
          layer._areaId = area.id;
          drawnItems.addLayer(layer);

          if (area.area_name) {
            layer.bindTooltip(area.area_name, {
              permanent: true,
              direction: 'center',
              className: 'area-label'
            });
          }
        }
      } catch (e) {
        console.error('Failed to restore area', e);
      }
    });

    // Re-add custom roads
    customRoads.forEach(road => {
      try {
        if (!road.geometry) return;
        const feature = { type: 'Feature', geometry: road.geometry, properties: {} };
        const layer = L.geoJSON(feature).getLayers()[0];
        if (layer) {
          layer._customRoadId = road.id;
          drawnItems.addLayer(layer);
        }
      } catch (e) {
        console.error('Failed to restore road', e);
      }
    });

    renderAllAreas();

    // Restore highlights
    areas.forEach(area => {
      if (area.roads) {
        area.roads.filter(r => r.is_highlighted).forEach(r => {
          toggleHighlightRoad(area.id, r.road_name, null, { silent: true });
        });
      }
    });

    customRoads.filter(r => r.is_highlighted).forEach(r => {
      toggleHighlightCustomRoad(r.id, null, { silent: true });
    });
  }

  // ============================================================
  // Load Views from PostGIS
  // ============================================================
  async function loadViewsFromServer() {
    try {
      document.getElementById('stats').innerHTML = '<small>Loading from PostGIS...</small>';
      
      const res = await fetch(SPATIAL_VIEWS_URL, {
        headers: { 'Accept': 'application/json' }
      });

      if (!res.ok) {
        console.error('Load failed', res.status);
        document.getElementById('stats').innerHTML = '<small style="color:#b00">Failed to load</small>';
        createDefaultViewIfEmpty();
        return;
      }

      const data = await res.json();
      const viewList = data.views || [];

      // Clear local cache
      Object.keys(views).forEach(k => delete views[k]);

      if (viewList.length === 0) {
        console.log('No views in PostGIS, creating default');
        await createDefaultViewIfEmpty();
        return;
      }

      // Load each view's details
      for (const v of viewList) {
        try {
          const detailRes = await fetch(`${SPATIAL_VIEW_URL}/${v.view_key}`, {
            headers: { 'Accept': 'application/json' }
          });
          
          if (detailRes.ok) {
            const detail = await detailRes.json();

            const areas = (detail.areas || []).map(a => ({
              id: a.id,
              area_name: a.area_name,
              geometry: a.geometry,
              roads: a.roads || [],
              buildings: a.buildings || []
            }));

            const customRoads = (detail.custom_roads || []).map(r => ({
              id: r.id,
              road_name: r.road_name,
              road_type: r.road_type,
              osm_id: r.osm_id,
              is_highlighted: !!r.is_highlighted,
              geometry: r.geometry
            }));

            views[v.view_key] = {
              id: v.id,
              key: v.view_key,
              name: v.view_name,
              areas,
              customRoads
            };
          }

        } catch (e) {
          console.error('Failed to load view detail', v.view_key, e);
        }
      }

      // Set current view
      if (Object.keys(views).length > 0) {
        currentViewKey = Object.keys(views)[0];
      }

      rebuildViewSelector();
      rebuildCurrentView();
      
      document.getElementById('stats').innerHTML = '<small>Loaded from PostGIS</small>';

    } catch (err) {
      console.error(err);
      document.getElementById('stats').innerHTML = '<small style="color:#b00">Error loading</small>';
      createDefaultViewIfEmpty();
    }
  }

  async function createDefaultViewIfEmpty() {
    if (Object.keys(views).length > 0) return;
    
    try {
      const res = await fetch(SPATIAL_VIEWS_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ name: 'Default View' })
      });
      
      if (res.ok) {
        const data = await res.json();
        views[data.key] = {
          id: data.id,
          key: data.key,
          name: 'Default View',
          areas: [],
          customRoads: []
        };
        currentViewKey = data.key;
        rebuildViewSelector();
        rebuildCurrentView();
      }
    } catch (err) {
      console.error('Failed to create default view', err);
    }
  }

  // ============================================================
  // Save Area to PostGIS (called after drawing)
  // ============================================================
  async function saveAreaToPostGIS(geometry, areaName, roads, buildings) {
    if (!currentViewKey) {
      alert('No view selected');
      return null;
    }

    try {
      const res = await fetch(`${SPATIAL_AREAS_URL}/${currentViewKey}/areas`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          name: areaName || null,
          geometry: geometry,
          roads: roads || [],
          buildings: buildings || []
        })
      });

      if (!res.ok) {
        const err = await res.text();
        console.error('Save area failed:', err);
        alert('Failed to save area to PostGIS');
        return null;
      }

      const data = await res.json();
      console.log('Area saved to PostGIS:', data);
      return data.area_id;

    } catch (err) {
      console.error(err);
      alert('Error saving area to PostGIS');
      return null;
    }
  }

  // ============================================================
  // Save Custom Road to PostGIS
  // ============================================================
  async function saveRoadToPostGIS(geometry, roadName) {
    if (!currentViewKey) {
      alert('No view selected');
      return null;
    }

    try {
      const res = await fetch(`${SPATIAL_ROADS_URL}/${currentViewKey}/roads`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          name: roadName || 'Unnamed Road',
          geometry: geometry
        })
      });

      if (!res.ok) {
        alert('Failed to save road to PostGIS');
        return null;
      }

      const data = await res.json();
      console.log('Road saved to PostGIS:', data);
      return data.road_id;

    } catch (err) {
      console.error(err);
      alert('Error saving road to PostGIS');
      return null;
    }
  }

  // ============================================================
  // Manual Save/Load Buttons
  // ============================================================
  const saveViewsBtn = document.getElementById('saveViewsBtn');
  if (saveViewsBtn) {
    saveViewsBtn.addEventListener('click', () => {
      alert('Data is saved automatically to PostGIS when you draw.\n\nTo manually refresh, click "Load from PostGIS".');
    });
  }

  const loadViewsBtn = document.getElementById('loadViewsBtn');
  if (loadViewsBtn) {
    loadViewsBtn.addEventListener('click', loadViewsFromServer);
  }

  // ============================================================
  // Draw Handler - Save to PostGIS immediately
  // ============================================================
  map.on(L.Draw.Event.CREATED, async (e) => {
    const layerType = e.layerType;
    const layer = e.layer;

    drawnItems.addLayer(layer);
    const geometry = layer.toGeoJSON().geometry;

    // A) POLYLINE → Custom road
    if (layerType === 'polyline') {
      let name = prompt('Name this road (e.g. "Jalan Baru 1"):', '');
      if (name) name = name.trim();
      if (!name || !name.length) {
        name = 'Unnamed Road';
      }

      const roadId = await saveRoadToPostGIS(geometry, name);
      
      if (roadId) {
        // Add to local cache
        const v = getCurrentView();
        if (v) {
          if (!v.customRoads) v.customRoads = [];
          v.customRoads.push({
            id: roadId,
            road_name: name,
            geometry: geometry,
            is_highlighted: false
          });
        }
        layer._customRoadId = roadId;
        renderAllAreas();
      }
      return;
    }

    // B) POLYGON/RECTANGLE → Area
    document.getElementById('stats').innerHTML = '<small>Fetching roads from Overpass...</small>';
    const t0 = performance.now();

    try {
      // First, query Overpass for road names in this area
      const res = await fetch(API_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ geometry })
      });
      
      if (!res.ok) throw new Error('Overpass HTTP ' + res.status);

      const data = await res.json();
      const ms = Math.round(performance.now() - t0);

      // Parse roads
      const listData = data.roads || data.names || [];
      const roads = listData.map(item => {
        if (typeof item === 'string') {
          return { key: item, label: item, road_name: item };
        }
        return { ...item, road_name: item.label || item.key };
      });

      // Parse buildings
      const buildings = Array.isArray(data.buildings) ? data.buildings : [];

      // Save to PostGIS
      document.getElementById('stats').innerHTML = '<small>Saving to PostGIS...</small>';
      const areaId = await saveAreaToPostGIS(geometry, null, roads, buildings);

      if (areaId) {
        // Add to local cache
        const v = getCurrentView();
        if (v) {
          v.areas.push({
            id: areaId,
            area_name: null,
            geometry: geometry,
            roads: roads,
            buildings: buildings
          });
        }
        layer._areaId = areaId;
        renderAllAreas(ms);
      } else {
        document.getElementById('stats').innerHTML = '<small style="color:#b00">Failed to save</small>';
      }

    } catch (err) {
      console.error(err);
      document.getElementById('stats').innerHTML = '<small style="color:#b00">Error</small>';
    }
  });

  // ============================================================
  // Render Sidebar
  // ============================================================
  function renderAllAreas(ms) {
    const stats = document.getElementById('stats');
    const list = document.getElementById('list');
    list.innerHTML = '';

    let totalRoads = 0;
    const areas = getAreas();
    const customRoads = getCustomRoads();

    areas.forEach((area, index) => {
      const areaRoads = area.roads || [];
      totalRoads += areaRoads.length;

      // Area header
      const header = document.createElement('div');
      header.className = 'area-header';

      let label = `Area ${index + 1}`;
      if (area.area_name) {
        label += ` – ${area.area_name}`;
      }
      label += ` – ${areaRoads.length} roads`;

      header.textContent = label;
      header.title = 'Double-click to rename this area';
      list.appendChild(header);

      // Rename area (local only for now)
      header.ondblclick = () => {
        const current = area.area_name || '';
        const renamed = prompt('New name for this area:', current);
        if (renamed === null) return;
        area.area_name = renamed.trim() || null;
        renderAllAreas();
      };

      // Road pills
      areaRoads.forEach(road => {
        const pill = document.createElement('button');
        pill.className = 'pill';
        pill.textContent = road.label || road.road_name || road.key;

        if (road.isHighlighted || road.is_highlighted) {
          pill.classList.add('pill-active');
        }

        pill.onclick = () => toggleHighlightRoad(area.id, road.key || road.road_name, pill);

        pill.ondblclick = () => {
          const current = road.label || road.road_name;
          const renamed = prompt('Enter a local name for this road:', current);
          if (renamed && renamed.trim()) {
            road.label = renamed.trim();
            pill.textContent = road.label;
          }
        };

        list.appendChild(pill);
      });

      // Buildings
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
          const nameLabel = b.name || b.building_name || '';
          row.textContent = [lotLabel, nameLabel].filter(Boolean).join(' – ');
          bList.appendChild(row);
        });
      }
    });

    // Custom roads section
    if (customRoads.length) {
      const crHeader = document.createElement('div');
      crHeader.className = 'area-header';
      crHeader.textContent = `Custom roads – ${customRoads.length}`;
      list.appendChild(crHeader);

      customRoads.forEach(road => {
        const pill = document.createElement('button');
        pill.className = 'pill';
        pill.textContent = road.road_name || road.name || '(unnamed)';

        if (road.is_highlighted || road.isHighlighted) {
          pill.classList.add('pill-active');
        }

        pill.onclick = () => toggleHighlightCustomRoad(road.id, pill);

        pill.ondblclick = () => {
          const current = road.road_name || road.name || '';
          const renamed = prompt('Rename this road:', current);
          if (renamed && renamed.trim()) {
            road.road_name = renamed.trim();
            road.name = renamed.trim();
            pill.textContent = road.road_name;
          }
        };

        list.appendChild(pill);
      });
    }

    stats.innerHTML =
      `<small>${areas.length} areas, ${totalRoads} roads` +
      (customRoads.length ? `, ${customRoads.length} custom roads` : '') +
      (ms != null ? ` (${ms} ms)` : '') +
      ` – PostGIS</small>`;
  }

  // ============================================================
  // Toggle Highlight for Custom Roads
  // ============================================================
  function toggleHighlightCustomRoad(roadId, pillEl, options = {}) {
    const roads = getCustomRoads();
    const road = roads.find(r => r.id === roadId);
    if (!road) return;

    const key = 'custom::' + roadId;
    const silent = options.silent === true;

    if (highlightLayers[key]) {
      highlightGroup.removeLayer(highlightLayers[key]);
      delete highlightLayers[key];
      if (pillEl) pillEl.classList.remove('pill-active');
      road.is_highlighted = false;
      return;
    }

    try {
      const feature = { type: 'Feature', geometry: road.geometry, properties: {} };
      const layer = L.geoJSON(feature, { style: { weight: 4, color: 'orange' } }).addTo(highlightGroup);

      highlightLayers[key] = layer;
      if (pillEl) pillEl.classList.add('pill-active');
      road.is_highlighted = true;

      if (!silent) {
        try { map.fitBounds(layer.getBounds(), { padding: [20, 20] }); } catch (e) {}
      }
    } catch (err) {
      console.error('Highlight failed', err);
    }
  }

  // ============================================================
  // Toggle Highlight for OSM Roads
  // ============================================================
  async function toggleHighlightRoad(areaId, roadKey, pillEl, options = {}) {
    const areas = getAreas();
    const area = areas.find(a => a.id === areaId);
    if (!area) return;

    const key = areaId + '::' + roadKey;
    const road = (area.roads || []).find(r => (r.key || r.road_name) === roadKey);
    const silent = options.silent === true;

    if (highlightLayers[key]) {
      highlightGroup.removeLayer(highlightLayers[key]);
      delete highlightLayers[key];
      if (pillEl) pillEl.classList.remove('pill-active');
      if (road) road.isHighlighted = false;
      return;
    }

    try {
      const res = await fetch(API_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ geometry: area.geometry, with_geom: true, name: roadKey })
      });

      if (!res.ok) {
        alert('API error ' + res.status);
        return;
      }

      const fc = await res.json();
      const layer = L.geoJSON(fc, { style: { weight: 4, color: 'orange' } }).addTo(highlightGroup);

      highlightLayers[key] = layer;
      if (pillEl) pillEl.classList.add('pill-active');
      if (road) road.isHighlighted = true;

      if (!silent) {
        try { map.fitBounds(layer.getBounds(), { padding: [20, 20] }); } catch (e) {}
      }
    } catch (err) {
      console.error(err);
      alert('Failed to highlight');
    }
  }

  // ============================================================
  // Admin Area Filters
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

    countrySearch.value = 'Malaysia';
    await searchCountries();
  })();

  // ============================================================
  // Clear All Areas
  // ============================================================
  document.getElementById('clearAllBtn').addEventListener('click', async () => {
    const v = getCurrentView();
    if (!v) return;

    if (!v.areas || v.areas.length === 0) {
      alert('No areas to clear.');
      return;
    }

    const viewName = getViewName(currentViewKey);
    if (!confirm(`This will clear the map display for "${viewName}".\n\nNote: Data remains in PostGIS. To delete permanently, delete the view.`)) {
      return;
    }

    drawnItems.clearLayers();
    highlightGroup.clearLayers();
    for (const k in highlightLayers) {
      delete highlightLayers[k];
    }

    // Clear local cache only (PostGIS data remains)
    v.areas = [];
    v.customRoads = [];

    renderAllAreas();
  });

  // ============================================================
  // Initial Load
  // ============================================================
  loadViewsFromServer();

})();