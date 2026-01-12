/**
 * Map Editor JavaScript - Simplified Version
 * Location: public/js/map.js
 * 
 * Features:
 * - Draw polygons for houses/lots
 * - Draw polylines for roads
 * - Draw circle markers for utilities (manholes, poles, etc.)
 * - All features are named and saved to PostGIS
 */

(function() {
  'use strict';

  // ============================================================
  // Configuration (injected from Blade)
  // ============================================================
  const CONFIG = window.MAP_CONFIG || {};
  const ADMIN_AREAS_URL = CONFIG.ADMIN_AREAS_URL;
  
  // PostGIS endpoints
  const SPATIAL_VIEWS_URL = CONFIG.SPATIAL_VIEWS_URL;
  const SPATIAL_VIEW_URL = CONFIG.SPATIAL_VIEW_URL;
  const SPATIAL_AREAS_URL = CONFIG.SPATIAL_AREAS_URL;
  const SPATIAL_ROADS_URL = CONFIG.SPATIAL_ROADS_URL;
  const SPATIAL_MARKERS_URL = CONFIG.SPATIAL_MARKERS_URL;

  // ============================================================
  // Marker Type Configuration
  // ============================================================
  const MARKER_TYPES = {
    manhole: { label: 'Manhole', color: '#8B4513', icon: '⬤' },
    electric_pole: { label: 'Electric Pole', color: '#FFD700', icon: '⚡' },
    telco_pole: { label: 'Telco Pole', color: '#1E90FF', icon: '📡' },
    lamp_post: { label: 'Lamp Post', color: '#FFA500', icon: '💡' },
    fire_hydrant: { label: 'Fire Hydrant', color: '#FF0000', icon: '🔴' },
    water_valve: { label: 'Water Valve', color: '#00CED1', icon: '💧' },
    generic: { label: 'Generic Marker', color: '#808080', icon: '📍' }
  };

  // ============================================================
  // Proxy URL Helper (for GeoServer CORS bypass)
  // ============================================================
  function proxyUrl(path) {
    return `/api/geoserver-proxy?path=${encodeURIComponent(path)}`;
  }

  // ============================================================
  // Base Map Layers
  // ============================================================
  const baseOSM = L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }
  );

  const googleSat = L.tileLayer(
    'http://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}&hl=en',
    { maxZoom: 20, subdomains: ['mt0', 'mt1', 'mt2', 'mt3'], attribution: 'Google' }
  );

  const googleHybrid = L.tileLayer(
    'http://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}&hl=en',
    { maxZoom: 20, subdomains: ['mt0', 'mt1', 'mt2', 'mt3'], attribution: 'Google' }
  );

  const cartoLight = L.tileLayer(
    'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
    { maxZoom: 20, attribution: '&copy; CARTO' }
  );

  const baseEsriSat = L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { maxZoom: 19, attribution: 'Tiles &copy; Esri' }
  );

  const basemaps = {
    'osm': baseOSM,
    'satellite': googleSat,
    'hybrid': googleHybrid,
    'carto': cartoLight,
    'esri': baseEsriSat
  };

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
  
  // Configure drawing controls with circle marker for utility points
  const drawControl = new L.Control.Draw({
    draw: {
      polygon: {
        allowIntersection: false,
        showArea: true,
        shapeOptions: { color: '#3388ff', weight: 2 }
      },
      rectangle: {
        shapeOptions: { color: '#3388ff', weight: 2 }
      },
      polyline: {
        shapeOptions: { color: '#ff7800', weight: 3 }
      },
      circle: false,
      marker: false,
      circlemarker: {
        radius: 8,
        color: '#808080',
        weight: 2,
        fillOpacity: 0.8
      }
    },
    edit: {
      featureGroup: drawnItems,
      remove: true
    }
  });
  
  map.addControl(drawControl);

  // Layer control
  L.control.layers(
    null,
    { 'Drawn features': drawnItems },
    { collapsed: false }
  ).addTo(map);

  // ============================================================
  // Basemap Switcher
  // ============================================================
  function initBasemapSwitcher() {
    const buttons = document.querySelectorAll('.basemap-btn');
    
    buttons.forEach(btn => {
      btn.addEventListener('click', () => {
        const basemapKey = btn.dataset.basemap;
        if (basemapKey === currentBasemap) return;
        
        if (basemaps[currentBasemap]) {
          map.removeLayer(basemaps[currentBasemap]);
        }
        
        if (basemaps[basemapKey]) {
          basemaps[basemapKey].addTo(map);
          basemaps[basemapKey].bringToBack();
          currentBasemap = basemapKey;
        }
        
        buttons.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBasemapSwitcher);
  } else {
    initBasemapSwitcher();
  }

  // ============================================================
  // Building Plan Overlay (WMS from GeoServer) - Kept for reference
  // ============================================================
  let BUILDING_PLAN_OVERLAYS = [];
  const GEOSERVER_URL = 'http://geoserversafe.duckdns.org:65437/geoserver';
  const GEOSERVER_WORKSPACE = 'gis_project';
  let currentBuildingPlanLayer = null;
  let buildingPlanVisible = true;
  let buildingPlanOpacity = 0.6;

  async function fetchBuildingPlanOverlays() {
    try {
      const wmsPath = `${GEOSERVER_WORKSPACE}/wms`;
      const capabilitiesUrl = proxyUrl(wmsPath) + '&service=WMS&request=GetCapabilities';
      
      const response = await fetch(capabilitiesUrl);
      if (!response.ok) throw new Error(`HTTP error: ${response.status}`);
      
      const text = await response.text();
      const parser = new DOMParser();
      const xml = parser.parseFromString(text, 'text/xml');
      
      let layers = xml.querySelectorAll('Layer > Name');
      if (layers.length === 0) layers = xml.getElementsByTagName('Name');
      
      const overlays = [];
      layers.forEach(layer => {
        const fullLayerName = layer.textContent;
        const layerName = fullLayerName.includes(':') 
          ? fullLayerName.split(':')[1] 
          : fullLayerName;
        
        if (layerName.toLowerCase().includes('geotiff') || 
            layerName.toLowerCase().includes('tiff') ||
            layerName.toLowerCase().startsWith('msb')) {
          overlays.push({
            id: layerName,
            name: formatLayerDisplayName(layerName)
          });
        }
      });
      
      overlays.sort((a, b) => a.name.localeCompare(b.name));
      return overlays;
    } catch (error) {
      console.error('Failed to fetch GeoServer capabilities:', error);
      return [];
    }
  }

  function formatLayerDisplayName(layerId) {
    const baseName = layerId.replace(/-geotiff$/i, '');
    const msbMatch = baseName.match(/^(msb)(\d+)$/i);
    if (msbMatch) {
      return `${msbMatch[1].toUpperCase()}${msbMatch[2].padStart(2, '0')} Building Plan`;
    }
    return baseName.replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) + ' Building Plan';
  }

  function createBuildingPlanLayer(layerId) {
    const wmsUrl = `${GEOSERVER_URL}/${GEOSERVER_WORKSPACE}/wms`;
    return L.tileLayer.wms(wmsUrl, {
      layers: `${GEOSERVER_WORKSPACE}:${layerId}`,
      format: 'image/png',
      transparent: true,
      styles: '',
      version: '1.1.0',
      maxZoom: 20,
      opacity: buildingPlanOpacity
    });
  }

  function switchBuildingPlan(layerId) {
    if (currentBuildingPlanLayer) {
      map.removeLayer(currentBuildingPlanLayer);
      currentBuildingPlanLayer = null;
    }
    
    if (!layerId) return;
    
    currentBuildingPlanLayer = createBuildingPlanLayer(layerId);
    
    if (buildingPlanVisible) {
      currentBuildingPlanLayer.addTo(map);
      currentBuildingPlanLayer.bringToBack();
      if (basemaps[currentBasemap]) {
        basemaps[currentBasemap].bringToBack();
      }
    }
  }

  async function initBuildingPlanControls() {
    const select = document.getElementById('buildingPlanSelect');
    const toggle = document.getElementById('buildingPlanToggle');
    const opacitySlider = document.getElementById('buildingPlanOpacity');
    const opacityValue = document.getElementById('opacityValue');
    const overlayControl = document.querySelector('.overlay-control');
    
    if (!select) return;
    
    select.innerHTML = '<option value="">Loading building plans...</option>';
    select.disabled = true;
    
    BUILDING_PLAN_OVERLAYS = await fetchBuildingPlanOverlays();
    
    select.innerHTML = '';
    select.disabled = false;
    
    if (BUILDING_PLAN_OVERLAYS.length === 0) {
      select.innerHTML = '<option value="">No building plans available</option>';
      select.disabled = true;
      return;
    }
    
    const noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = '-- Select a plan --';
    select.appendChild(noneOpt);
    
    BUILDING_PLAN_OVERLAYS.forEach(plan => {
      const opt = document.createElement('option');
      opt.value = plan.id;
      opt.textContent = plan.name;
      select.appendChild(opt);
    });
    
    if (BUILDING_PLAN_OVERLAYS.length > 0) {
      select.value = BUILDING_PLAN_OVERLAYS[0].id;
      switchBuildingPlan(BUILDING_PLAN_OVERLAYS[0].id);
    }
    
    select.addEventListener('change', (e) => switchBuildingPlan(e.target.value));
    
    if (toggle) {
      toggle.addEventListener('change', (e) => {
        buildingPlanVisible = e.target.checked;
        if (buildingPlanVisible && currentBuildingPlanLayer) {
          currentBuildingPlanLayer.addTo(map);
          currentBuildingPlanLayer.bringToBack();
          if (basemaps[currentBasemap]) basemaps[currentBasemap].bringToBack();
        } else if (currentBuildingPlanLayer) {
          map.removeLayer(currentBuildingPlanLayer);
        }
        if (overlayControl) overlayControl.classList.toggle('disabled', !buildingPlanVisible);
      });
    }
    
    if (opacitySlider && opacityValue) {
      opacitySlider.addEventListener('input', (e) => {
        buildingPlanOpacity = e.target.value / 100;
        if (currentBuildingPlanLayer) currentBuildingPlanLayer.setOpacity(buildingPlanOpacity);
        opacityValue.textContent = `${e.target.value}%`;
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBuildingPlanControls);
  } else {
    initBuildingPlanControls();
  }

  // ============================================================
  // Multi-View State Management
  // ============================================================
  const views = {};
  let currentViewKey = null;

  function getCurrentView() {
    return views[currentViewKey];
  }

  function getAreas() {
    const v = getCurrentView();
    return v ? (v.areas || []) : [];
  }

  function getRoads() {
    const v = getCurrentView();
    return v ? (v.roads || []) : [];
  }

  function getMarkers() {
    const v = getCurrentView();
    return v ? (v.markers || []) : [];
  }

  function getViewName(key) {
    return views[key]?.name || key;
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
    viewSelect.addEventListener('change', () => switchView(viewSelect.value));
  }

  // Add View
  if (addViewBtn) {
    addViewBtn.addEventListener('click', async () => {
      const name = prompt('Enter a name for the new view:', '');
      if (name === null) return;
      
      const viewName = name.trim() || 'New View';
      
      try {
        const res = await fetch(SPATIAL_VIEWS_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ name: viewName })
        });
        
        if (!res.ok) {
          alert('Failed to create view');
          return;
        }
        
        const data = await res.json();
        views[data.key] = {
          id: data.id,
          key: data.key,
          name: viewName,
          areas: [],
          roads: [],
          markers: []
        };
        
        rebuildViewSelector();
        switchView(data.key);
      } catch (err) {
        console.error(err);
        alert('Error creating view');
      }
    });
  }

  // Rename View
  if (renameViewBtn) {
    renameViewBtn.addEventListener('click', async () => {
      if (!currentViewKey || !views[currentViewKey]) return;
      
      const currentName = getViewName(currentViewKey);
      const newName = prompt('Enter a new name for this view:', currentName);
      if (newName === null || !newName.trim()) return;
      
      try {
        const res = await fetch(`${SPATIAL_VIEW_URL}/${currentViewKey}`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ name: newName.trim() })
        });
        
        if (!res.ok) {
          alert('Failed to rename view');
          return;
        }
        
        views[currentViewKey].name = newName.trim();
        rebuildViewSelector();
      } catch (err) {
        console.error(err);
        alert('Error renaming view');
      }
    });
  }

  // Delete View
  if (deleteViewBtn) {
    deleteViewBtn.addEventListener('click', async () => {
      if (!currentViewKey || !views[currentViewKey]) return;
      
      const viewKeys = Object.keys(views);
      if (viewKeys.length <= 1) {
        alert('Cannot delete the last view.');
        return;
      }
      
      if (!confirm(`Delete "${getViewName(currentViewKey)}"? This will delete all features in this view.`)) {
        return;
      }
      
      try {
        const res = await fetch(`${SPATIAL_VIEW_URL}/${currentViewKey}`, {
          method: 'DELETE',
          headers: { 'Accept': 'application/json' }
        });
        
        if (!res.ok) {
          alert('Failed to delete view');
          return;
        }
        
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
    if (!views[newKey] || newKey === currentViewKey) return;
    
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
    
    const areas = getAreas();
    const roads = getRoads();
    const markers = getMarkers();

    // Re-add areas (polygons)
    areas.forEach(area => {
      try {
        if (!area.geometry) return;
        const layer = L.geoJSON(area.geometry).getLayers()[0];
        if (layer) {
          layer._featureType = 'area';
          layer._featureId = area.id;
          layer.setStyle({ color: '#3388ff', weight: 2, fillOpacity: 0.2 });
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

    // Re-add roads (polylines)
    roads.forEach(road => {
      try {
        if (!road.geometry) return;
        const feature = { type: 'Feature', geometry: road.geometry, properties: {} };
        const layer = L.geoJSON(feature, {
          style: { color: '#ff7800', weight: 3 }
        }).getLayers()[0];
        if (layer) {
          layer._featureType = 'road';
          layer._featureId = road.id;
          drawnItems.addLayer(layer);
          
          if (road.road_name) {
            layer.bindTooltip(road.road_name, {
              permanent: false,
              direction: 'center',
              className: 'road-label'
            });
          }
        }
      } catch (e) {
        console.error('Failed to restore road', e);
      }
    });

    // Re-add markers (circle markers)
    markers.forEach(marker => {
      try {
        if (!marker.geometry || !marker.geometry.coordinates) return;
        const coords = marker.geometry.coordinates;
        const markerType = MARKER_TYPES[marker.marker_type] || MARKER_TYPES.generic;
        
        const circleMarker = L.circleMarker([coords[1], coords[0]], {
          radius: 8,
          color: markerType.color,
          fillColor: markerType.color,
          fillOpacity: 0.8,
          weight: 2
        });
        
        circleMarker._featureType = 'marker';
        circleMarker._featureId = marker.id;
        circleMarker._markerType = marker.marker_type;
        drawnItems.addLayer(circleMarker);
        
        const label = marker.marker_name || markerType.label;
        circleMarker.bindTooltip(label, {
          permanent: false,
          direction: 'top',
          className: 'marker-label'
        });
      } catch (e) {
        console.error('Failed to restore marker', e);
      }
    });

    renderSidebar();
  }

  // ============================================================
  // Load Views from PostGIS
  // ============================================================
  async function loadViewsFromServer() {
    try {
      updateStatus('Loading from PostGIS...');
      
      const res = await fetch(SPATIAL_VIEWS_URL, {
        headers: { 'Accept': 'application/json' }
      });

      if (!res.ok) {
        updateStatus('Failed to load', 'error');
        createDefaultViewIfEmpty();
        return;
      }

      const data = await res.json();
      const viewList = data.views || [];

      Object.keys(views).forEach(k => delete views[k]);

      if (viewList.length === 0) {
        await createDefaultViewIfEmpty();
        return;
      }

      for (const v of viewList) {
        try {
          const detailRes = await fetch(`${SPATIAL_VIEW_URL}/${v.view_key}`, {
            headers: { 'Accept': 'application/json' }
          });
          
          if (detailRes.ok) {
            const detail = await detailRes.json();

            views[v.view_key] = {
              id: v.id,
              key: v.view_key,
              name: v.view_name,
              areas: (detail.areas || []).map(a => ({
                id: a.id,
                area_name: a.area_name,
                geometry: a.geometry
              })),
              roads: (detail.roads || []).map(r => ({
                id: r.id,
                road_name: r.road_name,
                geometry: r.geometry
              })),
              markers: (detail.markers || []).map(m => ({
                id: m.id,
                marker_name: m.marker_name,
                marker_type: m.marker_type,
                description: m.description,
                geometry: m.geometry
              }))
            };
          }
        } catch (e) {
          console.error('Failed to load view detail', v.view_key, e);
        }
      }

      if (Object.keys(views).length > 0) {
        currentViewKey = Object.keys(views)[0];
      }

      rebuildViewSelector();
      rebuildCurrentView();
      updateStatus('Loaded from PostGIS');

    } catch (err) {
      console.error(err);
      updateStatus('Error loading', 'error');
      createDefaultViewIfEmpty();
    }
  }

  async function createDefaultViewIfEmpty() {
    if (Object.keys(views).length > 0) return;
    
    try {
      const res = await fetch(SPATIAL_VIEWS_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ name: 'Default View' })
      });
      
      if (res.ok) {
        const data = await res.json();
        views[data.key] = {
          id: data.id,
          key: data.key,
          name: 'Default View',
          areas: [],
          roads: [],
          markers: []
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
  // Save Functions
  // ============================================================
  async function saveAreaToPostGIS(geometry, areaName) {
    if (!currentViewKey) return null;

    try {
      const res = await fetch(`${SPATIAL_AREAS_URL}/${currentViewKey}/areas`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ name: areaName || null, geometry: geometry })
      });

      if (!res.ok) {
        alert('Failed to save area');
        return null;
      }

      const data = await res.json();
      return data.area_id;
    } catch (err) {
      console.error(err);
      alert('Error saving area');
      return null;
    }
  }

  async function saveRoadToPostGIS(geometry, roadName) {
    if (!currentViewKey) return null;

    try {
      const res = await fetch(`${SPATIAL_ROADS_URL}/${currentViewKey}/roads`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ name: roadName || 'Unnamed Road', geometry: geometry })
      });

      if (!res.ok) {
        alert('Failed to save road');
        return null;
      }

      const data = await res.json();
      return data.road_id;
    } catch (err) {
      console.error(err);
      alert('Error saving road');
      return null;
    }
  }

  async function saveMarkerToPostGIS(geometry, markerName, markerType, description) {
    if (!currentViewKey) return null;

    try {
      const res = await fetch(`${SPATIAL_MARKERS_URL}/${currentViewKey}/markers`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          name: markerName || null,
          marker_type: markerType || 'generic',
          description: description || null,
          geometry: geometry
        })
      });

      if (!res.ok) {
        alert('Failed to save marker');
        return null;
      }

      const data = await res.json();
      return data.marker_id;
    } catch (err) {
      console.error(err);
      alert('Error saving marker');
      return null;
    }
  }

  async function updateFeatureNameInPostGIS(featureType, featureId, newName) {
    if (!currentViewKey) return false;

    let endpoint;
    let bodyKey;
    
    switch (featureType) {
      case 'area':
        endpoint = `${SPATIAL_AREAS_URL}/${currentViewKey}/areas/${featureId}`;
        bodyKey = 'area_name';
        break;
      case 'road':
        endpoint = `${SPATIAL_ROADS_URL}/${currentViewKey}/roads/${featureId}`;
        bodyKey = 'road_name';
        break;
      case 'marker':
        endpoint = `${SPATIAL_MARKERS_URL}/${currentViewKey}/markers/${featureId}`;
        bodyKey = 'marker_name';
        break;
      default:
        return false;
    }

    try {
      const res = await fetch(endpoint, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ [bodyKey]: newName })
      });
      return res.ok;
    } catch (err) {
      console.error(err);
      return false;
    }
  }

  // ============================================================
  // Draw Handler
  // ============================================================
  map.on(L.Draw.Event.CREATED, async (e) => {
    const layerType = e.layerType;
    const layer = e.layer;

    drawnItems.addLayer(layer);
    const geometry = layer.toGeoJSON().geometry;

    // POLYLINE → Road
    if (layerType === 'polyline') {
      const name = prompt('Name this road:', '') || 'Unnamed Road';
      
      updateStatus('Saving road...');
      const roadId = await saveRoadToPostGIS(geometry, name.trim());
      
      if (roadId) {
        const v = getCurrentView();
        if (v) {
          if (!v.roads) v.roads = [];
          v.roads.push({ id: roadId, road_name: name.trim(), geometry: geometry });
        }
        layer._featureType = 'road';
        layer._featureId = roadId;
        layer.setStyle({ color: '#ff7800', weight: 3 });
        
        if (name.trim()) {
          layer.bindTooltip(name.trim(), { permanent: false, direction: 'center', className: 'road-label' });
        }
        
        renderSidebar();
        updateStatus('Road saved');
      }
      return;
    }

    // CIRCLE MARKER → Utility Marker
    if (layerType === 'circlemarker') {
      const markerType = await showMarkerTypeDialog();
      if (!markerType) {
        drawnItems.removeLayer(layer);
        return;
      }
      
      const name = prompt(`Name this ${MARKER_TYPES[markerType].label}:`, '') || '';
      const description = prompt('Description (optional):', '') || '';
      
      // Convert to Point geometry
      const latlng = layer.getLatLng();
      const pointGeometry = {
        type: 'Point',
        coordinates: [latlng.lng, latlng.lat]
      };
      
      updateStatus('Saving marker...');
      const markerId = await saveMarkerToPostGIS(pointGeometry, name.trim(), markerType, description.trim());
      
      if (markerId) {
        const v = getCurrentView();
        if (v) {
          if (!v.markers) v.markers = [];
          v.markers.push({
            id: markerId,
            marker_name: name.trim(),
            marker_type: markerType,
            description: description.trim(),
            geometry: pointGeometry
          });
        }
        
        layer._featureType = 'marker';
        layer._featureId = markerId;
        layer._markerType = markerType;
        
        const typeConfig = MARKER_TYPES[markerType];
        layer.setStyle({
          color: typeConfig.color,
          fillColor: typeConfig.color,
          fillOpacity: 0.8
        });
        
        const label = name.trim() || typeConfig.label;
        layer.bindTooltip(label, { permanent: false, direction: 'top', className: 'marker-label' });
        
        renderSidebar();
        updateStatus('Marker saved');
      }
      return;
    }

    // POLYGON/RECTANGLE → Area (House/Lot)
    if (layerType === 'polygon' || layerType === 'rectangle') {
      const name = prompt('Name this area (house/lot):', '') || '';
      
      updateStatus('Saving area...');
      const areaId = await saveAreaToPostGIS(geometry, name.trim());

      if (areaId) {
        const v = getCurrentView();
        if (v) {
          v.areas.push({ id: areaId, area_name: name.trim() || null, geometry: geometry });
        }
        
        layer._featureType = 'area';
        layer._featureId = areaId;
        layer.setStyle({ color: '#3388ff', weight: 2, fillOpacity: 0.2 });
        
        if (name.trim()) {
          layer.bindTooltip(name.trim(), { permanent: true, direction: 'center', className: 'area-label' });
        }
        
        renderSidebar();
        updateStatus('Area saved');
      }
      return;
    }
  });

  // ============================================================
  // Marker Type Selection Dialog
  // ============================================================
  function showMarkerTypeDialog() {
    return new Promise((resolve) => {
      const dialog = document.createElement('div');
      dialog.className = 'marker-type-dialog';
      dialog.innerHTML = `
        <div class="marker-dialog-content">
          <h3>Select Marker Type</h3>
          <div class="marker-type-options">
            ${Object.entries(MARKER_TYPES).map(([key, type]) => `
              <button class="marker-type-btn" data-type="${key}" style="border-color: ${type.color}">
                <span class="marker-icon" style="background: ${type.color}">${type.icon}</span>
                <span>${type.label}</span>
              </button>
            `).join('')}
          </div>
          <button class="marker-cancel-btn">Cancel</button>
        </div>
      `;
      
      document.body.appendChild(dialog);
      
      dialog.querySelectorAll('.marker-type-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          dialog.remove();
          resolve(btn.dataset.type);
        });
      });
      
      dialog.querySelector('.marker-cancel-btn').addEventListener('click', () => {
        dialog.remove();
        resolve(null);
      });
      
      dialog.addEventListener('click', (e) => {
        if (e.target === dialog) {
          dialog.remove();
          resolve(null);
        }
      });
    });
  }

  // ============================================================
  // Render Sidebar
  // ============================================================
  function renderSidebar() {
    const list = document.getElementById('featureList');
    if (!list) return;
    
    list.innerHTML = '';

    const areas = getAreas();
    const roads = getRoads();
    const markers = getMarkers();

    // Areas Section
    if (areas.length > 0) {
      const section = createSection('Areas / Lots', 'area-section');
      const items = document.createElement('div');
      items.className = 'feature-items';
      
      areas.forEach((area, index) => {
        const item = createFeatureItem(
          area.area_name || `Area ${index + 1}`,
          'area',
          area.id,
          '#3388ff'
        );
        items.appendChild(item);
      });
      
      section.appendChild(items);
      list.appendChild(section);
    }

    // Roads Section
    if (roads.length > 0) {
      const section = createSection('Roads', 'road-section');
      const items = document.createElement('div');
      items.className = 'feature-items';
      
      roads.forEach((road, index) => {
        const item = createFeatureItem(
          road.road_name || `Road ${index + 1}`,
          'road',
          road.id,
          '#ff7800'
        );
        items.appendChild(item);
      });
      
      section.appendChild(items);
      list.appendChild(section);
    }

    // Markers Section
    if (markers.length > 0) {
      const section = createSection('Markers', 'marker-section');
      const items = document.createElement('div');
      items.className = 'feature-items';
      
      markers.forEach((marker, index) => {
        const typeConfig = MARKER_TYPES[marker.marker_type] || MARKER_TYPES.generic;
        const item = createFeatureItem(
          marker.marker_name || `${typeConfig.label} ${index + 1}`,
          'marker',
          marker.id,
          typeConfig.color,
          typeConfig.icon
        );
        items.appendChild(item);
      });
      
      section.appendChild(items);
      list.appendChild(section);
    }

    // Empty state
    if (areas.length === 0 && roads.length === 0 && markers.length === 0) {
      list.innerHTML = '<div class="empty-state">No features drawn yet.<br>Use the drawing tools to add areas, roads, or markers.</div>';
    }

    // Update stats
    updateStatus(`${areas.length} areas, ${roads.length} roads, ${markers.length} markers`);
  }

  function createSection(title, className) {
    const section = document.createElement('div');
    section.className = `feature-section ${className}`;
    
    const header = document.createElement('h4');
    header.className = 'section-header';
    header.textContent = title;
    section.appendChild(header);
    
    return section;
  }

  function createFeatureItem(name, featureType, featureId, color, icon = null) {
    const item = document.createElement('div');
    item.className = 'feature-item';
    item.dataset.featureType = featureType;
    item.dataset.featureId = featureId;
    
    const indicator = document.createElement('span');
    indicator.className = 'feature-indicator';
    indicator.style.backgroundColor = color;
    if (icon) indicator.textContent = icon;
    
    const label = document.createElement('span');
    label.className = 'feature-label';
    label.textContent = name;
    
    item.appendChild(indicator);
    item.appendChild(label);
    
    // Click to zoom
    item.addEventListener('click', () => {
      zoomToFeature(featureType, featureId);
    });
    
    // Double-click to rename
    item.addEventListener('dblclick', async () => {
      const newName = prompt('Rename this feature:', name);
      if (newName === null) return;
      
      const success = await updateFeatureNameInPostGIS(featureType, featureId, newName.trim());
      if (success) {
        // Update local cache
        const v = getCurrentView();
        if (featureType === 'area') {
          const area = v.areas.find(a => a.id === featureId);
          if (area) area.area_name = newName.trim();
        } else if (featureType === 'road') {
          const road = v.roads.find(r => r.id === featureId);
          if (road) road.road_name = newName.trim();
        } else if (featureType === 'marker') {
          const marker = v.markers.find(m => m.id === featureId);
          if (marker) marker.marker_name = newName.trim();
        }
        
        // Update tooltip on map
        drawnItems.eachLayer(layer => {
          if (layer._featureType === featureType && layer._featureId === featureId) {
            if (layer.getTooltip()) layer.unbindTooltip();
            if (newName.trim()) {
              layer.bindTooltip(newName.trim(), {
                permanent: featureType === 'area',
                direction: featureType === 'marker' ? 'top' : 'center',
                className: `${featureType}-label`
              });
            }
          }
        });
        
        renderSidebar();
      } else {
        alert('Failed to rename feature');
      }
    });
    
    return item;
  }

  function zoomToFeature(featureType, featureId) {
    drawnItems.eachLayer(layer => {
      if (layer._featureType === featureType && layer._featureId === featureId) {
        if (layer.getBounds) {
          map.fitBounds(layer.getBounds(), { padding: [50, 50] });
        } else if (layer.getLatLng) {
          map.setView(layer.getLatLng(), 18);
        }
      }
    });
  }

  function updateStatus(message, type = 'info') {
    const stats = document.getElementById('stats');
    if (stats) {
      const color = type === 'error' ? '#c00' : '#666';
      stats.innerHTML = `<small style="color:${color}">${message}</small>`;
    }
  }

  // ============================================================
  // Manual Load Button
  // ============================================================
  const loadViewsBtn = document.getElementById('loadViewsBtn');
  if (loadViewsBtn) {
    loadViewsBtn.addEventListener('click', loadViewsFromServer);
  }

  // Save button just shows info
  const saveViewsBtn = document.getElementById('saveViewsBtn');
  if (saveViewsBtn) {
    saveViewsBtn.addEventListener('click', () => {
      alert('Data is saved automatically when you draw.\n\nClick "Load" to refresh from database.');
    });
  }

  // ============================================================
  // Clear All
  // ============================================================
  const clearAllBtn = document.getElementById('clearAllBtn');
  if (clearAllBtn) {
    clearAllBtn.addEventListener('click', () => {
      const v = getCurrentView();
      if (!v) return;

      const total = (v.areas?.length || 0) + (v.roads?.length || 0) + (v.markers?.length || 0);
      if (total === 0) {
        alert('No features to clear.');
        return;
      }

      if (!confirm(`Clear all features from "${getViewName(currentViewKey)}"?\n\nNote: This clears the display only. To delete permanently, delete the view.`)) {
        return;
      }

      drawnItems.clearLayers();
      v.areas = [];
      v.roads = [];
      v.markers = [];
      renderSidebar();
    });
  }

  // ============================================================
  // Address Search
  // ============================================================
  (function initAddressSearch() {
    const searchInput = document.getElementById('addressSearch');
    const searchBtn = document.getElementById('searchBtn');
    const searchResults = document.getElementById('searchResults');

    if (!searchInput || !searchBtn || !searchResults) return;

    let searchMarker = null;
    let searchTimeout = null;

    async function searchAddress(query) {
      if (!query || query.trim().length < 3) {
        searchResults.innerHTML = '<div class="search-no-results">Type at least 3 characters</div>';
        return;
      }

      searchResults.innerHTML = '<div class="search-loading">Searching...</div>';

      try {
        const url = `https://nominatim.openstreetmap.org/search?` + new URLSearchParams({
          q: query,
          format: 'json',
          addressdetails: 1,
          limit: 5,
          countrycodes: 'my',
          'accept-language': 'en'
        });

        const response = await fetch(url, { headers: { 'User-Agent': 'MapEditor/1.0' } });
        if (!response.ok) throw new Error('Search failed');

        let data = await response.json();

        if (data.length === 0) {
          const worldUrl = `https://nominatim.openstreetmap.org/search?` + new URLSearchParams({
            q: query, format: 'json', addressdetails: 1, limit: 5, 'accept-language': 'en'
          });
          const worldResponse = await fetch(worldUrl, { headers: { 'User-Agent': 'MapEditor/1.0' } });
          data = await worldResponse.json();

          if (data.length === 0) {
            searchResults.innerHTML = '<div class="search-no-results">No results found</div>';
            return;
          }
        }

        displaySearchResults(data);
      } catch (err) {
        console.error('Search error:', err);
        searchResults.innerHTML = '<div class="search-no-results">Search failed. Try again.</div>';
      }
    }

    function displaySearchResults(results) {
      searchResults.innerHTML = '';

      results.forEach(result => {
        const item = document.createElement('div');
        item.className = 'search-result-item';
        const name = result.name || result.display_name.split(',')[0];

        item.innerHTML = `
          <div class="search-result-name">${name}</div>
          <div class="search-result-address">${result.display_name}</div>
        `;

        item.addEventListener('click', () => {
          goToLocation(parseFloat(result.lat), parseFloat(result.lon), name);
          searchResults.innerHTML = '';
          searchInput.value = name;
        });

        searchResults.appendChild(item);
      });
    }

    function goToLocation(lat, lon, name) {
      if (searchMarker) map.removeLayer(searchMarker);

      map.flyTo([lat, lon], 16, { duration: 1.5 });

      searchMarker = L.marker([lat, lon], {
        icon: L.divIcon({
          className: 'search-marker',
          html: `<div style="background:#f44336;width:20px;height:20px;border-radius:50%;border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.4);"></div>`,
          iconSize: [20, 20],
          iconAnchor: [10, 10]
        })
      }).addTo(map);

      searchMarker.bindPopup(`<strong>${name}</strong>`).openPopup();
    }

    searchBtn.addEventListener('click', () => searchAddress(searchInput.value));
    searchInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') searchAddress(searchInput.value); });
    searchInput.addEventListener('input', () => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        if (searchInput.value.length >= 3) searchAddress(searchInput.value);
        else searchResults.innerHTML = '';
      }, 500);
    });

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.search-container') && !e.target.closest('.search-results')) {
        searchResults.innerHTML = '';
      }
    });
  })();

  // ============================================================
  // Admin Area Filters (kept for navigation)
  // ============================================================
  (async function initAdminFilters() {
    const countrySearch = document.getElementById('countrySearch');
    const btnCountrySearch = document.getElementById('btnCountrySearch');
    const selCountry = document.getElementById('countrySel');
    const selState = document.getElementById('stateSel');
    const selDistrict = document.getElementById('districtSel');

    if (!countrySearch || !btnCountrySearch || !selCountry) return;

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

    // Default search
    countrySearch.value = 'Malaysia';
    await searchCountries();
  })();

  // ============================================================
  // Initial Load
  // ============================================================
  loadViewsFromServer();

})();
