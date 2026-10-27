/* Scelta del luogo: città con i suggerimenti dei comuni italiani e punto esatto del campo sulla mappa.
   Usato da entrambi i siti (Next e PHP). Il markup è .place-picker con i campi data-pp="city|area|find|lat|lng|map"; si attiva da solo. */
(function () {
  var LEAFLET_CSS = "https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css";
  var LEAFLET_JS = "https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js";
  var comuni = null, comuniWait = [];

  function loadComuni(src, cb) {
    if (comuni) return cb(comuni);
    comuniWait.push(cb);
    if (comuniWait.length > 1) return;
    fetch(src).then(function (r) { return r.json(); }).catch(function () { return []; }).then(function (d) {
      comuni = d; comuniWait.splice(0).forEach(function (f) { f(d); });
    });
  }
  function loadLeaflet(cb) {
    if (!document.querySelector("link[data-leaflet]")) {
      var c = document.createElement("link"); c.rel = "stylesheet"; c.href = LEAFLET_CSS; c.setAttribute("data-leaflet", "1"); document.head.appendChild(c);
    }
    if (window.L) return cb();
    var s = document.querySelector("script[data-leaflet]");
    if (!s) { s = document.createElement("script"); s.src = LEAFLET_JS; s.setAttribute("data-leaflet", "1"); document.body.appendChild(s); }
    s.addEventListener("load", cb);
  }
  /* spec: una stringa libera (campo + città) oppure {city} per un comune: la ricerca «per comune» evita di cadere su province o frazioni omonime */
  function geocode(spec, cb) {
    var qs = typeof spec === "string" ? "q=" + encodeURIComponent(spec) : "city=" + encodeURIComponent(spec.city);
    fetch("https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=it&" + qs, { headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (j) { cb(j && j[0] ? [Number(j[0].lat), Number(j[0].lon)] : null); })
      .catch(function () { cb(null); });
  }
  var norm = function (s) { return s.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, ""); };

  function init(root) {
    if (root.getAttribute("data-ready")) return;
    root.setAttribute("data-ready", "1");
    var $ = function (k) { return root.querySelector('[data-pp="' + k + '"]'); };
    var city = $("city"), area = $("area"), find = $("find"), lat = $("lat"), lng = $("lng"), mapEl = $("map");
    var list = document.createElement("ul"); list.className = "pp-list"; list.hidden = true;
    city.parentNode.style.position = "relative"; city.parentNode.appendChild(list);
    var map = null, pin = null, status = root.querySelector(".pp-status");
    var say = function (t) { if (status) status.textContent = t; };

    function setPin(p, move) {
      lat.value = p[0].toFixed(6); lng.value = p[1].toFixed(6);
      if (!map) return;
      if (pin) pin.setLatLng(p);
      else pin = window.L.circleMarker(p, { radius: 11, color: "#1f1a2e", weight: 3, fillColor: "#ffd23f", fillOpacity: 1 }).addTo(map);
      if (move) map.setView(p, Math.max(map.getZoom(), 16));
      say("Punto scelto: puoi toccare un altro punto per spostarlo.");
    }
    function go(q, zoom) {
      say("Cerco sulla mappa…");
      geocode(q, function (p) {
        if (!p) return say("Non lo trovo: tocca direttamente la mappa dove si gioca.");
        if (map) map.setView(p, zoom);
        say("Tocca la mappa sul campo o sulla zona dove giocate.");
      });
    }
    loadLeaflet(function () {
      var L = window.L, has = lat.value && lng.value;
      map = L.map(mapEl, { scrollWheelZoom: false }).setView(has ? [Number(lat.value), Number(lng.value)] : [42.5, 12.5], has ? 16 : 5);
      L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: "© OpenStreetMap", referrerPolicy: "strict-origin-when-cross-origin" }).addTo(map);
      if (has) setPin([Number(lat.value), Number(lng.value)], false);
      else if (city.value) go({ city: city.value }, 13);
      map.on("click", function (e) { setPin([e.latlng.lat, e.latlng.lng], false); });
      setTimeout(function () { map.invalidateSize(); }, 300);
    });

    /* Elenco completo dei comuni (i più grandi per primi) che si restringe mentre si scrive; si disegna a pezzi da 60 e si prosegue scorrendo. */
    var matches = [], shown = 0;
    function pick(c) {
      city.value = c[0]; list.hidden = true; lat.value = ""; lng.value = "";
      if (pin && map) { map.removeLayer(pin); pin = null; }
      go({ city: c[0] }, 13); area.focus();
    }
    function more() {
      for (var end = Math.min(matches.length, shown + 60); shown < end; shown++) {
        (function (c) {
          var li = document.createElement("li"); li.tabIndex = 0;
          li.textContent = c[0] + " (" + c[1] + ")";
          li.addEventListener("mousedown", function (e) { e.preventDefault(); pick(c); });
          li.addEventListener("keydown", function (e) { if (e.key === "Enter") pick(c); });
          list.appendChild(li);
        })(matches[shown]);
      }
    }
    function suggest() {
      var v = norm(city.value.trim());
      loadComuni(root.getAttribute("data-src") || "/comuni-it.json", function (all) {
        var starts = [], inside = [];
        for (var i = 0; i < all.length; i++) {
          var n = norm(all[i][0]);
          if (!v || n.indexOf(v) === 0) starts.push(all[i]); else if (n.indexOf(v) > 0) inside.push(all[i]);
        }
        matches = starts.concat(inside); shown = 0; list.innerHTML = ""; list.scrollTop = 0;
        more();
        list.hidden = !matches.length;
      });
    }
    list.addEventListener("scroll", function () { if (list.scrollTop + list.clientHeight > list.scrollHeight - 80) more(); });
    city.addEventListener("click", suggest);
    city.addEventListener("input", suggest);
    city.addEventListener("focus", suggest);
    city.addEventListener("blur", function () { setTimeout(function () { list.hidden = true; }, 150); });
    city.addEventListener("keydown", function (e) { if (e.key === "Escape") list.hidden = true; });
    find.addEventListener("click", function () { go(area.value ? area.value + ", " + city.value + ", Italia" : { city: city.value }, area.value ? 16 : 13); });
    area.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); find.click(); } });
  }
  window.initPlacePickers = function () { document.querySelectorAll(".place-picker").forEach(init); };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", window.initPlacePickers); else window.initPlacePickers();
})();
