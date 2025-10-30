# 🗺️ Route Finder Dashboard (Laravel + Leaflet + Overpass API)

A zero-cost Laravel web app that lets you draw an area (circle, rectangle, or polygon) on a map of Malaysia (or the world) and instantly list all road/route names found inside the highlighted area using OpenStreetMap and the Overpass API.

---

## Overview

This project demonstrates a **fully functional map-based dashboard** that works entirely for free.

- **Frontend:** Leaflet.js + leaflet-draw for map and drawing tools  
- **Backend:** Laravel (PHP 8.2) with caching, rate limiting, and Overpass API integration  
- **Data Source:** OpenStreetMap via Overpass API  
- **Hosting:** Runs on any standard Laravel environment (e.g. your CentOS VM)

Draw any shape → app sends polygon to Laravel backend → Laravel queries Overpass → returns list of route/road names → displayed on sidebar.

---

## ⚙️ System Requirements

| Component | Minimum Version | Notes |
|------------|----------------|-------|
| **PHP** | **8.2+** | Required for Laravel 10+ and modern syntax |
| **Composer** | 2.x | To install dependencies |
| **Laravel** | 10.x | Framework used |
| **Web Server** | Apache or Nginx | Point document root to `/public` |
| **Extensions** | `curl`, `openssl`, `mbstring`, `json`, `pdo`, `tokenizer`, `fileinfo` | Standard Laravel stack |
| **CA Certificate Bundle** | Valid `cacert.pem` or system CA path | Needed for secure HTTPS connections (Overpass API) |

### 🧩 About CA Bundle

Since this app calls external HTTPS APIs (like `https://overpass-api.de`), PHP must verify SSL certificates.  
If you get errors such as:

- *cURL error 60: SSL certificate problem: unable to get local issuer certificate*
        You must configure a **CA bundle**.
        
        ####  Fix (Windows)
        1. Download a current bundle (e.g. from [curl.se/ca](https://curl.se/docs/caextract.html)) → save as `C:\php\extras\ssl\cacert.pem`
        2. Edit your `php.ini`:

## VERSION 1.0
## Design Reasoning and Architecture Decisions

### 1️⃣ Why No Database (for Now)

The app’s only job is:
> To draw an area and list the existing route/road names from OpenStreetMap.

This is a **pure query** to public data — there’s nothing to persist.  
There are no users created, saving shapes, or maintaining our own dataset.

Using a full database adds overhead (migrations, models, backups) with no benefit here.

Instead, we use **Laravel’s file cache** to:
- Store Overpass results for ~15 minutes.
- Reduce repeated API calls.
- Improve speed.
- Stay within Overpass usage limits.

Once we need to **store user accounts, saved areas, or query history**, then a database becomes useful.

---

### 2️⃣ Why we use Overpass API (Public) Instead of Hosting the Data

- **Free & instant:** no setup, no database import, no hosting cost.
- **Real-time OSM data:** you always query the live map.
- **Tradeoff:** public servers have rate limits and can be slow if overloaded.

We mitigate that by:
- Using small polygons.
- Caching server-side.
- Adding Laravel’s `throttle` middleware (rate limiting per IP).

Once we need heavy use or complex spatial joins, migrate to **PostGIS** instance with an OSM extract.

---

### 3️⃣ Why Leaflet Instead of Google Maps / Mapbox

- **Leaflet** is free, open-source, lightweight, and easy to extend.  
- Uses **OpenStreetMap raster tiles** (no API key, no billing).  
- Supports drawing tools easily via `leaflet-draw`.
Once we want to vector tiles, 3D, or custom map styling, we can switch to **MapLibre GL JS** and still keep a free-tier tile provider.

---

### 4️⃣ Why a Laravel API Endpoint Instead of Direct Browser Calls

Even though Overpass doesn’t need an API key, routing requests through the backend gives us:

- **No CORS issues** — frontend and backend share origin.  
- **Centralized caching and rate limiting.**  
- **Cleaner upgrade path.**  
  Once we switch to PostGIS or add user accounts, we only change the backend.

---

### 5️⃣ Why Convert Circles to Polygons

Overpass only supports **polygons**, not abstract “circles.”  
We approximate each circle as a polygon with 64 points (geodesic shape).  
It’s accurate enough for this use case and keeps the backend logic simple.

---

### 6️⃣ Why the Overpass Query Uses `way["highway"]["name"]`

In OpenStreetMap:
- **Roads** are stored as *ways* with tags `highway=*` and `name=*`.
- **Transit/hiking/cycling routes** are stored as *relations* (`type=route`).

This app targets road names first.  
If we want other routes, change the Overpass query in `OverpassController` to:

```txt
[out:json][timeout:25];
(
  relation["type"="route"]["route"~"bus|bicycle|hiking"](poly:"...");
);
out tags;
