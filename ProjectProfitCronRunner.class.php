<?php

require_once DOL_DOCUMENT_ROOT.'/custom/projectprofit/class/ProjectProfitReport.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

class ProjectProfitCronRunner
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ----------------------------------------------------
    // ENTRY POINT
    // ----------------------------------------------------
    public function run($parameters = '')
    {
        global $conf;

        dol_syslog('ProjectProfitCronRunner::START '.$parameters, LOG_INFO);

        $params = preg_split('/\s+/', trim($parameters));

        $fk_project = (int) ($params[2] ?? 0);
        $email_to   = $params[3] ?? $conf->global->MAIN_INFO_SOCIETE_MAIL;

        // Fechas
        if (!empty($params[0]) && !empty($params[1])) {
            $start_date = $params[0];
            $end_date   = $params[1];
        } else {
            $d = new DateTime();
            $start_date = $d->format('Y-m-01');
            $end_date   = $d->format('Y-m-t');
        }

        dol_syslog("ProjectProfitCronRunner::Params $start_date $end_date $fk_project $email_to", LOG_INFO);

        // ----------------------------------------------------
        // Datos
        // ----------------------------------------------------

        $report = new ProjectProfitReport($this->db);
        $data   = $report->buildReport($start_date, $end_date, $fk_project);

        if (empty($data) || empty($data['hierarchy'])) {
            dol_syslog('ProjectProfitCronRunner::Empty report', LOG_WARNING);
            return 0;
        }

        // ----------------------------------------------------
        // PDF
        // ----------------------------------------------------

        $pdf = $this->buildPdfReport($data, $start_date, $end_date, $fk_project);

        if (!empty($pdf['error'])) {
            dol_syslog('ProjectProfitCronRunner::PDF ERROR '.$pdf['error'], LOG_ERR);
            return -1;
        }

        // ----------------------------------------------------
        // Totales
        // ----------------------------------------------------

        $totals = $this->calculateTotals($data);

        // ----------------------------------------------------
        // Email
        // ----------------------------------------------------

        $subject = 'ProjectProfit '.$start_date.' - '.$end_date;

        $from = !empty($conf->global->MAIN_MAIL_SENDER)
            ? $conf->global->MAIN_MAIL_SENDER
            : $conf->global->MAIN_INFO_SOCIETE_MAIL;

        $body  = '<p>Proyecto: '.($fk_project ?: 'Todos').'</p>';
        $body .= '<p>Fechas: '.$start_date.' al '.$end_date.'</p>';
        $body .= '<p>Total ingresos: '.$totals['ingresos'].'</p>';
        $body .= '<p>Total gastos: '.$totals['gastos'].'</p>';
        $body .= '<p>Profit: '.$totals['profit'].'</p>';

        $attachments = array($pdf['path']);
        $types       = array('application/pdf');
        $names       = array(basename($pdf['path']));

        $mail = new CMailFile(
            $subject,
            $email_to,
            $from,
            $body,
            $attachments,
            $types,
            $names,
            '',
            '',
            0,
            -1,
            '',
            '',
            1
        );

        
        $res = $mail->sendfile();

        if (!$res) {
            dol_syslog('ProjectProfitCronRunner::MAIL ERROR '.$mail->error, LOG_ERR);
            return -1;
        }

        if (is_file($pdf['path'])) {
            @unlink($pdf['path']);
        }

        dol_syslog('ProjectProfitCronRunner::END OK', LOG_INFO);

        return 0;
    }

    // ----------------------------------------------------
    // Totales
    // ----------------------------------------------------
    protected function calculateTotals($data)
    {
        $tot_ing = 0;
        $tot_gas = 0;

        foreach ($data['hierarchy'] as $hijos) {
            foreach ($hijos as $servicios) {
                foreach ($servicios as $lineas) {
                    foreach ($lineas as $l) {
                        if ($l->tipo_linea === 'INGRESO') $tot_ing += (float) $l->total_ht;
                        if ($l->tipo_linea === 'GASTO')   $tot_gas += (float) $l->total_ht;
                    }
                }
            }
        }

        return array(
            'ingresos' => $tot_ing,
            'gastos'   => $tot_gas,
            'profit'   => $tot_ing - $tot_gas
        );
    }

    // ----------------------------------------------------
    // PDF builder
    // ----------------------------------------------------
    protected function buildPdfReport($data, $start_date, $end_date, $fk_project)
    {
        global $conf;

        if (!class_exists('TCPDF')) {
            require_once DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/tcpdf.php';
        }

        $outdir = (empty($conf->projectprofit->multidir_output[$conf->entity])
            ? $conf->dol_data_root.'/projectprofit'
            : $conf->projectprofit->multidir_output[$conf->entity]).'/temp';

        if (!is_dir($outdir) && !@mkdir($outdir, 0775, true)) {
            return array('error' => 'Cannot create '.$outdir);
        }

        $filename = 'projectprofit_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'.pdf';
        $filepath = $outdir.'/'.$filename;

        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(6, 6, 6);
        $pdf->setCellHeightRatio(1.1);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 8);

        $html  = '<h2>ProjectProfit</h2>';
        $html .= '<p><b>Proyecto:</b> '.($fk_project ?: 'Todos').'</p>';
        $html .= '<p><b>Fechas:</b> '.$start_date.' - '.$end_date.'</p>';

        $html .= $this->renderPdfHtml($data);

        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output($filepath, 'F');

        if (!is_file($filepath)) {
            return array('error' => 'PDF not generated');
        }

        return array(
            'path' => $filepath
        );
    }

    // ----------------------------------------------------
    // HTML del PDF
    // (adaptado de tu pintado real)
    // ----------------------------------------------------
    
    protected function renderPdfHtml($data)
    {
        $hierarchy     = $data['hierarchy'];
        $projects_info = $data['projects_info'];
    
        $out = '
        <style>
            th { background-color:#eeeeee; font-weight:bold; }
            td { font-size:8px; }
        </style>
    
        <table border="1" cellpadding="2" cellspacing="0" width="100%">
        <tr>
            <th width="8%">Grupo</th>
            <th width="10%">Inmueble</th>
            <th width="7%">Tipo</th>
            <th width="10%">Documento</th>
            <th width="8%">Fecha</th>
            <th width="12%">Tercero</th>
            <th width="25%">Descripción</th>
            <th width="5%">Qty</th>
            <th width="15%">Total HT</th>
        </tr>';
    
        foreach ($hierarchy as $padre_id => $hijos) {
    
            $padre_label = $projects_info[$padre_id]['title'] ?? 'Proyecto '.$padre_id;
    
            foreach ($hijos as $hijo_id => $servicios) {
    
                $hijo_label = $projects_info[$hijo_id]['title'] ?? 'Proyecto '.$hijo_id;
    
                foreach ($servicios as $servicio_ref => $lineas) {
    
                    foreach ($lineas as $l) {
    
                        $out .= '<tr>';
                        $out .= '<td width="8%">'.dol_escape_htmltag($padre_label).'</td>';
                        $out .= '<td width="10%">'.dol_escape_htmltag($hijo_label).'</td>';
                        $out .= '<td width="7%">'.dol_escape_htmltag($l->tipo_linea).'</td>';
                        $out .= '<td width="10%">'.dol_escape_htmltag($l->doc_ref).'</td>';
                        $out .= '<td width="8%">'.dol_print_date($this->db->jdate($l->fecha),'day').'</td>';
                        $out .= '<td width="12%">'.dol_escape_htmltag($l->tercero).'</td>';
                        $out .= '<td width="25%">'.dol_escape_htmltag($l->descripcion).'</td>';
                        $out .= '<td width="5%" align="right">'.(float)$l->qty.'</td>';
                        $out .= '<td width="15%" align="right">'.price($l->total_ht).'</td>';
                        $out .= '</tr>';
                    }
                }
            }
        }
    
        $out .= '</table>';
    
        return $out;
    }

}
