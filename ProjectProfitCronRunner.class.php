<?php
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

class ProjectProfitCronRunner
{
    /** @var DoliDB */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function run($parameters = '')
    {
        dol_syslog("ProjectProfitCronRunner::START parameters=".$parameters, LOG_INFO);

        global $conf;

        $pdf_file = '';
        $params = preg_split('/\s+/', trim((string) $parameters));
        $fk_project = (int) ($params[2] ?? 0);
        $email_to = $params[3] ?? 'boluda.casas@gmail.com';

        if (!empty($params[0]) && !empty($params[1])) {
            $start_date = $params[0];
            $end_date = $params[1];
        } else {
            $start_date = (new DateTime('first day of January'))->format('Y-m-d');
            $end_date = (new DateTime())->format('Y-m-d');
        }

        $report_payload = $this->getReportData($start_date, $end_date, $fk_project);
        if (!empty($report_payload['error'])) return -1;
        $data = $report_payload['data'];

        $pdf_meta = $this->buildPdfReport($data, $start_date, $end_date, $fk_project);
        if (!empty($pdf_meta['error'])) return -1;
        $pdf_file = $pdf_meta['path'];

        $totals = $this->calculateTotals($data);

        $html = "<h2>ProjectProfit Report</h2>";
        $html .= "<p>Proyecto: ".($fk_project ?: 'Todos')."</p>";
        $html .= "<p>Fechas: $start_date al $end_date</p>";
        $html .= "<p>Total ingresos: {$totals['ingresos']}</p>";
        $html .= "<p>Total gastos: {$totals['gastos']}</p>";
        $html .= "<p>Profit: {$totals['profit']}</p>";

        $subject = "ProjectProfit Cron Report: $start_date - $end_date";
        $from = !empty($conf->global->MAIN_MAIL_SENDER) ? $conf->global->MAIN_MAIL_SENDER : $conf->global->MAIN_INFO_SOCIETE_MAIL;

        $mail = new CMailFile($subject, $email_to, $from, $html, array($pdf_file), array(), array(), '', '', 0, -1, '', '', 'text/html');
        $res = $mail->sendfile();

        if (!empty($pdf_file) && file_exists($pdf_file)) unlink($pdf_file);

        if (!$res) {
            dol_syslog("ProjectProfitCronRunner::ERROR mail not sent: ".$mail->error, LOG_ERR);
            return -1;
        }

        return 0;
    }

    private function getReportData($start_date, $end_date, $fk_project)
    {
        require_once DOL_DOCUMENT_ROOT.'/custom/projectprofit/class/ProjectProfitReport.class.php';
        $report = new ProjectProfitReport($this->db);
        if (!method_exists($report, 'buildReport')) return array('error' => 'buildReport missing');
        $data = $report->buildReport($start_date, $end_date, $fk_project);
        if (empty($data) || !isset($data['hierarchy'])) return array('error' => 'Report data invalid');
        return array('data' => $data);
    }

    private function calculateTotals($data)
    {
        $tot_ing = 0.0;
        $tot_gas = 0.0;
        foreach ($data['hierarchy'] as $hijos) {
            foreach ($hijos as $servicios) {
                foreach ($servicios as $lineas) {
                    foreach ($lineas as $l) {
                        if ($l->tipo_linea == 'INGRESO') $tot_ing += (float) $l->total_ht;
                        if ($l->tipo_linea == 'GASTO') $tot_gas += (float) $l->total_ht;
                    }
                }
            }
        }
        return array('ingresos' => $tot_ing, 'gastos' => $tot_gas, 'profit' => $tot_ing - $tot_gas);
    }

    private function renderPdfHtml($data)
    {
        $hierarchy = $data['hierarchy'];
        $projects_info = $data['projects_info'];

        $out = '<h3>Super Detail por Proyecto</h3>';
        $out .= '<table border="1" cellpadding="3" cellspacing="0" width="100%">';
        $out .= '<tr><th>Project padre</th><th>Project hijo</th><th>Producto/Servicio</th><th>Documento</th><th>Fecha</th><th>Tercero</th><th>Descripcion</th><th>Cantidad</th><th>Total HT</th><th>Tipo</th></tr>';

        foreach ($hierarchy as $padre_id => $hijos) {
            $parent_label = $projects_info[$padre_id]['title'] ?? ('Proyecto '.$padre_id);
            foreach ($hijos as $hijo_id => $servicios) {
                $child_label = $projects_info[$hijo_id]['title'] ?? ('Proyecto '.$hijo_id);
                foreach ($servicios as $servicio_ref => $lineas) {
                    $product = !empty($lineas[0]->producto_nombre) ? $lineas[0]->producto_nombre : '';
                    $service_label = $servicio_ref.' - '.$product;
                    foreach ($lineas as $l) {
                        $out .= '<tr>';
                        $out .= '<td>'.dol_escape_htmltag($parent_label).'</td>';
                        $out .= '<td>'.dol_escape_htmltag($child_label).'</td>';
                        $out .= '<td>'.dol_escape_htmltag($service_label).'</td>';
                        $out .= '<td>'.dol_escape_htmltag($l->doc_ref).'</td>';
                        $out .= '<td>'.dol_print_date($this->db->jdate($l->fecha), 'day').'</td>';
                        $out .= '<td>'.dol_escape_htmltag($l->tercero).'</td>';
                        $out .= '<td>'.dol_escape_htmltag($l->descripcion).'</td>';
                        $out .= '<td align="right">'.((float) $l->qty).'</td>';
                        $out .= '<td align="right">'.price($l->total_ht).'</td>';
                        $out .= '<td>'.dol_escape_htmltag($l->tipo_linea).'</td>';
                        $out .= '</tr>';
                    }
                }
            }
        }
        $out .= '</table>';
        return $out;
    }

    private function buildPdfReport($data, $start_date, $end_date, $fk_project)
    {
        global $conf, $langs;

        if (!class_exists('TCPDF')) {
            if (file_exists(DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/tcpdf.php')) {
                require_once DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/tcpdf.php';
            } elseif (file_exists(DOL_DOCUMENT_ROOT.'/core/modules/facture/doc/tcpdf/tcpdf.php')) {
                require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/doc/tcpdf/tcpdf.php';
            } else {
                return array('error' => 'TCPDF library not found');
            }
        }

        if (is_object($langs)) $langs->load('main');

        $tmproot = empty($conf->projectprofit->multidir_output[$conf->entity]) ? $conf->dol_data_root.'/projectprofit' : $conf->projectprofit->multidir_output[$conf->entity];
        $tmpdir = $tmproot.'/temp';
        if (!is_dir($tmpdir) && !@mkdir($tmpdir, 0775, true) && !is_dir($tmpdir)) {
            return array('error' => 'Unable to create temp directory: '.$tmpdir);
        }

        $safe_start = preg_replace('/[^0-9-]/', '', (string) $start_date);
        $safe_end = preg_replace('/[^0-9-]/', '', (string) $end_date);
        $filename = 'projectprofit_'.$safe_start.'_'.$safe_end.'_'.(int) $fk_project.'_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'.pdf';
        $filepath = $tmpdir.'/'.$filename;

        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 8);

        $html = '<h2>ProjectProfit</h2>';
        $html .= '<p><strong>Proyecto:</strong> '.((int) $fk_project > 0 ? (int) $fk_project : 'Todos').'</p>';
        $html .= '<p><strong>Fechas:</strong> '.$safe_start.' al '.$safe_end.'</p>';
        $html .= $this->renderPdfHtml($data);

        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output($filepath, 'F');

        if (!file_exists($filepath)) return array('error' => 'Unable to write PDF report');
        return array('path' => $filepath, 'filename' => $filename);
    }
}
