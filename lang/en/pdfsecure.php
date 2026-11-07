<?php
$string['pluginname'] = 'PDF Secure';
$string['modulename'] = 'PDF Secure';
$string['modulename_help'] = 'The PDF Secure module delivers course PDFs through an authenticated route, so the uploaded '
    . 'file has no URL of its own and the viewer opens without download or print controls. By default it also stamps every page '
    . 'with the name of the user who opened it: a document that can be read can always be copied, '
    . 'and what the stamp guarantees is that any copy in circulation identifies the account it came from. Sites whose workstations '
    . 'already apply their own watermark can turn the stamp off in the plugin settings and keep everything else.';
$string['modulenameplural'] = 'PDF Secures';
$string['pluginadministration'] = 'PDF Secure Administration';
$string['pdfsecurename'] = 'PDF Name';
$string['pdfsecurename_help'] = 'This is the name of the link that students will see on the course page.';
$string['settingonlysecure'] = 'PDFs only as PDF Secure';
$string['settingonlysecure_desc'] = 'When enabled, blocks PDFs in Moodle File and Folder forms, offers PDF Secure for drag-and-drop PDFs, '
    . 'and converts PDFs created through other paths, including installed PDFProtect activities. Existing activities are kept but hidden after their PDFs are confirmed in PDF Secure. '
    . 'This covers File, Folder and PDFProtect activities; it does not change PDFs in forum posts, assignment submissions, interactive videos or certificates.';
$string['onlysecurevalidation'] = 'PDF Secure accepts several PDFs in one activity. Add an activity or resource > PDF Secure, then add all PDFs there. Remove the PDF from this File or Folder.';
$string['dnduploadpdfsecure'] = 'Create a PDF Secure activity (protected PDF)';
$string['dnduploadzipsecure'] = 'Create one PDF Secure activity from the PDFs in this ZIP';
$string['taskconvertplainpdfs'] = 'Convert a source activity PDF to PDF Secure';
$string['tasksweepplainpdfs'] = 'Sweep supported activities for PDFs';

// Strings for the form (Step 2)
// watermarktext / enablewatermark / enablewatermark_help were removed in v2.1.0:
// they were orphans with no setting, no form field and no database column behind
// them, and once a real stamp switch existed they read like the control for it.
$string['contentheader'] = 'PDF Document';
$string['selectfile'] = 'Select PDF files';
$string['cannotstamp'] = 'This document could not be prepared for viewing. Please report this to the site administrator.';

// Whether to stamp at all.
$string['settingstampmode'] = 'Watermark the documents';
$string['settingstampmode_desc'] = 'Whether this site burns a per-reader watermark into every PDF it serves. Turn it off only '
    . 'where the workstations already apply their own watermark - a second mark on top of the first adds no traceability and '
    . 'makes the document harder to read. Turning it off changes nothing else: the uploaded file still has no URL of its own, '
    . 'readers still have to be logged in and enrolled, the viewer still hides download and print, and the document text is still '
    . 'indexed for Global Search. It also removes the PDF rewriting step, so encrypted documents and files with links, bookmarks '
    . 'or form fields are served intact instead of being refused or flattened.';
$string['stampmodefull'] = 'Yes - stamp every page with the reader\'s identity';
$string['stampmodeoff'] = 'No - serve the document unmarked (for sites with endpoint watermarking)';

// Watermark appearance settings.
$string['settingtone'] = 'Watermark tone';
$string['settingtone_desc'] = 'How dark the diagonal watermark is. Darker is harder to remove from a screenshot but harder to read '
    . 'the document through; lighter reads better but survives a low-quality photo less well.';
$string['tonestrong'] = 'Strong (most visible)';
$string['tonemedium'] = 'Medium';
$string['tonelight'] = 'Light (recommended)';
$string['tonefaint'] = 'Faint (least intrusive)';
$string['settingfontsize'] = 'Watermark text size';
$string['settingfontsize_desc'] = 'Size of the repeated diagonal text. Larger text is more legible in a photo of the screen but '
    . 'covers more of the page.';
$string['settingincludedate'] = 'Include date in the diagonal watermark';
$string['settingincludedate_desc'] = 'Adds the date and time of access next to the name and email. The footer stamp always carries '
    . 'the date regardless of this setting.';
$string['settingshowfooter'] = 'Show footer stamp';
$string['settingshowfooter_desc'] = 'A small legible line at the bottom of every page with the full identity, user id, activity id '
    . 'and timestamp, on a white band so it stays readable over dark page designs. This is the stamp meant to be read by eye when '
    . 'tracing a leak.';

// Retention of generated copies.
$string['taskprune'] = 'Clean up cached copies from older versions';

// Global Search.
$string['search:activity'] = 'PDF Secure - activity and document text';

$string['settingmaxsize'] = 'Largest document to stamp';
$string['settingmaxsize_desc'] = 'A document is held in memory while it is stamped, and that happens on every view. Anything '
    . 'larger than this is refused rather than allowed to exhaust the PHP memory limit on each read. Raise it only alongside the '
    . 'PHP memory limit.';

$string['completionreadall'] = 'Read to the end';
$string['completionreadall_help'] = 'A PDF counts as read when the learner views at least 90% of its pages, rounded up, and the last page. '
    . 'Choose whether that applies to every PDF or any one PDF. Jumping straight to the last page does not complete a PDF.';
$string['completiondisabled'] = 'Do not track reading';
$string['completionallfiles'] = 'Read all pages of every PDF';
$string['completionanyfile'] = 'Read all pages of any one PDF';
$string['completiondetail:allfiles'] = 'Read at least 90% of the pages and the last page of every PDF.';
$string['completiondetail:anyfile'] = 'Read at least 90% of the pages and the last page of any one PDF.';
$string['selectdocument'] = 'Choose a PDF to read.';
$string['pagesread'] = '{$a->read} of {$a->total} pages read';
$string['readstatecomplete'] = 'Read';
$string['readstateincomplete'] = 'Not read yet';
$string['eventcoursemoduleviewed'] = 'PDF Secure activity viewed';

$string['privacy:exportpath'] = 'PDF reading progress';
$string['privacy:watermarkedpath'] = 'Watermarked PDF derivatives';
$string['privacy:metadata:pdfsecure_progress'] = 'PDF Secure stores per-user reading progress for each PDF activity.';
$string['privacy:metadata:pdfsecure_progress:pdfsecureid'] = 'The activity whose PDF was read.';
$string['privacy:metadata:pdfsecure_progress:userid'] = 'The user who viewed the pages.';
$string['privacy:metadata:pdfsecure_progress:filekey'] = 'The PDF filename whose pages were viewed.';
$string['privacy:metadata:pdfsecure_progress:pages'] = 'The total page count reported by the viewer.';
$string['privacy:metadata:pdfsecure_progress:seen'] = 'The distinct page numbers reported as viewed.';
$string['privacy:metadata:pdfsecure_progress:timemodified'] = 'The time when reading progress was last updated.';
$string['privacy:metadata:pdfsecure_progress:timecompleted'] = 'The time when the read-to-end rule was first met.';
$string['privacy:metadata:watermarked'] = 'PDF Secure can store per-user watermarked PDF derivatives in Moodle file storage.';
$string['resetprogress'] = 'Delete reading progress';
$string['pdfsecure:addinstance'] = 'Add a new PDF Secure activity';
$string['pdfsecure:view'] = 'View PDF Secure activities';
$string['errorcannotstamp'] = "This PDF cannot be used: the site could not read it to apply the reader's watermark. It may be password-protected or damaged. Remove the protection or export it again, then upload it.";
$string['errortoobigtostamp'] = "This PDF is larger than the size the site can watermark for readers. Upload a smaller file, or ask an administrator to raise the limit.";
$string['cachedef_pagecount'] = 'Page count cache for PDF Secure files';
$string['errorpagesmismatch'] = 'The page total does not match this PDF. Reload the page.';
$string['errorpagesnodocument'] = 'This activity has no document to read.';
$string['errorfileinvalid'] = 'That PDF does not belong to this activity.';
$string['errorfiletype'] = 'Only PDF files can be added to this activity.';
$string['errorzipunsupported'] = 'ZIP upload is unavailable because the server does not support ZIP archives.';
$string['errorzipinvalid'] = 'This ZIP archive could not be read safely.';
$string['errorzipentry'] = 'The ZIP must contain only top-level PDF files; folders and other file types are not accepted.';
$string['errorzipencrypted'] = 'Encrypted ZIP entries are not accepted.';
$string['errorzipsize'] = 'This ZIP exceeds the limit of 50 entries or 500 MB uncompressed.';
$string['errorzipnovalidpdfs'] = 'The ZIP contains no PDF that can be added to PDF Secure.';
$string['cachedef_viewtokens'] = 'Short-lived PDF viewer tokens';
$string['fullscreen'] = 'Enter fullscreen';
$string['exitfullscreen'] = 'Exit fullscreen';
$string['toolbarlabel'] = 'Viewer controls';
$string['thumbnails'] = 'Thumbnails';
$string['previouspage'] = 'Previous page';
$string['nextpage'] = 'Next page';
$string['zoomin'] = 'Zoom in';
$string['zoomout'] = 'Zoom out';
$string['fitwidth'] = 'Fit to width';
$string['pagecounter'] = 'Page {n} / {total}';
