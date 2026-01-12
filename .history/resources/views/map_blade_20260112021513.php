<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Map Editor</title>
  
  <!-- Leaflet CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
  
  <!-- Our Custom CSS -->
  <link rel="stylesheet" href="{{ asset('css/map.css') }}">
</head>
<body>
  <div class="map-page">
    <!-- Map Container -->
    <div id="map"></div>
    
    <!-- Sidebar -->
    <div id="sidebar">
      
      <!-- Header -->
      <div class="sidebar-header">
        <h2>Map Editor</h2>
        <div id="stats"><small>Loading...</small></div>
      </div>

      <!-- View Management -->
      <div class="sidebar-section">
        <h4>View</h4>
        <div class="view-management">
          <select id="viewSelect"></select>
          <div class="view-buttons">
            <button id="addViewBtn" class="btn-icon btn-add" title="Add new view">+</button>
            <button id="renameViewBtn" class="btn-icon btn-rename" title="Rename">✏️</button>
            <button id="deleteViewBtn" class="btn-icon btn-delete" title="Delete">🗑️</button>
          </div>
        </div>
        <div class="view-actions">
          <button id="loadViewsBtn" class="btn-secondary">↻ Refresh</button>
          <button id="saveViewsBtn" class="btn-secondary">ℹ Info</button>
          <button id="clearAllBtn" class="btn-danger">Clear All</button>
        </div>
      </div>
      
      <!-- Base Map Selector -->
      <div class="sidebar-section">
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
          <button class="basemap-btn" data-basemap="hybrid" title="Google Hybrid">
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

      <!-- Location Search -->
      <div class="sidebar-section">
        <h4>Search Location</h4>
        <div class="search-container">
          <input type="text" id="addressSearch" class="search-input" placeholder="Search address or place...">
          <button id="searchBtn" class="search-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.35-4.35"></path>
            </svg>
          </button>
        </div>
        <div id="searchResults" class="search-results"></div>
      </div>

      <!-- Building Plan Overlay -->
      <div class="sidebar-section collapsible">
        <h4 class="section-toggle">
          Building Plan Overlay
          <span class="toggle-icon">▼</span>
        </h4>
        <div class="section-content">
          <div class="overlay-control">
            <div class="overlay-toggle">
              <input type="checkbox" id="buildingPlanToggle" checked>
              <label for="buildingPlanToggle">Show Building Plan</label>
            </div>
            
            <div class="overlay-select">
              <label for="buildingPlanSelect">Select Plan</label>
              <select id="buildingPlanSelect">
                <option value="">Loading plans...</option>
              </select>
            </div>
            
            <div class="opacity-control">
              <label for="buildingPlanOpacity">Opacity</label>
              <div class="opacity-slider-container">
                <input type="range" id="buildingPlanOpacity" min="0" max="100" value="60" class="opacity-slider">
                <span id="opacityValue" class="opacity-value">60%</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Navigate to Area -->
      <div class="sidebar-section collapsible">
        <h4 class="section-toggle">
          Navigate to Area
          <span class="toggle-icon">▼</span>
        </h4>
        <div class="section-content">
          <div id="filters">
            <label>
              Country
              <div class="input-group">
                <input id="countrySearch" type="text" placeholder="e.g., Malaysia">
                <button id="btnCountrySearch" type="button">Search</button>
              </div>
              <select id="countrySel">
                <option value="" selected disabled>Choose a country…</option>
              </select>
            </label>

            <label>
              State / Province
              <select id="stateSel" disabled>
                <option value="" selected disabled>Choose a state…</option>
              </select>
            </label>

            <label>
              District / Regency
              <select id="districtSel" disabled>
                <option value="" selected disabled>Choose a district…</option>
              </select>
            </label>
          </div>
        </div>
      </div>

      <!-- Features List -->
      <div class="sidebar-section features-section">
        <h4>Features</h4>
        <div id="featureList" class="feature-list">
          <div class="empty-state">No features drawn yet.<br>Use the drawing tools to add areas, roads, or markers.</div>
        </div>
      </div>

      <!-- Drawing Help -->
      <div class="sidebar-section help-section">
        <h4>Drawing Tools</h4>
        <div class="help-items">
          <div class="help-item">
            <span class="help-icon" style="background:#3388ff">▢</span>
            <span>Polygon/Rectangle = Area (house/lot)</span>
          </div>
          <div class="help-item">
            <span class="help-icon" style="background:#ff7800">━</span>
            <span>Polyline = Road</span>
          </div>
          <div class="help-item">
            <span class="help-icon" style="background:#808080">●</span>
            <span>Circle Marker = Utility (manhole, pole, etc.)</span>
          </div>
        </div>
        <p class="help-tip">Double-click items in the list to rename them.</p>
      </div>

    </div>
  </div>

  <!-- Leaflet JS -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>

  <!-- Configuration -->
  <script>
    window.MAP_CONFIG = {
      // Admin area endpoints
      ADMIN_AREAS_URL: @json(route('api.admin-areas')),
      ADMIN_GEOM_TMPL: @json(route('api.admin-geometry', ['relId' => 'REL_ID'])),
      
      // Spatial/PostGIS endpoints
      SPATIAL_VIEWS_URL: @json(route('spatial.views.list')),
      SPATIAL_VIEW_URL: @json(url('/api/spatial/views')),
      SPATIAL_AREAS_URL: @json(url('/api/spatial/views')),
      SPATIAL_ROADS_URL: @json(url('/api/spatial/views')),
      SPATIAL_MARKERS_URL: @json(url('/api/spatial/views')),
      SPATIAL_EXPORT_URL: @json(url('/api/spatial/export')),
    };
  </script>

  <!-- Collapsible Sections Script -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('.section-toggle').forEach(toggle => {
        toggle.addEventListener('click', function() {
          const section = this.closest('.collapsible');
          section.classList.toggle('collapsed');
        });
      });
    });
  </script>

  <!-- Our Custom JS -->
  <script src="{{ asset('js/map.js') }}"></script>
</body>
</html>
