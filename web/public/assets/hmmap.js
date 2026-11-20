/* Hitech Medicos Delivery - one map API for every page.
 * Uses Google Maps when a key is set in Settings > Maps, otherwise (or if Google fails to load) OpenStreetMap via Leaflet.
 * Usage: HMMap.ready(function () { var m = HMMap.create('map', {center:[lat,lng], zoom:12}); m.marker(...); });
 */
(function () {
  'use strict';
  var cfg = window.HM_MAP || {};
  var useGoogle = !!cfg.key;
  var waiting = [];
  var loaded = false;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  function done() { loaded = true; var w = waiting; waiting = []; w.forEach(function (f) { try { f(); } catch (e) { console.error(e); } }); }
  function fallback() { if (useGoogle) { useGoogle = false; HMMap.provider = 'osm'; var n = document.querySelector('.hm-map-note'); if (n) n.hidden = false; } if (!loaded) done(); }

  if (useGoogle) {
    window.gm_authFailure = function () { fallback(); };
    window.__hmGmReady = function () { done(); };
    var s = document.createElement('script');
    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(cfg.key) + '&libraries=places,geometry&loading=async&callback=__hmGmReady';
    s.async = true;
    s.onerror = fallback;
    document.head.appendChild(s);
    setTimeout(function () { if (!loaded) fallback(); }, 8000);
  } else {
    setTimeout(done, 0);
  }

  // ---------- Google adapter ----------
  function GoogleMap(el, o) {
    var map = new google.maps.Map(el, { center: { lat: o.center[0], lng: o.center[1] }, zoom: o.zoom || 12, mapTypeId: cfg.type || 'roadmap', streetViewControl: false, fullscreenControl: true, mapTypeControl: true, clickableIcons: false, gestureHandling: 'greedy' });
    var info = new google.maps.InfoWindow();
    var api = {
      provider: 'google', raw: map,
      marker: function (lat, lng, opt) {
        opt = opt || {};
        var mk = new google.maps.Marker({ position: { lat: lat, lng: lng }, map: map, title: opt.title || '', zIndex: opt.z || 1,
          icon: opt.pin ? undefined : { path: google.maps.SymbolPath.CIRCLE, scale: opt.size || 9, fillColor: opt.color || '#2f6fed', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 } });
        var html = opt.popup || '';
        mk.addListener('click', function () { if (html) { info.setContent('<div style="font:13px/1.45 Inter,Arial,sans-serif;max-width:240px">' + html + '</div>'); info.open({ map: map, anchor: mk }); } if (opt.onClick) opt.onClick(); });
        return { remove: function () { mk.setMap(null); }, move: function (a, b) { mk.setPosition({ lat: a, lng: b }); },
          update: function (a, b, p, t) { mk.setPosition({ lat: a, lng: b }); html = p || html; if (t) mk.setTitle(t); if (info.getAnchor && info.getAnchor() === mk && html) info.setContent('<div style="font:13px/1.45 Inter,Arial,sans-serif;max-width:240px">' + html + '</div>'); } };
      },
      circle: function (lat, lng, r, opt) { var c = new google.maps.Circle({ map: map, center: { lat: lat, lng: lng }, radius: r, strokeColor: (opt || {}).color || '#20b2c4', strokeWeight: 1, fillColor: (opt || {}).color || '#20b2c4', fillOpacity: .08, clickable: false }); return { remove: function () { c.setMap(null); } }; },
      line: function (pts, opt) { var p = new google.maps.Polyline({ map: map, path: pts.map(function (x) { return { lat: x[0], lng: x[1] }; }), strokeColor: (opt || {}).color || '#6b5cf0', strokeWeight: (opt || {}).weight || 4, strokeOpacity: .85 }); return { remove: function () { p.setMap(null); } }; },
      fit: function (pts, maxZoom) { if (!pts.length) return; if (pts.length === 1) { map.setCenter({ lat: pts[0][0], lng: pts[0][1] }); map.setZoom(maxZoom || 15); return; } var b = new google.maps.LatLngBounds(); pts.forEach(function (x) { b.extend({ lat: x[0], lng: x[1] }); }); map.fitBounds(b, 40); if (maxZoom) google.maps.event.addListenerOnce(map, 'idle', function () { if (map.getZoom() > maxZoom) map.setZoom(maxZoom); }); },
      view: function (lat, lng, z) { map.panTo({ lat: lat, lng: lng }); if (z) map.setZoom(z); },
      onClick: function (cb) { map.addListener('click', function (e) { cb(e.latLng.lat(), e.latLng.lng()); }); },
      resize: function () { google.maps.event.trigger(map, 'resize'); }
    };
    return api;
  }

  // ---------- Leaflet / OpenStreetMap adapter ----------
  function OsmMap(el, o) {
    var map = L.map(el).setView(o.center, o.zoom || 12);
    // OSM tile policy requires a Referer header: send the site origin only.
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors', referrerPolicy: 'strict-origin-when-cross-origin', crossOrigin: false }).addTo(map);
    return {
      provider: 'osm', raw: map,
      marker: function (lat, lng, opt) {
        opt = opt || {};
        var m = L.circleMarker([lat, lng], { radius: opt.size || 9, color: '#fff', weight: 2, fillColor: opt.color || '#2f6fed', fillOpacity: 1 }).addTo(map);
        if (opt.title) m.bindTooltip(esc(opt.title));
        if (opt.popup) m.bindPopup(opt.popup);
        if (opt.onClick) m.on('click', opt.onClick);
        return { remove: function () { map.removeLayer(m); }, move: function (a, b) { m.setLatLng([a, b]); },
          update: function (a, b, p, t) { m.setLatLng([a, b]); if (p) { if (m.getPopup()) m.setPopupContent(p); else m.bindPopup(p); } if (t) { if (m.getTooltip()) m.setTooltipContent(esc(t)); else m.bindTooltip(esc(t)); } } };
      },
      circle: function (lat, lng, r, opt) { var c = L.circle([lat, lng], { radius: r, color: (opt || {}).color || '#20b2c4', weight: 1 }).addTo(map); return { remove: function () { map.removeLayer(c); } }; },
      line: function (pts, opt) { var p = L.polyline(pts, { color: (opt || {}).color || '#6b5cf0', weight: (opt || {}).weight || 4, opacity: .85 }).addTo(map); return { remove: function () { map.removeLayer(p); } }; },
      fit: function (pts, maxZoom) { if (!pts.length) return; if (pts.length === 1) { map.setView(pts[0], maxZoom || 15); return; } map.fitBounds(pts, { padding: [40, 40], maxZoom: maxZoom || 17 }); },
      view: function (lat, lng, z) { map.setView([lat, lng], z || map.getZoom()); },
      onClick: function (cb) { map.on('click', function (e) { cb(e.latlng.lat, e.latlng.lng); }); },
      resize: function () { map.invalidateSize(); }
    };
  }

  var geoCache = {};
  window.HMMap = {
    provider: useGoogle ? 'google' : 'osm',
    esc: esc,
    ready: function (f) { if (loaded) f(); else waiting.push(f); },
    create: function (id, o) {
      var el = typeof id === 'string' ? document.getElementById(id) : id;
      return (useGoogle && window.google && google.maps) ? GoogleMap(el, o) : OsmMap(el, o);
    },
    /** Place name for a point (Google only); resolves null without a key. Cached per ~100 m. */
    placeName: function (lat, lng) {
      return new Promise(function (resolve) {
        if (!(useGoogle && window.google && google.maps)) return resolve(null);
        var k = lat.toFixed(3) + ',' + lng.toFixed(3);
        if (geoCache[k] !== undefined) return resolve(geoCache[k]);
        new google.maps.Geocoder().geocode({ location: { lat: lat, lng: lng } }, function (res, st) {
          var name = null;
          if (st === 'OK' && res && res.length) {
            var r = res.find(function (x) { return x.types.indexOf('sublocality') > -1 || x.types.indexOf('neighborhood') > -1 || x.types.indexOf('route') > -1; }) || res[0];
            name = r.formatted_address.replace(/, India$/, '').split(',').slice(0, 3).join(',');
          }
          geoCache[k] = name;
          resolve(name);
        });
      });
    },
    /** Google Places address search on a text input; cb(place) with lat, lng, address, pincode. */
    autocomplete: function (input, cb) {
      if (!(useGoogle && window.google && google.maps && google.maps.places)) return false;
      var ac = new google.maps.places.Autocomplete(input, { componentRestrictions: { country: 'in' }, fields: ['formatted_address', 'geometry', 'address_components', 'name'] });
      ac.addListener('place_changed', function () {
        var p = ac.getPlace();
        if (!p.geometry) return;
        var pin = '';
        (p.address_components || []).forEach(function (c) { if (c.types.indexOf('postal_code') > -1) pin = c.long_name; });
        cb({ lat: p.geometry.location.lat(), lng: p.geometry.location.lng(), address: p.formatted_address, name: p.name, pincode: pin });
      });
      return true;
    }
  };
})();
