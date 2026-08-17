globalThis.LinkVaultI18n = (() => {
  const messages = {
    en: {
      appTitle: "Save to LinkVault", settings: "Settings", language: "Language", english: "English", chinese: "Chinese",
      checkingConfiguration: "Checking configuration...", setupRequired: "Setup required", configureService: "Configure the service URL and bearer token in Settings.",
      permissionRequired: "Permission required", grantServiceAccess: "Open Settings and grant access to the configured LinkVault service.",
      invalidPage: "This page cannot be saved. Open a regular HTTP or HTTPS page.",
      save: "Save", findLinks: "Find links", queue: "Queue", title: "Title", url: "URL", tags: "Tags", onePerLine: "one per line",
      duplicateReuse: "An existing link for this URL will be reused.", duplicateNew: "A new short link will be created even when the URL already exists.",
      advancedOptions: "Advanced options", hideAdvancedOptions: "Hide advanced options", customShortCode: "Custom short code", startsAt: "Starts at", expiresAt: "Expires at", clickLimit: "Click limit",
      oneTimeLink: "One-time link", visitorConfirmation: "Require visitor confirmation", addFavorites: "Add to favorites", campaign: "Campaign", source: "Source", medium: "Medium", content: "Content", saveLink: "Save link", saving: "Saving...",
      searchSavedLinks: "Search saved links", searchPlaceholder: "Title, tag, code, or URL", copyShortUrl: "Copy short URL", copied: "Copied",
      unavailableQueued: "LinkVault is unavailable. The link was added to the offline queue.", existingLinkReused: "Existing link reused", linkSaved: "Link saved", couldNotReach: "Could not reach LinkVault.",
      savedQueued: "Saved {count} queued link(s).", noQueuedSynced: "No queued links could be synced yet.", couldNotSync: "Could not sync the queue.", noSavedLinks: "No saved links found.", searchFailed: "Search failed.", couldNotCopy: "Could not copy the short URL.", couldNotReadTab: "The extension could not read the current tab.",
      active: "Link active; no target health result yet.", linkStatus: "Link {status}", expires: "expires {date}", clicks: "{clicks}/{max} clicks", targetHealthy: "target healthy", targetStatus: "target {status}",
      settingsTitle: "LinkVault Extension Settings", extension: "LinkVault extension", connectionAndDefaults: "Connection and save defaults", connection: "Connection", serviceUrl: "Service URL", serviceUrlHint: "Use the LinkVault base URL, including any deployment subpath.", bearerToken: "Bearer token", tokenHint: "The token must have the <code>links:create</code> scope.",
      saveDefaults: "Save defaults", defaultTags: "Default tags", reuseDuplicates: "Reuse duplicate links", reuseDuplicatesHint: "Return the existing active short link when this URL is already saved.", automaticTags: "Automatic tags", tagDomain: "Tag with the domain", tagDomainHint: "For example, save <code>www.example.com</code> with <code>example.com</code>.", tagParameters: "Tag common URL parameters", tagParametersHint: "Add source, medium, campaign, ref, and similar values when present.", customRules: "Custom rules", customRulesHint: "Each rule has <code>match</code> fields (<code>host</code>, <code>title</code>, <code>url</code>, or <code>param</code>) and a <code>tags</code> array. Example: <code>[{\"match\":{\"host\":\"github.com\"},\"tags\":[\"code\"]}]</code>.", saveSettings: "Save settings", serviceUrlProtocol: "The service URL must use HTTP or HTTPS.", serviceUrlParts: "The service URL cannot contain credentials, a query, or a fragment.", invalidUrl: "Invalid service URL.", enterToken: "Enter a bearer token.", rulesJson: "Custom tag rules must be valid JSON.", rulesArray: "Custom tag rules must be a JSON array.", rulesTags: "Each rule needs a tags array containing tags up to 24 characters.", ruleMatch: "A rule match must be an object.", invalidRules: "Invalid custom tag rules.", accessNotGranted: "Access to the LinkVault service was not granted.", settingsSaved: "Settings saved.", couldNotSaveSettings: "Could not save settings.", couldNotLoadSettings: "Could not load extension settings.",
      menuSavePage: "Save page to LinkVault", menuSaveLink: "Save link to LinkVault", menuSaveSelection: "Save URL in selection to LinkVault"
    },
    zh: {
      appTitle: "保存到 LinkVault", settings: "设置", language: "语言", english: "English", chinese: "中文",
      checkingConfiguration: "正在检查配置...", setupRequired: "需要设置", configureService: "请在设置中配置服务地址和 Bearer 令牌。",
      permissionRequired: "需要授权", grantServiceAccess: "请打开设置并授权访问已配置的 LinkVault 服务。",
      invalidPage: "此页面无法保存。请打开普通的 HTTP 或 HTTPS 页面。",
      save: "保存", findLinks: "查找链接", queue: "队列", title: "标题", url: "URL", tags: "标签", onePerLine: "每行一个",
      duplicateReuse: "此 URL 已有的链接将被复用。", duplicateNew: "即使此 URL 已存在，也会创建新的短链接。", advancedOptions: "高级选项", hideAdvancedOptions: "收起高级选项", customShortCode: "自定义短码", startsAt: "开始时间", expiresAt: "过期时间", clickLimit: "点击上限",
      oneTimeLink: "一次性链接", visitorConfirmation: "要求访客确认", addFavorites: "添加到收藏", campaign: "广告系列", source: "来源", medium: "媒介", content: "内容", saveLink: "保存链接", saving: "保存中...",
      searchSavedLinks: "搜索已保存链接", searchPlaceholder: "标题、标签、短码或 URL", copyShortUrl: "复制短链接", copied: "已复制",
      unavailableQueued: "LinkVault 当前不可用，链接已加入离线队列。", existingLinkReused: "已复用现有链接", linkSaved: "链接已保存", couldNotReach: "无法连接 LinkVault。",
      savedQueued: "已保存 {count} 个队列中的链接。", noQueuedSynced: "暂时没有可同步的队列链接。", couldNotSync: "无法同步队列。", noSavedLinks: "未找到已保存的链接。", searchFailed: "搜索失败。", couldNotCopy: "无法复制短链接。", couldNotReadTab: "扩展无法读取当前标签页。",
      active: "链接有效，尚无目标地址健康检查结果。", linkStatus: "链接状态：{status}", expires: "过期时间：{date}", clicks: "点击：{clicks}/{max}", targetHealthy: "目标地址正常", targetStatus: "目标地址：{status}",
      settingsTitle: "LinkVault 扩展设置", extension: "LinkVault 扩展", connectionAndDefaults: "连接和保存默认设置", connection: "连接", serviceUrl: "服务地址", serviceUrlHint: "请输入 LinkVault 基础 URL，可包含部署子路径。", bearerToken: "Bearer 令牌", tokenHint: "令牌必须具有 <code>links:create</code> 权限。",
      saveDefaults: "保存默认设置", defaultTags: "默认标签", reuseDuplicates: "复用重复链接", reuseDuplicatesHint: "URL 已保存时，返回已有的有效短链接。", automaticTags: "自动标签", tagDomain: "使用域名作为标签", tagDomainHint: "例如，保存 <code>www.example.com</code> 时添加 <code>example.com</code>。", tagParameters: "标记常见 URL 参数", tagParametersHint: "存在时添加 source、medium、campaign、ref 等参数值。", customRules: "自定义规则", customRulesHint: "每条规则包含 <code>match</code> 字段（<code>host</code>、<code>title</code>、<code>url</code> 或 <code>param</code>）及 <code>tags</code> 数组。示例：<code>[{\"match\":{\"host\":\"github.com\"},\"tags\":[\"code\"]}]</code>。", saveSettings: "保存设置", serviceUrlProtocol: "服务地址必须使用 HTTP 或 HTTPS。", serviceUrlParts: "服务地址不能包含凭据、查询参数或片段。", invalidUrl: "无效的服务地址。", enterToken: "请输入 Bearer 令牌。", rulesJson: "自定义标签规则必须是有效 JSON。", rulesArray: "自定义标签规则必须是 JSON 数组。", rulesTags: "每条规则都需要 tags 数组，标签长度不能超过 24 个字符。", ruleMatch: "规则的 match 必须是对象。", invalidRules: "无效的自定义标签规则。", accessNotGranted: "未获授予访问 LinkVault 服务的权限。", settingsSaved: "设置已保存。", couldNotSaveSettings: "无法保存设置。", couldNotLoadSettings: "无法加载扩展设置。",
      menuSavePage: "保存页面到 LinkVault", menuSaveLink: "保存链接到 LinkVault", menuSaveSelection: "保存所选文本中的 URL 到 LinkVault"
    }
  };

  function t(key, language = "en", values = {}) {
    return (messages[language]?.[key] || messages.en[key] || key).replace(/\{(\w+)\}/g, (_, name) => values[name] ?? "");
  }

  function apply(language) {
    document.documentElement.lang = language === "zh" ? "zh-CN" : "en";
    document.querySelectorAll("[data-i18n]").forEach((element) => { element.innerHTML = t(element.dataset.i18n, language); });
    document.querySelectorAll("[data-i18n-placeholder]").forEach((element) => { element.placeholder = t(element.dataset.i18nPlaceholder, language); });
    document.querySelectorAll("[data-i18n-title]").forEach((element) => { element.title = t(element.dataset.i18nTitle, language); });
    document.querySelectorAll("[data-i18n-aria-label]").forEach((element) => { element.setAttribute("aria-label", t(element.dataset.i18nAriaLabel, language)); });
    document.querySelectorAll("[data-language-select]").forEach((element) => { element.value = language; });
    document.title = t(document.documentElement.dataset.titleKey || "appTitle", language);
  }

  return { t, apply };
})();
