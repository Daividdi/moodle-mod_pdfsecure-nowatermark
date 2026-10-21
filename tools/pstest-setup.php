<?php
/**
 * Cria um usuario temporario e o matricula, para provar a entrega do PDF por
 * HTTP com sessao real. Apagado depois pelo pstest-cleanup.php.
 *
 * Uso: php pstest-setup.php --course=13 --cmid=1803
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/clilib.php');

list($opt, ) = cli_get_params(['course' => 0, 'cmid' => 0, 'help' => false], ['h' => 'help']);
if ($opt['help'] || !$opt['course'] || !$opt['cmid']) {
    cli_writeln("php pstest-setup.php --course=<id> --cmid=<id>");
    exit(1);
}

if (!$u = $DB->get_record('user', ['username' => 'ps.verify', 'deleted' => 0])) {
    $u = new stdClass();
    $u->username   = 'ps.verify';
    $u->password   = 'Ps!verify2026#x';
    $u->firstname  = 'Teste';
    $u->lastname   = 'Verificacao';
    $u->email      = 'ps.verify@example.invalid';
    $u->auth       = 'manual';
    $u->confirmed  = 1;
    $u->mnethostid = $CFG->mnet_localhost_id;
    $u->id = user_create_user($u, true, false);
    cli_writeln("usuario criado id={$u->id}");
} else {
    cli_writeln("usuario ja existia id={$u->id}");
}

$ctxcurso = context_course::instance($opt['course']);
if (!is_enrolled($ctxcurso, $u->id)) {
    $plugin   = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol',
        ['courseid' => $opt['course'], 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
    if (!$instance) {
        cli_error("O curso {$opt['course']} nao tem inscricao manual habilitada.");
    }
    $role = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    $plugin->enrol_user($instance, $u->id, $role->id);
    cli_writeln("matriculado no curso {$opt['course']}");
}

$ctxmod = context_module::instance($opt['cmid']);
cli_writeln('contextid do modulo = ' . $ctxmod->id);
cli_writeln('pode ver = ' . (has_capability('mod/pdfsecure:view', $ctxmod, $u->id) ? 'sim' : 'NAO'));

// Nome e hash do arquivo, para o teste HTTP comparar.
$fs = get_file_storage();
foreach ($fs->get_area_files($ctxmod->id, 'mod_pdfsecure', 'content', 0, 'id', false) as $f) {
    cli_writeln('arquivo   = ' . $f->get_filename());
    cli_writeln('tamanho   = ' . $f->get_filesize());
    $tmp = make_request_directory() . '/f.pdf';
    $f->copy_content_to($tmp);
    cli_writeln('sha256    = ' . hash_file('sha256', $tmp));
    break;
}
