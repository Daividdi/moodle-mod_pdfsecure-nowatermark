<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_pdfsecure_mod_form extends moodleform_mod {

    public function definition() {
        global $CFG;
        $mform = $this->_form;

        // Cabeçalho Geral
        $mform->addElement('header', 'general', get_string('general', 'core'));

        // Nome da Atividade
        $mform->addElement('text', 'name', get_string('pdfsecurename', 'mod_pdfsecure'), array('size'=>'64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Descrição Padrão
        $this->standard_intro_elements();

        // Área de Upload do PDF
        $mform->addElement('header', 'content', get_string('contentheader', 'mod_pdfsecure'));
        
        $mform->addElement('filemanager', 'pdf_file', get_string('selectfile', 'mod_pdfsecure'), null,
            array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1,
                  'accepted_types' => array('.pdf'))
        );
        $mform->addRule('pdf_file', null, 'required', null, 'client');

        // Configurações Padrão do Moodle e Botões
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}
