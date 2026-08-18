<?php
require('../../config.php');
require_once('lib.php');

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

$PAGE->set_url('/mod/pdfsecure/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($pdfsecure->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();

echo "<h3>" . format_string($pdfsecure->name) . "</h3>";

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_pdfsecure', 'content', 0, 'id', false);

$file = null;
foreach ($files as $f) {
    if (!$f->is_directory()) {
        $file = $f;
        break;
    }
}

if ($file) {
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
        'stamped', 0, '/', $file->get_filename());
    $viewerpath = $CFG->wwwroot . '/mod/pdfsecure/pdfjs-drm/web/viewer.html';
    
    // Forçando o idioma para Inglês
    $viewerurl = $viewerpath . '?file=' . urlencode($fileurl->out(false)) . '&locale=en-US';

    ?>
    <style>
        @media print {
            body, html, #page, #page-wrapper {
                display: none !important;
                visibility: hidden !important;
            }
        }
    </style>

    <div style="position: relative; width: 100%; height: 800px; border: 1px solid #ccc; margin-top: 20px; overflow: hidden; user-select: none;">
        
        <iframe id="pdf-iframe" src="<?php echo $viewerurl; ?>#toolbar=0" width="100%" height="100%" style="border:none; position: absolute; z-index: 1;" onload="secureIframe()"></iframe>
        
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
    // 2. ESCUDO GLOBAL DO MOODLE
    document.addEventListener('contextmenu', function(event) {
        event.preventDefault();
    });

    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && (e.key === 'p' || e.key === 's' || e.key === 'P' || e.key === 'S')) {
            e.preventDefault();
        }
    });

    // 3. CORTINA DE FUMAÇA INTELIGENTE
    var iframeElement = document.getElementById('pdf-iframe');

    window.addEventListener('blur', function() {
        if (document.activeElement === iframeElement) {
            return;
        }
        if(iframeElement) iframeElement.style.opacity = '0'; 
    });

    window.addEventListener('focus', function() {
        if(iframeElement) iframeElement.style.opacity = '1';
    });

    // 4. LOOP INVASOR NO IFRAME DO PDF.JS
    function secureIframe() {
        var iframe = document.getElementById('pdf-iframe');
        
        var injectInterval = setInterval(function() {
            try {
                var innerWindow = iframe.contentWindow;
                var innerDoc = iframe.contentDocument || innerWindow.document;
                
                if (innerDoc) {
                    var style = innerDoc.createElement('style');
                    style.innerHTML = `
                        /* CORREÇÃO 2: Destruição dos botões de Tela Cheia (#presentationMode, #secondaryPresentationMode) */
                        #print, #download, #secondaryPrint, #secondaryDownload, #openFile, #secondaryOpenFile, .print, .download, #presentationMode, #secondaryPresentationMode, .presentationMode { display: none !important; }
                        body, .textLayer { user-select: none !important; -webkit-user-select: none !important; cursor: default !important; }
                        @media print { body { display: none !important; } }
                    `;
                    innerDoc.head.appendChild(style);

                    innerDoc.addEventListener('keydown', function(e) {
                        // CORREÇÃO 3: Bloqueio adicional da tecla F11
                        if (e.key === 'F11' || (e.ctrlKey && (e.key === 'p' || e.key === 's' || e.key === 'P' || e.key === 'S'))) {
                            e.preventDefault();
                        }
                    });

                    innerDoc.addEventListener('contextmenu', function(event) {
                        event.preventDefault();
                    });

                    innerWindow.addEventListener('blur', function() {
                        iframe.style.opacity = '0';
                    });
                    
                    innerWindow.addEventListener('focus', function() {
                        iframe.style.opacity = '1';
                    });

                    clearInterval(injectInterval);
                }
            } catch (e) {
                // Silencia até que o Iframe termine de carregar
            }
        }, 500);
    }
    </script>
    <?php

} else {
    echo $OUTPUT->notification("O arquivo PDF não foi encontrado nesta atividade.", 'notifyproblem');
}

echo $OUTPUT->footer();
