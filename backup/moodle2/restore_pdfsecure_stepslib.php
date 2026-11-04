<?php
// Estrutura de restauracao do mod_pdfsecure. Par do backup_pdfsecure_stepslib.

defined('MOODLE_INTERNAL') || die();

/**
 * Restaura uma instancia de pdfsecure a partir de pdfsecure.xml.
 */
class restore_pdfsecure_activity_structure_step extends restore_activity_structure_step {

    /**
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');
        $paths[] = new restore_path_element('pdfsecure', '/activity/pdfsecure');
        if ($userinfo) {
            $paths[] = new restore_path_element('pdfsecure_progress', '/activity/pdfsecure/progressrows/progress');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * @param array|object $data
     */
    protected function process_pdfsecure($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        // O curso de destino pode ser outro; a instancia sempre pertence ao
        // curso em que esta sendo restaurada.
        $data->course = $this->get_courseid();

        // A tabela declara timecreated/timemodified NOT NULL, e um .mbz antigo
        // pode nao trazer os dois - preencher evita falha de insercao.
        $data->timecreated = isset($data->timecreated) ? $this->apply_date_offset($data->timecreated) : time();
        $data->timemodified = isset($data->timemodified) ? $this->apply_date_offset($data->timemodified) : time();
        $data->completionreadall = isset($data->completionreadall) ? $data->completionreadall : 0;

        $newitemid = $DB->insert_record('pdfsecure', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restores one user's reading progress for the restored activity.
     *
     * @param array|object $data Restored progress row.
     */
    protected function process_pdfsecure_progress($data) {
        global $DB;

        $data = (object)$data;
        $data->pdfsecureid = $this->get_new_parentid('pdfsecure');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (empty($data->userid)) {
            return;
        }
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        if (!empty($data->timecompleted)) {
            $data->timecompleted = $this->apply_date_offset($data->timecompleted);
        } else {
            $data->timecompleted = null;
        }

        unset($data->id);
        $DB->insert_record('pdfsecure_progress', $data);
    }

    /**
     * Reanexa os arquivos depois que o contexto do modulo ja existe.
     */
    protected function after_execute() {
        $this->add_related_files('mod_pdfsecure', 'intro', null);
        $this->add_related_files('mod_pdfsecure', 'content', null);
    }
}
