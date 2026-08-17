const form = document.querySelector("#settings-form");
const serviceUrlInput = document.querySelector("#service-url");
const tokenInput = document.querySelector("#token");
const defaultTagsInput = document.querySelector("#default-tags");
const reuseDuplicatesInput = document.querySelector("#reuse-duplicates");
const autoTagDomainInput = document.querySelector("#auto-tag-domain");
const autoTagParametersInput = document.querySelector("#auto-tag-parameters");
const tagRulesInput = document.querySelector("#tag-rules");
const saveButton = document.querySelector("#save-settings");
const status = document.querySelector("#status");
const languageSelect = document.querySelector("#language");
let language = "en";

function t(key) {
  return LinkVaultI18n.t(key, language);
}

function normalizeServiceUrl(value) {
  const url = new URL(value.trim());
  if (!['http:', 'https:'].includes(url.protocol)) {
    throw new Error(t("serviceUrlProtocol"));
  }
  if (url.username || url.password || url.search || url.hash) {
    throw new Error(t("serviceUrlParts"));
  }
  url.pathname = url.pathname.replace(/\/+$/, "");
  return url.toString().replace(/\/$/, "");
}

function permissionPattern(serviceUrl) {
  return `${new URL(serviceUrl).origin}/*`;
}

async function loadSettings() {
  const settings = await chrome.storage.local.get({
    serviceUrl: "",
    token: "",
    defaultTags: "",
    reuseDuplicates: true,
    autoTagDomain: true,
    autoTagParameters: true,
    tagRules: "[]",
    language: "en"
  });
  language = settings.language === "zh" ? "zh" : "en";
  LinkVaultI18n.apply(language);
  serviceUrlInput.value = settings.serviceUrl;
  tokenInput.value = settings.token;
  defaultTagsInput.value = settings.defaultTags;
  reuseDuplicatesInput.checked = settings.reuseDuplicates;
  autoTagDomainInput.checked = settings.autoTagDomain;
  autoTagParametersInput.checked = settings.autoTagParameters;
  tagRulesInput.value = settings.tagRules;
}

function validateTagRules(value) {
  let rules;
  try {
    rules = JSON.parse(value || "[]");
  } catch {
    throw new Error(t("rulesJson"));
  }
  if (!Array.isArray(rules)) throw new Error(t("rulesArray"));
  for (const rule of rules) {
    if (!rule || typeof rule !== "object" || !Array.isArray(rule.tags) || rule.tags.length > 10 || rule.tags.some((tag) => typeof tag !== "string" || !tag.trim() || tag.length > 24)) {
      throw new Error(t("rulesTags"));
    }
    if (rule.match !== undefined && (!rule.match || typeof rule.match !== "object" || Array.isArray(rule.match))) throw new Error(t("ruleMatch"));
  }
  return JSON.stringify(rules);
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  status.classList.remove("error");
  status.textContent = "";

  let serviceUrl;
  try {
    serviceUrl = normalizeServiceUrl(serviceUrlInput.value);
  } catch (error) {
    status.classList.add("error");
    status.textContent = error instanceof Error ? error.message : t("invalidUrl");
    return;
  }

  if (!tokenInput.value.trim()) {
    status.classList.add("error");
    status.textContent = t("enterToken");
    return;
  }

  let tagRules;
  try {
    tagRules = validateTagRules(tagRulesInput.value);
  } catch (error) {
    status.classList.add("error");
    status.textContent = error instanceof Error ? error.message : t("invalidRules");
    return;
  }

  saveButton.disabled = true;
  try {
    const origin = permissionPattern(serviceUrl);
    const granted = await chrome.permissions.request({ origins: [origin] });
    if (!granted) {
      throw new Error(t("accessNotGranted"));
    }

    await chrome.storage.local.set({
      serviceUrl,
      token: tokenInput.value.trim(),
      defaultTags: defaultTagsInput.value,
      reuseDuplicates: reuseDuplicatesInput.checked,
      autoTagDomain: autoTagDomainInput.checked,
      autoTagParameters: autoTagParametersInput.checked,
      tagRules,
      language
    });
    serviceUrlInput.value = serviceUrl;
    status.textContent = t("settingsSaved");
  } catch (error) {
    status.classList.add("error");
    status.textContent = error instanceof Error ? error.message : t("couldNotSaveSettings");
  } finally {
    saveButton.disabled = false;
  }
});

languageSelect.addEventListener("change", async () => {
  language = languageSelect.value;
  await chrome.storage.local.set({ language });
  LinkVaultI18n.apply(language);
});

loadSettings().catch(() => {
  status.classList.add("error");
  status.textContent = t("couldNotLoadSettings");
});
