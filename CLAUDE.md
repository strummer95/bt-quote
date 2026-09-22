# BT Quote

Boomer T's Quick Quote tool. A public quoting form at `/quote/` plus a `boomerts/v1/price`
endpoint that other BT plugins call. It is the **single pricing engine** for the shop.

- Live: boomerts.com/quote/ via `[bt_quick_quote]`
- Current version: **0.4.1**. Constant `BTQ_VERSION`, function prefix `btq_`.
- Repo: `strummer95/bt-quote`

## Environment

**Boomer T's Ink & Thread is a separate company from Duck and Rabbit Co.** Dillon's dad's
shop. **AWS Lightsail Bitnami WordPress + Elementor, NOT IONOS.** Never conflate with
PresStora.

**Dillon works only through the WordPress dashboard.** No SSH, no SFTP. Everything ships as
a plugin update.

Brand: navy `#27267e`, pink/magenta `#e535ab`, Oswald.

## Release process

Four places must match or WordPress loops forever trying to reinstall:

1. `Version:` in `bt-quote/bt-quote.php`
2. `BTQ_VERSION` in the same file
3. `version` in `manifest.json`
4. The version inside the zip

Steps: edit under `bt-quote/`, bump both version spots, `node --check` touched JS
(no PHP binary in the container, brace-audit by hand), build `bt-quote-X.Y.Z.zip` plus
plain `bt-quote.zip` at the repo root, update `manifest.json` with the version, the
**versioned** raw `download_url` and a changelog entry, commit and push to `main`. Dillon
then does **BT Quote → Check for updates** (the panel at the bottom of the BT Quote page),
then **Plugins → Update Now**.

`uploads.github.com` is blocked from the container, which is why releases use versioned raw
zips instead of GitHub Release assets. The updater reads `manifest.json` through
`api.github.com` with `Accept: application/vnd.github.raw`, so a push is live instantly.

`includes/bt-admin.php` is byte-identical across bt-portal, bt-catalog, bt-quote,
bt-accounts and bt-dtf. Don't fork it; re-copy into all five in the same release round if
it changes.

**Where the update check lives is a fixed rule across the BT plugins:** the shared panel
is the last thing on the plugin's own top-level admin page. Never a separate Updates
submenu. BT Transfers was the one exception until 0.7.5 and it is not coming back.

## This plugin is a dependency

BT Portal's Quote tab renders **this plugin's** `[bt_quick_quote]` shortcode, and BT
Catalog's quote step posts to **this plugin's** `boomerts/v1/price` endpoint. There is one
quote UI and one pricing engine, deliberately. The portal used to carry its own copy and it
drifted until every request came back `400 invalid_garment`. 189 lines of duplicated quote
code were deleted to fix that. **Do not let a copy of this UI or these tables exist
anywhere else.**

Consequences when changing the endpoint contract:

- It takes garment **IDs** (`g5000`, `bc3001`, `supplied`, `custom`), not generic labels.
- `embroidery`, not `emb`. `embType`, not `emb_type`. `retail`, not `garment_cost`.
- The response is `{perShirt, total, discPct, breaks}`. Callers read `perShirt`.
- Print takes `locations` 1 to 3. Embroidery takes `embType` of text, logo or hard.
- Embroidery above the by-quote minimum returns `perShirt = null`, which callers render as
  "By Quote" rather than an error.

Any change here needs the portal and the catalog checked in the same pass.

## Embedding rules

Two bugs came from this plugin being embedded rather than standing alone, both fixed, both
easy to reintroduce:

- **Don't hijack the host page's URL.** The tool syncs filters into the address bar on
  `/quote/` by design. When it is one tab of the portal, that rewrote the portal URL to
  `/employees/?qty=4&g=supplied&loc=2` and it stuck through refreshes and bookmarks. It is
  now told not to sync when embedded (0.4.1).
- **Copy Quote Link must build a `/quote/` link**, not a link off whatever page it is on.
  It was handing staff a link to the employee portal, which customers cannot open.

The portal enqueues this plugin's CSS and JS itself, in order, and dequeues the copies the
shortcode queues so nothing loads twice. If you change how assets are enqueued here, check
the portal's Quote tab.

Also: no bare `bt-` ids or classes. A shared `btModalOverlay` id between this plugin and
the portal once made clicking a job card silently open this tool's hidden overlay.

## Structure

`bt-quote.php` main · `includes/pricing.php` the tables and the math ·
`includes/pricing-admin.php` the admin editor · `includes/shortcode.php`
`[bt_quick_quote]` · `includes/submit.php` · `includes/admin.php` ·
`includes/bt-admin.php` · `includes/updater.php` · `assets/quick-quote.css` /
`quick-quote.js`

Pricing is locked factory defaults with admin overrides layered on top, with percent tools
and per-section plus full reset. Sections: `PRINT_TIERS`, `LOC2_TIERS`, `LOC3_TIERS`,
`EMB_TEXT_TIERS`. `BREAKS` is the quantity ladder.

## Open pricing bug, found Aug 4 2026, nothing changed yet

`PRINT_TIERS` has **two curves stitched together**, which creates price inversions where
ordering more shirts costs less per piece, and in one case less in total:

- 192 shirts is $766, cheaper per piece than any larger quantity
- 204 shirts is $938, which is **$171 more than 192** for 12 more shirts
- The same seam appears at 96/108 and at 288/300

Two ways to fix it: pull the 204 to 300 block down onto the lower curve, or raise the 144
to 192 block up onto the higher curve. Which one is a **business decision** and needs
Dillon's real print costs and target margin.

Do not adjust these numbers on your own initiative. When Dillon is ready to resume, produce
the full corrected table with before and after at every break, plus a monotonicity check
proving no inversion remains.

## Working notes

- Compact, always. Text sizing errs UP: table body 15px or larger, badges 13px or larger.
- Terse and results-first. Ship the deliverable, not narration.
- The garment dropdown is generated from the pricing tables at render time so it cannot
  drift from the engine. Keep it that way.
