<?php
require('../../config.php');
require_once('lib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT); // ID do Course Module

if (! $cm = get_coursemodule_from_id('pdfsecure', $id)) {
    throw new \moodle_exception('invalidcoursemodule');
}
if (! $course = $DB->get_record('course', array('id' => $cm->course))) {
    throw new \moodle_exception('coursemisconf');
}
if (! $pdfsecure = $DB->get_record('pdfsecure', array('id' => $cm->instance))) {
    throw new \moodle_exception('invalidcoursemodule');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/pdfsecure:view', $context);

$pdfs = \mod_pdfsecure\local\content_files::get_pdfs($context);
$filekey = optional_param('file', '', PARAM_RAW);
if (!is_string($filekey)) {
    throw new \moodle_exception('errorfileinvalid', 'mod_pdfsecure');
}
$selectedfile = null;
if ($filekey !== '') {
    $selectedfile = \mod_pdfsecure\local\content_files::resolve($context, (string)$filekey);
} else if (count($pdfs) === 1) {
    $selectedfile = reset($pdfs);
}

$urlparams = ['id' => $cm->id];
if ($filekey !== '') {
    $urlparams['file'] = $filekey;
}
$PAGE->set_url('/mod/pdfsecure/view.php', $urlparams);
$PAGE->set_title(format_string($pdfsecure->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$event = \mod_pdfsecure\event\course_module_viewed::create([
    'objectid' => $pdfsecure->id,
    'context' => $context,
]);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('pdfsecure', $pdfsecure);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);
if ($selectedfile && !empty($pdfsecure->completionreadall)) {
    $PAGE->requires->js_call_amd('mod_pdfsecure/progress', 'init', [$cm->id, $selectedfile->get_filename()]);
}

echo $OUTPUT->header();

// The activity header already shows the name (Moodle 4+), so no second heading here.

if ($selectedfile) {
    // Points at the virtual `stamped` area - the only addressable area this plugin
    // has. Where the site stamps, that area regenerates the document on every request
    // with the reader's identity and the current time, and a document FPDI refuses is
    // an error rather than a fallback to the original. Where the site has stamping
    // turned off (endpoint watermarking already in place), the same area serves the
    // document unmarked behind the same checks.
    //
    // No itemid either way: the reader's identity comes from the session, so the URL
    // carries nothing worth editing.
    $fileurl = moodle_url::make_pluginfile_url($context->id, 'mod_pdfsecure',
        'stamped', 0, '/', $selectedfile->get_filename());
    // The stamped bytes contain the current minute, so the PDF URL must be unique
    // for each page view. Otherwise PDF.js or an intermediary can reuse the previous
    // response even though the pluginfile callback disables shared caching.
    // One-use viewer token (also unique per view, so nothing reuses a previous response).
    $fileurl->param('pdfsecureview', \mod_pdfsecure\local\view_token::issue((int)$cm->id, 'mod_pdfsecure',
        $selectedfile->get_filename(), pdfsecure_stamp_mode() === 'off' ? 50 : 1));
    $viewerpath = $CFG->wwwroot . '/mod/pdfsecure/pdfjs-drm/web/viewer.html';
    
    // Forçando o idioma para Inglês
    $viewerurl = $viewerpath . '?file=' . urlencode($fileurl->out(false)) . '&locale=en-US';
    ?>
    <div class="pdfsecure-frame-wrap" id="pdfsecure-frame-wrap">
        <div class="pdfsecure-toolbar" id="pdfsecure-toolbar" role="toolbar"
                aria-label="<?php echo s(get_string('toolbarlabel', 'mod_pdfsecure')); ?>">
            <button type="button" class="btn btn-sm btn-light" id="pdfsecure-thumbs" aria-pressed="false"
                    aria-label="<?php echo s(get_string('thumbnails', 'mod_pdfsecure')); ?>"
                    title="<?php echo s(get_string('thumbnails', 'mod_pdfsecure')); ?>">
                <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M4 4h6v7H4zM4 14h6v6H4zM14 4h6v16h-6z"/></svg>
                <span class="pdfsecure-action-label"><?php echo get_string('thumbnails', 'mod_pdfsecure'); ?></span>
            </button>
            <div class="pdfsecure-nav">
                <button type="button" class="btn btn-sm btn-light" id="pdfsecure-prev" disabled
                        aria-label="<?php echo s(get_string('previouspage', 'mod_pdfsecure')); ?>"
                        title="<?php echo s(get_string('previouspage', 'mod_pdfsecure')); ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M15 5l-7 7 7 7"/></svg>
                    <span class="pdfsecure-action-label"><?php echo get_string('previouspage', 'mod_pdfsecure'); ?></span>
                </button>
                <output class="pdfsecure-counter" id="pdfsecure-counter" aria-live="polite">&nbsp;</output>
                <button type="button" class="btn btn-sm btn-light" id="pdfsecure-next"
                        aria-label="<?php echo s(get_string('nextpage', 'mod_pdfsecure')); ?>"
                        title="<?php echo s(get_string('nextpage', 'mod_pdfsecure')); ?>">
                    <span class="pdfsecure-action-label"><?php echo get_string('nextpage', 'mod_pdfsecure'); ?></span>
                    <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
            <div class="pdfsecure-zoom">
                <button type="button" class="btn btn-sm btn-light" id="pdfsecure-zoomout"
                        aria-label="<?php echo s(get_string('zoomout', 'mod_pdfsecure')); ?>"
                        title="<?php echo s(get_string('zoomout', 'mod_pdfsecure')); ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M5 12h14"/></svg>
                </button>
                <button type="button" class="btn btn-sm btn-light" id="pdfsecure-fit"
                        aria-label="<?php echo s(get_string('fitwidth', 'mod_pdfsecure')); ?>"
                        title="<?php echo s(get_string('fitwidth', 'mod_pdfsecure')); ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M3 12h18M7 8l-4 4 4 4M17 8l4 4-4 4"/></svg>
                </button>
                <button type="button" class="btn btn-sm btn-light" id="pdfsecure-zoomin"
                        aria-label="<?php echo s(get_string('zoomin', 'mod_pdfsecure')); ?>"
                        title="<?php echo s(get_string('zoomin', 'mod_pdfsecure')); ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M5 12h14M12 5v14"/></svg>
                </button>
            </div>
            <button type="button" class="btn btn-sm btn-light" id="pdfsecure-fullscreen"
                    aria-label="<?php echo s(get_string('fullscreen', 'mod_pdfsecure')); ?>"
                    title="<?php echo s(get_string('fullscreen', 'mod_pdfsecure')); ?>"
                    data-enter-label="<?php echo s(get_string('fullscreen', 'mod_pdfsecure')); ?>"
                    data-exit-label="<?php echo s(get_string('exitfullscreen', 'mod_pdfsecure')); ?>"
                    aria-pressed="false">
                <svg data-icon-enter aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                    <path d="M4 9V4h5M4 4l6 6m10 5v5h-5m5 0-6-6M15 4h5v5m0-5-6 6M9 20H4v-5m0 5 6-6"/>
                </svg>
                <svg data-icon-exit aria-hidden="true" viewBox="0 0 24 24" focusable="false" hidden>
                    <path d="M9 4v5H4m0-5 6 6m5 10v-5h5m0 5-6-6m6-10h-5V4m5 0-6 6M4 20h5v-5m-5 5 6-6"/>
                </svg>
                <span class="pdfsecure-action-label"><?php echo get_string('fullscreen', 'mod_pdfsecure'); ?></span>
            </button>
        </div>
        <div class="pdfsecure-stage" id="pdfsecure-stage" tabindex="0">
            <iframe id="pdf-iframe" src="<?php echo s($viewerurl); ?>#toolbar=0"
                    title="<?php echo s(format_string($pdfsecure->name)); ?>"
                    width="100%" height="100%"></iframe>
        </div>
        
        <?php
        // O overlay CSS de marca d'agua foi REMOVIDO em 2026-08-07.
        //
        // A marca agora e queimada nos bytes do PDF (mod_pdfsecure\local\watermarker),
        // entao o overlay so duplicava a marca na tela: o usuario via duas, e o que
        // ele via nao era o que estava no arquivo. Pior, ele dava a impressao de ser
        // a protecao, quando sumia com um clique no DevTools.
        ?>

    </div>

    <script>
    (function() {
        var iframe = document.getElementById('pdf-iframe');
        var wrap = document.getElementById('pdfsecure-frame-wrap');
        var stage = document.getElementById('pdfsecure-stage');
        var toolbar = document.getElementById('pdfsecure-toolbar');
        var button = document.getElementById('pdfsecure-fullscreen');
        var active = false;
        var savedScale = 'auto';
        var savedPage = 1;

        function app() {
            try {
                return iframe.contentWindow.PDFViewerApplication || null;
            } catch (error) {
                return null;
            }
        }

        function isFullscreen() {
            return document.fullscreenElement === wrap || wrap.classList.contains('is-fullscreen');
        }

        function curtain(on) {
            if (iframe) {
                iframe.style.opacity = on ? '0' : '1';
            }
        }

        function updateButton() {
            var fullscreen = isFullscreen();
            var label = fullscreen ? button.dataset.exitLabel : button.dataset.enterLabel;
            button.setAttribute('aria-label', label);
            button.setAttribute('aria-pressed', fullscreen ? 'true' : 'false');
            button.title = label;
            button.querySelector('[data-icon-enter]').hidden = fullscreen;
            button.querySelector('[data-icon-exit]').hidden = !fullscreen;
            button.querySelector('.pdfsecure-action-label').textContent = label;
        }

        function setNormalHeight() {
            if (isFullscreen()) {
                return;
            }
            // Full screen height minus the sticky site header, so a page is never squashed; the reader scrolls
            // the page once and the whole viewer is in view.
            var nav = document.querySelector('#topBar, nav.navbar.fixed-top, .navbar.fixed-top, nav.navbar');
            var header = nav ? Math.max(0, nav.getBoundingClientRect().bottom) : 0;
            wrap.style.scrollMarginTop = (header + 8) + 'px';
            wrap.style.height = Math.max(window.innerWidth < 576 ? 420 : 560, window.innerHeight - header - 24) + 'px';
            if (!wrap.dataset.autoscrolled) {
                // Open with the viewer filling the screen; title and completion stay one scroll up.
                wrap.dataset.autoscrolled = '1';
                if (window.scrollY < 10 && wrap.getBoundingClientRect().bottom > window.innerHeight) {
                    window.scrollTo({top: wrap.getBoundingClientRect().top + window.scrollY - header - 8});
                }
            }
        }

        function syncFullscreen() {
            var fullscreen = isFullscreen();
            if (fullscreen === active) {
                updateButton();
                return;
            }
            active = fullscreen;
            var application = app();
            if (fullscreen) {
                if (application && application.pdfViewer) {
                    application.pdfViewer.currentPageNumber = savedPage;
                    application.pdfViewer.currentScaleValue = 'page-width';
                }
                stage.focus();
            } else {
                if (application && application.pdfViewer) {
                    savedPage = application.pdfViewer.currentPageNumber || savedPage;
                    application.pdfViewer.currentPageNumber = savedPage;
                    application.pdfViewer.currentScaleValue = savedScale || 'auto';
                }
                document.body.classList.remove('pdfsecure-fullscreen-active');
                button.focus();
                setNormalHeight();
            }
            updateButton();
        }

        function enterFallback() {
            wrap.classList.add('is-fullscreen');
            document.body.classList.add('pdfsecure-fullscreen-active');
            syncFullscreen();
        }

        function exitFallback() {
            wrap.classList.remove('is-fullscreen');
            document.body.classList.remove('pdfsecure-fullscreen-active');
            syncFullscreen();
        }

        function toggleFullscreen() {
            var application = app();
            if (isFullscreen()) {
                if (document.fullscreenElement === wrap && document.exitFullscreen) {
                    document.exitFullscreen().catch(function() {});
                } else {
                    exitFallback();
                }
                return;
            }
            if (application && application.pdfViewer) {
                savedPage = application.pdfViewer.currentPageNumber || 1;
                savedScale = application.pdfViewer.currentScaleValue || 'auto';
            }
            if (!document.fullscreenEnabled || !wrap.requestFullscreen) {
                enterFallback();
                return;
            }
            wrap.requestFullscreen().catch(enterFallback);
        }

        function keyName(event) {
            return (event.key || String.fromCharCode(event.keyCode || 0)).toLowerCase();
        }

        function shield(doc, win) {
            ['contextmenu', 'copy', 'cut', 'selectstart', 'dragstart'].forEach(function(type) {
                doc.addEventListener(type, function(event) {
                    event.preventDefault();
                }, true);
            });
            win.addEventListener('keydown', function(event) {
                var key = keyName(event);
                var command = event.ctrlKey || event.metaKey;
                if ((command && ['p', 's', 'o', 'c', 'x', 'a', 'u'].indexOf(key) !== -1)
                        || key === 'f12' || event.keyCode === 123
                        || (command && event.shiftKey && ['i', 'j', 'c', 's'].indexOf(key) !== -1)) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            }, true);
            doc.addEventListener('keyup', function(event) {
                if (event.key === 'PrintScreen') {
                    curtain(true);
                    try {
                        if (win.navigator.clipboard && win.navigator.clipboard.writeText) {
                            win.navigator.clipboard.writeText('').catch(function() {});
                        }
                    } catch (error) {
                        // Clipboard access depends on browser permissions.
                    }
                    window.setTimeout(function() {
                        if (!document.hidden && document.hasFocus()) {
                            curtain(false);
                        }
                    }, 1500);
                }
            }, true);
            win.addEventListener('beforeprint', function() { curtain(true); });
            win.addEventListener('afterprint', function() { curtain(false); });
            doc.addEventListener('visibilitychange', function() {
                curtain(doc.hidden || !doc.hasFocus());
            });
            win.addEventListener('blur', function() {
                if (document.activeElement !== iframe) {
                    curtain(true);
                }
            });
            win.addEventListener('focus', function() { curtain(false); });
        }

        function hardenFrame() {
            try {
                var innerWindow = iframe.contentWindow;
                var innerDoc = iframe.contentDocument || innerWindow.document;
                if (!innerDoc || innerDoc.readyState !== 'complete') {
                    return false;
                }
                if (innerDoc.documentElement.dataset.pdfsecureHardened === 'true') {
                    return true;
                }
                var style = innerDoc.createElement('style');
                style.id = 'pdfsecure-hardening';
                style.textContent = ':root { --sidebar-width: 170px !important; } #toolbarContainer, #viewAttachments, #viewLayers, #attachmentsView, #layersView { display:none !important; } #viewerContainer, #sidebarContainer { top:0 !important; } '
                    + 'body, .textLayer { user-select:none !important; -webkit-user-select:none !important; cursor:default !important; }'
                    + '@media print { html, body { display:none !important; visibility:hidden !important; } }';
                innerDoc.head.appendChild(style);
                innerDoc.documentElement.dataset.pdfsecureHardened = 'true';
                shield(innerDoc, innerWindow);
                innerDoc.addEventListener('keydown', function(event) {
                    var key = keyName(event);
                    if (key === 'f' && !event.ctrlKey && !event.metaKey && !event.altKey && !event.repeat) {
                        event.preventDefault();
                        event.stopPropagation();
                        toggleFullscreen();
                    } else if (key === 'escape' && wrap.classList.contains('is-fullscreen')) {
                        event.preventDefault();
                        exitFallback();
                    } else if (key === 'f11') {
                        event.preventDefault();
                    }
                }, true);
                return true;
            } catch (error) {
                return false;
            }
        }

        document.addEventListener('keydown', function(event) {
            var key = keyName(event);
            if (key === 'f' && !event.ctrlKey && !event.metaKey && !event.altKey && !event.repeat
                    && !/^(INPUT|TEXTAREA|SELECT)$/.test(event.target && event.target.tagName || '')) {
                event.preventDefault();
                toggleFullscreen();
            } else if (key === 'escape' && wrap.classList.contains('is-fullscreen')) {
                event.preventDefault();
                exitFallback();
            }
        }, true);
        button.addEventListener('click', toggleFullscreen);
        toolbar.addEventListener('pointerdown', function() { curtain(false); });
        toolbar.addEventListener('click', function() { curtain(false); });
        document.addEventListener('fullscreenchange', syncFullscreen);
        window.addEventListener('resize', setNormalHeight);
        window.addEventListener('load', setNormalHeight);
        iframe.addEventListener('load', function() {
            setNormalHeight();
            if (!hardenFrame()) {
                var interval = window.setInterval(function() {
                    if (hardenFrame()) {
                        window.clearInterval(interval);
                    }
                }, 250);
                window.setTimeout(function() { window.clearInterval(interval); }, 30000);
            }
        });
        shield(document, window);
        setNormalHeight();
        updateButton();
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete' && !hardenFrame()) {
            var fallbackInterval = window.setInterval(function() {
                if (hardenFrame()) {
                    window.clearInterval(fallbackInterval);
                }
            }, 250);
            window.setTimeout(function() { window.clearInterval(fallbackInterval); }, 30000);
        }
    })();
    // Barra própria (igual à da apresentação): miniaturas, página anterior/próxima, contador e zoom.
    (function() {
        var iframe = document.getElementById('pdf-iframe');
        var q = function(id) { return document.getElementById(id); };
        var thumbs = q('pdfsecure-thumbs'), prev = q('pdfsecure-prev'), next = q('pdfsecure-next'),
            counter = q('pdfsecure-counter');
        var label = <?php echo json_encode(get_string('pagecounter', 'mod_pdfsecure')); ?>;
        function app() {
            try { return iframe.contentWindow.PDFViewerApplication || null; } catch (e) { return null; }
        }
        function sync() {
            var a = app();
            if (!a || !a.pdfDocument) { return; }
            var n = a.page, total = a.pagesCount;
            counter.textContent = label.replace('{n}', n).replace('{total}', total);
            prev.disabled = n <= 1;
            next.disabled = n >= total;
            var open = !!(a.pdfSidebar && a.pdfSidebar.isOpen);
            thumbs.setAttribute('aria-pressed', open ? 'true' : 'false');
        }
        function hook() {
            var a = app();
            if (!a || !a.initializedPromise) { return setTimeout(hook, 200); }
            a.initializedPromise.then(function() {
                a.eventBus.on('pagechanging', sync);
                a.eventBus.on('pagesinit', function() {
                    if (window.innerWidth >= 992 && a.pdfSidebar && !a.pdfSidebar.isOpen) { a.pdfSidebar.open(); }
                    sync();
                });
                a.eventBus.on('sidebarviewchanged', sync);
                var openWide = function() {
                    if (window.innerWidth >= 992 && a.pdfSidebar && !a.pdfSidebar.isOpen && a.pdfDocument) {
                        a.pdfSidebar.open();
                    }
                    sync();
                };
                if (a.pdfDocument) { openWide(); } else { a.eventBus.on('documentloaded', openWide); }
                sync();
            });
        }
        hook();
        prev.addEventListener('click', function() { var a = app(); if (a) { a.page = Math.max(1, a.page - 1); } });
        next.addEventListener('click', function() { var a = app(); if (a) { a.page = Math.min(a.pagesCount, a.page + 1); } });
        thumbs.addEventListener('click', function() { var a = app(); if (a && a.pdfSidebar) { a.pdfSidebar.toggle(); setTimeout(sync, 50); } });
        q('pdfsecure-zoomin').addEventListener('click', function() { var a = app(); if (a) { a.zoomIn(); } });
        q('pdfsecure-zoomout').addEventListener('click', function() { var a = app(); if (a) { a.zoomOut(); } });
        q('pdfsecure-fit').addEventListener('click', function() { var a = app(); if (a) { a.pdfViewer.currentScaleValue = 'page-width'; } });
    })();
    </script>
    <?php

} else if (count($pdfs) > 1) {
    global $USER;
    echo html_writer::tag('p', get_string('selectdocument', 'mod_pdfsecure'));
    echo html_writer::start_tag('div', ['class' => 'pdfsecure-document-list list-group mb-3']);
    foreach ($pdfs as $filename => $document) {
        $progress = $DB->get_record('pdfsecure_progress', [
            'pdfsecureid' => $pdfsecure->id,
            'userid' => $USER->id,
            'filekey' => $filename,
        ]);
        $seen = $progress && $progress->seen !== ''
            ? count(array_filter(explode(',', $progress->seen), static function($page) {
                return $page !== '';
            })) : 0;
        $total = \mod_pdfsecure\local\watermarker::page_count($document);
        if ($total === null && $progress && (int)$progress->pages > 0) {
            $total = (int)$progress->pages;
        }
        $pages = (object)['read' => $seen, 'total' => $total === null ? '?' : $total];
        $done = $progress && !empty($progress->timecompleted);
        $status = $done
            ? get_string('readstatecomplete', 'mod_pdfsecure')
            : get_string('readstateincomplete', 'mod_pdfsecure');
        $link = new moodle_url('/mod/pdfsecure/view.php', ['id' => $cm->id, 'file' => $filename]);
        $name = html_writer::tag('span', $OUTPUT->pix_icon('f/pdf', '') . ' ' . format_string($filename),
            ['class' => 'pdfsecure-document-name fw-semibold']);
        $meta = html_writer::tag('span', get_string('pagesread', 'mod_pdfsecure', $pages),
            ['class' => 'pdfsecure-document-pages text-muted small me-2']);
        $meta .= html_writer::tag('span', $status,
            ['class' => 'pdfsecure-document-state badge ' . ($done ? 'bg-success' : 'bg-secondary')]);
        $row = $name . html_writer::tag('span', $meta, ['class' => 'text-nowrap']);
        echo html_writer::link($link, $row, ['class' => 'pdfsecure-document-item list-group-item list-group-item-action'
            . ' d-flex flex-wrap justify-content-between align-items-center gap-2']);
    }
    echo html_writer::end_tag('div');
} else {
    echo $OUTPUT->notification(get_string('errorpagesnodocument', 'mod_pdfsecure'), 'notifyproblem');
}

echo $OUTPUT->footer();
