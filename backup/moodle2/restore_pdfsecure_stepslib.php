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
        $paths[] = new restore_path_element('pdfsecure', '/activity/pdfsecure');
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

        $newitemid = $DB->insert_record('pdfsecure', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Reanexa os arquivos depois que o contexto do modulo ja existe.
     */
    protected function after_execute() {
        $this->add_related_files('mod_pdfsecure', 'intro', null);
        $this->add_related_files('mod_pdfsecure', 'content', null);
    }
}
