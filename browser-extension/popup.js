const form = document.querySelector("#save-form");
const titleInput = document.querySelector("#title");
const urlInput = document.querySelector("#url");
const tagsInput = document.querySelector("#tags");
const saveButton = document.querySelector("#save-button");
const status = document.querySelector("#status");
const result = document.querySelector("#result");
const resultMessage = document.querySelector("#result-message");
const shortUrl = document.querySelector("#short-url");
const copyButton = document.querySelector("#copy-button");
const serviceName = document.querySelector("#service-name");
const duplicateBehavior = document.querySelector("#duplicate-behavior");
const tools = document.querySelector("#tools");
const searchPanel = document.querySelector("#search-panel");
const searchInput = document.querySelector("#search");
const searchResults = document.querySelector("#search-results");
const queueCount = document.querySelector("#queue-count");
const advancedToggle = document.querySelector("#advanced-toggle");
const advancedFields = document.querySelector("#advanced-fields");
const health = document.querySelector("#link-health");
const languageSelect = document.querySelector("#language");

let settings;
let currentLink;
let language = "en";

function t(key, values) {
  return LinkVaultI18n.t(key, language, values);
}

function serviceEndpoint(path) {
  return `${settings.serviceUrl.replace(/\/+$/, "")}${path}`;
}

function tagLines(value) {
  return [...new Set(value.split(/[\r\n,]+/).map((tag) => tag.trim()).filter(Boolean))].slice(0, 10);
}

function localDateToIso(value) {
  return value ? new Date(value).toISOString() : undefined;
}

function apiErrorMessage(response, body) {
  return body?.error?.message || `LinkVault returned HTTP ${response.status}.`;
}

function healthLabel(link) {
  const details = [];
  if (link.status !== "active") details.push(t("linkStatus", { status: link.status }));
  if (link.expires_at) details.push(t("expires", { date: new Date(link.expires_at).toLocaleString(language === "zh" ? "zh-CN" : "en") }));
  if (link.max_clicks !== null) details.push(t("clicks", { clicks: link.clicks, max: link.max_clicks }));
  if (link.target_health_state) {
    details.push(link.target_health_state === "healthy" ? t("targetHealthy") : t("targetStatus", { status: link.target_health_state }));
  }
  if (link.target_health_reason) details.push(link.target_health_reason);
  return details.join(" · ") || t("active");
}

function showResult(link, message) {
  currentLink = link;
  resultMessage.textContent = message;
  shortUrl.textContent = link.short_url;
  shortUrl.href = link.short_url;
  health.textContent = healthLabel(link);
  form.hidden = true;
  searchPanel.hidden = true;
  result.hidden = false;
}

async function sendMessage(message) {
  const response = await chrome.runtime.sendMessage(message);
  if (response?.error) throw new Error(response.error);
  return response;
}

async function updateQueueCount() {
  queueCount.textContent = String((await sendMessage({ type: "queue-count" })).count);
}

async function fetchLink(id) {
  const response = await fetch(serviceEndpoint(`/api/links/${id}`), { headers: { Authorization: `Bearer ${settings.token}` } });
  const body = await response.json().catch(() => null);
  if (!response.ok || !body?.data) throw new Error(apiErrorMessage(response, body));
  return body.data;
}

async function initialize() {
  settings = await chrome.storage.local.get({ serviceUrl: "", token: "", reuseDuplicates: true, language: "en" });
  language = settings.language === "zh" ? "zh" : "en";
  LinkVaultI18n.apply(language);
  if (!settings.serviceUrl || !settings.token) {
    serviceName.textContent = t("setupRequired");
    status.textContent = t("configureService");
    return;
  }
  let hasPermission = false;
  try { hasPermission = await chrome.permissions.contains({ origins: [`${new URL(settings.serviceUrl).origin}/*`] }); } catch { /* status below */ }
  if (!hasPermission) {
    serviceName.textContent = t("permissionRequired");
    status.textContent = t("grantServiceAccess");
    return;
  }
  serviceName.textContent = new URL(settings.serviceUrl).host;
  tools.hidden = false;
  await updateQueueCount();
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.url || !/^https?:\/\//i.test(tab.url)) {
    status.textContent = t("invalidPage");
    return;
  }
  titleInput.value = tab.title || "";
  urlInput.value = tab.url;
  const suggestion = await sendMessage({ type: "suggest-tags", url: tab.url, title: tab.title || "" });
  tagsInput.value = (suggestion.tags || []).join("\n");
  duplicateBehavior.textContent = settings.reuseDuplicates
    ? t("duplicateReuse")
    : t("duplicateNew");
  form.hidden = false;
}

function formPayload() {
  const payload = {
    url: urlInput.value.trim(),
    title: titleInput.value.trim(),
    tags: tagLines(tagsInput.value),
    force: !settings.reuseDuplicates
  };
  const slug = document.querySelector("#slug").value.trim();
  const startsAt = localDateToIso(document.querySelector("#starts-at").value);
  const expiresAt = localDateToIso(document.querySelector("#expires-at").value);
  const maxClicks = document.querySelector("#max-clicks").value;
  if (slug) payload.slug = slug;
  if (startsAt) payload.starts_at = startsAt;
  if (expiresAt) payload.expires_at = expiresAt;
  if (maxClicks) payload.max_clicks = Number(maxClicks);
  payload.one_time = document.querySelector("#one-time").checked;
  if (payload.one_time) payload.one_time_mode = document.querySelector("#one-time-confirm").checked ? "confirm" : "immediate";
  payload.favorite = document.querySelector("#favorite").checked;
  for (const [field, key] of [["campaign-name", "campaign_name"], ["source", "source"], ["medium", "medium"], ["content", "content"]]) {
    const value = document.querySelector(`#${field}`).value.trim();
    if (value) payload[key] = value;
  }
  return payload;
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  status.textContent = "";
  result.hidden = true;
  saveButton.disabled = true;
  saveButton.textContent = t("saving");
  try {
    const response = await sendMessage({ type: "save", payload: formPayload() });
    if (response.queued) {
      status.textContent = t("unavailableQueued");
      await updateQueueCount();
      return;
    }
    const link = await fetchLink(response.id).catch(() => ({ id: response.id, short_url: response.short_url, status: "active", clicks: 0, max_clicks: null }));
    showResult(link, response.duplicate ? t("existingLinkReused") : t("linkSaved"));
  } catch (error) {
    status.textContent = error instanceof Error ? error.message : t("couldNotReach");
  } finally {
    saveButton.disabled = false;
    saveButton.textContent = t("saveLink");
  }
});

advancedToggle.addEventListener("click", () => {
  advancedFields.hidden = !advancedFields.hidden;
  advancedToggle.setAttribute("aria-expanded", String(!advancedFields.hidden));
  advancedToggle.textContent = advancedFields.hidden ? t("advancedOptions") : t("hideAdvancedOptions");
});

document.querySelector("#show-save").addEventListener("click", () => {
  result.hidden = true;
  searchPanel.hidden = true;
  form.hidden = false;
});
document.querySelector("#show-search").addEventListener("click", () => {
  form.hidden = true;
  result.hidden = true;
  searchPanel.hidden = false;
  searchInput.focus();
});
document.querySelector("#sync-queue").addEventListener("click", async () => {
  try {
    const outcome = await sendMessage({ type: "sync-queue" });
    status.textContent = outcome.saved ? t("savedQueued", { count: outcome.saved }) : t("noQueuedSynced");
    await updateQueueCount();
  } catch (error) { status.textContent = error instanceof Error ? error.message : t("couldNotSync"); }
});

let searchTimer;
searchInput.addEventListener("input", () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    const query = searchInput.value.trim();
    if (!query) { searchResults.replaceChildren(); return; }
    try {
      const response = await fetch(`${serviceEndpoint("/api/links")}?q=${encodeURIComponent(query)}&per_page=10`, { headers: { Authorization: `Bearer ${settings.token}` } });
      const body = await response.json().catch(() => null);
      if (!response.ok || !Array.isArray(body?.data)) throw new Error(apiErrorMessage(response, body));
      searchResults.replaceChildren(...body.data.map((link) => {
        const item = document.createElement("a");
        item.className = "search-result";
        item.href = link.short_url;
        item.target = "_blank";
        item.rel = "noreferrer";
        const name = document.createElement("strong");
        name.textContent = link.title || link.slug;
        const detail = document.createElement("small");
        detail.textContent = `${link.short_url} · ${link.status}${link.tags.length ? ` · ${link.tags.join(", ")}` : ""}`;
        item.append(name, detail);
        return item;
      }));
      if (!body.data.length) searchResults.textContent = t("noSavedLinks");
    } catch (error) { searchResults.textContent = error instanceof Error ? error.message : t("searchFailed"); }
  }, 250);
});

copyButton.addEventListener("click", async () => {
  try { await navigator.clipboard.writeText(shortUrl.href); copyButton.textContent = t("copied"); } catch { status.textContent = t("couldNotCopy"); }
});
document.querySelector("#open-options").addEventListener("click", () => chrome.runtime.openOptionsPage());
languageSelect.addEventListener("change", async () => {
  language = languageSelect.value;
  await chrome.storage.local.set({ language });
  LinkVaultI18n.apply(language);
  if (settings) initialize().catch(() => { status.textContent = t("couldNotReadTab"); });
});
initialize().catch(() => { status.textContent = t("couldNotReadTab"); });
