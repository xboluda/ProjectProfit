<?php

/**
 * Cron-focused helpers for ProjectProfit report email.
 */

/**
 * Fetch report data from ProjectProfitReport provider.
 *
 * @param DoliDB $db
 * @param string $start_date
 * @param string $end_date
 * @param int    $fk_project
 *
 * @return array{data:array}|array{error:string}
 */
function projectprofit_cron_get_report_data($db, $start_date, $end_date, $fk_project = 0)
{
    require_once DOL_DOCUMENT_ROOT.'/custom/projectprofit/class/ProjectProfitReport.class.php';
    dol_syslog("projectprofit_cron_get_report_data::start start_date=".$start_date." end_date=".$end_date." fk_project=".(int) $fk_project, LOG_DEBUG);

    $report = new ProjectProfitReport($db);
    if (!method_exists($report, 'buildReport')) {
        return array('error' => 'buildReport method missing in ProjectProfitReport');
    }

    $data = $report->buildReport($start_date, $end_date, $fk_project);
    if (empty($data) || !isset($data['hierarchy'])) {
        dol_syslog("projectprofit_cron_get_report_data::invalid payload", LOG_ERR);
        return array('error' => 'Report data empty or invalid hierarchy');
    }

    return array('data' => $data);
}

/**
 * @param array $data
 * @return array{ingresos:float,gastos:float,profit:float}
 */
function projectprofit_cron_calculate_totals($data)
{
    $tot_ing = 0;
    $tot_gas = 0;

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

/**
 * Build PDF body HTML without depending on projectprofit.lib.php.
 *
 * @param DoliDB $db
 * @param array  $data
 * @return string
 */
function projectprofit_cron_render_pdf_html($db, $data)
{
    $hierarchy = $data['hierarchy'];
    $projects_info = $data['projects_info'];

    $out = '<h3>Super Detail por Proyecto</h3>';
    $out .= '<table border="1" cellpadding="3" cellspacing="0" width="100%">';
    $out .= '<tr>'
        .'<th>Project padre</th>'
        .'<th>Project hijo</th>'
        .'<th>Producto/Servicio</th>'
        .'<th>Documento</th>'
        .'<th>Fecha</th>'
        .'<th>Tercero</th>'
        .'<th>Descripcion</th>'
        .'<th>Cantidad</th>'
        .'<th>Total HT</th>'
        .'<th>Tipo</th>'
        .'</tr>';

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
                    $out .= '<td>'.dol_print_date($db->jdate($l->fecha), 'day').'</td>';
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

/**
 * Build PDF report file for cron flow.
 *
 * @param DoliDB $db
 * @param array  $data
 * @param string $start_date
 * @param string $end_date
 * @param int    $fk_project
 * @return array{path:string,filename:string}|array{error:string}
 */
function projectprofit_cron_build_pdf_report($db, $data, $start_date, $end_date, $fk_project = 0)
{
    global $conf, $langs;
    dol_syslog("projectprofit_cron_build_pdf_report::start", LOG_INFO);

    if (empty($data) || empty($data['hierarchy'])) {
        return array('error' => 'No report data to build PDF');
    }

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

    $tmpdir = (empty($conf->projectprofit->multidir_output[$conf->entity]) ? $conf->dol_data_root.'/projectprofit' : $conf->projectprofit->multidir_output[$conf->entity]).'/temp';

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
    $html .= projectprofit_cron_render_pdf_html($db, $data);

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filepath, 'F');

    if (!file_exists($filepath)) {
        return array('error' => 'Unable to write PDF report');
    }

    return array('path' => $filepath, 'filename' => $filename);
}
