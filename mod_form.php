<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

/**
 * PDF Secure activity settings form.
 */
class mod_pdfsecure_mod_form extends moodleform_mod {

    /**
     * Adds the custom read-to-end completion rule.
     *
     * @return array Form element names added by this method.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $fieldname = 'completionreadall' . $this->get_suffix();
        $mform->addElement('select', $fieldname, get_string('completionreadall', 'mod_pdfsecure'), [
            0 => get_string('completiondisabled', 'mod_pdfsecure'),
            1 => get_string('completionallfiles', 'mod_pdfsecure'),
            2 => get_string('completionanyfile', 'mod_pdfsecure'),
        ]);
        $mform->setDefault($fieldname, 0);
        $mform->addHelpButton($fieldname, 'completionreadall', 'mod_pdfsecure');

        return [$fieldname];
    }

    /**
     * Checks whether the custom read-to-end completion rule is enabled.
     *
     * @param array $data Form data.
     * @return bool Whether the read-to-end rule is enabled.
     */
    public function completion_rule_enabled($data) {
        $fieldname = 'completionreadall' . $this->get_suffix();
        return !empty($data[$fieldname]);
    }

    /**
     * Sets the custom rule value when editing an existing activity.
     *
     * @param array $defaultvalues Form default values.
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        // Load the PDFs already saved, so editing shows them and lets the teacher delete or rename them.
        $draftitemid = file_get_submitted_draft_itemid('pdf_file');
        if ($this->current->instance) {
            file_prepare_draft_area($draftitemid, $this->context->id, 'mod_pdfsecure', 'content', 0,
                ['subdirs' => 0, 'maxfiles' => -1]);
        }
        $defaultvalues['pdf_file'] = $draftitemid;
        $fieldname = 'completionreadall' . $this->get_suffix();
        if (!array_key_exists($fieldname, $defaultvalues)) {
            $defaultvalues[$fieldname] = isset($defaultvalues['completionreadall'])
                ? (int)$defaultvalues['completionreadall'] : 0;
        }
    }

    /**
     * Refuses a PDF that could never reach a reader, instead of saving an empty activity.
     *
     * @param array $data Form data.
     * @param array $files Uploaded files.
     * @return array Errors by element name.
     */
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);
        if (empty($data['pdf_file'])) {
            return $errors;
        }
        $draft = get_file_storage()->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft',
            $data['pdf_file'], 'id', false);
        foreach ($draft as $file) {
            if (!\mod_pdfsecure\local\content_files::is_pdf($file)) {
                $errors['pdf_file'] = get_string('errorfiletype', 'mod_pdfsecure');
                continue;
            }
            if (pdfsecure_stamp_mode() === 'off') {
                continue;
            }
            if (\mod_pdfsecure\local\watermarker::too_big($file)) {
                $errors['pdf_file'] = get_string('errortoobigtostamp', 'mod_pdfsecure');
            } else if (!\mod_pdfsecure\local\watermarker::can_stamp($file)) {
                $errors['pdf_file'] = get_string('errorcannotstamp', 'mod_pdfsecure');
            }
        }
        return $errors;
    }

    /**
     * Defines the PDF Secure activity settings form.
     */
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
            array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => -1,
                  'accepted_types' => array('.pdf'),
                  // Blank by default: the uploader's name is not the article's author.
                  'author' => '')
        );
        $mform->addRule('pdf_file', null, 'required', null, 'client');

        // Configurações Padrão do Moodle e Botões
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}
