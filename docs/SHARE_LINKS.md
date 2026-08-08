# Client share links

A **share link** lets a client see their own project — and nothing else — without an
account. It expires by itself (24 h by default) and can be killed early.

```
admin → project page → "Share with client"
     → mints a token → sends it by WhatsApp / email / copy-link
     → client opens  https://project.vakhariaairtech.com/pms/s/<token>
     → 24 h later the same URL is a dead end, forever
```

---

## 1. What the client sees

The public page (`share.php`) re-uses the panel's own look, but **not** the panel's
queries. Everything on it is built by `ShareData` from a whitelist:

| Shown | Hidden |
|---|---|
| Project label, lifecycle, site type | Submitter identity / account names |
| Current stage, progress %, target end, next activity | PE names (option, off by default) |
| Stage timeline: status, planned, started, completed | Workforce + contractor names |
| Stage-change history with dates | Internal remarks, activity/next-plan free text |
| Amendment / drawing / measurement flags | Risks, alerts, pipeline health, delivery events |
| Site photos, drawings, measurement PDFs | Any other project, other flats, other order ids |

**Hold notes** follow the business rule:

* hold sits with the **client** → spelled out: *“Awaiting input from your side: cable
  drawing approval”* — they have to act on it;
* hold sits with **VAPL** → neutral: *“On hold with our team — being followed up”*.
  The internal detail never leaves the panel.

Implemented in `ShareData::holdNote()`; the per-link option is `hold_detail`
(`client_only` = default, `none`, `all`).

## 2. Scopes

| Scope | Key | The client gets |
|---|---|---|
| `project` | `projects.project_key` | one flat / one site |
| `building` | `B\|<developer>\|<building>` | index of that building's flats, drill into each |
| `developer` | `V\|<developer>` | every building of that developer |

Scope is fixed when the link is minted. `?u=<project_key>` is re-checked against the
scope on every request, so a token for one flat cannot open its neighbour.

General (non-developer) sites are always `project` scope — there is nothing to widen to.

## 3. Security model

The link **is** the credential. Everything below follows from that.

* **Token** — 32 random bytes → 43-char URL-safe string (256 bits). Only its SHA-256 is
  stored, so a database dump yields no working links. Lookup is `hash_equals` on the hash.
* **Expiry** — hard, from mint time (`share.ttl_hours`, default 24, max 168). Enforced on
  every request, including file downloads.
* **Revoke** — admin → **Shared Links** → *Revoke*. Immediate.
* **View cap** — `share.max_views` (default 300) caps a forwarded link's blast radius.
* **Bots never count and never burn** — WhatsApp/Slack link previews, Outlook Safe Links,
  Gmail's image proxy all fetch the URL automatically. They are logged as `bot` and
  excluded from the view count (`ShareLink::isBot()`).
* **Headers** — `no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`,
  `X-Frame-Options: DENY`, strict CSP, `nosniff`. The token cannot leak through a
  Referer header, a search index or a shared cache.
* **Logs** — with the `/pms/s/<token>` route, nginx writes a masked line
  (`/pms/s/***`), so a live credential never lands in `/var/log`.
* **Files stay private on Drive** — photos and PDFs are streamed through `share.php`
  with the service account's own token (`Drive::download()`), chunk by chunk.
  `makeLinkViewable()` is deliberately **not** used for shares: that permission is
  permanent and public, so it would outlive the 24-hour link.
* **File scope check** — `?f=<id>` re-derives the whole scope server-side, including the
  `Flat<TAG>_` filename filter, so a multi-flat visit cannot hand over a neighbour's photos.
* **Probing** — 60 unknown tokens per IP per hour, then the page stops answering. Expired,
  revoked and unknown all render the same generic page: no oracle.
* **No session, ever** — `share.php` never loads the admin bootstrap and never starts the
  `PMSADMIN` session; there is no path from the public page into the panel.
* **Audit** — `share_events` records created / sent / view / bot / download / denied /
  revoked, with recipients **masked** (`ya***@gmail.com`, `91****3893`) and IPs stored
  only as a salted hash.

### Who may share

Sharing is its own permission (`admin_users.can_share`), not a role:

* **admin** accounts have it implicitly (they can grant it to themselves anyway);
* **viewer** accounts get it from admin → **Admin Users** → *Client sharing*;
* it is re-read from the DB on every page load, so revoking takes effect immediately,
  not at next login.

### Residual risk

A client can forward the link. That cannot be prevented by a bearer link — it is
mitigated (24 h, view cap, revoke, one scope, full audit), not eliminated. If a stronger
guarantee is needed for developer-wide links, add a second factor (e.g. "enter the last
4 digits of the phone this was sent to") — one extra column and one form.

## 4. Delivery

**WhatsApp** — template `project_progress_link` (UTILITY, `en`), submitted to WABA
`1568163707846136`. Body vars are positional: `{1}` client name, `{2}` project,
`{3}` stage, `{4}` percent. The token rides as the **URL button suffix**, so the host is
fixed by the approved template:

```
https://project.vakhariaairtech.com/pms/s/{{1}}
```

Re-submit or inspect with `php scripts/create_share_template.php --list | --create <base>`.

**Email** — SMTP, one recipient per message, no CC (a CC would hand the same credential
to more inboxes). Subject: `Project progress: <label> (link valid 24 hours)`.

Both honour the existing OFF/TEST/LIVE switches; the dialog warns when a channel is not
LIVE so nothing quietly goes to the test inbox instead of the client.

## 5. Configuration

`config/app.php → 'share'` (shared defaults):

| Key | Default | Meaning |
|---|---|---|
| `ttl_hours` | 24 | link lifetime |
| `max_views` | 300 | per-link view cap |
| `retain_days` | 30 | purge dead links after |
| `wa_template` | `project_progress_link` | approved template name |
| `waba_id` | `1568163707846136` | WhatsApp Business Account |
| `defaults` | photos on, PE names off, `hold_detail: client_only` | per-link defaults |

`config/secrets.php → 'share'` (per server, gitignored):

```php
'share' => [
    'base_url'   => 'https://project.vakhariaairtech.com/pms',  // blank on dev
    'link_style' => 'path',      // 'path' on the server, 'query' on XAMPP
],
```

`link_style: path` **requires** the `/pms/s/` nginx location (DEPLOY_GCP.md §9) and must
match the URL baked into the approved template.

Two nginx gotchas, both hit during the first prod rollout:

* the location regex must be **quoted** — unquoted, nginx parses `{43}` as a config
  block and refuses to start (`pcre2_compile() failed: missing closing parenthesis`);
* it must include **`fastcgi_params`**, not `snippets/fastcgi-php.conf` — that snippet
  ends with `try_files $fastcgi_script_name =404`, and since no file exists at
  `/pms/s/<token>`, nginx 404s the request before PHP ever runs.

## 6. Files

| File | Role |
|---|---|
| `share.php` | the public page + file proxy |
| `src/ShareLink.php` | mint / resolve / revoke / audit / purge |
| `src/ShareData.php` | client-safe projection + scope enforcement |
| `src/ShareSender.php` | email + WhatsApp delivery, contacts on file |
| `admin/inc/share_modal.php` | the Share dialog |
| `admin/share_action.php` | mint + send endpoint (CSRF, permission, audit) |
| `admin/shares.php` | live links, activity trail, revoke |
| `db/share_schema.sql` | `share_links`, `share_events`, `admin_users.can_share` |
| `scripts/share_mint.php` | CLI mint (testing/support) |
| `scripts/share_purge.php` | nightly housekeeping |
| `scripts/create_share_template.php` | WhatsApp template create/list |

## 7. Edge cases handled

1. **Bot prefetch** — logged, never counted, never burns the link.
2. **Expiry mid-session** — a click after expiry gets the "link expired" page (410), not an error.
3. **Multi-flat visits** — `Flat<TAG>_` filtering on both the page and the file proxy.
4. **Late order id** (`G|name` → `O|order id`) — links also carry `project_id`, so they survive the re-key.
5. **Drive file trashed / SA access lost** — "File unavailable right now", never the API error.
6. **Large PDFs** — streamed chunk by chunk; no `memory_limit` death.
7. **No GD on the box** — thumbnails silently fall back to the full image.
8. **Contacts unavailable** (Orders sheet 403 / slow) — dialog still opens, contacts load async, manual entry always available.
9. **Channel in TEST** — the dialog says so before you send.
10. **Revoked link already in a chat** — clean "no longer active" page.
11. **New flat added inside a developer-scope window** — it becomes visible; that is the point of the scope, and it is stated in the dialog.
12. **Deleted/renamed project** — `scopeProjects()` returns nothing → generic not-found page.
