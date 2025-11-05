<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Map Dashboard</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
  <style>
    html,body { height:100%; margin:0; font-family:sans-serif; }
    #map { position:absolute; top:0; bottom:0; left:0; right:260px; }
    #sidebar {
      position:absolute; top:0; right:0; bottom:0; width:260px;
      overflow:auto; padding:10px; background:#fafafa; box-shadow:-2px 0 5px rgba(0,0,0,.1);
    }
    .pill {
      display:block; width:100%; text-align:left; margin:3px 0;
      padding:5px 8px; border:1px solid #ccc; border-radius:4px;
      background:#fff; cursor:pointer;
    }
    .pill:hover { background:#eaeaea; }
  </style>
</head>
<body>
  <div id="map"></div>
  <div id="sidebar">
    <h3 style="margin-top:0;">Road Names</h3>
    <div id="stats"><small>Draw an area on the map</small></div>
    <div id="list"></div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>

  <script>
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

  const API_URL = {{ json_encode(route('api.roads')) }};
  let currentGeometry = null;
  let highlightLayer = null;

  // ---------- draw handler ----------
  map.on(L.Draw.Event.CREATED, async (e) => {
    drawnItems.clearLayers();
    const layer = e.layer;
    drawnItems.addLayer(layer);

    currentGeometry = layer.toGeoJSON().geometry;

    document.getElementById('stats').innerHTML = '<small>Fetching…</small>';
    const t0 = performance.now();

    try {
      const res = await fetch(API_URL, {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({ geometry: currentGeometry })
      });
      if (!res.ok) throw new Error('HTTP '+res.status);
      const data = await res.json();
      const ms = Math.round(performance.now()-t0);
      renderList(data.names || [], data.count ?? 0, ms);
    } catch(err){
      console.error(err);
      document.getElementById('stats').innerHTML = '<small style="color:#b00">API error</small>';
      document.getElementById('list').innerHTML = '';
    }
  });

  // ---------- list rendering ----------
  function renderList(names,count,ms){
    const stats = document.getElementById('stats');
    stats.innerHTML = `<small>${count} names found (${ms} ms)</small>`;

    const list = document.getElementById('list');
    list.innerHTML = '';

    names.forEach(n=>{
      const pill = document.createElement('button');
      pill.className='pill';
      pill.textContent=n;
      pill.onclick = ()=>highlightRoad(n);
      list.appendChild(pill);
    });
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
  </script>
</body>
</html>
