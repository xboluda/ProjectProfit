<?php

class ProjectProfitCron
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function sendprojectprofitreport($parameters = '')
    {
        require_once DOL_DOCUMENT_ROOT.'/custom/projectprofit/class/ProjectProfitCronRunner.class.php';
        if (!class_exists('ProjectProfitCronRunner')) {
            dol_syslog('ProjectProfitCron::ERROR ProjectProfitCronRunner class not found after require_once', LOG_ERR);
            return -1;
        }

        $runner = new ProjectProfitCronRunner($this->db);
        return $runner->run($parameters);
    }
}
