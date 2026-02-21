<?php

class ProjectProfitReport
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }


    /**
     * Devuelve todos los datos del informe
     */
    public function buildReport($start_date, $end_date, $fk_project)
    {
        require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

        $db   = $this->db;
        $proj = new Project($db);

        if ($fk_project < 0) $fk_project = 0;

        // ==============================
        // 1. Construcción proyectos
        // ==============================

        $project_ids       = [];
        $projects_info     = [];
        $project_hierarchy = [];
        $project_parent    = [];

        if ($fk_project > 0) {

            if ($proj->fetch($fk_project) > 0) {

                $project_ids[] = $fk_project;
                $projects_info[$fk_project] = [
                    'ref'   => $proj->ref,
                    'title' => $proj->title
                ];

                if (!empty($proj->fk_project_parent)) {

                    $parent_id = $proj->fk_project_parent;
                    $project_parent[$fk_project] = $parent_id;

                    if ($proj->fetch($parent_id) > 0) {
                        $project_ids[] = $parent_id;
                        $projects_info[$parent_id] = [
                            'ref'   => $proj->ref,
                            'title' => $proj->title
                        ];
                    }

                    $project_hierarchy[$parent_id][] = $fk_project;

                } else {

                    $project_hierarchy[$fk_project] = [];

                    $children = $proj->getChildren($fk_project, true);
                    foreach ($children as $child) {

                        $project_ids[] = $child->rowid;
                        $projects_info[$child->rowid] = [
                            'ref'   => $child->ref,
                            'title' => $child->title
                        ];

                        $project_parent[$child->rowid] = $fk_project;
                        $project_hierarchy[$fk_project][] = $child->rowid;
                    }
                }
            }

        } else {

            $sql = "SELECT rowid FROM llx_projet";
            $resql = $db->query($sql);

            while ($o = $db->fetch_object($resql)) {

                if ($proj->fetch($o->rowid) <= 0) continue;

                $project_ids[] = $proj->id;

                $projects_info[$proj->id] = [
                    'ref'   => $proj->ref,
                    'title' => $proj->title
                ];

                if (!empty($proj->fk_project_parent)) continue; // solo padres reales

                $project_hierarchy[$proj->id] = [];

                $children = $proj->getChildren($proj->id, true);

                foreach ($children as $child) {

                    $project_ids[] = $child->rowid;

                    $projects_info[$child->rowid] = [
                        'ref'   => $child->ref,
                        'title' => $child->title
                    ];

                    $project_parent[$child->rowid] = $proj->id;
                    $project_hierarchy[$proj->id][] = $child->rowid;
                }
            }
        }

        $project_ids = array_unique($project_ids);

        // ==============================
        // 2. Where
        // ==============================

        $where_client = "f.fk_statut IN (1,2)
                          AND f.datef BETWEEN '".$db->escape($start_date)."'
                          AND '".$db->escape($end_date)."'";

        $where_fourn  = $where_client;

        if (!empty($project_ids)) {

            $ids = implode(',', $project_ids);

            $where_client .= " AND (
                f.fk_projet IN ($ids)
                OR EXISTS (
                    SELECT 1
                    FROM llx_element_element ee
                    WHERE ee.sourcetype = 'facturedet'
                      AND ee.targettype = 'project'
                      AND ee.fk_source = fd.rowid
                      AND ee.fk_target IN ($ids)
                )
            )";

            $where_fourn .= " AND (
                f.fk_projet IN ($ids)
                OR EXISTS (
                    SELECT 1
                    FROM llx_element_element ee
                    WHERE ee.sourcetype = 'facture_fourn_det'
                      AND ee.targettype = 'project'
                      AND ee.fk_source = fd.rowid
                      AND ee.fk_target IN ($ids)
                )
            )";
        }

        // ==============================
        // 3. SQL principal
        // ==============================

        $sql = "SELECT t.tipo_documento, t.producto_ref, t.producto_nombre, t.doc_ref, t.fecha, t.tercero, t.descripcion, t.qty, t.total_ht, t.tipo_linea, t.estado_factura, t.fk_project AS fk_project_child
        FROM (
            -- FACTURAS CLIENTE
            SELECT
                'CLIENTE' AS tipo_documento,
                COALESCE(pr.ref,'SIN_PRODUCTO') AS producto_ref,
                COALESCE(pr.label,'Sin descripción') AS producto_nombre,
                f.ref AS doc_ref,
                f.datef AS fecha,
                s.nom AS tercero,
                fd.description AS descripcion,
                fd.qty AS qty,
                fd.total_ht AS total_ht,
                CASE
                    WHEN a.pcg_type IN ('INGRES','INCOME') THEN 'INGRESO'
                    WHEN a.pcg_type IN ('DESPESA','EXPENSE') THEN 'GASTO'
                WHEN a.rowid IS NULL THEN 'SIN VENTILAR'
                    ELSE CONCAT(a.account_number,' - ',a.pcg_type)
                END AS tipo_linea,
                CASE
                    WHEN f.paye = 1 THEN 'PAGADA'
                    WHEN EXISTS (
                        SELECT 1 FROM llx_paiement_facture pf
                        WHERE pf.fk_facture = f.rowid
                    ) THEN 'PARCIAL'
                    ELSE 'VALIDADA'
                END AS estado_factura,
                f.fk_projet AS fk_project
            FROM llx_facture f
            JOIN llx_facturedet fd ON fd.fk_facture = f.rowid
            LEFT JOIN llx_product pr ON pr.rowid = fd.fk_product
            LEFT JOIN llx_accounting_account a ON a.rowid = fd.fk_code_ventilation
            JOIN llx_societe s ON s.rowid = f.fk_soc
            WHERE $where_client

            UNION ALL

            -- FACTURAS PROVEEDOR
            SELECT
                'PROVEEDOR' AS tipo_documento,
                COALESCE(pr.ref,'SIN_PRODUCTO') AS producto_ref,
                COALESCE(pr.label,'Sin descripción') AS producto_nombre,
                f.ref AS doc_ref,
                f.datef AS fecha,
                s.nom AS tercero,
                fd.description AS descripcion,
                fd.qty AS qty,
                fd.total_ht AS total_ht,
                CASE
                    WHEN a.pcg_type IN ('INGRES','INCOME') THEN 'INGRESO'
                    WHEN a.pcg_type IN ('DESPESA','EXPENSE') THEN 'GASTO'
                    WHEN a.rowid IS NULL THEN 'SIN VENTILAR'
                    ELSE CONCAT(a.account_number,' - ',a.pcg_type)
                END AS tipo_linea,
                CASE
                    WHEN f.paye = 1 THEN 'PAGADA'
                    WHEN EXISTS (
                        SELECT 1 FROM llx_paiementfourn_facturefourn pf
                        WHERE pf.fk_facturefourn = f.rowid
                    ) THEN 'PARCIAL'
                    ELSE 'VALIDADA'
                END AS estado_factura,
                f.fk_projet AS fk_project
            FROM llx_facture_fourn f
            JOIN llx_facture_fourn_det fd ON fd.fk_facture_fourn = f.rowid
            LEFT JOIN llx_product pr ON pr.rowid = fd.fk_product
            LEFT JOIN llx_accounting_account a ON a.rowid = fd.fk_code_ventilation
            JOIN llx_societe s ON s.rowid = f.fk_soc
            WHERE $where_fourn
        ) t
        ORDER BY t.fk_project, t.producto_ref, t.tipo_linea, t.fecha, t.doc_ref";


        $resql = $db->query($sql);
        if (!$resql) {
            throw new Exception($db->lasterror());
        }

        // ==============================
        // 4. Construcción hierarchy final
        // ==============================

        $hierarchy = [];

        while ($obj = $db->fetch_object($resql)) {

            $fk_child = (int) $obj->fk_project_child;

            // -----------------------------
            // Determinar padre real
            // -----------------------------
            if (!empty($project_parent[$fk_child]) && $project_parent[$fk_child] > 0) {

                $padre_id = (int) $project_parent[$fk_child];

                // <<< CAMBIO: asegurar info del PADRE real
                if (!isset($projects_info[$padre_id])) {
                    if ($proj->fetch($padre_id) > 0) {
                        $projects_info[$padre_id] = [
                            'ref'   => $proj->ref,
                            'title' => $proj->title
                        ];
                    } else {
                        $projects_info[$padre_id] = [
                            'ref'   => 'PADRE-'.$padre_id,
                            'title' => 'Proyecto '.$padre_id
                        ];
                    }
                }

            } else {

                // <<< CAMBIO: el propio proyecto es padre
                $padre_id = $fk_child;

                if (!isset($projects_info[$padre_id])) {
                    if ($proj->fetch($padre_id) > 0) {
                        $projects_info[$padre_id] = [
                            'ref'   => $proj->ref,
                            'title' => $proj->title
                        ];
                    } else {
                        $projects_info[$padre_id] = [
                            'ref'   => 'PADRE-'.$padre_id,
                            'title' => 'Proyecto '.$padre_id
                        ];
                    }
                }
            }

            // <<< CAMBIO: asegurar también info del HIJO
            if (!isset($projects_info[$fk_child])) {
                if ($proj->fetch($fk_child) > 0) {
                    $projects_info[$fk_child] = [
                        'ref'   => $proj->ref,
                        'title' => $proj->title
                    ];
                } else {
                    $projects_info[$fk_child] = [
                        'ref'   => 'HIJO-'.$fk_child,
                        'title' => 'Proyecto '.$fk_child
                    ];
                }
            }

            // -----------------------------
            // Insertar en jerarquía
            // -----------------------------
            $hierarchy[$padre_id][$fk_child][$obj->producto_ref][] = $obj;

        }




        // ==============================
        // 5. Resultado
        // ==============================

        return [
            'hierarchy'        => $hierarchy,
            'projects_info'    => $projects_info,
            'project_parent'   => $project_parent,
            'project_ids'      => $project_ids
        ];
    }
}
