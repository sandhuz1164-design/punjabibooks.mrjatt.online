(function () {
  'use strict';

  var COMPARATORS = {
    'popular': function (a, b) { return b.dl - a.dl || a.title.localeCompare(b.title); },
    'title':   function (a, b) { return a.title.localeCompare(b.title); },
    'author':  function (a, b) { return a.author.localeCompare(b.author) || a.title.localeCompare(b.title); },
    'new':     function (a, b) { return b.year - a.year || a.title.localeCompare(b.title); },
    'old':     function (a, b) { return a.year - b.year || a.title.localeCompare(b.title); }
  };

  /**
   * "Gurmukhi" also matches mixed-script titles. Archive.org mangles a lot of
   * Punjabi titles into "Tarksheel, Nov-Dec 2001" alongside the Gurmukhi, and a
   * reader filtering by script wants those books, not just the pure ones.
   */
  function scriptMatches(want, have) {
    if (want === have) return true;
    if (want === 'gurmukhi' && have === 'mixed') return true;
    return false;
  }

  function setupSort(bar, keyed) {
    var grid = document.getElementById('grid');
    if (!grid) return;

    bar.addEventListener('click', function (ev) {
      var btn = ev.target.closest('button[data-sort]');
      if (!btn) return;

      var cmp = COMPARATORS[btn.getAttribute('data-sort')];
      if (!cmp) return;

      keyed.sort(cmp);
      var frag = document.createDocumentFragment();
      keyed.forEach(function (k) { frag.appendChild(k.el); });
      grid.appendChild(frag);

      Array.prototype.forEach.call(bar.querySelectorAll('button[data-sort]'), function (b) {
        b.setAttribute('aria-pressed', String(b === btn));
      });

      try {
        sessionStorage.setItem('bookSort', btn.getAttribute('data-sort'));
      } catch (e) { /* private mode */ }
    });

    return function apply(key) {
      var btn = bar.querySelector('button[data-sort="' + key + '"]');
      if (btn) btn.click();
    };
  }

  /**
   * Filters read the data-* attributes the build already writes onto every card,
   * so this never touches the network and works on any page with #grid.
   */
  function setupFilter(bar, keyed) {
    var countEl = document.getElementById('filter-count');
    var clearBtn = document.getElementById('filter-clear');
    var controls = Array.prototype.slice.call(bar.querySelectorAll('[data-filter]'));
    if (!controls.length) return;

    var emptyMsg = document.getElementById('filter-empty');

    function state() {
      var s = { script: '', cat: '', author: '', subject: '', pdf: false };
      controls.forEach(function (c) {
        var k = c.getAttribute('data-filter');
        s[k] = c.type === 'checkbox' ? c.checked : c.value.trim().toLowerCase();
      });
      return s;
    }

    function apply() {
      var s = state();
      var any = !!(s.script || s.cat || s.author || s.subject || s.pdf);
      var shown = 0;

      keyed.forEach(function (k) {
        var ok = true;
        if (s.script && !scriptMatches(s.script, k.script)) ok = false;
        else if (s.cat && k.cat.toLowerCase() !== s.cat) ok = false;
        else if (s.author && k.author.toLowerCase().indexOf(s.author) === -1) ok = false;
        else if (s.subject && k.subject.indexOf(s.subject) === -1) ok = false;
        else if (s.pdf && k.pdf !== '1') ok = false;

        if (ok) { k.el.removeAttribute('hidden'); shown++; }
        else { k.el.setAttribute('hidden', ''); }
      });

    if (countEl) {
      countEl.textContent = any ? (shown + ' of ' + keyed.length + ' books') : '';
    }
    if (clearBtn) { clearBtn.hidden = !any; }
    if (emptyMsg) { emptyMsg.hidden = shown !== 0; }

      // Badge on the collapsed mobile button, so a hidden filter is still visible.
      var badge = document.getElementById('filter-toggle-n');
      if (badge) {
        var active = 0;
        controls.forEach(function (c) {
          if (c.type === 'checkbox' ? c.checked : c.value.trim() !== '') active++;
        });
        badge.textContent = String(active);
        badge.hidden = active === 0;
      }
    }

    var t = null;
    controls.forEach(function (c) {
      var ev = (c.tagName === 'SELECT' || c.type === 'checkbox') ? 'change' : 'input';
      c.addEventListener(ev, function () {
        clearTimeout(t);
        // text inputs fire per keystroke; debounce so 2,875 cards are walked once
        t = setTimeout(apply, ev === 'input' ? 130 : 0);
      });
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        controls.forEach(function (c) {
          if (c.type === 'checkbox') { c.checked = false; } else { c.value = ''; }
        });
        apply();
      });
    }

    apply();
  }

  /** Mobile: the filter controls collapse behind one button. */
  function setupFilterToggle(bar) {
    var btn = document.getElementById('filter-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var open = bar.classList.toggle('open');
      btn.setAttribute('aria-expanded', String(open));
    });
  }

  function init() {
    var grid = document.getElementById('grid');
    var bar = document.getElementById('sortbar');
    if (!grid) return;

    var items = Array.prototype.slice.call(grid.children);
    if (!items.length) return;

    var keyed = items.map(function (el) {
      return {
        el: el,
        year: parseInt(el.getAttribute('data-year'), 10) || 0,
        author: el.getAttribute('data-author') || '',
        title: el.getAttribute('data-title') || '',
        dl: parseInt(el.getAttribute('data-dl'), 10) || 0,
        script: el.getAttribute('data-script') || '',
        cat: el.getAttribute('data-cat') || '',
        subject: el.getAttribute('data-subject') || '',
        pdf: el.getAttribute('data-pdf') || '0'
      };
    });

    var applySort = bar ? setupSort(bar, keyed) : null;
    var filterBar = document.getElementById('filterbar');
    if (filterBar) {
      setupFilter(filterBar, keyed);
      setupFilterToggle(filterBar);
    }

    if (!applySort) return;

    var saved = null;
    try { saved = sessionStorage.getItem('bookSort'); } catch (e) { /* ignore */ }

    // The build states the order the cards were actually rendered in, so the
    // pressed button always matches what is on screen. Marking the first button
    // instead would claim "Most read" on a page that is really Title A-Z.
    if (saved && COMPARATORS[saved]) {
      applySort(saved);
      return;
    }

    var fallback = bar.getAttribute('data-default-sort') || 'title';
    var fb = bar.querySelector('button[data-sort="' + fallback + '"]');
    if (fb) fb.setAttribute('aria-pressed', 'true');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
