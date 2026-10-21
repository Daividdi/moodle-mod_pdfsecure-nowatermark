<?php
/**
 * Migra atividades de um modulo de PDF de terceiro para mod_pdfsecure.
 *
 * Generico: a origem e um parametro, nao esta cravada no codigo. Na Malasia a
 * origem foi mod_pdfprotect; na WuXi sera o que o levantamento apontar.
 *
 * Por que existe: plugins de PDF de terceiro costumam nao ter area de busca
 * (classes/search/), entao o Moodle nunca entrega os arquivos deles ao
 * indexador -- os PDFs sao invisiveis por construcao, nao por falha do Tika.
 * O mod_pdfsecure tem area de busca. Migrar por script evita pedir a dezenas
 * de professores que recarreguem o mesmo arquivo a mao.
 *
 * O que faz por atividade:
 *   1. acha o PDF nas fileareas do plugin de origem, naquele contexto
 *   2. cria uma atividade pdfsecure no MESMO curso e seccao, com o mesmo nome,
 *      intro e visibilidade, posicionada logo apos a original
 *   3. anexa o mesmo arquivo (mesmo contenthash, sem copiar bytes no disco)
 *   4. ESCONDE a original -- nunca apaga. Desinstalar o plugin de origem
 *      apagaria as atividades e os arquivos junto.
 *
 * ARMADILHA QUE CUSTOU UMA RODADA NA MALASIA: pdfsecure_add_instance() so
 * grava o arquivo `if ($mform)`, ou seja, apenas quando a atividade nasce do
 * formulario web. Criando por add_moduleinfo() o $mform e null, o bloco e
 * pulado e a atividade nasce SEM ARQUIVO -- sem erro nenhum, o que e pior.
 * Por isso o anexo e feito aqui fora, depois de criar, e a original so e
 * escondida DEPOIS de confirmar o arquivo na nova.
 *
 * Idempotente e auto-reparavel: marca o cm novo com idnumber
 * "migrado-<origem>-<cmid antigo>". Numa nova execucao quem ja existe e
 * pulado -- e quem estiver sem arquivo e reparado.
 *
 * Uso:
 *   php migrate_pdf.php --source=pdfprotect                 (simulacao)
 *   php migrate_pdf.php --source=pdfprotect --commit
 *   php migrate_pdf.php --source=pdfprotect --commit --limit=2
 *   php migrate_pdf.php --source=pdfprotect --commit --course=12
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/clilib.php');

list($opt, $unrecognised) = cli_get_params(
    ['source' => '', 'commit' => false, 'limit' => 0, 'course' => 0, 'help' => false],
    ['h' => 'help', 's' => 'source']
);

if ($opt['help'] || $opt['source'] === '') {
    cli_writeln("php migrate_pdf.php --source=<modulo> [--commit] [--limit=N] [--course=ID]");
    cli_writeln("  --source  nome do modulo de origem, como aparece na tabela {modules}");
    cli_writeln("            (ex.: pdfprotect, securepdf). Obrigatorio.");
    exit($opt['help'] ? 0 : 1);
}

// Valida a origem contra a tabela de modulos ANTES de interpolar o nome em
// qualquer SQL. E o unico ponto onde um nome vindo da linha de comando entra
// numa consulta, e o Moodle nao aceita nome de tabela como parametro ligado.
$source = clean_param($opt['source'], PARAM_ALPHANUMEXT);
$modsource = $DB->get_record('modules', ['name' => $source]);
if (!$modsource) {
    cli_error("Modulo de origem '{$source}' nao existe na tabela modules. "
        . "Rode o levantamento (0.7) para ver os nomes instalados.");
}
if (!$DB->get_manager()->table_exists($source)) {
    cli_error("A tabela '{$source}' nao existe. Origem invalida.");
}
$modpdfsecure = $DB->get_record('modules', ['name' => 'pdfsecure']);
if (!$modpdfsecure) {
    cli_error("mod_pdfsecure nao esta instalado. Faca a Fase 5 antes desta.");
}

// add_moduleinfo() checa capacidade; em CLI nao ha usuario logado.
\core\session\manager::set_user(get_admin());

$dry = empty($opt['commit']);
$fs  = get_file_storage();

/** O arquivo do pdfsecure vive em mod_pdfsecure/content, itemid 0, na raiz. */
function pdfsecure_arquivo($fs, $cmid) {
    $ctx  = context_module::instance($cmid);
    $arqs = $fs->get_area_files($ctx->id, 'mod_pdfsecure', 'content', 0, 'sortorder', false);
    return $arqs ? reset($arqs) : null;
}

/**
 * Acha o PDF do plugin de origem naquele contexto.
 *
 * Procura por componente, sem fixar filearea nem itemid, porque cada plugin
 * de terceiro guarda de um jeito -- o pdfprotect usa content/0, outros usam o
 * id da instancia como itemid. Filtrar so por componente e extensao cobre
 * todos sem precisar saber o layout de cada um.
 */
function origem_pdf($DB, $fs, $ctxid, $source) {
    $recs = $DB->get_records_select('files',
        "contextid = :ctx AND component = :comp AND filename <> '.' AND filesize > 0",
        ['ctx' => $ctxid, 'comp' => 'mod_' . $source], 'filearea, itemid, sortorder, id');
    foreach ($recs as $rec) {
        if (strtolower(pathinfo($rec->filename, PATHINFO_EXTENSION)) === 'pdf') {
            return $fs->get_file_by_id($rec->id);
        }
    }
    return null;
}

/**
 * Anexa o PDF a atividade nova. Passa pela area de rascunho de proposito: e o
 * mesmo caminho que o formulario percorre, entao normalizacao de nome e limite
 * de um arquivo saem identicos ao que o plugin faria sozinho.
 */
function anexar($fs, $origem, $novocmid) {
    global $USER;
    $draftid = file_get_unused_draft_itemid();
    $fs->create_file_from_storedfile([
        'contextid' => context_user::instance($USER->id)->id,
        'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid,
        'filepath' => '/', 'filename' => $origem->get_filename(),
    ], $origem);
    $ctx = context_module::instance($novocmid);
    file_save_draft_area_files($draftid, $ctx->id, 'mod_pdfsecure', 'content', 0,
        ['subdirs' => 0, 'maxfiles' => 1]);
}

cli_heading(($dry ? 'SIMULACAO (nada sera gravado)' : 'MIGRACAO REAL') . " -- origem: {$source}");

$where  = $opt['course'] ? "WHERE cm.course = :courseid" : "";
$params = $opt['course'] ? ['courseid' => $opt['course']] : [];
$rows = $DB->get_records_sql(
    "SELECT cm.id AS cmid, cm.course, cm.section AS sectionid, cm.visible,
            p.id AS instanceid, p.name, p.intro, p.introformat
       FROM {course_modules} cm
       JOIN {{$source}} p ON p.id = cm.instance
       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
       {$where}
   ORDER BY cm.course, cm.id", $params + ['modname' => $source]);

cli_writeln(count($rows) . " atividades {$source} encontradas.\n");

$feito = $pulado = $reparado = $semarquivo = $erro = 0;

foreach ($rows as $r) {
    if ($opt['limit'] && $feito >= $opt['limit']) {
        cli_writeln("-- limite de {$opt['limit']} atingido, parando (reparos nao contam).");
        break;
    }

    $ctxvelho = context_module::instance($r->cmid);
    $origem   = origem_pdf($DB, $fs, $ctxvelho->id, $source);
    $marca    = 'migrado-' . $source . '-' . $r->cmid;
    $ja       = $DB->get_record('course_modules', ['idnumber' => $marca]);

    if ($ja) {
        $temarq = pdfsecure_arquivo($fs, $ja->id);
        if ($temarq) {
            // Arquivo que chegou DEPOIS da ultima modificacao da atividade e
            // invisivel para a busca: o indexador so revisita quem tem
            // timemodified novo. Tocar o campo poe de volta na fila.
            $inst = $DB->get_record('pdfsecure', ['id' => $ja->instance]);
            if ($inst && $temarq->get_timecreated() > $inst->timemodified) {
                cli_writeln(sprintf("TOCA    cm %-6s %-45s arquivo mais novo que o indice",
                    $r->cmid, shorten_text($r->name, 45)));
                if (!$dry) {
                    $DB->set_field('pdfsecure', 'timemodified', time(), ['id' => $inst->id]);
                    rebuild_course_cache($r->course, true);
                    cli_writeln("        -> marcada para reindexar");
                }
                $reparado++;
                continue;
            }
            cli_writeln(sprintf("PULA    cm %-6s %-45s ja migrado, com arquivo",
                $r->cmid, shorten_text($r->name, 45)));
            $pulado++;
            continue;
        }
        cli_writeln(sprintf("REPARA  cm %-6s %-45s migrado SEM arquivo",
            $r->cmid, shorten_text($r->name, 45)));
        if ($dry || !$origem) { $reparado++; continue; }
        try {
            anexar($fs, $origem, $ja->id);
            $ok = pdfsecure_arquivo($fs, $ja->id);
            $DB->set_field('pdfsecure', 'timemodified', time(), ['id' => $ja->instance]);
            rebuild_course_cache($r->course, true);
            cli_writeln("        -> " . ($ok
                ? "arquivo anexado: " . $ok->get_filename() . ", marcada para reindexar"
                : "!! FALHOU, continua sem arquivo"));
            $reparado++;
        } catch (Throwable $e) {
            $erro++;
            cli_writeln("        !! ERRO: " . $e->getMessage());
        }
        continue;
    }

    if (!$origem) {
        cli_writeln(sprintf("SEM PDF cm %-6s %-45s", $r->cmid, shorten_text($r->name, 45)));
        $semarquivo++;
        continue;
    }

    $course = get_course($r->course);
    $secnum = $DB->get_field('course_sections', 'section', ['id' => $r->sectionid]);

    cli_writeln(sprintf("MIGRA   cm %-6s curso %-4s sec %-3s  %-38s  %s (%s)",
        $r->cmid, $r->course, $secnum, shorten_text($r->name, 38),
        shorten_text($origem->get_filename(), 32), display_size($origem->get_filesize())));

    if ($dry) { $feito++; continue; }

    try {
        $mi = new stdClass();
        $mi->modulename          = 'pdfsecure';
        $mi->module              = $modpdfsecure->id;
        $mi->course              = $r->course;
        $mi->section             = $secnum;
        $mi->name                = $r->name;
        $mi->intro               = $r->intro;
        $mi->introformat         = $r->introformat;
        $mi->visible             = $r->visible;
        $mi->visibleoncoursepage = 1;
        $mi->cmidnumber          = $marca;
        $mi->groupmode           = 0;
        $mi->groupingid          = 0;
        $mi->availability        = null;
        $mi->completion          = COMPLETION_TRACKING_NONE;
        $mi->completionview      = 0;
        $mi->completionexpected  = 0;
        $mi->showdescription     = 0;

        $mi = add_moduleinfo($mi, $course);

        // O plugin nao anexa quando nasce fora do formulario -- ver o topo.
        anexar($fs, $origem, $mi->coursemodule);
        $conferido = pdfsecure_arquivo($fs, $mi->coursemodule);
        if (!$conferido) {
            throw new moodle_exception('O arquivo nao ficou anexado; '
                . 'atividade nova mantida e original NAO escondida.');
        }

        // Poe a nova logo depois da antiga, em vez de no fim da seccao.
        $seq = explode(',', (string)$DB->get_field('course_sections', 'sequence',
            ['id' => $r->sectionid]));
        $pos = array_search((string)$r->cmid, $seq, true);
        $depois = ($pos !== false && isset($seq[$pos + 1])) ? (int)$seq[$pos + 1] : null;
        if ($depois && $depois != $mi->coursemodule) {
            $novocm = get_coursemodule_from_id('pdfsecure', $mi->coursemodule, 0, false, MUST_EXIST);
            $secao  = $DB->get_record('course_sections', ['id' => $r->sectionid], '*', MUST_EXIST);
            moveto_module($novocm, $secao, $DB->get_record('course_modules', ['id' => $depois]));
        }

        // Esconde a original SO depois de confirmar o arquivo na nova.
        set_coursemodule_visible($r->cmid, 0);

        rebuild_course_cache($r->course, true);
        $feito++;
        cli_writeln("        -> novo cm {$mi->coursemodule}, arquivo "
            . $conferido->get_filename() . ", original escondida");

    } catch (Throwable $e) {
        $erro++;
        cli_writeln("        !! ERRO: " . $e->getMessage());
    }
}

cli_writeln("");
cli_heading('Resumo');
cli_writeln("  migradas : $feito");
cli_writeln("  reparadas: $reparado (existiam sem arquivo)");
cli_writeln("  puladas  : $pulado (ja migradas, com arquivo)");
cli_writeln("  sem PDF  : $semarquivo");
cli_writeln("  erros    : $erro");
if ($dry) {
    cli_writeln("\n  SIMULACAO -- nada foi gravado. Rode com --commit para valer.");
}
