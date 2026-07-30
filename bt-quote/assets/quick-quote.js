/**
 * BT Quote — Quick Quote tool.
 *
 * Ported from the BT-Quick-Quote-Tool WPCode snippet, with two additions:
 *
 *   1. URL PREFILL — the tool seeds itself from query params on load, so a
 *      link can arrive with all three boxes already filled in:
 *
 *        /quote/?qty=48&g=g5000&loc=2
 *        /quote/?qty=48&g=supplied&m=emb&et=logo
 *        /quote/?qty=24&g=custom&r=12.50&loc=1
 *
 *      Params (long aliases in parentheses, all optional, all validated —
 *      an unknown or out-of-range value is ignored, never rendered):
 *        qty            1–1000
 *        g  (garment)   a garment id, or 'supplied' / 'custom'
 *        loc(locations) 1–3           (print only)
 *        m  (method)    'print' | 'emb' / 'embroidery'
 *        et (embtype)   'text' | 'logo' | 'hard'   (embroidery only)
 *        r  (retail)    dollar amount, only meaningful with g=custom
 *
 *   2. COPY QUOTE LINK — builds that URL from the current selections so a
 *      link never has to be hand-typed.
 */
(function () {
  var CFG      = window.BTQ_QQ || {};
  var API_BASE = CFG.apiBase || '/wp-json/boomerts/v1';

  var METHODS = [
    { id: 'print',      label: 'Printed',     sub: 'Screen / DTF' },
    { id: 'embroidery', label: 'Embroidered', sub: 'Stitched' }
  ];

  var LOCS = {
    1: { label: '1 Location',  desc: 'ie: Front Only' },
    2: { label: '2 Locations', desc: 'ie: Front & Back' },
    3: { label: '3 Locations', desc: 'ie: Front, Back & Sleeve' }
  };

  var EMB_TYPES = [
    { id: 'text', label: 'Names / Text',   short: 'Text', sub: '1\u20133 lines, stock fonts', badge: 'Text' },
    { id: 'logo', label: 'Logo',           short: 'Logo', sub: 'Up to 12k stitches',          badge: 'Logo' },
    { id: 'hard', label: 'Hard-to-Handle', short: 'Hard', sub: 'Bags, hats, blankets',        badge: 'Hard' }
  ];

  var GMTS = [
    { id: 'g5000',  name: 'Standard Cotton Tee', sub: 'ie: Gildan 5000',       retail: 5.95 },
    { id: 'g8000',  name: 'DryBlend 50/50 Tee',  sub: 'ie: Gildan 8000',       retail: 6.95 },
    { id: 'g64000', name: 'Softstyle Tee',       sub: 'ie: Gildan 64000',      retail: 7.45 },
    { id: 'g18000', name: 'Crewneck Sweatshirt', sub: 'ie: Gildan 18000',      retail: 15.95 },
    { id: 'g18500', name: 'Standard Hoodie',     sub: 'ie: Gildan 18500',      retail: 20.95 },
    { id: 'bc3001', name: 'Premium Tee',         sub: 'ie: Bella+Canvas 3001', retail: 9.45 },
    { id: 'nl3600', name: 'Premium Fitted Tee',  sub: 'ie: Next Level 3600',   retail: 8.95 },
    { id: 'c25100', name: 'Performance Tee',     sub: 'ie: C2 Sport 5100',     retail: 7.95 }
  ];

  var GMT_DISC = [
    {mx:11,m:1.00},{mx:23,m:0.97},{mx:35,m:0.95},{mx:47,m:0.92},{mx:59,m:0.90},
    {mx:71,m:0.87},{mx:99,m:0.84},{mx:199,m:0.81},{mx:299,m:0.70},{mx:9999,m:0.66}
  ];

  var QTY_MIN = 1, QTY_MAX = 1000;

  function gmtMult(q) {
    for (var i = 0; i < GMT_DISC.length; i++) {
      if (q <= GMT_DISC[i].mx) return GMT_DISC[i].m;
    }
    return 0.66;
  }

  function gmtBadge(retail, q) {
    var base = 5.95;
    if (retail === base) return 'Base';
    var diff = Math.round((retail - base) * gmtMult(q) * 100) / 100;
    return (diff >= 0 ? '+' : '') + '$' + diff.toFixed(2);
  }

  /* ── State ──────────────────────────────────────────────────────────── */

  var curMethod = 'print', curEmbType = 'text';
  var curQ = 12, curL = 1, curG = 'g5000', curRetail = '';
  var curTot = 0, curPerShirt = 0, curDiscPct = 0, curQuote = false;
  var debounceTimer = null, lastFetch = '';
  var hadParams = false;

  function fmt(n) { return '$' + parseFloat(n).toFixed(2); }
  function g(id) { return document.getElementById(id); }

  function isGarmentId(v) {
    if (v === 'supplied' || v === 'custom') return true;
    for (var i = 0; i < GMTS.length; i++) if (GMTS[i].id === v) return true;
    return false;
  }

  /* ── Prefill ────────────────────────────────────────────────────────── */

  /**
   * Seed state from shortcode attributes, then from the query string.
   * URL wins over attributes. Anything invalid is silently ignored so a
   * malformed link degrades to the normal defaults instead of breaking.
   */
  function applyPrefill() {
    var src = {}, k;
    hadParams = false;

    var d = CFG.defaults || {};
    for (k in d) if (d[k] !== '' && d[k] !== null) src[k] = String(d[k]);

    var qs;
    try { qs = new URLSearchParams(window.location.search); } catch (e) { qs = null; }
    if (qs) {
      var MAP = {
        qty: 'qty',
        g: 'g', garment: 'g',
        loc: 'loc', locations: 'loc',
        m: 'm', method: 'm',
        et: 'et', embtype: 'et', emb: 'et',
        r: 'r', retail: 'r'
      };
      qs.forEach(function (val, key) {
        var slot = MAP[String(key).toLowerCase()];
        if (slot && val !== '') { src[slot] = val; hadParams = true; }
      });
    }

    // Method
    if (src.m) {
      var m = src.m.toLowerCase();
      if (m === 'print') curMethod = 'print';
      else if (m === 'emb' || m === 'embroidery') curMethod = 'embroidery';
    }

    // Embroidery defaults to Supplied Items, matching the in-tool toggle.
    if (curMethod === 'embroidery') curG = 'supplied';

    // Quantity
    if (src.qty) {
      var q = parseInt(src.qty, 10);
      if (!isNaN(q)) curQ = Math.min(QTY_MAX, Math.max(QTY_MIN, q));
    }

    // Garment
    if (src.g) {
      var gid = src.g.toLowerCase();
      if (isGarmentId(gid)) curG = gid;
    }

    // Custom retail
    if (src.r) {
      var r = parseFloat(src.r);
      if (!isNaN(r) && r >= 0) curRetail = String(r);
    }

    // Print locations
    if (src.loc) {
      var l = parseInt(src.loc, 10);
      if (l >= 1 && l <= 3) curL = l;
    }

    // Embroidery type
    if (src.et) {
      var et = src.et.toLowerCase();
      for (var i = 0; i < EMB_TYPES.length; i++) {
        if (EMB_TYPES[i].id === et) { curEmbType = et; break; }
      }
    }
  }

  /** Every key this tool owns — cleared before rewriting, so aliases can't linger. */
  var OWNED = ['qty', 'g', 'garment', 'loc', 'locations', 'm', 'method', 'et', 'embtype', 'emb', 'r', 'retail'];

  /**
   * Build a link that reproduces the current selections.
   * Anything else already on the URL (utm_source, fbclid, gclid, …) is kept —
   * a customer following an ad link must not lose its tracking when the tool
   * rewrites the address bar.
   */
  function shareUrl() {
    var qs;
    try { qs = new URLSearchParams(window.location.search); } catch (e) { qs = null; }

    var foreign = [];
    if (qs) {
      qs.forEach(function (val, key) {
        if (OWNED.indexOf(String(key).toLowerCase()) === -1) {
          foreign.push(encodeURIComponent(key) + '=' + encodeURIComponent(val));
        }
      });
    }

    var p = [];
    p.push('qty=' + curQ);
    p.push('g=' + encodeURIComponent(curG));
    if (curMethod === 'embroidery') {
      p.push('m=emb');
      p.push('et=' + curEmbType);
    } else {
      p.push('loc=' + curL);
    }
    if (curG === 'custom') {
      var v = parseFloat((g('btCustomPrice') || {}).value || 0) || 0;
      if (v > 0) p.push('r=' + v.toFixed(2));
    }

    return window.location.origin + window.location.pathname
      + '?' + p.concat(foreign).join('&')
      + (window.location.hash || '');
  }

  /**
   * Keep the address bar in sync with the tool, so the URL is always an
   * accurate quote — copy it from the bar, bookmark it, or hit refresh and
   * land on the same numbers.
   *
   * replaceState, not pushState: adjusting quantity should not stack up
   * dozens of back-button steps. Called from the debounced path only, which
   * keeps it clear of Safari's replaceState rate limit.
   */
  var syncEnabled = false;
  function syncUrl() {
    if (!syncEnabled) return;
    try {
      var next = shareUrl();
      if (next !== window.location.href) window.history.replaceState(null, '', next);
    } catch (e) { /* sandboxed / file:// — the tool still works, the URL just won't track */ }
  }

  function shareFeedback(msg, isErr) {
    var el = g('btShareMsg');
    if (!el) return;
    el.textContent = msg;
    el.className = 'bt-share-msg show' + (isErr ? ' err' : '');
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.className = 'bt-share-msg'; }, 2500);
  }

  /** Fallback for browsers/contexts without clipboard access (non-HTTPS, etc). */
  function revealUrl(url) {
    var box = g('btShareUrl');
    if (!box) return;
    box.value = url;
    box.classList.add('show');
    box.focus();
    box.select();
    shareFeedback('Copy this link', true);
  }

  function copyShareLink() {
    var url = shareUrl();
    var box = g('btShareUrl');
    if (box) { box.classList.remove('show'); box.value = url; }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () {
        shareFeedback('Link copied!');
      }).catch(function () { revealUrl(url); });
      return;
    }

    try {
      var ta = document.createElement('textarea');
      ta.value = url;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      if (ok) shareFeedback('Link copied!');
      else revealUrl(url);
    } catch (e) {
      revealUrl(url);
    }
  }

  /* ── Labels ─────────────────────────────────────────────────────────── */

  function gmtLabel() {
    var gm = GMTS.filter(function (x) { return x.id === curG; })[0];
    if (gm) return gm.name;
    if (curG === 'supplied') return 'Supplied Items';
    if (curG === 'custom')   return 'Custom Garment';
    return '';
  }

  function embType() {
    var t = EMB_TYPES.filter(function (x) { return x.id === curEmbType; })[0];
    return t || EMB_TYPES[0];
  }

  /* ── Builders ───────────────────────────────────────────────────────── */

  function buildMethod() {
    var w = g('btMethodSel');
    w.innerHTML = '';
    for (var i = 0; i < METHODS.length; i++) {
      var o = METHODS[i];
      var el = document.createElement('button');
      el.type = 'button';
      el.className = 'method-btn' + (o.id === curMethod ? ' sel' : '');
      el.innerHTML = o.label + '<span class="mb-sub">' + o.sub + '</span>';
      (function (id) {
        el.addEventListener('click', function () {
          if (curMethod === id) return;
          curMethod = id;
          if (id === 'embroidery') curG = 'supplied';
          lastFetch = '';
          buildMethod();
          buildStep2();
          btCalc();
        });
      })(o.id);
      w.appendChild(el);
    }
  }

  function buildStep2() {
    var t = g('btStep2Title');
    var foot = g('btFootText');
    if (curMethod === 'embroidery') {
      if (t) t.textContent = 'Embroidery Type';
      if (foot) foot.innerHTML = "Embroidery priced by design type. Estimates only &mdash; <strong>contact Boomer T's</strong> to confirm pricing and get started.";
      buildEmbType();
    } else {
      if (t) t.textContent = 'Print Locations';
      if (foot) foot.innerHTML = "Pricing based on full color prints, by location. Estimates only &mdash; <strong>contact Boomer T's</strong> to confirm pricing and get started. All prices include setup.";
      buildLoc();
    }
  }

  function buildLoc() {
    var w = g('btLocList');
    w.innerHTML = '';
    for (var k in LOCS) {
      var v = LOCS[k];
      var el = document.createElement('label');
      el.className = 'opt-row' + (parseInt(k) === curL ? ' sel' : '');
      el.innerHTML = '<input type="radio" name="btloc">'
        + '<div class="opt-dot"></div>'
        + '<div class="opt-body"><span class="opt-name">' + v.label + '</span>'
        + '<span class="opt-sub">' + v.desc + '</span></div>'
        + '<div class="opt-badge">' + k + ' Loc' + (parseInt(k) > 1 ? 's' : '') + '</div>';
      (function (kk) {
        el.addEventListener('click', function () { curL = parseInt(kk); buildLoc(); btCalc(); });
      })(k);
      w.appendChild(el);
    }
  }

  function buildEmbType() {
    var w = g('btLocList');
    w.innerHTML = '';
    for (var i = 0; i < EMB_TYPES.length; i++) {
      var t = EMB_TYPES[i];
      var el = document.createElement('label');
      el.className = 'opt-row' + (t.id === curEmbType ? ' sel' : '');
      el.innerHTML = '<input type="radio" name="btemb">'
        + '<div class="opt-dot"></div>'
        + '<div class="opt-body"><span class="opt-name">' + t.label + '</span>'
        + '<span class="opt-sub">' + t.sub + '</span></div>'
        + '<div class="opt-badge">' + t.badge + '</div>';
      (function (id) {
        el.addEventListener('click', function () { curEmbType = id; buildEmbType(); lastFetch = ''; btCalc(); });
      })(t.id);
      w.appendChild(el);
    }
  }

  function buildGmt() {
    var w = g('btGmtList');
    var savedCustomVal = curRetail;
    var oldInp = g('btCustomPrice');
    if (oldInp) savedCustomVal = oldInp.value;
    w.innerHTML = '';

    for (var i = 0; i < GMTS.length; i++) {
      var gm = GMTS[i];
      var el = document.createElement('label');
      el.className = 'gmt-card' + (gm.id === curG ? ' sel' : '');
      el.innerHTML = '<input type="radio" name="btgmt">'
        + '<div class="gmt-dot"></div>'
        + '<div class="gmt-body">'
        + '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:6px;">'
        + '<span class="gmt-name">' + gm.name + '</span>'
        + '<span class="gmt-price">' + gmtBadge(gm.retail, curQ) + '</span></div>'
        + '<span class="gmt-sub">' + gm.sub + '</span></div>';
      (function (gid) {
        el.addEventListener('click', function () { curG = gid; buildGmt(); btCalc(); });
      })(gm.id);
      w.appendChild(el);
    }

    // Supplied Items
    var suppliedBadge = (curMethod === 'embroidery') ? 'Stitch Only' : 'Print Only';
    var elS = document.createElement('label');
    elS.className = 'gmt-card' + ('supplied' === curG ? ' sel' : '');
    elS.innerHTML = '<input type="radio" name="btgmt">'
      + '<div class="gmt-dot"></div>'
      + '<div class="gmt-body">'
      + '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:6px;">'
      + '<span class="gmt-name">Supplied Items</span>'
      + '<span class="gmt-price">' + suppliedBadge + '</span></div>'
      + '<span class="gmt-sub">Customer provides garments</span></div>';
    elS.addEventListener('click', function () { curG = 'supplied'; buildGmt(); btCalc(); });
    w.appendChild(elS);

    // Custom Garment (retail price input)
    var elC = document.createElement('label');
    elC.className = 'gmt-card' + ('custom' === curG ? ' sel' : '');
    elC.innerHTML = '<input type="radio" name="btgmt">'
      + '<div class="gmt-dot" style="flex-shrink:0;margin-top:3px;"></div>'
      + '<div class="gmt-body" style="flex:1;min-width:0;">'
      + '<span class="gmt-name" style="display:block;font-size:13px;font-weight:700;color:#27267e;line-height:1.2;">Custom Garment</span>'
      + '<div style="display:flex;align-items:center;gap:4px;margin-top:3px;" onclick="event.stopPropagation();" onmousedown="event.stopPropagation();">'
      + '<span style="font-size:11px;color:#888;white-space:nowrap;">retail $</span>'
      + '<input type="number" id="btCustomPrice" min="0" step="0.01" placeholder="0.00" value="' + savedCustomVal + '" '
      + 'style="width:72px;border:1.5px solid #c0c3e0;border-radius:4px;padding:2px 6px;font-size:12px;font-weight:700;color:#27267e;outline:none;background:#fff;display:block;"></div></div>';
    elC.addEventListener('mousedown', function (e) { if (e.target.tagName === 'INPUT') return; curG = 'custom'; });
    elC.addEventListener('click', function (e) { if (e.target.tagName === 'INPUT') return; curG = 'custom'; buildGmt(); btCalc(); });
    w.appendChild(elC);

    var inp = g('btCustomPrice');
    if (inp) {
      inp.addEventListener('focus', function () { curG = 'custom'; lastFetch = ''; });
      inp.addEventListener('input', function () { curG = 'custom'; curRetail = inp.value; lastFetch = ''; btCalc(); });
      inp.addEventListener('mousedown', function (e) { e.stopPropagation(); });
      inp.addEventListener('click', function (e) { e.stopPropagation(); });
    }
  }

  /* ── Calc / fetch ───────────────────────────────────────────────────── */

  window.btCalc = function () {
    var raw = parseInt(g('btQty').value);
    curQ = isNaN(raw) ? QTY_MIN : Math.min(QTY_MAX, Math.max(QTY_MIN, raw));
    var inp = g('btCustomPrice');
    if (!inp || document.activeElement !== inp) buildGmt();
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () { syncUrl(); btFetch(); }, 150);
  };

  window.btAdj = function (d) {
    var inp = g('btQty');
    inp.value = Math.min(QTY_MAX, Math.max(QTY_MIN, (parseInt(inp.value) || QTY_MIN) + d));
    btCalc();
  };

  function btFetch() {
    var customRetail = curG === 'custom'
      ? (parseFloat((g('btCustomPrice') || {}).value || 0) || 0)
      : 0;
    var key = curMethod + '|' + curEmbType + '|' + curQ + '|' + curG + '|' + curL + '|' + customRetail;
    if (key === lastFetch) return;
    lastFetch = key;

    var ps = g('btPriceSide');
    if (ps) ps.classList.add('loading');

    var fd = new FormData();
    fd.append('qty', curQ);
    fd.append('locations', curL);
    fd.append('method', curMethod);
    fd.append('embType', curEmbType);
    if (curG === 'custom') {
      fd.append('garment', 'custom');
      fd.append('retail', customRetail);
    } else if (curG === 'supplied') {
      fd.append('garment', 'supplied');
    } else {
      fd.append('garment', curG);
    }

    fetch(API_BASE + '/price', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.error || d.code) { if (ps) ps.classList.remove('loading'); return; }

        curQuote = (d.quote === true);

        if (curQuote) {
          curPerShirt = 0; curTot = 0; curDiscPct = 0;
          g('btDlr').textContent = 'Quote';
          g('btCts').textContent = '';
          g('btDiscount').style.display = 'none';
          g('btSTotal').textContent = 'By Quote';
          g('btTotalLbl').textContent = 'Orders of 84+';
          g('btBTotal').textContent = 'By Quote';
        } else {
          curPerShirt = d.perShirt;
          curTot      = d.total;
          curDiscPct  = d.discPct || 0;

          var pts = d.perShirt.toFixed(2).split('.');
          g('btDlr').textContent = '$' + pts[0];
          g('btCts').textContent = '.' + pts[1];
          g('btSTotal').textContent = fmt(d.total);
          g('btTotalLbl').textContent = fmt(d.perShirt) + ' \u00d7 ' + curQ + ' =';
          g('btBTotal').textContent   = fmt(d.total);

          var discEl = g('btDiscount');
          if (d.discPct > 0) {
            g('btDiscountBadge').textContent = d.discPct + '% Quantity Discount Applied';
            discEl.style.display = 'block';
          } else {
            discEl.style.display = 'none';
          }
        }

        g('btSQty').textContent = curQ + (curQ === 1 ? ' pc' : ' pcs');
        if (curMethod === 'embroidery') {
          g('btSLocLbl').textContent = 'Type';
          g('btSLoc').textContent = embType().short;
        } else {
          g('btSLocLbl').textContent = 'Locations';
          g('btSLoc').textContent = curL;
        }
        g('btSGmt').textContent = gmtLabel();

        if (ps) ps.classList.remove('loading');

        var tb = g('btTbody');
        tb.innerHTML = '';
        (d.breaks || []).forEach(function (row) {
          var tr = document.createElement('tr');
          var isQuoteRow = (row.quote === true || row.price === null);
          if (!curQuote && !isQuoteRow && curQ === row.qty) tr.className = 'active';
          var qtyLabel, priceCell, totalCell;
          if (isQuoteRow) {
            qtyLabel  = row.qty + '+ shirts';
            priceCell = 'By Quote';
            totalCell = '\u2014';
          } else {
            qtyLabel  = (row.qty === 1 ? '1 shirt' : row.qty + ' shirts');
            priceCell = fmt(row.price);
            totalCell = fmt(row.total);
          }
          tr.innerHTML = '<td>' + qtyLabel + '</td><td>' + priceCell + '</td><td>' + totalCell + '</td>';
          tb.appendChild(tr);
        });
        syncTableHeight();
      })
      .catch(function () { if (ps) ps.classList.remove('loading'); });
  }

  function syncTableHeight() {
    var rs = document.querySelector('#btQuoteRoot .bt-results');
    var ts = g('btTblScroll');
    if (rs && ts) ts.style.height = rs.offsetHeight + 'px';
  }

  /* ── Modal ──────────────────────────────────────────────────────────── */

  window.btOpenModal = function () {
    var sum = g('btModalSummary');
    var chips = '<span class="bt-sum-chip">' + curQ + ' pcs</span>';
    if (curMethod === 'embroidery') {
      chips += '<span class="bt-sum-chip">Embroidery</span>'
            +  '<span class="bt-sum-chip">' + embType().label + '</span>';
    } else {
      chips += '<span class="bt-sum-chip">' + curL + ' Location' + (curL > 1 ? 's' : '') + '</span>';
    }
    chips += '<span class="bt-sum-chip">' + gmtLabel() + '</span>';
    if (curQuote) {
      chips += '<span class="bt-sum-chip pink">By Quote</span>';
    } else {
      chips += '<span class="bt-sum-chip pink">' + fmt(curPerShirt) + '/shirt</span>'
            +  '<span class="bt-sum-chip pink">' + fmt(curTot) + ' total</span>';
    }
    sum.innerHTML = chips;
    g('btModalForm').style.display = 'flex';
    g('btModalFooter').style.display = 'flex';
    g('btModalSuccess').classList.remove('show');
    g('btModalOverlay').classList.add('open');
  };

  window.btCloseModal = function () {
    g('btModalOverlay').classList.remove('open');
  };

  window.btSubmitForm = function () {
    var name  = g('btFName').value.trim();
    var email = g('btFEmail').value.trim();
    if (!name || !email) {
      alert('Please enter your name and email so we can send your quote.');
      return;
    }

    var summary;
    if (curMethod === 'embroidery') {
      var embPrice = curQuote
        ? 'Price Per Shirt: By Quote\nEst. Total: By Quote (orders of 84+)\n'
        : 'Price Per Shirt: ' + fmt(curPerShirt) + '\nEst. Total: ' + fmt(curTot)
          + (curDiscPct > 0 ? ' (' + curDiscPct + '% qty discount applied)' : '') + '\n';
      summary = 'Quantity: ' + curQ + ' pcs\n'
        + 'Garment: ' + gmtLabel() + '\n'
        + 'Embroidery: ' + embType().label + '\n'
        + embPrice
        + 'Message: ' + (g('btFMsg').value.trim() || '(none)');
    } else {
      summary = 'Quantity: ' + curQ + ' pcs\n'
        + 'Garment: ' + gmtLabel() + '\n'
        + 'Locations: ' + curL + '\n'
        + 'Price Per Shirt: ' + fmt(curPerShirt) + '\n'
        + 'Est. Total: ' + fmt(curTot)
        + (curDiscPct > 0 ? ' (' + curDiscPct + '% qty discount applied)' : '') + '\n'
        + 'Message: ' + (g('btFMsg').value.trim() || '(none)');
    }

    // Link that reproduces exactly what the customer was looking at.
    summary += '\nQuote link: ' + shareUrl();

    var btn = g('btSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Sending\u2026';

    var fd = new FormData();
    fd.append('your-name', name);
    fd.append('your-email', email);
    fd.append('your-organization', g('btFOrg').value.trim());
    fd.append('your-phone', g('btFPhone').value.trim());
    fd.append('your-message', summary);

    fetch(API_BASE + '/quote', { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (res.ok) {
          g('btModalForm').style.display = 'none';
          g('btModalFooter').style.display = 'none';
          g('btModalSuccess').classList.add('show');
        } else {
          alert('Something went wrong sending your request. Please try again, or call us directly.');
          btn.disabled = false;
          btn.textContent = 'Send My Quote Request \u2192';
        }
      })
      .catch(function () {
        alert('Something went wrong sending your request. Please try again, or call us directly.');
        btn.disabled = false;
        btn.textContent = 'Send My Quote Request \u2192';
      });
  };

  /* ── Init ───────────────────────────────────────────────────────────── */

  if (!g('btQuoteRoot')) return;

  applyPrefill();

  g('btQty').value = curQ;

  g('btModalOverlay').addEventListener('click', function (e) {
    if (e.target === this) btCloseModal();
  });

  var shareBtn = g('btShareBtn');
  if (shareBtn) shareBtn.addEventListener('click', copyShareLink);

  // A URL that already carries quote params gets normalised on load (junk and
  // aliases cleaned up). A clean /quote/ stays clean until the customer
  // actually touches something — no params appear just for showing up.
  syncEnabled = hadParams;
  var enableSync = function () { syncEnabled = true; };
  var root = g('btQuoteRoot');
  root.addEventListener('click', enableSync, true);
  root.addEventListener('input', enableSync, true);

  buildMethod();
  buildStep2();
  buildGmt();
  btCalc();
  setTimeout(syncTableHeight, 300);
  window.addEventListener('resize', syncTableHeight, { passive: true });
})();
