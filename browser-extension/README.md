# Save to LinkVault

A dependency-free Chromium Manifest V3 extension that saves, finds, and monitors LinkVault links.

## Install

1. Open `chrome://extensions` (or the equivalent page in your Chromium browser).
2. Enable **Developer mode**.
3. Choose **Load unpacked** and select this `browser-extension` directory.
4. Open the extension's **Options**, enter the LinkVault base URL and a bearer token, choose defaults and automatic tag rules, and save.
5. Pin the extension, open a web page, and use the toolbar action to save it.

The service URL may include a deployment subpath. The extension appends `/api/shorten`. By default LinkVault reuses an existing active link for the same URL; disable **Reuse duplicate links** to send `force: true` and create a new short link.

## Features

- Press `Ctrl+Shift+L` on Windows/Linux or `Command+Shift+L` on macOS to save the active page without opening the popup. Keyboard shortcuts can be changed in `chrome://extensions/shortcuts`.
- Right-click a page, link, or selected HTTP(S) URL to save it.
- Suggested tags combine defaults, the current domain, supported URL parameters, and optional JSON rules. Rules are local to the browser profile.
- The popup supports custom short codes, start/end times, click limits, one-time mode, favorites, and campaign fields.
- Use the language picker in the popup or Settings page to switch the extension interface between English and Chinese. The choice is saved in the browser profile.
- Saved-link search and link health/status details require a Token with `links:read` in addition to `links:create`. Saving only needs `links:create`.
- If LinkVault is unreachable or returns a retryable failure, saves are queued locally and retried every five minutes. The popup can retry the queue immediately; it retains at most 100 queued items.

## Security

- The token is stored in `chrome.storage.local`, which is local to the browser profile but is not an encrypted secret store. Protect the OS/browser profile and use a narrowly scoped token.
- Prefer HTTPS. HTTP is supported for local or otherwise trusted development services, but it exposes the token and request in transit.
- Host access is optional and requested only for the configured service origin. Chromium retains previously granted origins; revoke obsolete access from the extension's site-access settings after changing services.
- The extension sends a page URL, title, and configured creation fields only when a save action is invoked. It has no content scripts, analytics, or external dependencies.

## Privacy policy

Before publishing, set `LINKVAULT_BROWSER_EXTENSION_PRIVACY_CONTACT` to a monitored support email and submit the public policy URL `https://<your-linkvault-domain>/browser-extension-privacy` in the Chrome Web Store privacy tab. The URL must use your deployed HTTPS domain, not a localhost address.
