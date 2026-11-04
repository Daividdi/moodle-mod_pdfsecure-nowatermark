<?php
/**
 * Ativa a conclusao automatica de leitura ate o fim em atividades PDF Secure existentes.
 *
 * Copie este arquivo para a raiz do Moodle antes de executa-lo e remova-o depois.
 *
 * @package    mod_pdfsecure
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/clilib.php');

/**
 * Grava um novo arquivo de rollback sem substituir um arquivo existente.
 *
 * @param string $path Caminho solicitado para o arquivo de rollback.
 * @param array $entries Valores para restaurar se necessario.
 * @return void
 */
function pdfsecure_enable_write_rollback(string $path, array $entries): void {
    $directory = realpath(dirname($path));
    if ($directory === false || !is_writable($directory)) {
        cli_error("Diretorio do rollback inexistente ou sem permissao de escrita: " . dirname($path));
    }

    $filepath = $directory . DIRECTORY_SEPARATOR . basename($path);
    $handle = @fopen($filepath, 'x');
    if (!$handle) {
        cli_error("Nao foi possivel criar o rollback ou o arquivo ja existe: {$filepath}");
    }

    try {
        $json = json_encode([
            'format' => 'mod_pdfsecure_enable_read_completion',
            'version' => 1,
            'createdat' => date(DATE_ATOM),
            'entries' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new RuntimeException('Falha ao gravar o arquivo de rollback.');
        }
        fclose($handle);
    } catch (Throwable $e) {
        fclose($handle);
        cli_error("Falha ao gravar o rollback; arquivo incompleto em {$filepath}: " . $e->getMessage());
    }
}

/**
 * Le e valida o rollback antes de qualquer alteracao no banco.
 *
 * @param string $path Caminho do arquivo de rollback.
 * @return array Entradas lidas com cmid valido e unico.
 */
function pdfsecure_enable_read_rollback(string $path): array {
    $contents = @file_get_contents($path);
    if ($contents === false) {
        cli_error("Nao foi possivel ler o arquivo de rollback: {$path}");
    }

    try {
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        cli_error("JSON de rollback invalido: " . $e->getMessage());
    }

    if (!is_array($data) || ($data['format'] ?? null) !== 'mod_pdfsecure_enable_read_completion'
            || ($data['version'] ?? null) !== 1 || !isset($data['entries']) || !is_array($data['entries'])) {
        cli_error('Formato de rollback invalido.');
    }

    $entries = [];
    $seen = [];
    foreach ($data['entries'] as $entry) {
        if (!is_array($entry) || !isset($entry['cmid']) || !is_int($entry['cmid']) || $entry['cmid'] < 1) {
            cli_error('Entrada invalida no arquivo de rollback.');
        }
        if (isset($seen[$entry['cmid']])) {
            cli_error("cmid duplicado no arquivo de rollback: {$entry['cmid']}");
        }
        $seen[$entry['cmid']] = true;
        $entries[] = $entry;
    }

    return $entries;
}

/**
 * Valida os valores de uma entrada que pertence a uma atividade PDF Secure.
 *
 * @param array $entry Entrada do arquivo de rollback.
 * @param bool $hascompletionpassgrade Se o Moodle atual possui essa coluna.
 * @return array Entrada validada.
 */
function pdfsecure_enable_validate_rollback_entry(array $entry, bool $hascompletionpassgrade): array {
    $required = ['cmid', 'course', 'completion', 'completionview', 'completionexpected', 'completionreadall'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $entry) || !is_int($entry[$field])) {
            cli_error("Campo ausente ou invalido no rollback: {$field}");
        }
    }

    if ($entry['cmid'] < 1 || $entry['course'] < 1 || $entry['completion'] < 0 || $entry['completion'] > 2
            || $entry['completionview'] < 0 || $entry['completionview'] > 1
            || $entry['completionexpected'] < 0 || $entry['completionreadall'] < 0
            || $entry['completionreadall'] > 1) {
        cli_error('Valores fora do intervalo no arquivo de rollback.');
    }
    if (array_key_exists('completionpassgrade', $entry) && $entry['completionpassgrade'] !== null
            && (!is_numeric($entry['completionpassgrade']) || !is_finite((float)$entry['completionpassgrade']))) {
        cli_error("completionpassgrade invalido para cm {$entry['cmid']}.");
    }
    if ($hascompletionpassgrade && !array_key_exists('completionpassgrade', $entry)) {
        cli_error("Rollback incompleto: falta completionpassgrade para cm {$entry['cmid']}.");
    }

    return $entry;
}

/**
 * Aplica as alteracoes de um curso em uma transacao delegada.
 *
 * @param int $courseid ID do curso.
 * @param array $items Atividades para alterar.
 * @param bool $restore Se deve restaurar os valores salvos.
 * @param bool $hascompletionpassgrade Se o Moodle atual possui essa coluna.
 * @return void
 */
function pdfsecure_enable_apply_course(
    int $courseid,
    array $items,
    bool $restore,
    bool $hascompletionpassgrade
): void {
    global $DB;

    $transaction = $DB->start_delegated_transaction();
    try {
        foreach ($items as $item) {
            $coursejoin = $restore ? '' : ' JOIN {course} c ON c.id = cm.course AND c.enablecompletion = 1';
            $deletionfilter = $restore ? '' : ' AND cm.deletioninprogress = 0';
            $cm = $DB->get_record_sql(
                "SELECT cm.id, cm.instance, cm.module, cm.course
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'pdfsecure'
                   {$coursejoin}
                   JOIN {pdfsecure} p ON p.id = cm.instance AND p.course = cm.course
                  WHERE cm.id = :cmid AND cm.course = :courseid{$deletionfilter}",
                ['cmid' => $item['cmid'], 'courseid' => $courseid],
                IGNORE_MISSING
            );
            if (!$cm || (int)$cm->instance !== (int)$item['instance']) {
                throw new RuntimeException('Atividade PDF Secure alterada durante a execucao.');
            }

            if ($restore) {
                $fields = [
                    'completion' => $item['completion'],
                    'completionview' => $item['completionview'],
                    'completionexpected' => $item['completionexpected'],
                ];
                if ($hascompletionpassgrade && array_key_exists('completionpassgrade', $item)) {
                    $fields['completionpassgrade'] = $item['completionpassgrade'];
                }
                $sets = [];
                $params = ['cmid' => $item['cmid'], 'courseid' => $courseid, 'moduleid' => $cm->module,
                    'instanceid' => $cm->instance];
                foreach ($fields as $field => $value) {
                    $sets[] = $field . ' = :' . $field;
                    $params[$field] = $value;
                }
                $DB->execute(
                    'UPDATE {course_modules} SET ' . implode(', ', $sets)
                        . ' WHERE id = :cmid AND course = :courseid AND module = :moduleid AND instance = :instanceid',
                    $params
                );
                $DB->set_field('pdfsecure', 'completionreadall', $item['completionreadall'], [
                    'id' => $cm->instance,
                    'course' => $courseid,
                ]);
            } else {
                $DB->execute(
                    'UPDATE {course_modules}
                        SET completion = :completion, completionview = 0
                      WHERE id = :cmid AND course = :courseid AND module = :moduleid AND instance = :instanceid',
                    [
                        'completion' => COMPLETION_TRACKING_AUTOMATIC,
                        'cmid' => $item['cmid'],
                        'courseid' => $courseid,
                        'moduleid' => $cm->module,
                        'instanceid' => $cm->instance,
                    ]
                );
                $DB->set_field('pdfsecure', 'completionreadall', 1, [
                    'id' => $cm->instance,
                    'course' => $courseid,
                ]);
            }
        }
        $transaction->allow_commit();
    } catch (Throwable $e) {
        $transaction->rollback($e);
    }
}

list($options, $unrecognised) = cli_get_params(
    [
        'commit' => false,
        'course' => '',
        'limit' => '',
        'rollback' => '',
        'restore' => '',
        'help' => false,
    ],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln('php enable_read_completion.php [--commit] [--course=ID] [--limit=N] [--rollback=FILE]');
    cli_writeln('php enable_read_completion.php [--commit] --restore=FILE');
    cli_writeln('Sem --commit, o script apenas mostra o que faria.');
    exit(0);
}
if (!empty($unrecognised)) {
    cli_error('Opcao desconhecida: ' . implode(' ', $unrecognised));
}

foreach (['rollback', 'restore'] as $pathoption) {
    if ($options[$pathoption] !== '' && (!is_string($options[$pathoption]) || trim($options[$pathoption]) === '')) {
        cli_error("--{$pathoption} exige um caminho de arquivo.");
    }
}

$restore = $options['restore'] !== '';
$commit = !empty($options['commit']);
$hasrollback = $options['rollback'] !== '';
$hascourse = $options['course'] !== '';
$haslimit = $options['limit'] !== '';

if ($restore && $hasrollback) {
    cli_error('Use --restore ou --rollback, nao os dois.');
}
if ($commit && !$restore && !$hasrollback) {
    cli_error('--commit exige --rollback=FILE para permitir restauracao.');
}
if ($restore && ($hascourse || $haslimit)) {
    cli_error('--course e --limit nao se aplicam a --restore.');
}

$coursefilter = 0;
if ($hascourse) {
    if ((!is_string($options['course']) && !is_int($options['course']))
            || !preg_match('/^[0-9]+$/', (string)$options['course'])) {
        cli_error('--course deve ser um ID positivo.');
    }
    $coursefilter = clean_param($options['course'], PARAM_INT);
    if ($coursefilter < 1) {
        cli_error('--course deve ser um ID positivo.');
    }
}
$limit = 0;
if ($haslimit) {
    if ((!is_string($options['limit']) && !is_int($options['limit']))
            || !preg_match('/^[0-9]+$/', (string)$options['limit'])) {
        cli_error('--limit deve ser um inteiro positivo.');
    }
    $limit = clean_param($options['limit'], PARAM_INT);
    if ($limit < 1) {
        cli_error('--limit deve ser um inteiro positivo.');
    }
}

$hascompletionpassgrade = $DB->get_manager()->field_exists('course_modules', 'completionpassgrade');
$module = $DB->get_record('modules', ['name' => 'pdfsecure']);
if (!$module) {
    cli_error('mod_pdfsecure nao esta instalado neste Moodle.');
}

$dry = !$commit;
$changed = 0;
$skipped = 0;
$courses = [];
$rollbackpath = '';

if ($restore) {
    $rollbackpath = $options['restore'];
    $entries = pdfsecure_enable_read_rollback($rollbackpath);
    foreach ($entries as $rawentry) {
        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.course, cm.instance, cm.module
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'pdfsecure'
               JOIN {pdfsecure} p ON p.id = cm.instance AND p.course = cm.course
              WHERE cm.id = :cmid",
            ['cmid' => $rawentry['cmid']],
            IGNORE_MISSING
        );
        if (!$cm) {
            cli_writeln("AVISO cm {$rawentry['cmid']}: nao e atividade pdfsecure; ignorada.");
            $skipped++;
            continue;
        }
        $entry = pdfsecure_enable_validate_rollback_entry($rawentry, $hascompletionpassgrade);
        if ((int)$cm->course !== $entry['course']) {
            cli_writeln("AVISO cm {$entry['cmid']}: curso nao corresponde ao rollback; ignorada.");
            $skipped++;
            continue;
        }
        if (!$hascompletionpassgrade && array_key_exists('completionpassgrade', $entry)) {
            cli_writeln("AVISO cm {$entry['cmid']}: completionpassgrade nao existe neste Moodle; ignorado.");
        }

        $entry['instance'] = (int)$cm->instance;
        $entry['moduleid'] = (int)$cm->module;
        $courses[$entry['course']][] = $entry;
        $changed++;
        cli_writeln(sprintf(
            "RESTAURA cm %-6d curso %-5d completion=%d completionview=%d readall=%d",
            $entry['cmid'], $entry['course'], $entry['completion'], $entry['completionview'],
            $entry['completionreadall']
        ));
    }
} else {
    $passgradeselect = $hascompletionpassgrade ? ', cm.completionpassgrade' : '';
    $coursewhere = $coursefilter ? ' AND cm.course = :courseid' : '';
    $params = ['modname' => 'pdfsecure'];
    if ($coursefilter) {
        $params['courseid'] = $coursefilter;
    }
    $rows = $DB->get_records_sql(
        "SELECT cm.id AS cmid, cm.course, cm.instance, cm.module, cm.completion, cm.completionview,
                cm.completionexpected, p.completionreadall, p.name{$passgradeselect}
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module AND m.name = :modname
           JOIN {pdfsecure} p ON p.id = cm.instance AND p.course = cm.course
           JOIN {course} c ON c.id = cm.course
          WHERE c.enablecompletion = 1 AND cm.deletioninprogress = 0{$coursewhere}
       ORDER BY cm.course, cm.id",
        $params
    );

    foreach ($rows as $row) {
        if ((int)$row->completion === COMPLETION_TRACKING_AUTOMATIC && (int)$row->completionreadall === 1) {
            cli_writeln(sprintf("PULA    cm %-6d curso %-5d ja usa leitura ate o fim", $row->cmid, $row->course));
            $skipped++;
            continue;
        }
        if ($limit && $changed >= $limit) {
            break;
        }

        $entry = [
            'cmid' => (int)$row->cmid,
            'course' => (int)$row->course,
            'completion' => (int)$row->completion,
            'completionview' => (int)$row->completionview,
            'completionexpected' => (int)$row->completionexpected,
            'completionreadall' => (int)$row->completionreadall,
            'instance' => (int)$row->instance,
            'moduleid' => (int)$row->module,
        ];
        if ($hascompletionpassgrade) {
            $entry['completionpassgrade'] = $row->completionpassgrade;
        }

        $manual = (int)$row->completion === COMPLETION_TRACKING_MANUAL;
        cli_writeln(sprintf(
            "ALTERA  cm %-6d curso %-5d %-36s%s",
            $row->cmid,
            $row->course,
            shorten_text((string)$row->name, 36),
            $manual ? ' (manual; estados existentes preservados)' : ''
        ));
        $courses[$entry['course']][] = $entry;
        $changed++;
    }

    if ($hasrollback) {
        $rollbackpath = $options['rollback'];
    }
}

if ($commit && !$restore) {
    $snapshot = [];
    foreach ($courses as $items) {
        foreach ($items as $item) {
            unset($item['instance'], $item['moduleid']);
            $snapshot[] = $item;
        }
    }
    pdfsecure_enable_write_rollback($rollbackpath, $snapshot);
    cli_writeln("Rollback gravado antes das alteracoes: {$rollbackpath}");
}

if (!$dry) {
    foreach ($courses as $courseid => $items) {
        pdfsecure_enable_apply_course((int)$courseid, $items, $restore, $hascompletionpassgrade);
        rebuild_course_cache((int)$courseid, true);
    }
    $changed = 0;
    foreach ($courses as $items) {
        $changed += count($items);
    }
}

cli_writeln('');
cli_heading('Resumo');
cli_writeln('  atividades alteradas : ' . ($dry ? '0 (' . $changed . ' previstas)' : (string)$changed));
cli_writeln('  atividades puladas   : ' . $skipped);
cli_writeln('  cursos tocados       : ' . ($dry ? '0 (' . count($courses) . ' previstos)' : (string)count($courses)));
if ($restore) {
    $status = $dry ? 'nao restaurado (dry run)' : 'restaurado';
    cli_writeln("  arquivo de rollback  : {$status}: {$rollbackpath}");
} elseif ($rollbackpath !== '') {
    cli_writeln('  arquivo de rollback  : ' . ($dry ? "nao gravado (dry run): {$rollbackpath}" : $rollbackpath));
} else {
    cli_writeln('  arquivo de rollback  : nao gravado (dry run)');
}
