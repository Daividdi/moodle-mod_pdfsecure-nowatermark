<?php
// Define a estrutura de backup do mod_pdfsecure.
//
// O plugin declara FEATURE_BACKUP_MOODLE2 => true em lib.php mas nao entregava
// nenhuma classe de backup, entao QUALQUER backup de curso que contivesse uma
// instancia dele abortava com:
//
//     Class "backup_pdfsecure_activity_task" not found
//
// Isso derrubava o backup manual E o automatizado do curso inteiro, nao apenas
// desta atividade.

defined('MOODLE_INTERNAL') || die();

/**
 * Estrutura de backup de uma instancia de pdfsecure.
 */
class backup_pdfsecure_activity_structure_step extends backup_activity_structure_step {

    /**
     * @return backup_nested_element
     */
    protected function define_structure() {

        // Este modulo nao guarda dado por usuario: nao ha tentativas, notas nem
        // estado individual - so o PDF e a descricao. Por isso a estrutura nao
        // ramifica em userinfo.
        $pdfsecure = new backup_nested_element('pdfsecure', ['id'], [
            'name', 'intro', 'introformat', 'timecreated', 'timemodified',
        ]);

        $pdfsecure->set_source_table('pdfsecure', ['id' => backup::VAR_ACTIVITYID]);

        // 'intro' e obrigatorio por causa de FEATURE_MOD_INTRO; 'content' e a
        // filearea onde mod_form.php grava o PDF (itemid 0, ver lib.php:21).
        $pdfsecure->annotate_files('mod_pdfsecure', 'intro', null);
        $pdfsecure->annotate_files('mod_pdfsecure', 'content', null);

        return $this->prepare_activity_structure($pdfsecure);
    }
}
