# PDF Secure (mod_pdfsecure)

A Moodle activity module that serves course PDFs through an authenticated route the
uploaded file itself never has, with a **per-user watermark burned into the file**.

The watermark can be switched off for sites that already watermark at the endpoint —
see [Turning the watermark off](#turning-the-watermark-off). Everything below
describes the default, stamping mode.

![Moodle](https://img.shields.io/badge/Moodle-4.5%20%E2%80%93%205.2-orange.svg)
![License](https://img.shields.io/badge/License-GPLv3-blue.svg)

**Author:** Daividdi

---

## What this does, and what it does not

Read this part before deciding whether the plugin is right for you.

A document a browser can display is a document the reader can copy. There is no
configuration, no plugin and no commercial DRM that changes this — a screenshot, a
screen recording or a phone camera defeats all of them. Anything claiming otherwise
is selling reassurance, not protection.

So this module does not try to prevent copying. It makes copies **attributable**:

**It does:**

- Stamp every page with the name, email, user id, activity id and access time of
  the account that opened it — written into the PDF's page content, not overlaid by
  the browser, so it survives download, re-upload and forwarding.
- Repeat the same identity in the PDF metadata (`Author`, `Subject`), so a leaked
  file can be traced by tooling without reading a single page.
- Refuse to serve the original upload. The raw file is never reachable over HTTP,
  by any URL, for any user.
- Bind each generated copy to one account. Editing the user id in the URL returns
  404, so nobody can fetch a colleague's stamped copy and frame them with it.
- Fail closed. If a PDF cannot be stamped, the reader gets an error — never the
  unstamped original.

**It does not:**

- Prevent screenshots, screen recordings or photographs of the screen.
- Prevent a determined user from saving what the viewer renders.
- Protect the file once it has legitimately left your site by another route — for
  example a course backup. If your teachers can download `.mbz` archives, they can
  extract every original PDF, and no viewer-side control changes that. Restrict
  `moodle/backup:downloadfile` if that matters to you.

The honest summary: **it will not stop a leak, it will tell you whose account it
came from, and when they read it.** In practice that is the control that changes
behaviour.

Note the asymmetry with video: a stamped PDF carries its mark *inside the file*, so a
copy that is downloaded and passed on is still traceable. An on-screen overlay — the
usual approach for video — only survives a screen recording, not a downloaded file.
This module is the stronger of the two because the mark is in the bytes.

---

## How it works

1. A teacher uploads a PDF to the activity as usual. The original is stored
   untouched and is never served.
2. **On every view**, the plugin renders a personalised copy with
   [FPDI](https://www.setasign.com/products/fpdi/about/) — a tiled diagonal mark
   plus a legible footer stamp — and streams it. Nothing is stored.
3. The document is displayed in a bundled [PDF.js](https://mozilla.github.io/pdf.js/)
   viewer with its download and print controls removed.

The viewer-side controls are a speed bump for the casual user, nothing more. The
watermark is the part that actually holds.

### Why nothing is cached

An earlier version stored one stamped copy per user and reused it. That froze the
timestamp at the moment of that user's **first** view: someone opening a document in
November carried a stamp from the day in August they first looked at it. For a mark
whose job is tracing a leak, a stale time is worse than none — it points at the wrong
moment with the same confidence as the right one.

Rendering per view costs about 0.02 s and 8 MB for typical course documents. That is
cheaper than the storage it replaces, which grew with users x documents x time and
had no ceiling — on one live site it projected to roughly 14 GB.

**`Accept-Ranges: none` is load-bearing.** FPDI output is not byte-identical between
runs, so a browser fetching byte ranges would stitch fragments of separately
generated files into one corrupt PDF. PDF.js ships with `disableRange = false` and
would do exactly that, so the header is what makes per-view rendering safe. Do not
remove it.

The reader's identity comes from the **session**, not from the URL. There is nothing
in the address that selects whose name is burned in, so nothing to tamper with in
order to obtain a copy stamped with a colleague's name.

---

## Requirements

- Moodle 4.5 or later (tested on 4.5 and 5.2, including the 5.x split webroot)
- PHP 8.1+ with `iconv` supporting `//TRANSLIT`

FPDI and FPDF are bundled in `vendor/` — no Composer step is needed on the server.

**Known limitation of the PDF engine:** FPDI cannot read encrypted PDFs, and
importing drops annotations, links, bookmarks and form fields. For reading material
this is usually fine; if your PDFs are interactive forms, test before rolling out.

---

## Installation

```bash
cd /path/to/moodle/mod          # Moodle 5.x: /path/to/moodle/public/mod
git clone https://github.com/Daividdi/moodle-mod_pdfsecure.git pdfsecure
php admin/cli/upgrade.php --non-interactive
```

The directory **must** be named `pdfsecure`.

## Settings

*Site administration → Plugins → Activity modules → PDF Secure*

| Setting | Default | Notes |
| --- | --- | --- |
| Watermark the documents | Yes | Turn off where the workstations already watermark — see below. The appearance settings disappear when it is off. |
| Watermark tone | Light | Darker survives a low-quality photo better; lighter reads through more comfortably |
| Watermark text size | 11 pt | Larger is more legible in a photo of the screen, and covers more of the page |
| Include date in the diagonal watermark | On | The footer stamp always carries the date regardless |
| Show footer stamp | On | The legible line meant to be read by eye when tracing a leak |
| Largest document to stamp | 40 MB | A document is held in memory while stamped, and that happens on every view. Larger files are refused rather than allowed to exhaust the PHP memory limit on each read. |

### Turning the watermark off

Some organisations already watermark at the endpoint — every managed workstation
stamps whatever it displays. On those sites this module's mark lands on top of
theirs. Two overlapping marks make the document measurably harder to read and add
no attribution the organisation did not already have, so **Watermark the documents
→ No** is the right setting there.

It is a deliberately narrow switch. With the stamp off:

- The uploaded file still has no URL of its own. The `content` area is unaddressable
  in both modes; only the module's own delivery route exists.
- Delivery still requires login, enrolment and `mod/pdfsecure:view`, checked on every
  request.
- The viewer still opens without download or print controls.
- The document text is still indexed for Global Search.

Three things genuinely change, and two of them are improvements:

- **No identity in the file.** This is the point of the setting, and the reason to
  only use it where something else provides the mark.
- **Encrypted PDFs work.** FPDI cannot read them, so in stamping mode they fail
  closed and the reader gets an error. Unmarked delivery has no PDF-rewriting step,
  so they are served as uploaded.
- **Links, bookmarks, annotations and form fields survive.** FPDI drops all of them
  on import. Unmarked delivery does not touch the file, and the size ceiling stops
  applying because nothing is held in memory.

The default is **Yes** on purpose: a site upgrading from an earlier version never
saved this setting, and a site that was stamping yesterday must still be stamping
today.

#### A build with the default already inverted

For sites that should never stamp, there is a variant build that ships with the
watermark off and needs no configuration after install:
[moodle-mod_pdfsecure-nowatermark](https://github.com/Daividdi/moodle-mod_pdfsecure-nowatermark).

It is the **same component** with three lines changed, carrying the same
`$plugin->version`, so the two builds replace each other by copying files — no
upgrade, no downgrade, and activities keep working either way. **Reload PHP after
swapping**: OPcache serves the previously compiled `lib.php` until it revalidates,
so for up to `opcache.revalidate_freq` seconds the site does the opposite of what
the files say. Prefer the setting
above if the site is comfortable managing settings; prefer that build if it should
not be possible to forget the step.

**If you change this plugin, that repository has to be re-synced** — it ships a
`sync-from-upstream.sh` for exactly that. A variant left behind is how a security
plugin quietly diverges from the one under review.

---

## Privacy

The plugin writes the viewer's name, email and user id into the documents it
generates, and stores those documents in the Moodle file API. Deployments subject to
GDPR or similar should say so in their privacy policy: this is personal data being
embedded in a file that may leave the platform — which is, after all, the point.

---

## Licence

GPL v3 or later, matching Moodle.

Bundled third-party components keep their own licences: PDF.js (Apache 2.0),
FPDF and FPDI (see `vendor/setasign/`).
