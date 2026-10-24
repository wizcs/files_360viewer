(() => {
  // src/viewerRegistration.mjs
  var RAW_HANDLER_ID = "files-360viewer-raw";
  var RAW_MIMES = ["video/x-insv", "video/x-osv"];
  function registerRawHandler(viewer, handler) {
    if (!viewer) return false;
    const handlers = viewer.availableHandlers;
    if (!Array.isArray(handlers)) return false;
    if (!handlers.some((candidate) => candidate.id === handler.id)) viewer.registerHandler(handler);
    return handlers.some((candidate) => candidate.id === handler.id);
  }
  function installRawHandler(win, doc, component) {
    const handler = {
      id: RAW_HANDLER_ID,
      group: "files-360viewer",
      mimes: [...RAW_MIMES],
      mimesAliases: { "application/x-insv": "video/x-insv", "application/x-osv": "video/x-osv" },
      component
    };
    const install = () => registerRawHandler(win.OCA?.Viewer, handler);
    if (!install()) {
      if (!win._oca_viewer_handlers) win._oca_viewer_handlers = /* @__PURE__ */ new Map();
      if (typeof win._oca_viewer_handlers.set === "function") win._oca_viewer_handlers.set(handler.id, handler);
      else if (typeof win._oca_viewer_handlers.add === "function") win._oca_viewer_handlers.add(handler);
      else if (!win._oca_viewer_handlers.some((candidate) => candidate.id === handler.id)) win._oca_viewer_handlers.push(handler);
    }
    if (doc.readyState === "loading") doc.addEventListener("DOMContentLoaded", install, { capture: true, once: true });
    return handler;
  }

  // src/bridge.ts
  var w = window;
  var isPublicShare = /\/s\/[A-Za-z0-9]+(?:\/|$)/.test(location.pathname);
  var isFilesPage = /\/(?:index\.php\/)?apps\/files(?:\/|$)/.test(location.pathname);
  var rootPath = String(w.OC?.webroot || `/${location.pathname.split("/").filter(Boolean)[0] || ""}`).replace(/\/$/, "");
  if (isPublicShare && w.OC) {
    if (typeof w.OC.generateUrl !== "function") w.OC.generateUrl = (path) => `${rootPath}${String(path).startsWith("/") ? path : `/${path}`}`;
    if (typeof w.OC.filePath !== "function") w.OC.filePath = (app, path = "") => `${rootPath}/apps/${app}/${String(path).replace(/^\/+/, "")}`;
  }
  function loadComponent() {
    if (w.Files360MountViewer) return Promise.resolve(w.Files360MountViewer);
    return new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.src = `${rootPath}/apps/files_360viewer/js/viewer-component.js`;
      script.onload = () => resolve(w.Files360MountViewer);
      script.onerror = () => reject(new Error("360 viewer component failed to load"));
      document.head.appendChild(script);
    });
  }
  if (isPublicShare || isFilesPage) {
    document.addEventListener("click", (event) => {
      const target = event.target;
      const link = target?.closest("[data-cy-files-list-row-name-link]");
      const row = link?.closest("[data-cy-files-list-row]");
      const name = (link?.innerText || row?.getAttribute("data-cy-files-list-row-name") || "").replace(/\s+/g, "");
      if (!link || !row || !/\.(insv|osv)$/i.test(name) || !w.OCA?.Viewer?.openWith) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      const dir = new URLSearchParams(location.search).get("dir") || "";
      const path = `${dir.replace(/\/+$/, "")}/${name}`.replace(/^\/+/, "/");
      const node = { fileid: Number(row.getAttribute("data-cy-files-list-row-fileid") || 0), basename: name, filename: name, name, mime: /\.osv$/i.test(name) ? "video/x-osv" : "video/x-insv", permissions: isPublicShare ? "R" : 1, path };
      void loadComponent().then((mountViewer) => {
        const registered = w.OCA?.Viewer?.availableHandlers?.find((candidate) => candidate.id === RAW_HANDLER_ID);
        const queued = w._oca_viewer_handlers instanceof Map ? w._oca_viewer_handlers.get(RAW_HANDLER_ID) : null;
        const handler = registered || queued || installRawHandler(w, document, mountViewer.component);
        if (!isPublicShare && w.OCA?.Files?.Sidebar && typeof w.OCA.Files.Sidebar.setFullScreenMode !== "function") w.OCA.Files.Sidebar.setFullScreenMode = () => {
        };
        w.OCA.Viewer.openWith(handler.id, { path, fileInfo: node, list: [node], enableSidebar: false, loadMore: () => [], onPrev: () => {
        }, onNext: () => {
        }, onClose: () => {
        } });
      }).catch((error) => console.error("[files_360viewer] viewer load failed", error));
    }, true);
  }
})();
