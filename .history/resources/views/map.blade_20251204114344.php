<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Map Dashboard</title>
  
  <!-- Leaflet CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
  
  <!-- Our Custom CSS -->
  <link rel="stylesheet" href="{{ asset('css/map.css') }}">
  
  <!-- Basemap Switcher Styles -->
  <style>
    /* Base Map Selector */
    .basemap-section {
      margin-bottom: 12px;
      padding-bottom: 12px;
      border-bottom: 1px solid #e9ecef;
    }
    
    .basemap-section h4 {
      font-size: 12px;
      font-weight: 600;
      color: #666;
      margin-bottom: 8px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .basemap-selector {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }
    
    .basemap-btn {
      flex: 1;
      min-width: 60px;
      padding: 8px 4px;
      border: 2px solid #e9ecef;
      background: white;
      border-radius: 6px;
      cursor: pointer;
      text-align: center;
      transition: all 0.2s;
    }
    
    .basemap-btn:hover {
      border-color: #2196f3;
    }
    
    .basemap-btn.active {
      border-color: #2196f3;
      background: #e3f2fd;
    }
    
    .basemap-btn .basemap-icon {
      width: 32px;
      height: 32px;
      margin: 0 auto 4px;
      border-radius: 4px;
    }
    
    .basemap-btn span {
      display: block;
      font-size: 10px;
      font-weight: 500;
      color: #333;
    }
    
    /* Icon colors for each basemap */
    .basemap-icon.osm { background: #c8e6c9; }
    .basemap-icon.satellite { background: #1a237e; }
    .basemap-icon.hybrid { background: #33691e; }
    .basemap-icon.carto { background: #f5f5f5; border: 1px solid #ddd; }
    .basemap-icon.esri { background: #0d47a1; }
  </style>
</head>
<body>
  <div class="map-page">
    <!-- Map Container -->
    <div id="map"></div>
    
    <!-- Sidebar -->
    <div id="sidebar">
      <button id="clearAllBtn" class="btn-clear">Clear All Areas</button>
      
      <!-- Base Map Selector (NEW) -->
      <div class="basemap-section">
        <h4>Base Map</h4>
        <div class="basemap-selector">
          <button class="basemap-btn active" data-basemap="osm" title="OpenStreetMap">
            <div class="basemap-icon osm"></div>
            <span>OSM</span>
          </button>
          <button class="basemap-btn" data-basemap="satellite" title="Google Satellite">
            <div class="basemap-icon satellite"></div>
            <span>Satellite</span>
          </button>
          <button class="basemap-btn" data-basemap="hybrid" title="Google Hybrid (Satellite + Labels)">
            <div class="basemap-icon hybrid"></div>
            <span>Hybrid</span>
          </button>
          <button class="basemap-btn" data-basemap="carto" title="Carto Light">
            <div class="basemap-icon carto"></div>
            <span>Carto</span>
          </button>
          <button class="basemap-btn" data-basemap="esri" title="Esri Satellite">
            <div class="basemap-icon esri"></div>
            <span>Esri</span>
          </button>
        </div>
      </div>
      
      <!-- View Management Section -->
      <div class="view-management">
        <label style="font-size:12px; font-weight:600;">
          View
          <select id="viewSelect" style="margin-left:4px; padding:2px 6px; font-size:12px;">
            <!-- Options will be populated by JavaScript -->
          </select>
        </label>
        <div class="view-buttons">
          <button id="addViewBtn" class="btn-small btn-add" title="Add new view">+ Add</button>
          <button id="renameViewBtn" class="btn-small btn-rename" title="Rename current view">Rename</button>
          <button id="deleteViewBtn" class="btn-small btn-delete" title="Delete current view">Delete</button>
        </div>
      </div>
      
      <!-- Save/Load Buttons -->
      <div style="display:flex; gap:4px; margin-bottom:8px;">
        <button id="saveViewsBtn" class="btn-clear" style="flex:1;">Save to PostGIS</button>
        <button id="loadViewsBtn" class="btn-clear" style="flex:1;">Load from PostGIS</button>
      </div>
      
      <!-- Admin Area Filters -->
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

      <!-- Road Names List -->
      <h3 style="margin-top:0;">Road Names</h3>
      <div id="stats"><small>Draw an area on the map</small></div>
      <div id="list"></div>
    </div>
  </div>

  <!-- Leaflet JS -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>

  <!-- Configuration (injected from Laravel) -->
  <script>
    window.MAP_CONFIG = {
      // Admin area endpoints (unchanged)
      ADMIN_AREAS_URL: @json(route('api.admin-areas')),
      ADMIN_GEOM_TMPL: @json(route('api.admin-geometry', ['relId' => 'REL_ID'])),
      
      // Overpass roads endpoint (unchanged)
      API_URL: @json(route('api.roads')),
      
      // NEW: Spatial/PostGIS endpoints
      SPATIAL_VIEWS_URL: @json(route('spatial.views.list')),
      SPATIAL_VIEW_URL: @json(url('/api/spatial/views')),  // Base URL, we append /{key}
      SPATIAL_AREAS_URL: @json(url('/api/spatial/views')), // Base URL, we append /{key}/areas
      SPATIAL_ROADS_URL: @json(url('/api/spatial/views')), // Base URL, we append /{key}/roads
      SPATIAL_EXPORT_URL: @json(url('/api/spatial/export')),
    };
  </script>

  <!-- Our Custom JS -->
  <script src="{{ asset('js/map.js') }}"></script>
</body>
</html>