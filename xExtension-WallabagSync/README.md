# Wallabag Sync — FreshRSS extension

A FreshRSS extension that mirrors **read state** from FreshRSS to **Wallabag**.

When you subscribe to your Wallabag Atom feed (e.g. the "unread" feed) inside
FreshRSS and then read one of those entries in FreshRSS, this extension calls
the Wallabag API to mark the same entry as archived. The next refresh of the
Wallabag feed in FreshRSS will then no longer surface it.

> Direction: **FreshRSS → Wallabag**. Reading in FreshRSS archives in Wallabag.
> The reverse direction is not required because Wallabag's unread Atom feed
> already filters out archived entries on its next pull.

## How it works

1. The extension exposes a per-user config page where you enter your Wallabag
   base URL and API credentials (client id/secret + username/password).
2. A small JavaScript bridge runs alongside FreshRSS's UI. It watches entry
   elements (`.flux`) for transitions from `not_read` to `read` via a
   `MutationObserver`.
3. For each entry that flips to read, the bridge inspects the entry's link.
   If it points at your configured Wallabag instance and matches the
   `/view/{id}` URL pattern, the Wallabag entry id is POSTed to the extension.
4. The PHP side obtains (and caches/refreshes) an OAuth2 access token using
   the `password` grant, then calls
   `PATCH {wallabag}/api/entries/{id}.json` with body `{"archive": 1}`.

Read events triggered locally in FreshRSS — clicking, scrolling past with
"mark as read on scroll", "mark all as read", keyboard shortcuts — all flip
the entry element class, so all of them sync.

## Installation

1. Copy the `xExtension-WallabagSync` directory into your FreshRSS
   `extensions/` folder.
2. Restart your FreshRSS instance (or refresh the extensions page).
3. Go to **Settings → Extensions** and enable **Wallabag Sync**.
4. Click **Configure** on the extension and fill in:
   - **Wallabag base URL** — e.g. `https://wallabag.example.com` (no trailing slash)
   - **Client ID / Client secret** — create one in Wallabag at
     *Settings → API clients management → Create a new client*
   - **Wallabag username / password**
5. Save. The password is used once to obtain an OAuth token; the token is
   cached in your FreshRSS user config and refreshed automatically.

## Setting up the Wallabag feed in FreshRSS

In Wallabag, go to **Config → RSS** and generate a token. Subscribe FreshRSS
to the unread feed:

```
https://wallabag.example.com/feed/<USER>/<TOKEN>/unread
```

The entries in this feed have `<link rel="alternate">` pointing at
`/view/{id}` on your Wallabag host — that is what this extension matches.

## Troubleshooting

- Open your browser console and look for `[WallabagSync]` warnings.
- The extension writes failures to FreshRSS's log via `Minz_Log::warning`.
- If you revoke or rotate your Wallabag API client, re-enter the credentials
  in the config page; cached tokens will be cleared on save.

**Nothing happens when I mark a Wallabag entry as read.** The whole detection
chain depends on the Atom feed exposing `<link rel="alternate" href=".../view/{id}">`.
Verify directly:

```sh
curl -s 'https://wallabag.example.com/feed/USER/TOKEN/unread' | grep -E '<(link|id)'
```

You should see `<link rel="alternate" type="text/html" href="https://wallabag.example.com/view/123"/>`
on each entry. Then inspect a rendered entry in FreshRSS (DevTools) and
confirm the `.flux`'s primary link anchor (`a.item-element`, `h1.title a`, or
`a.link`) carries that same `/view/{id}` href. If FreshRSS is rendering the
original article URL (Wallabag's `rel="via"`) instead, the regex match will
never fire and no sync will happen — that's the most likely silent failure.

## Scope and limitations

- Sync direction is one-way (FreshRSS → Wallabag). Archiving in Wallabag does
  not un-read entries in FreshRSS.
- Detection relies on the Wallabag base URL + `/view/{id}` link shape. If you
  reverse-proxy Wallabag at a sub-path, configure the base URL to include it.
- Credentials are stored in plaintext in the FreshRSS user data dir, same as
  other FreshRSS per-user extension settings.
