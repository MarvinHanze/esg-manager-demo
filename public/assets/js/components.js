/* HanzeOnline gedeelde componentenbibliotheek — vanilla JS, geen dependencies.
   Elke helper is idempotent en zoekt zelf zijn hooks via data-attributen,
   zodat dit bestand veilig op elke pagina geladen kan worden. */
(function () {
  "use strict";

  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  /* --- Sticky navbar shrink-on-scroll --- */
  function initStickyNavbars() {
    qsa(".hz-navbar--sticky").forEach(function (nav) {
      window.addEventListener("scroll", function () {
        nav.classList.toggle("hz-is-shrunk", window.scrollY > 40);
      }, { passive: true });
    });
  }

  /* --- Mega menu --- */
  function initMegaMenus() {
    qsa("[data-hz-megamenu-trigger]").forEach(function (trigger) {
      var menu = document.getElementById(trigger.getAttribute("data-hz-megamenu-trigger"));
      if (!menu) return;
      trigger.addEventListener("click", function (e) {
        e.stopPropagation();
        menu.classList.toggle("hz-is-open");
      });
      document.addEventListener("click", function () { menu.classList.remove("hz-is-open"); });
    });
  }

  /* --- Collapsible sidebar --- */
  function initSidebarToggle() {
    qsa("[data-hz-sidebar-toggle]").forEach(function (btn) {
      var sidebar = document.getElementById(btn.getAttribute("data-hz-sidebar-toggle"));
      if (!sidebar) return;
      btn.addEventListener("click", function () { sidebar.classList.toggle("hz-sidebar--collapsed"); });
    });
  }

  /* --- Slide-over panel --- */
  function initSlideOvers() {
    qsa("[data-hz-slideover-open]").forEach(function (btn) {
      var id = btn.getAttribute("data-hz-slideover-open");
      var panel = document.getElementById(id);
      var backdrop = document.querySelector('[data-hz-slideover-backdrop="' + id + '"]');
      btn.addEventListener("click", function () {
        if (panel) panel.classList.add("hz-is-open");
        if (backdrop) backdrop.classList.add("hz-is-open");
      });
    });
    qsa("[data-hz-slideover-close]").forEach(function (btn) {
      var id = btn.getAttribute("data-hz-slideover-close");
      var panel = document.getElementById(id);
      var backdrop = document.querySelector('[data-hz-slideover-backdrop="' + id + '"]');
      btn.addEventListener("click", function () {
        if (panel) panel.classList.remove("hz-is-open");
        if (backdrop) backdrop.classList.remove("hz-is-open");
      });
    });
  }

  /* --- Mobile hamburger overlay --- */
  function initMobileOverlay() {
    qsa("[data-hz-mobile-toggle]").forEach(function (btn) {
      var overlay = document.getElementById(btn.getAttribute("data-hz-mobile-toggle"));
      if (!overlay) return;
      btn.addEventListener("click", function () { overlay.classList.toggle("hz-is-open"); });
    });
  }

  /* --- Password visibility toggle --- */
  function initPasswordToggles() {
    qsa("[data-hz-password-toggle]").forEach(function (btn) {
      var input = document.getElementById(btn.getAttribute("data-hz-password-toggle"));
      if (!input) return;
      btn.addEventListener("click", function () {
        var isPw = input.type === "password";
        input.type = isPw ? "text" : "password";
        btn.textContent = isPw ? "🙈" : "👁";
      });
    });
  }

  /* --- Multi-select met zoekfunctie --- */
  function initMultiSelects() {
    qsa(".hz-multiselect").forEach(function (widget) {
      var search = widget.querySelector(".hz-multiselect__search");
      var options = qsa(".hz-multiselect__option", widget);
      var tagsBox = widget.querySelector(".hz-multiselect__tags");

      function renderTags() {
        if (!tagsBox) return;
        tagsBox.innerHTML = "";
        options.filter(function (o) { return o.classList.contains("hz-is-selected"); })
          .forEach(function (o) {
            var tag = document.createElement("span");
            tag.className = "hz-badge hz-badge--gray";
            tag.textContent = o.textContent.trim();
            tagsBox.appendChild(tag);
          });
      }
      options.forEach(function (opt) {
        opt.addEventListener("click", function () {
          opt.classList.toggle("hz-is-selected");
          renderTags();
        });
      });
      if (search) {
        search.addEventListener("input", function () {
          var term = search.value.toLowerCase();
          options.forEach(function (o) {
            o.style.display = o.textContent.toLowerCase().indexOf(term) > -1 ? "" : "none";
          });
        });
      }
      renderTags();
    });
  }

  /* --- Drag & drop upload --- */
  function initDropzones() {
    qsa(".hz-dropzone").forEach(function (zone) {
      var input = zone.querySelector("input[type=file]");
      var progressBar = zone.querySelector(".hz-dropzone__progress-bar");
      var preview = zone.querySelector(".hz-dropzone__preview");

      function handleFiles(files) {
        if (preview) preview.innerHTML = "";
        Array.prototype.forEach.call(files, function (file) {
          if (preview) {
            var thumb = document.createElement("div");
            thumb.className = "hz-dropzone__thumb";
            thumb.title = file.name;
            thumb.textContent = file.name.split(".").pop().toUpperCase().slice(0, 4);
            preview.appendChild(thumb);
          }
        });
        if (progressBar) {
          progressBar.style.width = "0%";
          var pct = 0;
          var timer = setInterval(function () {
            pct += 20;
            progressBar.style.width = Math.min(pct, 100) + "%";
            if (pct >= 100) clearInterval(timer);
          }, 120);
        }
      }
      ["dragenter", "dragover"].forEach(function (evt) {
        zone.addEventListener(evt, function (e) { e.preventDefault(); zone.classList.add("hz-is-dragover"); });
      });
      ["dragleave", "drop"].forEach(function (evt) {
        zone.addEventListener(evt, function (e) { e.preventDefault(); zone.classList.remove("hz-is-dragover"); });
      });
      zone.addEventListener("drop", function (e) { if (e.dataTransfer.files.length) handleFiles(e.dataTransfer.files); });
      zone.addEventListener("click", function () { if (input) input.click(); });
      if (input) input.addEventListener("change", function () { if (input.files.length) handleFiles(input.files); });
    });
  }

  /* --- Simpele rich-text editor (contenteditable) --- */
  function initRichText() {
    qsa(".hz-richtext").forEach(function (editor) {
      var toolbar = editor.querySelector(".hz-richtext__toolbar");
      var body = editor.querySelector(".hz-richtext__body");
      if (!toolbar || !body) return;
      qsa("button", toolbar).forEach(function (btn) {
        btn.addEventListener("click", function () {
          document.execCommand(btn.getAttribute("data-cmd"), false, null);
          body.focus();
        });
      });
    });
  }

  /* --- Data tables: sort / bulk-select / expand-row --- */
  function initDataTables() {
    qsa(".hz-table[data-hz-sortable]").forEach(function (table) {
      qsa("th[data-key]", table).forEach(function (th) {
        th.addEventListener("click", function () {
          var key = th.getAttribute("data-key");
          var tbody = table.querySelector("tbody");
          var rows = qsa("tr[data-row]", tbody);
          var asc = !th.classList.contains("hz-sort-asc");
          qsa("th", table).forEach(function (h) { h.classList.remove("hz-sort-asc", "hz-sort-desc"); });
          th.classList.add(asc ? "hz-sort-asc" : "hz-sort-desc");
          rows.sort(function (a, b) {
            var av = a.querySelector('[data-col="' + key + '"]').textContent.trim();
            var bv = b.querySelector('[data-col="' + key + '"]').textContent.trim();
            var an = parseFloat(av), bn = parseFloat(bv);
            var cmp = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv);
            return asc ? cmp : -cmp;
          });
          rows.forEach(function (r) { tbody.appendChild(r); });
        });
      });
    });

    qsa(".hz-table [data-hz-select-all]").forEach(function (master) {
      var table = master.closest("table");
      master.addEventListener("change", function () {
        qsa("tbody input[type=checkbox]", table).forEach(function (cb) { cb.checked = master.checked; });
      });
    });

    qsa(".hz-table [data-hz-expand]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var row = document.getElementById(btn.getAttribute("data-hz-expand"));
        if (row) row.style.display = row.style.display === "table-row" ? "none" : "table-row";
      });
    });
  }

  /* --- Modals --- */
  function initModals() {
    qsa("[data-hz-modal-open]").forEach(function (btn) {
      var modal = document.getElementById(btn.getAttribute("data-hz-modal-open"));
      btn.addEventListener("click", function () { if (modal) modal.classList.add("hz-is-open"); });
    });
    qsa("[data-hz-modal-close]").forEach(function (btn) {
      var modal = btn.closest(".hz-modal__backdrop");
      btn.addEventListener("click", function () { if (modal) modal.classList.remove("hz-is-open"); });
    });
    qsa(".hz-modal__backdrop").forEach(function (backdrop) {
      backdrop.addEventListener("click", function (e) { if (e.target === backdrop) backdrop.classList.remove("hz-is-open"); });
    });
  }

  /* --- Toasts --- */
  function ensureToastContainer() {
    var el = document.querySelector(".hz-toast-container");
    if (!el) {
      el = document.createElement("div");
      el.className = "hz-toast-container";
      document.body.appendChild(el);
    }
    return el;
  }
  function hzToast(message, type) {
    var container = ensureToastContainer();
    var toast = document.createElement("div");
    toast.className = "hz-toast hz-toast--" + (type || "info");
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(function () {
      toast.style.opacity = "0";
      toast.style.transition = "opacity .2s";
      setTimeout(function () { toast.remove(); }, 200);
    }, 3500);
  }
  window.hzToast = hzToast;

  function initToastTriggers() {
    qsa("[data-hz-toast]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        hzToast(btn.getAttribute("data-hz-toast"), btn.getAttribute("data-hz-toast-type") || "info");
      });
    });
  }

  /* --- Dropdown buttons --- */
  function initDropdowns() {
    qsa("[data-hz-dropdown-trigger]").forEach(function (trigger) {
      var menu = document.getElementById(trigger.getAttribute("data-hz-dropdown-trigger"));
      if (!menu) return;
      trigger.addEventListener("click", function (e) {
        e.stopPropagation();
        qsa(".hz-dropdown__menu.hz-is-open").forEach(function (m) { if (m !== menu) m.classList.remove("hz-is-open"); });
        menu.classList.toggle("hz-is-open");
      });
    });
    document.addEventListener("click", function () {
      qsa(".hz-dropdown__menu.hz-is-open").forEach(function (m) { m.classList.remove("hz-is-open"); });
    });
  }

  /* --- Bevestigingsprompt bij destructieve/bulk-acties --- */
  function initConfirms() {
    qsa("[data-hz-confirm]").forEach(function (el) {
      el.addEventListener("click", function (e) {
        if (!window.confirm(el.getAttribute("data-hz-confirm"))) {
          e.preventDefault();
          e.stopImmediatePropagation();
        }
      });
    });
  }

  /* --- Loading state helper voor knoppen (bijv. bij fetch-calls) --- */
  window.hzSetLoading = function (btn, loading) {
    if (!btn) return;
    btn.classList.toggle("hz-btn--loading", !!loading);
    btn.disabled = !!loading;
  };

  document.addEventListener("DOMContentLoaded", function () {
    initStickyNavbars();
    initMegaMenus();
    initSidebarToggle();
    initSlideOvers();
    initMobileOverlay();
    initPasswordToggles();
    initMultiSelects();
    initDropzones();
    initRichText();
    initDataTables();
    initModals();
    initToastTriggers();
    initDropdowns();
    initConfirms();
  });
})();
