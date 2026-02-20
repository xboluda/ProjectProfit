<?php
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/projectprofit/lib/projectprofit.cron.lib.php';


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

        $pdf_file = '';

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

        // Crear payload usando proveedor ProjectProfitReport
        dol_syslog("ProjectProfitCron::Building report data through projectprofit_get_report_data", LOG_INFO);
        $report_payload = projectprofit_cron_get_report_data($db, $start_date, $end_date, $fk_project);
        $report_payload = projectprofit_get_report_data($db, $start_date, $end_date, $fk_project);
        if (!empty($report_payload['error'])) {
            dol_syslog("ProjectProfitCron::ERROR report data: ".$report_payload['error'], LOG_ERR);
            echo "ERROR: ".$report_payload['error']."<br>\n";
            return -1;
        }

        $data = $report_payload['data'];
        dol_syslog("ProjectProfitCron::Report payload loaded. Parent groups=".count($data['hierarchy']), LOG_INFO);

        echo "Generando PDF<br>\n";
        dol_syslog("ProjectProfitCron::Generating PDF file", LOG_INFO);
        $pdf_meta = projectprofit_cron_build_pdf_report($db, $data, $start_date, $end_date, $fk_project);

        echo "Generando PDF<br>\n";
        dol_syslog("ProjectProfitCron::Generating PDF file", LOG_INFO);

        echo "Generando PDF<br>\n";
        $pdf_meta = projectprofit_build_pdf_report($db, $data, $start_date, $end_date, $fk_project);
        if (!empty($pdf_meta['error'])) {
            dol_syslog("ProjectProfitCron::ERROR building PDF: ".$pdf_meta['error'], LOG_ERR);
            echo "ERROR PDF: ".$pdf_meta['error']."<br>\n";
            return -1;
        }

        $pdf_file = $pdf_meta['path'];
        dol_syslog("ProjectProfitCron::PDF generated at ".$pdf_file, LOG_INFO);

        echo "Calculando totales<br>\n";
        $totals = projectprofit_cron_calculate_totals($data);
        echo "Generando PDF<br>\n";
        $pdf_meta = projectprofit_build_pdf_report($db, $data, $start_date, $end_date, $fk_project);
        if (!empty($pdf_meta['error'])) {
            dol_syslog("ProjectProfitCron::ERROR building PDF: ".$pdf_meta['error'], LOG_ERR);
            echo "ERROR PDF: ".$pdf_meta['error']."<br>\n";
            return -1;
        }

        $pdf_file = $pdf_meta['path'];

        echo "Calculando totales<br>\n";
        $totals = projectprofit_calculate_totals($data);
        $tot_ing = $totals['ingresos'];
        $tot_gas = $totals['gastos'];
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
        dol_syslog("ProjectProfitCron::Sending email to ".$email_to, LOG_INFO);
        $res = $mail->sendfile();

        if ($res) {
            dol_syslog("ProjectProfitCron::OK mail sent to ".$email_to, LOG_INFO);
            echo "MAIL SENT OK<br>\n";
            $result = 0;
        } else {
            dol_syslog("ProjectProfitCron::ERROR mail not sent: ".$mail->error, LOG_ERR);
            echo "MAIL ERROR: ".$mail->error."<br>\n";
            $result = -1;
        }

        if (!empty($pdf_file) && file_exists($pdf_file)) {
            unlink($pdf_file); // borrar el fichero enviado
        }

        return $result;

    }
}
