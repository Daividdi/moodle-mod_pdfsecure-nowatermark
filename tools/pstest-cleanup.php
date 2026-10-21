<?php
/**
 * Apaga o usuario de verificacao. Faz parte do passo, nao e faxina depois:
 * conta de teste esquecida em producao e divida, e ja aconteceu de sobrar
 * conteudo protegido em /tmp por nao limpar na hora.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');

if ($u = $DB->get_record('user', ['username' => 'ps.verify', 'deleted' => 0])) {
    delete_user($u);
    cli_writeln('ps.verify apagado');
} else {
    cli_writeln('ps.verify nao existe');
}
cli_writeln('contas de teste restantes: '
    . $DB->count_records_select('user', "username LIKE 'ps.%' AND deleted = 0"));
