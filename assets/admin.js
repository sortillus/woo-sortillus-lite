(function () {
  "use strict";

  var config = window.wooSortillusLiteAdmin || {};
  var button = document.getElementById("woo-sortillus-lite-import");
  var stateEl = document.getElementById("woo-sortillus-lite-state");
  var bar = document.getElementById("woo-sortillus-lite-progress-bar");
  var progress = document.getElementById("woo-sortillus-lite-progress-text");
  var details = document.getElementById("woo-sortillus-lite-sync-details");
  var error = document.getElementById("woo-sortillus-lite-sync-error");
  var health = document.getElementById("woo-sortillus-lite-health");
  if (!button || !stateEl) return;

  function request(action, extra) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", config.nonce || "");
    Object.keys(extra || {}).forEach(function (key) { body.append(key, extra[key]); });
    return fetch(config.ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
      .then(function (response) { return response.json(); });
  }

  function render(payload) {
    var state = payload.state || {};
    var total = Number(state.total || 0);
    var processed = Number(state.processed || 0);
    var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
    var active = state.status === "queued" || state.status === "running";
    stateEl.textContent = state.status || "idle";
    bar.style.width = pct + "%";
    progress.textContent = total > 0 ? processed + " / " + total + " " + config.i18n.products : config.i18n.never;
    details.textContent = "Accepted: " + Number(state.accepted || 0) + " · Rejected: " + Number(state.rejected || 0) +
      (state.finished_at ? " · Finished: " + state.finished_at : "");
    error.textContent = state.last_error || state.last_delta_error || state.report_error || payload.health_error || "";
    button.disabled = active;
    button.textContent = active ? config.i18n.starting : config.i18n.importAgain;
    if (payload.health) {
      health.textContent = "Sortillus health: " + (payload.health.healthy ? "healthy" : payload.health.status || "unknown") +
        (payload.health.last_successful_sync_at ? " · Last successful sync: " + payload.health.last_successful_sync_at : "");
    }
    return active;
  }

  function poll(includeHealth) {
    request("woo_sortillus_lite_sync_status", { include_health: includeHealth ? "1" : "0" })
      .then(function (response) {
        if (!response.success) return;
        var active = render(response.data || {});
        if (active) {
          window.setTimeout(function () { poll(false); }, 2500);
        } else if (!includeHealth) {
          poll(true);
        }
      });
  }

  button.addEventListener("click", function () {
    button.disabled = true;
    button.textContent = config.i18n.starting;
    error.textContent = "";
    request("woo_sortillus_lite_start_import")
      .then(function (response) {
        if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : config.i18n.failed);
        render(response.data || {});
        window.setTimeout(function () { poll(false); }, 1000);
      })
      .catch(function (caught) {
        error.textContent = caught.message || config.i18n.failed;
        button.disabled = false;
        button.textContent = config.i18n.importAgain;
      });
  });

  poll(true);
})();
