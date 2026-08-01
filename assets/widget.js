(function () {
  "use strict";

  function mount() {
    var config = window.wooSortillusLiteWidget || {};
    if (!config.bootstrapUrl || document.querySelector("sortillus-shop-assistant")) return;

    var widget = document.createElement("sortillus-shop-assistant");
    widget.setAttribute("api-origin", config.apiOrigin || "https://data.sortillus.com");
    widget.setAttribute("bootstrap-url", config.bootstrapUrl);
    widget.setAttribute("locale", config.locale || document.documentElement.lang || "en");
    widget.setAttribute("placement", "floating");
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

