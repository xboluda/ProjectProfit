<?php
require_once DOL_DOCUMENT_ROOT.'/custom/projectprofit/class/ProjectProfitReport.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/projectprofit/lib/projectprofit.lib.php';


class ProjectProfitCron
{
    public function __construct($db)
    {
        $this->db = $db;
    }



    public function sendprojectprofitreport($parameters = '')
    {
        dol_syslog("ProjectProfitCron::START parameters=".$parameters, LOG_INFO);
        echo "START cron<br>\n";

        global $conf;

        $db = $this->db;

        // Parseo de parámetros
        $params = preg_split('/\s+/', trim($parameters));
        $fk_project = (int) ($params[2] ?? 0);
        $email_to   = $params[3] ?? 'boluda.casas@gmail.com';

	// Calcular fechas
	if (!empty($params[0]) && !empty($params[1])) {
    	    $start_date = $params[0];
    	    $end_date   = $params[1];
	} else {
	    // Por defecto: mes actual
	    $hoy = new DateTime();
	    $start_date = (new DateTime('first day of January'))->format('Y-m-d');
	    $end_date = (new DateTime())->format('Y-m-d');

	    // $start_date = $hoy->format('Y-m-01');  // primer día del mes
	    // $end_date   = $hoy->format('Y-m-t');   // último día del mes
	}

        echo "Params: $start_date - $end_date - $fk_project - $email_to<br>\n";
        dol_syslog("ProjectProfitCron::Params parsed: start=$start_date end=$end_date fk_project=$fk_project email=$email_to", LOG_INFO);

        // Crear objeto report
        echo "Instanciando ProjectProfitReport<br>\n";
        dol_syslog("ProjectProfitCron::Creating ProjectProfitReport object", LOG_INFO);

        $report = new ProjectProfitReport($db);
        if (!method_exists($report, 'buildReport')) {
            dol_syslog("ProjectProfitCron::ERROR buildReport method does not exist", LOG_ERR);
            echo "ERROR: buildReport method missing<br>\n";
            return -1;
        }

        echo "Llamando buildReport<br>\n";
        $data = $report->buildReport($start_date, $end_date, $fk_project);

        if (empty($data) || !isset($data['hierarchy'])) {
            dol_syslog("ProjectProfitCron::ERROR data empty or hierarchy missing", LOG_ERR);
            echo "ERROR: data empty<br>\n";
            return -1;
        }

        echo "Calculando totales<br>\n";
        $tot_ing = 0;
        $tot_gas = 0;
        foreach ($data['hierarchy'] as $padre_id => $hijos) {
            foreach ($hijos as $hijo_id => $servicios) {
                foreach ($servicios as $servicio_ref => $lineas) {
                    foreach ($lineas as $l) {
                        if ($l->tipo_linea == 'INGRESO') $tot_ing += $l->total_ht;
                        if ($l->tipo_linea == 'GASTO')   $tot_gas += $l->total_ht;
                    }
                }
            }
        }
        echo "Totales: ING=$tot_ing GAST=$tot_gas<br>\n";








        // Preparar mail
        $html  = "<h2>ProjectProfit Report</h2>";
        $html .= "<p>Proyecto: ".($fk_project ?: 'Todos')."</p>";
        $html .= "<p>Fechas: $start_date al $end_date</p>";
        $html .= "<p>Total ingresos: $tot_ing</p>";
        $html .= "<p>Total gastos: $tot_gas</p>";
        $html .= "<p>Profit: ".($tot_ing - $tot_gas)."</p>";

        $subject = "ProjectProfit Cron Report: $start_date - $end_date";

        $from = !empty($conf->global->MAIN_MAIL_SENDER)
            ? $conf->global->MAIN_MAIL_SENDER
            : $conf->global->MAIN_INFO_SOCIETE_MAIL;

        echo "Preparando mail<br>\n";
        dol_syslog("ProjectProfitCron::Preparing mail", LOG_INFO);

        $mail = new CMailFile(
            $subject,
            $email_to,
            $from,
            $html,
            array($pdf_file),   // attachments
            array(),   // cc
            array(),   // bcc
            '',        // delivery receipt
            '',        // msgid
            0,
            -1,
            '',
            '',
            'text/html'
        );

        echo "Enviando mail<br>\n";
        $res = $mail->sendfile();

        if ($res) {
            dol_syslog("ProjectProfitCron::OK mail sent to ".$email_to, LOG_INFO);
            echo "MAIL SENT OK<br>\n";
            return 0;
        } else {
            dol_syslog("ProjectProfitCron::ERROR mail not sent: ".$mail->error, LOG_ERR);
            echo "MAIL ERROR: ".$mail->error."<br>\n";
            return -1;
        }

	if (file_exists($pdf_file)) unlink($pdf_file); // borrar el fichero enviado

    }
}
