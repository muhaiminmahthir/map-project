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
    let currentGeometry = null;          // <- remember the last drawn polygon
    let highlightLayer = null;

    function renderNames(names, count, ms) {
      const stats = document.getElementById('stats');
      stats.innerHTML = `<small>${count} unique names ${ms ? '('+ms+' ms)' : ''}</small>`;

      const list = document.getElementById('list');
      list.innerHTML = '';

      if (!names || !names.length) {
        list.innerHTML = '<small>No named roads found in this area.</small>';
        return;
      }

      names.forEach(n => {
        const pill = document.createElement('button');
        pill.className = 'pill';
        pill.type = 'button';
        pill.textContent = n;

        pill.onclick = async () => {
          if (!currentGeometry) {
            console.warn('No geometry saved from draw event.');
            return;
          }
          // fetch geometry for THIS name
          try {
            const r = await fetch({{ json_encode(route('api.roads')) }}, {
              method: 'POST',
              headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
              body: JSON.stringify({ geometry: currentGeometry, with_geom: true, name: n })
            });
            if (!r.ok) {
              const txt = await r.text();
              console.error('with_geom request failed', r.status, txt);
              alert('API ' + r.status + ' while fetching geometry');
              return;
            }
            const fc = await r.json();

            // clear previous highlights
            if (highlightLayer) { highlightLayer.remove(); }
            highlightLayer = L.geoJSON(fc, { style: { weight: 4 } }).addTo(map);

            try { map.fitBounds(highlightLayer.getBounds(), { padding:[20,20] }); } catch (e) {}
          } catch (err) {
            console.error('geom fetch error', err);
            alert('Failed to fetch geometry');
          }
        };

        list.appendChild(pill);
      });
    }

    // When user draws a shape
    map.on(L.Draw.Event.CREATED, async (e) => {
      drawnItems.clearLayers();
      const layer = e.layer;
      drawnItems.addLayer(layer);

      // convert to Polygon GeoJSON
      const geometry = await (async () => {
        if (layer instanceof L.Circle) {
          // circle -> approximate polygon
          const center = layer.getLatLng(), radius = layer.getRadius();
          const pts = [];
          for (let i=0;i<64;i++){
            const ang = (i/64)*360;
            pts.push(L.latLng(center).destinationPoint(ang, radius));
          }
          pts.push(pts[0]);
          return L.polygon(pts).toGeoJSON().geometry;
        }
        return layer.toGeoJSON().geometry;
      })();

      currentGeometry = geometry; // <- SAVE IT so clicks can use it

      // names-only fetch
      const t0 = performance.now();
      try {
        const res = await fetch({{ json_encode(route('api.roads')) }}, {
          method: 'POST',
          headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
          body: JSON.stringify({ geometry })
        });
        if (!res.ok) {
          const txt = await res.text();
          console.error('names-only request failed', res.status, txt);
          document.getElementById('stats').innerHTML = `<small style="color:#b00">API ${res.status}</small>`;
          document.getElementById('list').innerHTML = '';
          return;
        }
        const data = await res.json();
        const ms = Math.round(performance.now()-t0);
        renderNames(data.names || [], data.count ?? 0, ms);
      } catch (err) {
        console.error('names fetch error', err);
        document.getElementById('stats').innerHTML = `<small style="color:#b00">Network error</small>`;
        document.getElementById('list').innerHTML = '';
      }
    });

    // small geodesic helper for circle approximation
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
  </script>
</body>
</html>
