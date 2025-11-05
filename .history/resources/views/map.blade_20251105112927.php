<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Road names in area</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
  <link href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" rel="stylesheet">
  <style>
    body { margin:0; display:grid; grid-template-columns: 1fr 320px; height:100vh; font-family:system-ui,Segoe UI,Arial; }
    #map { height: 100%; }
    #side { padding:12px; overflow:auto; border-left:1px solid #ddd; }
    #side h2 { margin:0 0 8px; font-size:1.1rem; }
    #side small { color:#666; }
    .pill { display:inline-block; padding:4px 8px; margin:2px 4px 2px 0; border:1px solid #ccc; border-radius:9999px; font-size:.9rem; }
    .toolbar { padding:8px; background:#fff; position:absolute; z-index:1000; right:340px; top:10px; border:1px solid #ddd; border-radius:8px; }
  </style>
</head>
<body>
  <div id="map"></div>
  <aside id="side">
    <h2>Road names</h2>
    <div id="stats"><small>Draw a circle, rectangle, or polygon.</small></div>
    <div id="list"></div>
  </aside>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
  <script>
    const map = L.map('map').setView([4.2105, 101.9758], 6); // Malaysia

// --- Road drawing & highlight support ---
const defaultRoadStyle = { color:'#60a5fa', weight:3, opacity:1 };
const highlightStyle   = { color:'#f59e0b', weight:7, opacity:1 };

// Indexes
const layerIndexById = new Map();     // id -> Leaflet layer
const idsByName = new Map();          // name -> Set(ids)
let selectedIds = new Set();

// Layer for road features
const roadsLayer = L.geoJSON(null, {
  style: defaultRoadStyle,
  onEachFeature: (f, layer) => {
    const id = f?.properties?.id ?? f?.id;
    const name = f?.properties?.name ?? '(unnamed)';
    if (id != null) layerIndexById.set(id, layer);
    if (name) {
      if (!idsByName.has(name)) idsByName.set(name, new Set());
      if (id != null) idsByName.get(name).add(id);
    }
    layer.bindPopup(`<b>${name}</b>${f?.properties?.highway ? '<br>'+f.properties.highway : ''}`);
    layer.on('mouseover', () => layer.setStyle({ weight:5 }));
    layer.on('mouseout', () => {
      const fid = id;
      if (!fid || !selectedIds.has(fid)) layer.setStyle(defaultRoadStyle);
    });
  }
}).addTo(map);

function clearSelection() {
  for (const id of selectedIds) {
    const lyr = layerIndexById.get(id);
    if (lyr) lyr.setStyle(defaultRoadStyle);
  }
  selectedIds.clear();
}

function selectByIds(ids, fit=true) {
  clearSelection();
  const bounds = L.latLngBounds();
  for (const id of ids) {
    const lyr = layerIndexById.get(id);
    if (lyr) {
      lyr.setStyle(highlightStyle);
      lyr.bringToFront();
      selectedIds.add(id);
      try { bounds.extend(lyr.getBounds()); } catch {}
      lyr.openPopup();
    }
  }
  if (fit && bounds.isValid()) map.fitBounds(bounds, { padding:[40,40] });
}

function renderFeatureSidebar(features, ms) {
  // Build a unique list by road name -> ids
  idsByName.clear();
  for (const f of features) {
    const name = f?.properties?.name ?? '(unnamed)';
    const id = f?.properties?.id ?? f?.id;
    if (!idsByName.has(name)) idsByName.set(name, new Set());
    if (id != null) idsByName.get(name).add(id);
  }

  const stats = document.getElementById('stats');
  stats.innerHTML = `<small>${features.length} road segments${ms ? ' ('+ms+' ms)' : ''}</small>`;

  const list = document.getElementById('list');
  list.innerHTML = '';

  // Sort names alphabetically
  const entries = Array.from(idsByName.entries()).sort((a,b)=> a[0].localeCompare(b[0]));

  for (const [name, idset] of entries) {
    const btn = document.createElement('button');
    btn.className = 'pill';
    btn.title = 'Click to highlight on map';
    const count = idset.size;
    btn.innerHTML = `${name}${count>1 ? ' <small>(' + count + ')</small>' : ''}`;
    btn.onclick = () => selectByIds(Array.from(idset), true);
    list.appendChild(btn);
  }
}
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const drawnItems = new L.FeatureGroup().addTo(map);
    const drawControl = new L.Control.Draw({
      edit: { featureGroup: drawnItems, edit: false },
      draw: { polygon:true, rectangle:true, circle:true, polyline:false, marker:false, circlemarker:false }
    });
    map.addControl(drawControl);

    function circleToPolygon(layer, sides = 64) {
      const center = layer.getLatLng();
      const radius = layer.getRadius(); // meters
      const pts = [];
      for (let i=0;i<sides;i++){
        const angle = (i / sides) * 360;
        pts.push(L.latLng(center).destinationPoint(angle, radius));
      }
      pts.push(pts[0]);
      return L.polygon(pts);
    }

    // Add a very small geodesic helper for destinationPoint (bearing deg, distance m)
    L.LatLng.prototype.destinationPoint = function (brng, dist) {
      const R = 6371e3, δ = dist / R, θ = brng * Math.PI/180;
      const φ1 = this.lat * Math.PI/180, λ1 = this.lng * Math.PI/180;
      const sinφ1 = Math.sin(φ1), cosφ1 = Math.cos(φ1);
      const sinδ = Math.sin(δ), cosδ = Math.cos(δ), sinθ = Math.sin(θ), cosθ = Math.cos(θ);
      const sinφ2 = sinφ1*cosδ + cosφ1*sinδ*cosθ;
      const φ2 = Math.asin(sinφ2);
      const y = sinθ * sinδ * cosφ1;
      const x = cosδ - sinφ1*sinφ2;
      const λ2 = λ1 + Math.atan2(y, x);
      return L.latLng(φ2*180/Math.PI, (λ2*180/Math.PI + 540)%360 - 180);
    };

    async function queryPolygonAsGeoJSON(layer) {
      let poly;
      if (layer instanceof L.Circle) {
        poly = circleToPolygon(layer);
      } else if (layer instanceof L.Rectangle || layer instanceof L.Polygon) {
        poly = layer;
      }
      // toGeoJSON as Polygon
      const gj = poly.toGeoJSON();
      return gj.geometry; // {type:"Polygon", coordinates:[ [ [lng,lat], ... ] ] }
    }

    function renderNames(names, count, ms) {
      const stats = document.getElementById('stats');
      stats.innerHTML = `<small>${count} unique names ${ms ? '('+ms+' ms)' : ''}</small>`;
      const list = document.getElementById('list');
      list.innerHTML = '';
      if (!names.length) {
        list.innerHTML = '<small>No named roads found in this area.</small>';
        return;
      }
      names.forEach(n => {
        const div = document.createElement('div');
        div.className = 'pill';
        div.textContent = n;
        list.appendChild(div);
      });
    }

    map.on(L.Draw.Event.CREATED, async (e) => {
      drawnItems.clearLayers();
      const layer = e.layer;
      drawnItems.addLayer(layer);

      const geometry = await queryPolygonAsGeoJSON(layer);

      const t0 = performance.now();
      try {
        const res = await fetch(@json('/api/roads'), {
          method: 'POST',
          headers: { 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' },
          body: JSON.stringify({ geometry })
        });
        if (!res.ok) throw new Error('API error '+res.status);
        const data = await res.json();
        const ms = Math.round(performance.now()-t0);
        // Draw features if present; otherwise fallback to names-only mode
        roadsLayer.clearLayers();
        layerIndexById.clear();
        clearSelection();

        const fc = data.geojson || data.features || (data.type==='FeatureCollection' ? data : null);
        if (fc && Array.isArray(fc.features)) {
          roadsLayer.addData(fc);
          renderFeatureSidebar(fc.features, ms);
        } else {
          renderNames(data.names || [], data.count ?? 0, ms);
        }
      } catch (err) {
        document.getElementById('stats').innerHTML = `<small style="color:#b00">${err.message}</small>`;
        document.getElementById('list').innerHTML = '';
      }
    });
        // keep the latest drawn polygon around
    let currentGeometry = null;

    map.on(L.Draw.Event.CREATED, async (e) => {
      drawnItems.clearLayers();
      const layer = e.layer;
      drawnItems.addLayer(layer);

      const geometry = await queryPolygonAsGeoJSON(layer);
      currentGeometry = geometry;

      const t0 = performance.now();
      const res = await fetch(@json('/api/roads'), {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' },
        body: JSON.stringify({ geometry })
      });
      if (!res.ok) throw new Error('API error '+res.status);
      const data = await res.json();
      const ms = Math.round(performance.now()-t0);

      // render list with click handler
      const ul = document.getElementById('list');
      ul.innerHTML = '';
      (data.names || []).forEach(n => {
        const el = document.createElement('div');
        el.className = 'pill';
        el.textContent = n;
        el.onclick = async () => {
          // fetch geometry for this name
          const r2 = await fetch(@json('/api/roads'), {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
            body: JSON.stringify({ geometry: currentGeometry, with_geom: true, name: n })
          });
          if (!r2.ok) { alert('API '+r2.status); return; }
          const fc = await r2.json();
          if (window._hi) window._hi.clearLayers();
          window._hi = L.geoJSON(fc, { style: { weight: 4 } }).addTo(map);
          try { map.fitBounds(window._hi.getBounds(), { padding:[20,20] }); } catch(e){}
        };
        ul.appendChild(el);
      });
    });

  </script>
</body>
</html>
