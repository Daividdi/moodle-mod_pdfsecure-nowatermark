# PDF Secure (mod_pdfsecure)

A Moodle activity module that serves course PDFs through an authenticated route the
uploaded file itself never has, with a **per-user watermark burned into the file**.

The watermark can be switched off for sites that already watermark at the endpoint —
see [Turning the watermark off](#turning-the-watermark-off). Everything below
describes the default, stamping mode.

![Moodle](https://img.shields.io/badge/Moodle-4.5%20%E2%80%93%205.3-orange.svg)
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

1. A teacher uploads one or more PDFs to the activity as usual, or drags a ZIP
   containing top-level PDFs onto the course. The original files are stored
   untouched and are never served.
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

- Ghostscript, at the path in *Site administration › Server › System paths* (`pathtogs`), to stamp PDFs the
  bundled parser cannot read

**PDFs FPDI cannot read (v2.3.1).** The free FPDI parser refuses PDF 1.5+ cross-reference streams, which most
current exporters write. Such a file is rewritten once with Ghostscript into the `normalized` file area, which has
no URL, and every reader's copy is stamped from it. The upload stays untouched in `content`. To diagnose a PDF that
gives a 404, call `watermarker::render_for()` from the CLI and read the exception.

**What is still refused:** a password-protected or damaged PDF, a file over the size limit, and any file needing a
rewrite when Ghostscript is missing. The activity form and drag-and-drop refuse these with a message. A file that
reaches the viewer anyway fails closed: it is never served unstamped.

**The viewer (v2.3.2).** `pdfjs-drm` is PDF.js 3.11.174, older than the fix for CVE-2024-4367. Two local changes in
`web/viewer.js` contain it: `isEvalSupported` is off, so glyphs are drawn without compiled code, and the `file`
parameter accepts only this plugin's `stamped` URLs, so the page cannot be pointed at a PDF from elsewhere on the site.
Keep both if the viewer is ever replaced.

**Known limitation of the PDF engine:** importing drops annotations, links, bookmarks and form fields. For reading
material this is usually fine; if your PDFs are interactive forms, test before rolling out.

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

### Only PDF Secure

Turn on **PDFs only as PDF Secure** to keep PDFs in Moodle's native **File** and
**Folder** activities from being served as ordinary files. The setting is off by
default, so an upgrade keeps the existing behavior until an administrator enables it.

With the setting on, Moodle rejects a PDF in a File or Folder activity form before
the activity is saved and offers PDF Secure when a `.pdf` is dragged onto a course.
Since v2.4.0 the form also rejects a `.zip` that contains a PDF (or that cannot be
read), because a zip would hand learners the same plain files in one download.
If another path creates or updates a File or Folder containing a PDF — such as
drag-and-drop chosen as File, Folder editing, restore, import or a web service — an
event hides that activity at once (v2.4.0) and queues conversion, and an hourly
sweep catches anything an event missed. Hiding first means a plain PDF is never
visible to learners while it waits for the conversion task. A
PDFProtect activity is also converted when that module is installed. A
File becomes one PDF Secure activity immediately after it, with its visibility,
availability and completion carried over. Each PDF in a Folder gets
its own activity in folder order. The original activity is kept and hidden only
after the protected copy is confirmed; a Folder stays visible while it contains
anything besides PDFs, and confirmed PDFs are removed from a mixed Folder while its
other files remain.

This applies to Moodle's File and Folder activities and installed PDFProtect
activities. It does not change PDFs in forum posts, assignment submissions,
interactive videos or certificates. The
`tools/migrate_pdf.php` administrator migration remains available for an explicit
run on `resource`, `folder` and installed `pdfprotect` activities.

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

## Several PDFs and ZIP uploads (v2.4.0)

One PDF Secure activity can hold several PDFs. With one document, opening the
activity still opens the protected viewer directly. With several, the activity
shows each filename, pages read out of the total, and whether that PDF is read.
Each file opens in the same protected viewer and has separate reading progress.

**To group several articles (the "folder" use case),** don't use a Folder: add one
PDF Secure activity and put all the PDFs in its file box, or drag a ZIP of them onto
the course. Dragging several loose PDFs at once still creates one activity per file;
that is how Moodle core handles a multi-file drop.

Reading progress is stored per file, keyed by file name inside the activity
(`pdfsecure_progress.filekey`), not by stored-file id, so it survives backup and
restore. The v2.4.0 upgrade fills the key for existing progress from the single PDF
each activity had, so nobody loses what they already read.

Dragging a ZIP onto a course creates one PDF Secure activity named after the ZIP.
The archive must contain only top-level PDF files; nested folders, other file
types, and encrypted entries are rejected. It is limited to 50 entries and 500 MB
of total uncompressed data. PDFs that cannot be stamped or exceed the site's stamp
size limit are skipped; an archive with no valid PDFs fails.

### Editing, deleting and ordering files (v2.4.1)

**Edit settings** shows the PDFs already in the activity, so a teacher can delete,
rename or add files there. The list learners see is sorted by file name in natural
order ("2 ..." before "10 ..."): to choose the order, number the names (rename a
file by clicking it in the file box). Renaming a file starts its reading progress
again, because progress is kept per file name.

The file **Author** field is hidden in this form and cleared on save: Moodle fills
it with the uploader's name, which is not the article's author.

## Completion: reading to the end (v2.2.0; multi-file modes in v2.4.0)

An activity can complete on view, or only when the person has **read the document**:
at least 90 % of its pages, the last one included. The pdf.js viewer reports the pages
shown, and a page counts once it has stayed in front of the reader for 1.5 seconds,
so scrolling straight to the end reads nothing in between. Progress is kept per person
(`pdfsecure_progress`), backed up with user data and exported/deleted by the privacy
API, as are the per-person stamped copies.

What it does not do: prove the reading. Since v2.3.2 the server reads the page total
from the stored PDF and refuses any other figure, so nobody can claim a one-page
document. The pages seen still come from the browser, so someone forging the web
service call by hand can report every page: the signal stays attested by the client.
For internal training that is an accepted limit; the gamification on top of it
(local_escalada) rewards the completion like any other.

If the server cannot read a PDF at all (possible only with stamping off, and a file
needing a rewrite while Ghostscript is missing), it falls back to the viewer's total for
that file. Readers can still complete it, and the forged-total check does not apply.

For an activity with several PDFs, choose whether the rule requires reading every
file or any one file. The 90% and last-page rule applies to each required PDF.
Switch it on per activity under *Completion conditions → Read to the end*, or for
many activities at once with Moodle's bulk edit of activity completion.

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
