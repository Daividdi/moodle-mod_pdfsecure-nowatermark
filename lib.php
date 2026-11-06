<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Declares the features supported by PDF Secure.
 *
 * @param string $feature Moodle feature constant.
 * @return bool|string|null Feature support value.
 */
function pdfsecure_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_INTRO: return true;
        case FEATURE_SHOW_DESCRIPTION: return true;
        case FEATURE_BACKUP_MOODLE2: return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES: return true;
        case FEATURE_MOD_PURPOSE: return MOD_PURPOSE_CONTENT;
        default: return null;
    }
}

/**
 * Returns information about a PDF Secure activity for the course page cache.
 *
 * @param stdClass $coursemodule Course module record.
 * @return cached_cm_info|false Information for the course page, or false if missing.
 */
function pdfsecure_get_coursemodule_info($coursemodule) {
    global $DB;

    $pdfsecure = $DB->get_record('pdfsecure', ['id' => $coursemodule->instance],
        'id, name, intro, introformat, completionreadall');
    if (!$pdfsecure) {
        return false;
    }

    $info = new cached_cm_info();
    // Stored raw: the course cache is built without a page, and Moodle formats the name where it shows it.
    $info->name = $pdfsecure->name;
    if (!empty($coursemodule->showdescription)) {
        $info->content = format_module_intro('pdfsecure', $pdfsecure, $coursemodule->id, false);
    }

    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionreadall'] = (int)$pdfsecure->completionreadall;
    }

    return $info;
}

/**
 * Creates a PDF Secure activity instance.
 *
 * @param stdClass $pdfsecure Activity data.
 * @param moodleform_mod|null $mform Activity form.
 * @return int New activity instance ID.
 */
/**
 * Blank the Author of the activity's PDFs: Moodle fills it with the uploader's name,
 * which is not the article's author and which teachers kept deleting by hand.
 *
 * @param context_module $context Activity context.
 * @return void
 */
function pdfsecure_clear_authors(context_module $context): void {
    $files = get_file_storage()->get_area_files($context->id, 'mod_pdfsecure', 'content', 0, 'id', false);
    foreach ($files as $file) {
        if ((string)$file->get_author() !== '') {
            $file->set_author('');
        }
    }
}

function pdfsecure_add_instance($pdfsecure, $mform = null) {
    global $DB;
    $pdfsecure->completionreadall = isset($pdfsecure->completionreadall)
        && in_array((int)$pdfsecure->completionreadall, [1, 2], true)
            ? (int)$pdfsecure->completionreadall : 0;
    $pdfsecure->timecreated = time();
    $pdfsecure->timemodified = time();
    $pdfsecure->id = $DB->insert_record('pdfsecure', $pdfsecure);

    if ($mform) {
        $context = context_module::instance($pdfsecure->coursemodule);
        file_save_draft_area_files(
            $pdfsecure->pdf_file,
            $context->id,
            'mod_pdfsecure',
            'content',
            0,
            ['subdirs' => 0, 'maxfiles' => -1]
        );
        pdfsecure_clear_authors($context);
    }
    return $pdfsecure->id;
}

/**
 * Updates a PDF Secure activity instance.
 *
 * @param stdClass $pdfsecure Activity data.
 * @param moodleform_mod|null $mform Activity form.
 * @return bool Whether the update succeeded.
 */
function pdfsecure_update_instance($pdfsecure, $mform = null) {
    global $DB;
    $pdfsecure->completionreadall = isset($pdfsecure->completionreadall)
        && in_array((int)$pdfsecure->completionreadall, [1, 2], true)
            ? (int)$pdfsecure->completionreadall : 0;
    $pdfsecure->timemodified = time();
    $pdfsecure->id = $pdfsecure->instance;
    $DB->update_record('pdfsecure', $pdfsecure);

    if ($mform) {
        $context = context_module::instance($pdfsecure->coursemodule);
        file_save_draft_area_files(
            $pdfsecure->pdf_file,
            $context->id,
            'mod_pdfsecure',
            'content',
            0,
            ['subdirs' => 0, 'maxfiles' => -1]
        );
        pdfsecure_clear_authors($context);
        // Rebuilt on next use. Clear derivatives together so removed/changed sources cannot remain stale.
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_pdfsecure', \mod_pdfsecure\local\watermarker::NORMALIZED_AREA);
        $fs->delete_area_files($context->id, 'mod_pdfsecure', \mod_pdfsecure\local\watermarker::AREA);
    }
    return true;
}

/**
 * Deletes an activity instance and its reading progress.
 *
 * @param int $id Activity instance ID.
 * @return bool Whether the instance existed and was deleted.
 */
/**
 * Adds the reading progress option to the course reset form.
 *
 * @param MoodleQuickForm $mform Course reset form.
 * @return void
 */
function pdfsecure_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'pdfsecureheader', get_string('modulenameplural', 'pdfsecure'));
    $mform->addElement('advcheckbox', 'reset_pdfsecure_progress', get_string('resetprogress', 'pdfsecure'));
}

/**
 * Reading progress is reset by default with the course.
 *
 * @param stdClass $course Course being reset.
 * @return array Default form values.
 */
function pdfsecure_reset_course_form_defaults($course) {
    return ['reset_pdfsecure_progress' => 1];
}

/**
 * Removes reading progress on course reset, so a reset course does not complete everyone again at once.
 *
 * Progress also goes when the reset deletes completion data, since the rule would otherwise restore it.
 *
 * @param stdClass $data Course reset form data.
 * @return array Status lines for the reset report.
 */
function pdfsecure_reset_userdata($data) {
    global $DB;
    $status = [];
    if (!empty($data->reset_pdfsecure_progress) || !empty($data->reset_completion)) {
        $DB->delete_records_select(
            'pdfsecure_progress',
            'pdfsecureid IN (SELECT id FROM {pdfsecure} WHERE course = :course)',
            ['course' => $data->courseid]
        );
        $status[] = [
            'component' => get_string('modulenameplural', 'pdfsecure'),
            'item' => get_string('resetprogress', 'pdfsecure'),
            'error' => false,
        ];
    }
    return $status;
}

function pdfsecure_delete_instance($id) {
    global $DB;
    if (!$pdfsecure = $DB->get_record('pdfsecure', array('id' => $id))) {
        return false;
    }
    $DB->delete_records('pdfsecure_progress', ['pdfsecureid' => $pdfsecure->id]);
    $DB->delete_records('pdfsecure', array('id' => $pdfsecure->id));
    return true;
}

/**
 * The site's stamp mode: 'full' to watermark every view, 'off' to pass the document
 * through unmarked.
 *
 * 'off' exists for sites whose workstations already apply their own watermark. A
 * second mark layered on the first buys no attribution and costs legibility.
 *
 * @return string 'full' or 'off'
 */
function pdfsecure_stamp_mode(): string {
    $mode = get_config('mod_pdfsecure', 'stampmode');
    // THIS BUILD INVERTS THE DEFAULT - see VARIANT.md. Unmarked unless an admin has
    // explicitly asked for stamping.
    //
    // The fallback lives here in the code, not only in the setting's default value,
    // and that is the whole trick that makes the two builds swappable: they carry the
    // SAME $plugin->version, so Moodle runs no upgrade step when one replaces the
    // other and therefore never applies a new setting default. Deciding at call time
    // means the build behaves correctly as soon as the new code is in force, in
    // either direction, with no upgrade and no downgrade.
    //
    // Caveat proven on a live install: OPcache serves the previously compiled
    // lib.php until it revalidates (opcache.revalidate_freq, 60s on our servers),
    // so reload PHP after swapping builds instead of trusting the file copy.
    return ($mode === 'full') ? 'full' : 'off';
}

/**
 * Refuses PDFs in Moodle's native File and Folder activities when onlysecure is enabled.
 *
 * @param moodleform_mod $mform Activity form being validated.
 * @param array|stdClass $data Submitted form data.
 * @return array Form errors keyed by element name.
 */
function pdfsecure_coursemodule_validation($mform, $data) {
    global $USER;

    if (empty(get_config('mod_pdfsecure', 'onlysecure'))) {
        return [];
    }

    $values = (array)$data;
    if (empty($values['modulename']) || !in_array($values['modulename'], ['resource', 'folder'], true)) {
        return [];
    }
    $draftitemid = (int)($values['files'] ?? 0);
    if (!$draftitemid || empty($USER->id)) {
        return [];
    }

    $contextid = context_user::instance($USER->id)->id;
    $files = get_file_storage()->get_area_files($contextid, 'user', 'draft', $draftitemid, 'filepath, filename', false);
    foreach ($files as $file) {
        if ($file->get_mimetype() === 'application/pdf'
                || strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === 'pdf') {
            return ['files' => get_string('onlysecurevalidation', 'mod_pdfsecure')];
        }
        // A zip of PDFs would hand students the same plain files in one download.
        if (strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === 'zip') {
            $entries = $file->list_files(get_file_packer('application/zip'));
            if ($entries === false) {
                return ['files' => get_string('onlysecurevalidation', 'mod_pdfsecure')];
            }
            foreach ($entries as $entry) {
                if (strtolower(pathinfo((string)$entry->pathname, PATHINFO_EXTENSION)) === 'pdf') {
                    return ['files' => get_string('onlysecurevalidation', 'mod_pdfsecure')];
                }
            }
        }
    }

    return [];
}

/**
 * Register the PDF Secure activity for drag-and-drop PDF uploads.
 *
 * @return array Supported file types.
 */
function pdfsecure_dndupload_register() {
    return ['files' => [
        ['extension' => 'pdf', 'message' => get_string('dnduploadpdfsecure', 'mod_pdfsecure')],
        ['extension' => 'zip', 'message' => get_string('dnduploadzipsecure', 'mod_pdfsecure')],
    ]];
}

/**
 * Create a PDF Secure activity from a drag-and-dropped PDF.
 *
 * The course module already exists when Moodle calls this handler. The instance
 * callback intentionally does not save draft files, so the file is stored here.
 *
 * @param stdClass $uploadinfo Upload details.
 * @return int New activity instance id.
 */
function pdfsecure_dndupload_handle($uploadinfo) {
    global $USER;

    $draftcontext = context_user::instance($USER->id);
    $draftfiles = get_file_storage()->get_area_files(
        $draftcontext->id,
        'user',
        'draft',
        $uploadinfo->draftitemid,
        'filepath, filename',
        false
    );
    $zipfile = null;
    $pdffile = null;
    foreach ($draftfiles as $file) {
        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if ($extension === 'zip') {
            $zipfile = $file;
            break;
        }
        if ($extension === 'pdf' || $file->get_mimetype() === 'application/pdf') {
            $pdffile = $file;
            break;
        }
    }
    if ($zipfile) {
        return \mod_pdfsecure\local\zip_upload::handle($uploadinfo, $zipfile);
    }
    if (!$pdffile) {
        return 0;
    }
    // Returning 0 makes Moodle report the failed upload, instead of an activity no one can read.
    if (pdfsecure_stamp_mode() !== 'off' && (
            \mod_pdfsecure\local\watermarker::too_big($pdffile)
                || !\mod_pdfsecure\local\watermarker::can_stamp($pdffile)
        )) {
        return 0;
    }

    $context = context_module::instance($uploadinfo->coursemodule);
    file_save_draft_area_files(
        $uploadinfo->draftitemid,
        $context->id,
        'mod_pdfsecure',
        'content',
        0,
        ['subdirs' => 0, 'maxfiles' => -1]
    );
    pdfsecure_clear_authors($context);

    $storedfiles = get_file_storage()->get_area_files($context->id, 'mod_pdfsecure', 'content', 0, 'sortorder', false);
    $storedpdf = false;
    foreach ($storedfiles as $file) {
        if ($file->get_mimetype() === 'application/pdf'
                || strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === 'pdf') {
            $storedpdf = true;
            break;
        }
    }
    if (!$storedpdf) {
        get_file_storage()->delete_area_files($context->id, 'mod_pdfsecure', 'content', 0);
        return 0;
    }

    $data = new stdClass();
    $data->course = $uploadinfo->course->id;
    $data->name = $uploadinfo->displayname;
    $data->intro = '';
    $data->introformat = FORMAT_HTML;
    $data->coursemodule = $uploadinfo->coursemodule;
    $data->completionreadall = 0;

    try {
        return pdfsecure_add_instance($data, null);
    } catch (Throwable $e) {
        get_file_storage()->delete_area_files($context->id, 'mod_pdfsecure', 'content', 0);
        throw $e;
    }
}

/**
 * Serves protected PDF content through the module file endpoint.
 *
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module record.
 * @param context $context File context.
 * @param string $filearea Requested file area.
 * @param array $args File path arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options File serving options.
 * @return bool False when the requested file cannot be served.
 */
function pdfsecure_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    // Only the virtual `stamped` area is addressable. The real `content` area, which
    // holds the untouched upload, is refused here, so the upload has no URL of its
    // own - there is no address a reader could construct to reach around this
    // function, whatever it decides to serve.
    //
    // What comes OUT of this area depends on the site's stamp mode: the watermarked
    // rendering, or the original bytes where the site already watermarks at the
    // endpoint. The access control below runs identically either way. Delivery and
    // marking are two separate questions, and only the second one is configurable.
    if ($filearea !== 'stamped') {
        return false;
    }

    require_login($course, false, $cm);
    require_capability('mod/pdfsecure:view', $context);

    // The filename identifies WHICH document; WHO is reading it comes from the
    // session, never from the URL. There is therefore nothing in the address to
    // tamper with in order to obtain a copy stamped with somebody else's name.
    // This route has exactly one filename after the itemid. Reject directory paths
    // and the area root before resolving the filename against the content area.
    array_shift($args);                 // itemid placeholder, always 0
    if (count($args) !== 1) {
        return false;
    }
    $filename = (string)array_pop($args);
    if ($filename === '' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        return false;
    }

    $fs = get_file_storage();
    $source = $fs->get_file($context->id, 'mod_pdfsecure', 'content', 0, '/', $filename);
    if (!$source || !\mod_pdfsecure\local\content_files::is_pdf($source)) {
        return false;
    }

    if (pdfsecure_stamp_mode() === 'off') {
        // Stamping is off, so the document goes out as uploaded. Note what this does
        // NOT relax: the login, enrolment and capability checks above have already
        // run, and the `content` area is still unaddressable. What a reader gets here
        // is the same file they would have got stamped, minus the mark.
        //
        // Streamed by Moodle rather than read into a string: without FPDI in the path
        // there is no per-view rendering, so the size ceiling below stops applying and
        // a 200 MB document is served without ever being held in memory. Byte ranges
        // are safe again for the same reason - these bytes are the same on every
        // request, so a browser resuming a transfer cannot stitch together two
        // different generations of the file.
        //
        // forcedownload is hardcoded false, ignoring the URL parameter, so the
        // document opens in the viewer rather than landing in the downloads folder.
        send_stored_file($source, 0, 0, false, ['cacheability' => 'private']);
        // send_stored_file() ends the request.
    }

    // The whole document is held in memory while it is rendered, and now that
    // happens on every view rather than once. An oversized upload would therefore
    // exhaust the PHP memory limit on each read instead of failing once, so refuse
    // it up front and leave a trace explaining why.
    $maxbytes = (int)(get_config('mod_pdfsecure', 'maxstampbytes') ?: 41943040);
    if ($source->get_filesize() > $maxbytes) {
        debugging('pdfsecure: refusing to stamp ' . $source->get_filename() . ' ('
            . display_size($source->get_filesize()) . ' exceeds the '
            . display_size($maxbytes) . ' limit)', DEBUG_DEVELOPER);
        send_file_not_found();
    }

    try {
        $content = \mod_pdfsecure\local\watermarker::render_for($source, $USER, (int)$cm->id);
    } catch (\Throwable $e) {
        // Fail closed. Serving the original "just this once" is precisely how an
        // unstamped copy escapes, and FPDI legitimately refuses some sources.
        debugging('pdfsecure: stamping failed for file ' . $source->get_id() . ': '
            . $e->getMessage(), DEBUG_DEVELOPER);
        send_file_not_found();
    }

    // Accept-Ranges: none is load-bearing, not tidiness. Each request regenerates the
    // document, and FPDI output is not byte-identical between runs, so a browser
    // fetching byte ranges would stitch pieces of different generations into one
    // corrupt file. Refusing ranges makes the viewer fetch it whole, once.
    header('Content-Type: application/pdf');
    // RFC 6266: the plain form stays readable for ASCII names, and filename* carries
    // the real one for anything else. rawurlencode() alone would show the reader a
    // percent-encoded name in the save dialog.
    header('Content-Disposition: inline; filename="'
        . str_replace(['"', "\r", "\n"], '', $filename) . '"; filename*=UTF-8\'\''
        . rawurlencode($filename));
    header('Content-Length: ' . strlen($content));
    header('Accept-Ranges: none');
    // Per-user and per-view: it must not be held by a shared cache, and the browser
    // must not reuse it either, or the timestamp stops reflecting the actual read.
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo $content;
    die;
}
