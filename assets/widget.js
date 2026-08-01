(function () {
  "use strict";

  function findTarget(selector) {
    if (!selector) return null;
    try {
      return document.querySelector(selector);
    } catch (_) {
      return null;
    }
  }

  function mount() {
    var config = window.wooSortillusLiteWidget || {};
    if (!config.bootstrapUrl || document.querySelector("sortillus-shop-assistant")) return;

    var mobile = window.matchMedia && window.matchMedia("(max-width: 767px)").matches;
    var selector = mobile ? config.mobileSelector : config.desktopSelector;
    var target = findTarget(selector);
    var widget = document.createElement("sortillus-shop-assistant");
    widget.setAttribute("api-origin", config.apiOrigin || "https://data.sortillus.com");
    widget.setAttribute("bootstrap-url", config.bootstrapUrl);
    widget.setAttribute("locale", config.locale || document.documentElement.lang || "en");
    widget.setAttribute("theme", "woocommerce");
    widget.setAttribute("placement", target ? "header_search" : "floating");
    if (config.desktopSelector) widget.setAttribute("desktop-selector", config.desktopSelector);
    if (config.mobileSelector) widget.setAttribute("mobile-selector", config.mobileSelector);
    widget.setAttribute("name-source", "original");
    document.body.appendChild(widget);
  }

  function ready() {
    if (window.customElements && customElements.whenDefined) {
      customElements.whenDefined("sortillus-shop-assistant").then(mount);
    } else {
      mount();
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", ready, { once: true });
  } else {
    ready();
  }
})();
