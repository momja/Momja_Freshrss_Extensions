/* Wallabag Sync — FreshRSS extension client-side bridge.
 *
 * Watches FreshRSS entries (.flux) for read-state changes and, for entries
 * whose link points at the configured Wallabag instance, posts the Wallabag
 * entry id back to the extension's PHP endpoint so the entry can be archived
 * via the Wallabag API.
 */
(function () {
	'use strict';

	const VIEW_PATH_RE = /\/view\/(\d+)(?:[/?#]|$)/;
	const SYNCED_ATTR = 'data-wallabag-synced';
	const ENDPOINT = (typeof window !== 'undefined' && window.location)
		? window.location.pathname + window.location.search
		: '/';

	let wallabagUrl = null;
	let configured = false;
	let initialised = false;

	function csrfToken() {
		if (typeof window !== 'undefined' && window.context && typeof window.context.csrf === 'string') {
			return window.context.csrf;
		}
		const meta = document.querySelector('meta[name="csrf"]');
		return meta ? meta.getAttribute('content') || '' : '';
	}

	function postSync(params) {
		const body = new FormData();
		body.append('_csrf', csrfToken());
		Object.keys(params).forEach((k) => body.append(k, String(params[k])));
		return fetch(ENDPOINT, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
			headers: { 'Accept': 'application/json' },
		});
	}

	function loadConfig() {
		return postSync({ wallabag_sync_action: 'config' })
			.then((r) => r.ok ? r.json() : null)
			.then((data) => {
				if (!data || !data.ok) {
					return false;
				}
				wallabagUrl = (data.wallabag_url || '').replace(/\/+$/, '');
				configured = Boolean(data.configured) && wallabagUrl.length > 0;
				return configured;
			})
			.catch(() => false);
	}

	function extractEntryId(href) {
		if (!href || !wallabagUrl) {
			return null;
		}
		// Match only entries whose link points at the configured Wallabag host.
		if (href.indexOf(wallabagUrl) !== 0) {
			return null;
		}
		const m = href.match(VIEW_PATH_RE);
		return m ? parseInt(m[1], 10) : null;
	}

	function entryLinkHref(fluxEl) {
		// The first `a.item-element` in a .flux is the read-toggle action link
		// (/i/?c=entry&a=read&...), not the article URL. Target the title
		// anchor specifically across the feed-list and reader views.
		const candidates = [
			fluxEl.querySelector('a.item-element.title[href]'),    // feed list view
			fluxEl.querySelector('h1.title a.go_website[href]'),   // article/reader view
			fluxEl.querySelector('a.go_website[href]'),
			fluxEl.querySelector('li.item.link a[href]'),
		];
		for (const a of candidates) {
			if (a && a.href) {
				return a.href;
			}
		}
		return null;
	}

	function syncRead(fluxEl) {
		if (!configured || fluxEl.hasAttribute(SYNCED_ATTR)) {
			return;
		}
		const href = entryLinkHref(fluxEl);
		const entryId = extractEntryId(href);
		if (entryId === null) {
			return;
		}
		fluxEl.setAttribute(SYNCED_ATTR, '1');

		postSync({ wallabag_sync_action: 'mark_read', entry_id: entryId })
			.then((r) => r.ok ? r.json() : { ok: false, error: 'http_' + r.status })
			.then((data) => {
				if (!data || !data.ok) {
					fluxEl.removeAttribute(SYNCED_ATTR);
					console.warn('[WallabagSync] failed for entry', entryId, data);
				}
			})
			.catch((err) => {
				fluxEl.removeAttribute(SYNCED_ATTR);
				console.warn('[WallabagSync] network error for entry', entryId, err);
			});
	}

	function isRead(fluxEl) {
		// FreshRSS toggles between `not_read` and `read` on the .flux element.
		return fluxEl.classList.contains('read') && !fluxEl.classList.contains('not_read');
	}

	function scanInitial(root) {
		const fluxes = root.querySelectorAll('.flux.read');
		// Don't replay history — only react to live transitions. We mark
		// already-read entries as synced so we don't ping the API for them.
		fluxes.forEach((el) => el.setAttribute(SYNCED_ATTR, '1'));
	}

	function observe(root) {
		const observer = new MutationObserver((mutations) => {
			for (const m of mutations) {
				if (m.type !== 'attributes' || m.attributeName !== 'class') {
					continue;
				}
				const el = m.target;
				if (!(el instanceof Element) || !el.classList.contains('flux')) {
					continue;
				}
				const wasRead = typeof m.oldValue === 'string'
					&& m.oldValue.split(/\s+/).includes('read')
					&& !m.oldValue.split(/\s+/).includes('not_read');
				if (!wasRead && isRead(el)) {
					syncRead(el);
				}
			}
		});

		observer.observe(root, {
			subtree: true,
			attributes: true,
			attributeOldValue: true,
			attributeFilter: ['class'],
		});
	}

	function start() {
		if (initialised) {
			return;
		}
		initialised = true;

		loadConfig().then((ok) => {
			if (!ok) {
				return;
			}
			const root = document.getElementById('stream') || document.body;
			scanInitial(root);
			observe(root);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, { once: true });
	} else {
		start();
	}
})();
