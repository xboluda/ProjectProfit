<?php
/* Copyright (C) 2026		Xavier Boluda				<xboludac@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    projectprofit/lib/projectprofit.lib.php
 * \ingroup projectprofit
 * \brief   Library files with common functions for ProjectProfit
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function projectprofitAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("projectprofit@projectprofit");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/projectprofit/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/projectprofit/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dol_buildpath("/projectprofit/admin/myobjectline_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/projectprofit/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@projectprofit:/projectprofit/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@projectprofit:/projectprofit/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'projectprofit@projectprofit');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'projectprofit@projectprofit', 'remove');

	return $head;
}

function projectprofit_render_html($db, $data, $forpdf = false)
{
    require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

    $proj = new Project($db);

    ob_start();

    $hierarchy      = $data['hierarchy'];
    $projects_info  = $data['projects_info'];
    //$project_parent = $data['project_parent'];

    $tot_ing = 0;
    $tot_gas = 0;

    ?>
    <style>
    .linea-header { cursor: pointer; }
    </style>

    <h3>Super Detail por Proyecto</h3>

    <table border="1" cellpadding="3" cellspacing="0" width="100%">
        <tr>
            <th>Grupo</th>
            <th>Inmueble</th>
            <th>Tipo</th>
            <th>Documento</th>
            <th>Fecha</th>
            <th>Tercero</th>
            <th>Descripción</th>
            <th>Cantidad</th>
            <th>Total HT</th>
            <th>Estado</th>
        </tr>

    <?php

    // Pintar jerarquía padre -> hijo -> servicio -> lineas
    foreach ($hierarchy as $padre_id => $hijos) {


        // --- ASEGURAR INFO DEL PADRE REAL (aunque no haya filas del padre)
        if (!isset($projects_info[$padre_id])) {

            if ($proj->fetch($padre_id) > 0) {
                $projects_info[$padre_id] = [
                    'ref'   => $proj->ref,
                    'title' => $proj->title
                ];
            } else {
                $projects_info[$padre_id] = [
                    'ref'   => $padre_id,
                    'title' => 'Proyecto '.$padre_id
                ];
            }
        }



        $padre_toggle = 'padre_'.$padre_id;
        $tot_padre_ing = 0;
        $tot_padre_gas = 0;
        $estado_padre = 'PAGADA'; // Inicializamos el estado del padre

        // Primero calculamos el worst-case del padre recorriendo hijos y servicios
        foreach ($hijos as $hijo_id => $servicios) {
            $estado_hijo = 'PAGADA';
            foreach ($servicios as $servicio_ref => $lineas) {
                $estado_servicio = 'PAGADA';
                foreach ($lineas as $l) {
                    if ($l->estado_factura == 'VALIDADA') {
                        $estado_servicio = 'VALIDADA';
                        break;
                    } elseif ($l->estado_factura == 'PARCIAL' && $estado_servicio != 'VALIDADA') {
                        $estado_servicio = 'PARCIAL';
                    }
                }
                // Propagar al hijo
                if ($estado_servicio == 'VALIDADA') {
                    $estado_hijo = 'VALIDADA';
                } elseif ($estado_servicio == 'PARCIAL' && $estado_hijo != 'VALIDADA') {
                    $estado_hijo = 'PARCIAL';
                }
            }
            // Propagar al padre
            if ($estado_hijo == 'VALIDADA') {
                $estado_padre = 'VALIDADA';
            } elseif ($estado_hijo == 'PARCIAL' && $estado_padre != 'VALIDADA') {
                $estado_padre = 'PARCIAL';
            }

        }

        // Color del padre según worst-case
        $color_padre = '#27ae60';
        if ($estado_padre == 'PARCIAL')  $color_padre = '#f39c12';
        if ($estado_padre == 'VALIDADA') $color_padre = '#f44336';

        // Fila PADRE
        $label_padre = $projects_info[$padre_id]['title']; //?? 'Proyecto '.$padre_id;
        $style = $forpdf ? '' : 'style="background:#d9edf7;font-weight:bold"';
        $onclick = $forpdf ? '' : 'onclick="toggleClass(\'grp-'.$padre_toggle.'\')"';
        echo '<tr class="linea-header" '.$style.' '.$onclick.'>';
        echo '<td>'.$label_padre.'</td>';
        echo '<td colspan="8"></td>';
        echo '<td class="center">
                <span title="'.$estado_padre.'"
                    style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'.$color_padre.'"></span>
            </td>';
        echo '</tr>';

        foreach ($hijos as $hijo_id => $servicios) {

            $hijo_toggle = 'hijo_'.$padre_id.'_'.$hijo_id;
            $tot_hijo_ing = 0;
            $tot_hijo_gas = 0;
            $estado_hijo = 'PAGADA';

            // Calcular worst-case del hijo
            foreach ($servicios as $servicio_ref => $lineas) {
                $estado_servicio = 'PAGADA';
                foreach ($lineas as $l) {
                    if ($l->estado_factura == 'VALIDADA') {
                        $estado_servicio = 'VALIDADA';
                        break;
                    } elseif ($l->estado_factura == 'PARCIAL' && $estado_servicio != 'VALIDADA') {
                        $estado_servicio = 'PARCIAL';
                    }
                }
                if ($estado_servicio == 'VALIDADA') {
                    $estado_hijo = 'VALIDADA';
                } elseif ($estado_servicio == 'PARCIAL' && $estado_hijo != 'VALIDADA') {
                    $estado_hijo = 'PARCIAL';
                }
            }

            // Color del hijo
            $color_hijo = '#27ae60';
            if ($estado_hijo == 'PARCIAL')  $color_hijo = '#f39c12';
            if ($estado_hijo == 'VALIDADA') $color_hijo = '#e74c3c';

            // Fila HIJO
	    $label_hijo  = $projects_info[$hijo_id]['title'] ?? 'Proyecto '.$hijo_id;
            $style = $forpdf ? '' : 'style="display:none;background:#f7f7f7;font-weight:bold"';
            $onclick = $forpdf ? '' : 'onclick="toggleClass(\'grp-'.$hijo_toggle.'\')"';
            echo '<tr class="linea-header grp-'.$padre_toggle.'" '.$style.' '.$onclick.'>';
            echo '<td></td>';
            echo '<td>'.$label_hijo.'</td>';
            echo '<td colspan="7"></td>';
            echo '<td class="center">
                    <span title="'.$estado_hijo.'"
                        style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'.$color_hijo.'"></span>
                </td>';
            echo '</tr>';

            foreach ($servicios as $servicio_ref => $lineas) {

                $serv_toggle = 'srv_'.$padre_id.'_'.$hijo_id.'_'.md5($servicio_ref);
                $sub_ing = 0;
                $sub_gas = 0;
                $estado_servicio = 'PAGADA';

                foreach ($lineas as $l) {
                    if ($l->tipo_linea == 'INGRESO') $sub_ing += $l->total_ht;
                    if ($l->tipo_linea == 'GASTO')   $sub_gas += $l->total_ht;

                    if ($l->estado_factura == 'VALIDADA') {
                        $estado_servicio = 'VALIDADA';
                    } elseif ($l->estado_factura == 'PARCIAL' && $estado_servicio != 'VALIDADA') {
                        $estado_servicio = 'PARCIAL';
                    }

                    // Totales finales
                    if ($l->tipo_linea == 'INGRESO') $tot_ing += $l->total_ht;
                    if ($l->tipo_linea == 'GASTO')   $tot_gas += $l->total_ht;
                }

                $tot_hijo_ing  += $sub_ing;
                $tot_hijo_gas  += $sub_gas;

                // Color del servicio
                $color_srv = '#27ae60';
                if ($estado_servicio == 'PARCIAL')  $color_srv = '#f39c12';
                if ($estado_servicio == 'VALIDADA') $color_srv = '#f44336';

                // Fila SERVICIO
                $style = $forpdf ? '' : 'style="display:none"';
                $onclick = $forpdf ? '' : 'onclick="toggleClass(\'grp-'.$serv_toggle.'\')"';
                echo '<tr class="linea-header grp-'.$padre_toggle.' grp-'.$hijo_toggle.'" '.$style.' '.$onclick.'>';             
                echo '<td></td><td></td>';
                echo '<td>'.$servicio_ref.' - '.$lineas[0]->producto_nombre.'</td>';
                echo '<td colspan="5"></td>';
                echo '<td class="right">'.price($sub_ing - $sub_gas).'</td>';
                echo '<td class="center">
                        <span title="'.$estado_servicio.'" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'.$color_srv.'"></span>
                    </td>';
                echo '</tr>';

                // Fila de líneas
                foreach ($lineas as $l) {
                    $color = '#999';
                    if ($l->estado_factura == 'VALIDADA') $color = '#e74c3c';
                    if ($l->estado_factura == 'PARCIAL')  $color = '#f39c12';
                    if ($l->estado_factura == 'PAGADA')   $color = '#27ae60';

                    $line_style = $forpdf ? '' : 'style="display:none"';
                    echo '<tr class="grp-'.$padre_toggle.' grp-'.$hijo_toggle.' grp-'.$serv_toggle.'" '.$line_style.'>';
                    echo '<td></td><td></td><td>'.$l->tipo_linea.'</td>';
                    echo '<td>'.$l->doc_ref.'</td>';
                    echo '<td>'.dol_print_date($db->jdate($l->fecha),'day').'</td>';
                    echo '<td>'.$l->tercero.'</td>';
                    echo '<td>'.$l->descripcion.'</td>';
                    echo '<td class="right">'.$l->qty.'</td>';
                    echo '<td class="right">'.price($l->total_ht).'</td>';
                    echo '<td class="center">
                            <span title="'.$l->estado_factura.'" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'.$color.'"></span>
                        </td>';
                    echo '</tr>';
                }

            }

            // subtotal HIJO
            echo '<tr class="liste_total grp-'.$padre_toggle.'" style="display:none;background:#fcf8e3">';
            echo '<td></td><td colspan="7" class="right">Subtotal proyecto '.$label_hijo.'</td>';
            echo '<td class="right">'.price($tot_hijo_ing - $tot_hijo_gas).'</td>';
            echo '<td></td>';
            echo '</tr>';

            $tot_padre_ing += $tot_hijo_ing;
            $tot_padre_gas += $tot_hijo_gas;
        }

        // subtotal PADRE
        echo '<tr class="liste_total" style="background:#dff0d8">';
        echo '<td colspan="8" class="right">Subtotal proyecto padre '.$label_padre.'</td>';
        echo '<td class="right">'.price($tot_padre_ing - $tot_padre_gas).'</td>';
        echo '<td></td>';
        echo '</tr>';
    }


    ?>

    </table>

    <?php

    return ob_get_clean();
}


/**
 * Fetch report data using ProjectProfitReport provider.
 *
 * @param DoliDB $db
 * @param string $start_date
 * @param string $end_date
 * @param int    $fk_project
 *
 * @return array{data:array}|array{error:string}
 */
function projectprofit_get_report_data($db, $start_date, $end_date, $fk_project = 0)
{
    require_once DOL_DOCUMENT_ROOT.'/custom/projectprofit/class/ProjectProfitReport.class.php';

    $report = new ProjectProfitReport($db);
    if (!method_exists($report, 'buildReport')) {
        return array('error' => 'buildReport method missing in ProjectProfitReport');
    }

    $data = $report->buildReport($start_date, $end_date, $fk_project);
    if (empty($data) || !isset($data['hierarchy'])) {
        return array('error' => 'Report data empty or invalid hierarchy');
    }

    return array('data' => $data);
}

/**
 * Calculate totals from report hierarchy.
 *
 * @param array $data
 *
 * @return array{ingresos:float,gastos:float,profit:float}
 */
function projectprofit_calculate_totals($data)
{
    $tot_ing = 0;
    $tot_gas = 0;

    foreach ($data['hierarchy'] as $hijos) {
        foreach ($hijos as $servicios) {
            foreach ($servicios as $lineas) {
                foreach ($lineas as $l) {
                    if ($l->tipo_linea == 'INGRESO') {
                        $tot_ing += (float) $l->total_ht;
                    }
                    if ($l->tipo_linea == 'GASTO') {
                        $tot_gas += (float) $l->total_ht;
                    }
                }
            }
        }
    }

    return array(
        'ingresos' => $tot_ing,
        'gastos' => $tot_gas,
        'profit' => $tot_ing - $tot_gas,
    );
}

/**
 * Build a PDF report file for ProjectProfit.
 *
 * @param DoliDB $db         Database handler
 * @param array  $data       Report payload returned by ProjectProfitReport::buildReport
 * @param string $start_date Start date (Y-m-d)
 * @param string $end_date   End date (Y-m-d)
 * @param int    $fk_project Selected project id (0=all)
 *
 * @return array{path:string,filename:string}|array{error:string}
 */
function projectprofit_build_pdf_report($db, $data, $start_date, $end_date, $fk_project = 0)
{
    global $conf, $langs;

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

    $langs->load('main');

    $tmpdir = empty($conf->projectprofit->multidir_output[$conf->entity])
        ? $conf->dol_data_root.'/projectprofit'
        : $conf->projectprofit->multidir_output[$conf->entity];
    $tmpdir .= '/temp';

    if (!is_dir($tmpdir)) {
        if (!@mkdir($tmpdir, 0775, true) && !is_dir($tmpdir)) {
            return array('error' => 'Unable to create temp directory: '.$tmpdir);
        }
    if (!dol_is_dir($tmpdir)) {
        dol_mkdir($tmpdir);
    }

    $safe_start = preg_replace('/[^0-9-]/', '', (string) $start_date);
    $safe_end = preg_replace('/[^0-9-]/', '', (string) $end_date);
    $filename = 'projectprofit_'.$safe_start.'_'.$safe_end.'_'.(int) $fk_project.'_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'.pdf';
    $filepath = $tmpdir.'/'.$filename;

    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Dolibarr');
    $pdf->SetAuthor('Dolibarr ProjectProfit');
    $pdf->SetTitle('ProjectProfit '.$safe_start.' - '.$safe_end);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(8, 8, 8);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 8);

    $html = '<h2>ProjectProfit</h2>';
    $html .= '<p><strong>Proyecto:</strong> '.((int) $fk_project > 0 ? (int) $fk_project : 'Todos').'</p>';
    $html .= '<p><strong>Fechas:</strong> '.$safe_start.' al '.$safe_end.'</p>';
    $html .= projectprofit_render_html($db, $data, true);

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filepath, 'F');

    if (!file_exists($filepath)) {
        return array('error' => 'Unable to write PDF report');
    }

    return array('path' => $filepath, 'filename' => $filename);
}
