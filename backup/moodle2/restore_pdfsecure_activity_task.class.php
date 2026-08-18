<?php
// Task de restauracao do mod_pdfsecure.

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/pdfsecure/backup/moodle2/restore_pdfsecure_stepslib.php');

/**
 * Restaura uma instancia de pdfsecure.
 */
class restore_pdfsecure_activity_task extends restore_activity_task {

    /**
     * Sem configuracoes proprias.
     */
    protected function define_my_settings() {
    }

    /**
     * Passos de restauracao.
     */
    protected function define_my_steps() {
        $this->add_step(
            new restore_pdfsecure_activity_structure_step('pdfsecure_structure', 'pdfsecure.xml')
        );
    }

    /**
     * Areas de conteudo que podem conter links a decodificar.
     *
     * @return array
     */
    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('pdfsecure', ['intro'], 'pdfsecure');
        return $contents;
    }

    /**
     * Regras de decodificacao dos links criados por encode_content_links().
     *
     * @return array
     */
    public static function define_decode_rules() {
        $rules = [];
        $rules[] = new restore_decode_rule('PDFSECUREVIEWBYID', '/mod/pdfsecure/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('PDFSECUREINDEX', '/mod/pdfsecure/index.php?id=$1', 'course');
        return $rules;
    }

    /**
     * Regras de restauracao de log.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        $rules = [];
        $rules[] = new restore_log_rule('pdfsecure', 'add', 'view.php?id={course_module}', '{pdfsecure}');
        $rules[] = new restore_log_rule('pdfsecure', 'update', 'view.php?id={course_module}', '{pdfsecure}');
        $rules[] = new restore_log_rule('pdfsecure', 'view', 'view.php?id={course_module}', '{pdfsecure}');
        return $rules;
    }
}
