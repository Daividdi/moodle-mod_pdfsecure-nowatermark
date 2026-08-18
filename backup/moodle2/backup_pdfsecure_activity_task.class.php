<?php
// Task de backup do mod_pdfsecure. Ver backup_pdfsecure_stepslib.php para o
// motivo de este arquivo existir.

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/pdfsecure/backup/moodle2/backup_pdfsecure_stepslib.php');

/**
 * Executa um backup completo de uma instancia de pdfsecure.
 */
class backup_pdfsecure_activity_task extends backup_activity_task {

    /**
     * Sem configuracoes proprias.
     */
    protected function define_my_settings() {
    }

    /**
     * Grava os dados da instancia em pdfsecure.xml.
     */
    protected function define_my_steps() {
        $this->add_step(
            new backup_pdfsecure_activity_structure_step('pdfsecure_structure', 'pdfsecure.xml')
        );
    }

    /**
     * Codifica URLs absolutas para este modulo, para que sobrevivam a uma
     * restauracao em outro site.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $search = "/(" . $base . "\/mod\/pdfsecure\/index.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@PDFSECUREINDEX*$2@$', $content);

        $search = "/(" . $base . "\/mod\/pdfsecure\/view.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@PDFSECUREVIEWBYID*$2@$', $content);

        return $content;
    }
}
