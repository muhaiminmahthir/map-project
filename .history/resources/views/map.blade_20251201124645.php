<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Map Dashboard</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
  <link rel="stylesheet" href="{{ asset('css/map.css') }}">
</head>
<body>
  <div class="map-page">
    <div id="map"></div>
    <div id="sidebar">
      <button id="clearAllBtn" class="btn-clear"> Clear All Areas</button>
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
        <button id="saveViewsBtn" class="btn-clear" style="flex:1;"> Save views</button>
        <button id="loadViewsBtn" class="btn-clear" style="flex:1;"> Load saved</button>
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
    window.MapConfig = {
      adminAreasUrl: @json(route('api.admin-areas')),
      adminGeomTemplateUrl: @json(route('api.admin-geometry', ['relId' => 'REL_ID'])),
      apiUrl: @json(route('api.roads')),
      viewsLoadUrl: @json(route('api.views.load', ['key' => 'default'])),
      viewsSaveUrl: @json(route('api.views.save', ['key' => 'default'])),
    };
  </script>
  <script src="{{ asset('js/map.js') }}"></script>
</body>
</html>
