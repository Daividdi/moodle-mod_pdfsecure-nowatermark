<?php
/**
 * Confere, de forma independente do migrador, que cada atividade migrada tem
 * o arquivo certo.
 *
 * Existe separado de proposito. O migrador ja reporta "erros: 0", e foi
 * exatamente esse numero que quase deixou passar 93 atividades vazias na
 * Malasia -- o plugin nao anexa arquivo quando a atividade nasce fora do
 * formulario web, e nada falha. Um conferidor que reabre o resultado pelo
 * outro lado e o que transforma "rodou" em "esta certo".
 *
 * Compara o CONTENTHASH, nao o nome nem o tamanho: e a unica prova de que os
 * bytes sao os mesmos e de que nada foi duplicado no disco.
 *
 * Uso:  php verify_pdf.php --source=pdfprotect
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');

list($opt, $unrecognised) = cli_get_params(
    ['source' => '', 'help' => false], ['h' => 'help', 's' => 'source']);

if ($opt['help'] || $opt['source'] === '') {
    cli_writeln("php verify_pdf.php --source=<modulo>");
    exit($opt['help'] ? 0 : 1);
}

$source = clean_param($opt['source'], PARAM_ALPHANUMEXT);
$fs = get_file_storage();

$novos = $DB->get_records_select('course_modules',
    "idnumber LIKE :marca", ['marca' => 'migrado-' . $source . '-%'], 'id');

cli_heading("Conferindo " . count($novos) . " atividades migradas de {$source}");

$ok = $semarq = $hashdif = $origvisivel = 0;

foreach ($novos as $cm) {
    $velhocmid = (int)substr($cm->idnumber, strlen('migrado-' . $source . '-'));
    $nome = $DB->get_field('pdfsecure', 'name', ['id' => $cm->instance]);
    $rotulo = sprintf("cm %-6s <- %-6s %-42s", $cm->id, $velhocmid, shorten_text((string)$nome, 42));

    // Arquivo na atividade nova.
    $ctxnovo = context_module::instance($cm->id);
    $arqs = $fs->get_area_files($ctxnovo->id, 'mod_pdfsecure', 'content', 0, 'sortorder', false);
    $novo = $arqs ? reset($arqs) : null;

    if (!$novo) {
        cli_writeln("SEM ARQUIVO  {$rotulo}");
        $semarq++;
        continue;
    }

    // Arquivo na atividade de origem, para comparar o hash.
    $velho = null;
    if ($ctxvelho = context_module::instance($velhocmid, IGNORE_MISSING)) {
        $recs = $DB->get_records_select('files',
            "contextid = :ctx AND component = :comp AND filename <> '.' AND filesize > 0",
            ['ctx' => $ctxvelho->id, 'comp' => 'mod_' . $source], 'id');
        foreach ($recs as $rec) {
            if (strtolower(pathinfo($rec->filename, PATHINFO_EXTENSION)) === 'pdf') {
                $velho = $rec;
                break;
            }
        }
    }

    if ($velho && $velho->contenthash !== $novo->get_contenthash()) {
        cli_writeln("HASH DIFERE  {$rotulo}");
        $hashdif++;
        continue;
    }

    // A original tem de estar escondida -- senao o aluno ve o PDF duas vezes.
    $visivel = $DB->get_field('course_modules', 'visible', ['id' => $velhocmid]);
    if ($visivel) {
        cli_writeln("ORIG VISIVEL {$rotulo}");
        $origvisivel++;
        continue;
    }

    cli_writeln(sprintf("ok           %s %s (%s)", $rotulo,
        shorten_text($novo->get_filename(), 30), display_size($novo->get_filesize())));
    $ok++;
}

cli_writeln("");
cli_heading('Resumo');
cli_writeln("  corretas          : $ok");
cli_writeln("  SEM arquivo       : $semarq");
cli_writeln("  hash divergente   : $hashdif");
cli_writeln("  original visivel  : $origvisivel");
cli_writeln("");
cli_writeln($semarq + $hashdif + $origvisivel === 0
    ? "  Tudo certo."
    : "  ATENCAO: rode migrate_pdf.php --commit de novo, ele repara quem esta sem arquivo.");
