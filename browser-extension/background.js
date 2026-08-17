importScripts("i18n.js");

const QUEUE_KEY = "offlineQueue";
const SYNC_ALARM = "linkvault-sync-queue";
const SETTINGS_DEFAULTS = {
  serviceUrl: "",
  token: "",
  defaultTags: "",
  reuseDuplicates: true,
  autoTagDomain: true,
  autoTagParameters: true,
  tagRules: "[]",
  language: "en"
};

function serviceEndpoint(serviceUrl, path) {
  return `${serviceUrl.replace(/\/+$/, "")}${path}`;
}

function tagLines(value) {
  return [...new Set(String(value).split(/[\r\n,]+/).map((tag) => tag.trim()).filter(Boolean))].slice(0, 10);
}

function safeTag(value) {
  return String(value).trim().replace(/[\x00-\x1f\x7f]/g, "").slice(0, 24);
}

function suggestedTags(urlValue, title, settings) {
  const tags = tagLines(settings.defaultTags);
  let url;
  try {
    url = new URL(urlValue);
  } catch {
    return tags;
  }
  if (settings.autoTagDomain) {
    const host = safeTag(url.hostname.replace(/^www\./i, ""));
    if (host) tags.push(host);
  }
  if (settings.autoTagParameters) {
    for (const key of ["utm_source", "utm_medium", "utm_campaign", "source", "ref"]) {
      const prefix = key.startsWith("utm_") ? `${key.slice(4)}:` : `${key}:`;
      const value = safeTag(url.searchParams.get(key) || "").slice(0, 24 - prefix.length);
      if (value) tags.push(`${prefix}${value}`);
    }
  }
  try {
    const rules = JSON.parse(settings.tagRules || "[]");
    if (Array.isArray(rules)) {
      for (const rule of rules) {
        if (!rule || !Array.isArray(rule.tags)) continue;
        const match = rule.match && typeof rule.match === "object" ? rule.match : {};
        const hostMatches = !match.host || url.hostname.toLowerCase().includes(String(match.host).toLowerCase());
        const titleMatches = !match.title || String(title).toLowerCase().includes(String(match.title).toLowerCase());
        const urlMatches = !match.url || url.href.toLowerCase().includes(String(match.url).toLowerCase());
        const parameterMatches = !match.param || url.searchParams.has(String(match.param));
        if (hostMatches && titleMatches && urlMatches && parameterMatches) {
          tags.push(...rule.tags.map(safeTag).filter(Boolean));
        }
      }
    }
  } catch {
    // Invalid rules are reported in Options and never prevent a save.
  }
  return [...new Set(tags)].slice(0, 10);
}

async function savePayload(payload, allowQueue = true) {
  const settings = await chrome.storage.local.get(SETTINGS_DEFAULTS);
  if (!settings.serviceUrl || !settings.token) throw new Error("Configure the LinkVault service URL and token first.");
  try {
    const response = await fetch(serviceEndpoint(settings.serviceUrl, "/api/shorten"), {
      method: "POST",
      headers: { Authorization: `Bearer ${settings.token}`, "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    let body = null;
    try { body = await response.json(); } catch { /* Use the status below. */ }
    if (!response.ok || !body || typeof body.short_url !== "string") {
      const message = body?.error?.message || `LinkVault returned HTTP ${response.status}.`;
      const retryable = response.status >= 500 || response.status === 429;
      if (allowQueue && retryable) {
        await queuePayload(payload);
        return { queued: true, message };
      }
      throw new Error(message);
    }
    return { ...body, queued: false };
  } catch (error) {
    if (allowQueue && error instanceof TypeError) {
      await queuePayload(payload);
      return { queued: true, message: "LinkVault is unavailable. The link was queued locally." };
    }
    throw error;
  }
}

async function queuePayload(payload) {
  const { [QUEUE_KEY]: queue = [] } = await chrome.storage.local.get({ [QUEUE_KEY]: [] });
  await chrome.storage.local.set({ [QUEUE_KEY]: [...queue, { id: crypto.randomUUID(), payload, createdAt: new Date().toISOString() }].slice(-100) });
}

async function syncQueue() {
  const { [QUEUE_KEY]: queue = [] } = await chrome.storage.local.get({ [QUEUE_KEY]: [] });
  const remaining = [];
  let saved = 0;
  for (const item of queue) {
    try {
      const result = await savePayload(item.payload, false);
      if (result.queued) remaining.push(item); else saved++;
    } catch {
      remaining.push(item);
    }
  }
  await chrome.storage.local.set({ [QUEUE_KEY]: remaining });
  return { saved, remaining: remaining.length };
}

function setResultBadge(success) {
  chrome.action.setBadgeBackgroundColor({ color: success ? "#176b51" : "#a12b27" });
  chrome.action.setBadgeText({ text: success ? "OK" : "!" });
  setTimeout(() => chrome.action.setBadgeText({ text: "" }), 4000);
}

async function savePage(url, title) {
  if (!/^https?:\/\//i.test(url || "")) throw new Error("Only HTTP and HTTPS pages can be saved.");
  const settings = await chrome.storage.local.get(SETTINGS_DEFAULTS);
  return savePayload({
    url,
    title: String(title || "").slice(0, 120),
    tags: suggestedTags(url, title, settings),
    force: !settings.reuseDuplicates
  });
}

async function saveActiveTab() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  const result = await savePage(tab?.url, tab?.title);
  setResultBadge(!result.queued);
  return result;
}

async function createContextMenus() {
  const { language } = await chrome.storage.local.get({ language: "en" });
  const t = (key) => LinkVaultI18n.t(key, language);
  chrome.contextMenus.removeAll(() => {
    chrome.contextMenus.create({ id: "save-page", title: t("menuSavePage"), contexts: ["page"] });
    chrome.contextMenus.create({ id: "save-link", title: t("menuSaveLink"), contexts: ["link"] });
    chrome.contextMenus.create({ id: "save-selection", title: t("menuSaveSelection"), contexts: ["selection"] });
  });
}

chrome.runtime.onInstalled.addListener(() => {
  createContextMenus();
  chrome.alarms.create(SYNC_ALARM, { periodInMinutes: 5 });
});
chrome.runtime.onStartup.addListener(createContextMenus);
chrome.storage.onChanged.addListener((changes, area) => {
  if (area === "local" && changes.language) createContextMenus();
});
chrome.commands.onCommand.addListener((command) => {
  if (command === "save-current-page") saveActiveTab().catch(() => setResultBadge(false));
});
chrome.contextMenus.onClicked.addListener((info, tab) => {
  let url = info.linkUrl || (info.menuItemId === "save-page" ? tab?.url : "");
  if (info.menuItemId === "save-selection") url = (info.selectionText || "").match(/https?:\/\/[^\s<>'"]+/i)?.[0] || "";
  savePage(url, tab?.title).then((result) => setResultBadge(!result.queued)).catch(() => setResultBadge(false));
});
chrome.alarms.onAlarm.addListener((alarm) => { if (alarm.name === SYNC_ALARM) syncQueue(); });
chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  (async () => {
    if (message.type === "suggest-tags") return { tags: suggestedTags(message.url, message.title, await chrome.storage.local.get(SETTINGS_DEFAULTS)) };
    if (message.type === "save") return savePayload(message.payload);
    if (message.type === "sync-queue") return syncQueue();
    if (message.type === "queue-count") return { count: (await chrome.storage.local.get({ [QUEUE_KEY]: [] }))[QUEUE_KEY].length };
    throw new Error("Unknown extension message.");
  })().then(sendResponse).catch((error) => sendResponse({ error: error instanceof Error ? error.message : "Extension error." }));
  return true;
});
