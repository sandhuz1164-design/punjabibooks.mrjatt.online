(function () {
  'use strict';

  var grid = document.getElementById('grid');
  var input = document.getElementById('q');
  var countEl = document.getElementById('search-count');
  if (!grid || !input) return;

  var ALL = [];
  var INDEX_URL = 'search-index.json';

  // Higher wins. A title match must always beat an author or subject match, or
  // searching a common surname buries the book actually named after it.
  var W = {
    titleExact: 140,
    titleStart: 70,
    title: 45,
    author: 20,
    subject: 14,
    category: 12,
    publisher: 8
  };

  var FIELDS = {
    author:    function (b, v) { return String(b.a || '').toLowerCase(); },
    title:     function (b, v) { return String(b.t || '').toLowerCase(); },
    subject:   function (b, v) { return String(b.j || '').toLowerCase(); },
    category:  function (b, v) { return String(b.c || '').toLowerCase(); },
    publisher: function (b, v) { return String(b.p || '').toLowerCase(); }
  };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function mark(parent, text, q) {
    if (!q) { parent.textContent = text; return; }
    var i = text.toLowerCase().indexOf(q.toLowerCase());
    if (i < 0) { parent.textContent = text; return; }
    parent.appendChild(document.createTextNode(text.slice(0, i)));
    parent.appendChild(el('mark', null, text.slice(i, i + q.length)));
    parent.appendChild(document.createTextNode(text.slice(i + q.length)));
  }

  // Codepoint test rather than a regex literal: writing /[ऀ-ࣿ]/ into a
  // source file is fragile (the literal range needs the u flag and is easy to
  // mangle), and this is unambiguous.
  function isGurmukhi(s) {
    for (var i = 0; i < s.length; i++) {
      var c = s.charCodeAt(i);
      if (c >= 0x0a00 && c <= 0x0a7f) return true;
    }
    return false;
  }

  function scriptOf(b) {
    var t = String(b.t || '');
    var g = isGurmukhi(t);
    var l = /[A-Za-z]/.test(t);
    if (g && l) return 'mixed';
    if (g) return 'gurmukhi';
    if (l) return 'roman';
    return 'unknown';
  }

  var SCRIPT_LABELS = {
    gurmukhi: 'Gurmukhi', roman: 'Roman', mixed: 'Mixed', unknown: 'Other'
  };

  function render(list, q) {
    grid.textContent = '';

    if (!list.length) {
      countEl.textContent = q
        ? 'No books match "' + q + '".'
        : 'Type to filter ' + ALL.length + ' books instantly.';
      grid.appendChild(el('p', 'empty', q ? 'No books match that search.' : 'Start typing to search.'));
      return;
    }

    countEl.textContent = list.length + ' of ' + ALL.length + ' books'
      + (q ? ' for "' + q + '"' : '') + '.'
      + '  Tip: author:singh, title:khalsa, subject:history, category:poetry';

    var frag = document.createDocumentFragment();

    list.forEach(function (b) {
      var script = scriptOf(b);

      var art = el('article', 'card free');
      art.setAttribute('data-title', b.t);
      art.setAttribute('data-author', b.a || '');
      art.setAttribute('data-year', String(b.y || 0));
      art.setAttribute('data-dl', String(b.d || 0));

      var media = el('a', 'card-media');
      media.href = b.u;
      if (b.i) {
        var img = document.createElement('img');
        img.src = b.i;
        img.alt = 'Cover of ' + b.t;
        img.loading = 'lazy';
        img.decoding = 'async';
        img.width = 200;
        img.height = 280;
        media.appendChild(img);
      } else {
        var nc = el('span', 'card-nocover', '\u{1F4D6}');
        nc.appendChild(el('small', null, 'Free PDF'));
        media.appendChild(nc);
      }
      var tag = el('span', 'script-tag', SCRIPT_LABELS[script]);
      tag.setAttribute('data-script', script);
      media.appendChild(tag);
      art.appendChild(media);

      var body = el('div', 'card-body');
      var h3 = el('h3', 'card-title');
      var link = document.createElement('a');
      link.href = b.u;
      mark(link, b.t, q);
      h3.appendChild(link);
      body.appendChild(h3);
      art.appendChild(body);

      frag.appendChild(art);
    });

    grid.appendChild(frag);
  }

  /**
   * Score one book against one search token. Returns 0 when the token is absent
   * everywhere, which is what makes multi-word search AND rather than OR.
   */
  function scoreToken(b, tok) {
    var title = String(b.t || '').toLowerCase();
    var s = 0;
    if (title === tok) s = W.titleExact;
    else if (title.indexOf(tok) === 0) s = W.titleStart;
    else if (title.indexOf(tok) > -1) s = W.title;

    if (String(b.a || '').toLowerCase().indexOf(tok) > -1) s = Math.max(s, W.author);
    if (String(b.j || '').toLowerCase().indexOf(tok) > -1) s = Math.max(s, W.subject);
    if (String(b.c || '').toLowerCase().indexOf(tok) > -1) s = Math.max(s, W.category);
    if (String(b.p || '').toLowerCase().indexOf(tok) > -1) s = Math.max(s, W.publisher);
    return s;
  }

  /**
   * Supports field-scoped terms so a reader can be precise about 2,875 books:
   *   author:singh  title:khalsa  subject:history  category:poetry
   * Anything without a recognised prefix is a free-text token.
   */
  function parseQuery(q) {
    var scoped = [];
    var loose = [];
    String(q).split(/\s+/).forEach(function (tok) {
      if (!tok) return;
      var m = tok.match(/^([a-z]+):(.*)$/i);
      if (m && FIELDS[m[1].toLowerCase()] && m[2]) {
        scoped.push([m[1].toLowerCase(), m[2].toLowerCase()]);
      } else {
        loose.push(tok.toLowerCase());
      }
    });
    return { scoped: scoped, loose: loose };
  }

  function search(q) {
    var parsed = parseQuery(q);
    var loose = parsed.loose;
    var phrase = String(q).trim().toLowerCase();
    var out = [];

    for (var i = 0; i < ALL.length; i++) {
      var b = ALL[i];
      var score = 0;
      var ok = true;

      for (var s = 0; s < parsed.scoped.length; s++) {
        var field = parsed.scoped[s][0];
        var val = parsed.scoped[s][1];
        var hay = FIELDS[field](b);
        if (hay.indexOf(val) === -1) { ok = false; break; }
        // Scoping is a strong signal, so it outranks an incidental title hit.
        score += W.title;
      }
      if (!ok) continue;

      for (var t = 0; t < loose.length; t++) {
        var ts = scoreToken(b, loose[t]);
        if (ts === 0) { ok = false; break; }
        score += ts;
      }
      if (!ok) continue;

      if (phrase && String(b.t || '').toLowerCase().indexOf(phrase) > -1) score += 50;
      // Well-read books win ties, so "khalsa" surfaces the famous one first.
      out.push({ b: b, s: score + Math.min(30, Math.log10((b.d || 0) + 1) * 10) });
    }

    out.sort(function (x, y) {
      return y.s - x.s
        || (y.b.d || 0) - (x.b.d || 0)
        || String(x.b.t).localeCompare(String(y.b.t));
    });
    return out.map(function (r) { return r.b; });
  }

  fetch(INDEX_URL)
    .then(function (r) {
      if (!r.ok) throw new Error('index ' + r.status);
      return r.json();
    })
    .then(function (data) {
      ALL = data;
      var q = new URLSearchParams(location.search).get('q') || '';
      if (q) input.value = q;
      // With no query, render nothing. Rendering all 5,564 books up front built a
      // 39,000-node, ~970,000px page that took 7.6s before the visitor typed
      // anything - catastrophic on a phone.
      if (q.trim()) {
        render(search(q), q.trim());
      } else {
        countEl.textContent = 'Search ' + ALL.length
          + ' books by title, author, publisher, subject or category.'
          + '  Tip: author:singh, title:khalsa, subject:history, category:poetry';
        grid.appendChild(el('p', 'empty', 'Start typing to search.'));
      }
    })
    .catch(function () {
      countEl.textContent = 'Search index failed to load. Browse the categories instead.';
      grid.appendChild(el('p', 'empty', 'Search is unavailable right now.'));
    });

  var t = null;
  input.addEventListener('input', function () {
    clearTimeout(t);
    t = setTimeout(function () {
      var q = input.value.trim();
      render(search(q), q);
    }, 110);
  });
})();
