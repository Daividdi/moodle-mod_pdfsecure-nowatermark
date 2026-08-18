<?php
$string['pluginname'] = 'PDF Secure';
$string['modulename'] = 'PDF Secure';
$string['modulename_help'] = 'The PDF Secure module delivers course PDFs through an authenticated route, so the uploaded file has no URL of its own and the viewer opens without download or print controls. By default it also stamps every page with the name of the user who opened it: a document that can be read can always be copied, and what the stamp guarantees is that any copy in circulation identifies the account it came from. Sites whose workstations already apply their own watermark can turn the stamp off in the plugin settings and keep everything else.';
$string['modulenameplural'] = 'PDF Secures';
$string['pluginadministration'] = 'PDF Secure Administration';
$string['pdfsecurename'] = 'PDF Name';
$string['pdfsecurename_help'] = 'This is the name of the link that students will see on the course page.';

// Strings for the form (Step 2)
// watermarktext / enablewatermark / enablewatermark_help were removed in v2.1.0:
// they were orphans with no setting, no form field and no database column behind
// them, and once a real stamp switch existed they read like the control for it.
$string['contentheader'] = 'PDF Document';
$string['selectfile'] = 'Select the PDF file';
$string['cannotstamp'] = 'This document could not be prepared for viewing. Please report this to the site administrator.';

// Whether to stamp at all.
$string['settingstampmode'] = 'Watermark the documents';
$string['settingstampmode_desc'] = 'Whether this site burns a per-reader watermark into every PDF it serves. Turn it off only where the workstations already apply their own watermark - a second mark on top of the first adds no traceability and makes the document harder to read. Turning it off changes nothing else: the uploaded file still has no URL of its own, readers still have to be logged in and enrolled, the viewer still hides download and print, and the document text is still indexed for Global Search. It also removes the PDF rewriting step, so encrypted documents and files with links, bookmarks or form fields are served intact instead of being refused or flattened.';
$string['stampmodefull'] = 'Yes - stamp every page with the reader\'s identity';
$string['stampmodeoff'] = 'No - serve the document unmarked (for sites with endpoint watermarking)';

// Watermark appearance settings.
$string['settingtone'] = 'Watermark tone';
$string['settingtone_desc'] = 'How dark the diagonal watermark is. Darker is harder to remove from a screenshot but harder to read the document through; lighter reads better but survives a low-quality photo less well.';
$string['tonestrong'] = 'Strong (most visible)';
$string['tonemedium'] = 'Medium';
$string['tonelight'] = 'Light (recommended)';
$string['tonefaint'] = 'Faint (least intrusive)';
$string['settingfontsize'] = 'Watermark text size';
$string['settingfontsize_desc'] = 'Size of the repeated diagonal text. Larger text is more legible in a photo of the screen but covers more of the page.';
$string['settingincludedate'] = 'Include date in the diagonal watermark';
$string['settingincludedate_desc'] = 'Adds the date and time of access next to the name and email. The footer stamp always carries the date regardless of this setting.';
$string['settingshowfooter'] = 'Show footer stamp';
$string['settingshowfooter_desc'] = 'A small legible line at the bottom of every page with the full identity, user id, activity id and timestamp, on a white band so it stays readable over dark page designs. This is the stamp meant to be read by eye when tracing a leak.';

// Retention of generated copies.
$string['taskprune'] = 'Clean up cached copies from older versions';

// Global Search.
$string['search:activity'] = 'PDF Secure - activity and document text';

$string['settingmaxsize'] = 'Largest document to stamp';
$string['settingmaxsize_desc'] = 'A document is held in memory while it is stamped, and that happens on every view. Anything larger than this is refused rather than allowed to exhaust the PHP memory limit on each read. Raise it only alongside the PHP memory limit.';

