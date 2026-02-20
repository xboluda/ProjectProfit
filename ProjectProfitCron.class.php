<?php
require_once DOL_DOCUMENT_ROOT.'/custom/projectprofit/class/ProjectProfitCronRunner.class.php';

class ProjectProfitCron
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function sendprojectprofitreport($parameters = '')
    {
        $runner = new ProjectProfitCronRunner($this->db);
        return $runner->run($parameters);
    }
}
