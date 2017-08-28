<?php
/*
 * This file is part of presupuestos_y_pedidos
 * Copyright (C) 2013-2017  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'plugins/facturacion_base/extras/fbase_controller.php';

class ventas_presupuestos extends fbase_controller
{

    public $agente;
    public $articulo;
    public $buscar_lineas;
    public $cliente;
    public $codagente;
    public $codalmacen;
    public $codgrupo;
    public $codpago;
    public $codserie;
    public $desde;
    public $forma_pago;
    public $grupo;
    public $hasta;
    public $lineas;
    public $mostrar;
    public $num_resultados;
    public $offset;
    public $order;
    public $resultados;
    public $serie;
    public $total_resultados;
    public $total_resultados_txt;

    public function __construct()
    {
        parent::__construct(__CLASS__, ucfirst(FS_PRESUPUESTOS), 'ventas');
    }

    protected function private_core()
    {
        parent::private_core();

        $presupuesto = new presupuesto_cliente();
        $this->agente = new agente();
        $this->almacenes = new almacen();
        $this->forma_pago = new forma_pago();
        $this->grupo = new grupo_clientes();
        $this->serie = new serie();

        $this->mostrar = 'todo';
        if (isset($_GET['mostrar'])) {
            $this->mostrar = $_GET['mostrar'];
            setcookie('ventas_pres_mostrar', $this->mostrar, time() + FS_COOKIES_EXPIRE);
        } else if (isset($_COOKIE['ventas_pres_mostrar'])) {
            $this->mostrar = $_COOKIE['ventas_pres_mostrar'];
        }

        $this->offset = 0;
        if (isset($_REQUEST['offset'])) {
            $this->offset = intval($_REQUEST['offset']);
        }

        $this->order = 'fecha DESC';
        if (isset($_GET['order'])) {
            $orden_l = $this->orden();
            if (isset($orden_l[$_GET['order']])) {
                $this->order = $orden_l[$_GET['order']]['orden'];
            }

            setcookie('ventas_pres_order', $this->order, time() + FS_COOKIES_EXPIRE);
        } else if (isset($_COOKIE['ventas_pres_order'])) {
            $this->order = $_COOKIE['ventas_pres_order'];
        }

        if (isset($_POST['buscar_lineas'])) {
            $this->buscar_lineas();
        } else if (isset($_REQUEST['buscar_cliente'])) {
            $this->fbase_buscar_cliente($_REQUEST['buscar_cliente']);
        } else if (isset($_GET['ref'])) {
            $this->template = 'extension/ventas_presupuestos_articulo';

            $articulo = new articulo();
            $this->articulo = $articulo->get($_GET['ref']);

            $linea = new linea_presupuesto_cliente();
            $this->resultados = $linea->all_from_articulo($_GET['ref'], $this->offset);
        } else {
            $this->share_extension();
            $this->cliente = FALSE;
            $this->codagente = '';
            $this->codalmacen = '';
            $this->codgrupo = '';
            $this->codpago = '';
            $this->codserie = '';
            $this->desde = '';
            $this->hasta = '';
            $this->num_resultados = '';
            $this->total_resultados = array();
            $this->total_resultados_txt = '';

            if (isset($_POST['delete'])) {
                $this->delete_presupuesto();
            } else if (isset($_POST['rechazar'])) {
                $this->rechazar();
            } else {
                if (!isset($_GET['mostrar']) AND ( $this->query != '' OR isset($_REQUEST['codagente']) OR isset($_REQUEST['codcliente']) OR isset($_REQUEST['codserie']))) {
                    /**
                     * si obtenermos un codagente, un codcliente o un codserie pasamos direcatemente
                     * a la pestaña de búsqueda, a menos que tengamos un mostrar, que
                     * entonces nos indica donde tenemos que estar.
                     */
                    $this->mostrar = 'buscar';
                }

                if (isset($_REQUEST['codcliente']) && $_REQUEST['codcliente'] != '') {
                    $cli0 = new cliente();
                    $this->cliente = $cli0->get($_REQUEST['codcliente']);
                }

                if (isset($_REQUEST['codagente'])) {
                    $this->codagente = $_REQUEST['codagente'];
                }

                if (isset($_REQUEST['codalmacen'])) {
                    $this->codalmacen = $_REQUEST['codalmacen'];
                }

                if (isset($_REQUEST['codgrupo'])) {
                    $this->codgrupo = $_REQUEST['codgrupo'];
                }

                if (isset($_REQUEST['codpago'])) {
                    $this->codpago = $_REQUEST['codpago'];
                }

                if (isset($_REQUEST['codserie'])) {
                    $this->codserie = $_REQUEST['codserie'];
                }

                if (isset($_REQUEST['desde'])) {
                    $this->codserie = $_REQUEST['codserie'];
                    $this->desde = $_REQUEST['desde'];
                    $this->hasta = $_REQUEST['hasta'];
                }
            }

            /// añadimos segundo nivel de ordenación
            $order2 = '';
            if ($this->order == 'fecha DESC') {
                $order2 = ', hora DESC';
            } else if ($this->order == 'fecha ASC') {
                $order2 = ', hora ASC';
            } else if (strtolower(FS_DB_TYPE) == 'postgresql' AND ( $this->order == 'finoferta DESC' OR $this->order == 'finoferta ASC')) {
                $order2 = ' NULLS LAST';
            }

            /// ejecutamos la tarea del cron
            $presupuesto->cron_job();

            if ($this->mostrar == 'pendientes') {
                $this->resultados = $presupuesto->all_ptepedir($this->offset, $this->order . $order2);

                if ($this->offset == 0) {
                    /// calculamos el total, pero desglosando por divisa
                    $this->total_resultados = array();
                    $this->total_resultados_txt = 'Suma total de esta página:';
                    foreach ($this->resultados as $pre) {
                        if (!isset($this->total_resultados[$pre->coddivisa])) {
                            $this->total_resultados[$pre->coddivisa] = array(
                                'coddivisa' => $pre->coddivisa,
                                'total' => 0
                            );
                        }

                        $this->total_resultados[$pre->coddivisa]['total'] += $pre->total;
                    }
                }
            } else if ($this->mostrar == 'rechazados') {
                $this->resultados = $presupuesto->all_rechazados($this->offset, $this->order . $order2);

                if ($this->offset == 0) {
                    /// calculamos el total, pero desglosando por divisa
                    $this->total_resultados = array();
                    $this->total_resultados_txt = 'Suma total de esta página:';
                    foreach ($this->resultados as $pre) {
                        if (!isset($this->total_resultados[$pre->coddivisa])) {
                            $this->total_resultados[$pre->coddivisa] = array(
                                'coddivisa' => $pre->coddivisa,
                                'total' => 0
                            );
                        }

                        $this->total_resultados[$pre->coddivisa]['total'] += $pre->total;
                    }
                }
            } else if ($this->mostrar == 'buscar') {
                $this->buscar($order2);
            } else {
                $this->resultados = $presupuesto->all($this->offset, $this->order . $order2);
            }
        }
    }

    public function url($busqueda = FALSE)
    {
        if ($busqueda) {
            $codcliente = '';
            if ($this->cliente) {
                $codcliente = $this->cliente->codcliente;
            }

            $url = $this->url() . "&mostrar=" . $this->mostrar
                . "&query=" . $this->query
                . "&codagente=" . $this->codagente
                . "&codalmacen=" . $this->codalmacen
                . "&codcliente=" . $codcliente
                . "&codgrupo=" . $this->codgrupo
                . "&codpago=" . $this->codpago
                . "&codserie=" . $this->codserie
                . "&desde=" . $this->desde
                . "&hasta=" . $this->hasta;

            return $url;
        }

        return parent::url();
    }

    public function paginas()
    {
        if ($this->mostrar == 'pendientes') {
            $total = $this->total_pendientes();
        } else if ($this->mostrar == 'rechazados') {
            $total = $this->total_rechazados();
        } else if ($this->mostrar == 'buscar') {
            $total = $this->num_resultados;
        } else {
            $total = $this->total_registros();
        }

        return $this->fbase_paginas($this->url(TRUE), $total, $this->offset);
    }

    public function buscar_lineas()
    {
        /// cambiamos la plantilla HTML
        $this->template = 'ajax/ventas_lineas_presupuestos';

        $this->buscar_lineas = $_POST['buscar_lineas'];
        $linea = new linea_presupuesto_cliente();

        if (isset($_POST['codcliente'])) {
            $this->lineas = $linea->search_from_cliente2($_POST['codcliente'], $this->buscar_lineas, $_POST['buscar_lineas_o'], $this->offset);
        } else {
            $this->lineas = $linea->search($this->buscar_lineas, $this->offset);
        }
    }

    private function delete_presupuesto()
    {
        $pre0 = new presupuesto_cliente();
        $presup = $pre0->get($_POST['delete']);
        if ($presup) {
            if ($presup->delete()) {
                $this->clean_last_changes();
            } else {
                $this->new_error_msg("¡Imposible eliminar el " . FS_PRESUPUESTO . "!");
            }
        } else {
            $this->new_error_msg("¡" . ucfirst(FS_PRESUPUESTO) . " no encontrado!");
        }
    }

    private function share_extension()
    {
        /// añadimos las extensiones para clientes, agentes y artículos
        $extensiones = array(
            array(
                'name' => 'presupuestos_cliente',
                'page_from' => __CLASS__,
                'page_to' => 'ventas_cliente',
                'type' => 'button',
                'text' => '<span class="glyphicon glyphicon-list" aria-hidden="true"></span> &nbsp; ' . ucfirst(FS_PRESUPUESTOS),
                'params' => ''
            ),
            array(
                'name' => 'presupuestos_agente',
                'page_from' => __CLASS__,
                'page_to' => 'admin_agente',
                'type' => 'button',
                'text' => '<span class="glyphicon glyphicon-list" aria-hidden="true"></span> &nbsp; ' . ucfirst(FS_PRESUPUESTOS) . ' de cliente',
                'params' => ''
            ),
            array(
                'name' => 'presupuestos_articulo',
                'page_from' => __CLASS__,
                'page_to' => 'ventas_articulo',
                'type' => 'tab_button',
                'text' => '<span class="glyphicon glyphicon-list" aria-hidden="true"></span> &nbsp; ' . ucfirst(FS_PRESUPUESTOS) . ' de cliente',
                'params' => ''
            ),
        );
        foreach ($extensiones as $ext) {
            $fsext0 = new fs_extension($ext);
            if (!$fsext0->save()) {
                $this->new_error_msg('Imposible guardar los datos de la extensión ' . $ext['name'] . '.');
            }
        }
    }

    public function total_pendientes()
    {
        return $this->fbase_sql_total('presupuestoscli', 'idpresupuesto', 'WHERE idpedido IS NULL AND status = 0');
    }

    public function total_rechazados()
    {
        return $this->fbase_sql_total('presupuestoscli', 'idpresupuesto', 'WHERE status = 2');
    }

    private function total_registros()
    {
        return $this->fbase_sql_total('presupuestoscli', 'idpresupuesto');
    }

    private function buscar($order2)
    {
        $this->resultados = array();
        $this->num_resultados = 0;
        $sql = " FROM presupuestoscli ";
        $where = 'WHERE ';

        if ($this->query != '') {
            $query = $this->agente->no_html(mb_strtolower($this->query, 'UTF8'));
            $sql .= $where;
            if (is_numeric($query)) {
                $sql .= "(codigo LIKE '%" . $query . "%' OR numero2 LIKE '%" . $query . "%' OR observaciones LIKE '%" . $query . "%')";
            } else {
                $sql .= "(lower(codigo) LIKE '%" . $query . "%' OR lower(numero2) LIKE '%" . $query . "%' "
                    . "OR lower(observaciones) LIKE '%" . str_replace(' ', '%', $query) . "%')";
            }
            $where = ' AND ';
        }

        if ($this->cliente) {
            $sql .= $where . "codcliente = " . $this->agente->var2str($this->cliente->codcliente);
            $where = ' AND ';
        }

        if ($this->codagente != '') {
            $sql .= $where . "codagente = " . $this->agente->var2str($this->codagente);
            $where = ' AND ';
        }

        if ($this->codalmacen != '') {
            $sql .= $where . "codalmacen = " . $this->agente->var2str($this->codalmacen);
            $where = ' AND ';
        }

        if ($this->codgrupo != '') {
            $sql .= $where . "codcliente IN (SELECT codcliente FROM clientes WHERE codgrupo = " . $this->agente->var2str($this->codgrupo) . ")";
            $where = ' AND ';
        }

        if ($this->codpago != '') {
            $sql .= $where . "codpago = " . $this->agente->var2str($this->codpago);
            $where = ' AND ';
        }

        if ($this->codserie != '') {
            $sql .= $where . "codserie = " . $this->agente->var2str($this->codserie);
            $where = ' AND ';
        }

        if ($this->desde) {
            $sql .= $where . "fecha >= " . $this->agente->var2str($this->desde);
            $where = ' AND ';
        }

        if ($this->hasta) {
            $sql .= $where . "fecha <= " . $this->agente->var2str($this->hasta);
            $where = ' AND ';
        }

        $data = $this->db->select("SELECT COUNT(idpresupuesto) as total" . $sql);
        if ($data) {
            $this->num_resultados = intval($data[0]['total']);

            $data2 = $this->db->select_limit("SELECT *" . $sql . " ORDER BY " . $this->order . $order2, FS_ITEM_LIMIT, $this->offset);
            if ($data2) {
                foreach ($data2 as $d) {
                    $this->resultados[] = new presupuesto_cliente($d);
                }
            }

            $data2 = $this->db->select("SELECT coddivisa,SUM(total) as total" . $sql . " GROUP BY coddivisa");
            if ($data2) {
                $this->total_resultados_txt = 'Suma total de los resultados:';

                foreach ($data2 as $d) {
                    $this->total_resultados[] = array(
                        'coddivisa' => $d['coddivisa'],
                        'total' => floatval($d['total'])
                    );
                }
            }
        }
    }

    private function rechazar()
    {
        $pre0 = new presupuesto_cliente();
        $num = 0;
        $offset = 0;
        $presupuestos = $pre0->all_ptepedir();
        while ($presupuestos) {
            foreach ($presupuestos as $pre) {
                if (strtotime($pre->fecha) < strtotime($_POST['rechazar'])) {
                    $pre->status = 2;
                    $pre->save();
                    $num++;
                }

                $offset++;
            }

            $presupuestos = $pre0->all_ptepedir($offset);
        }

        $this->new_message($num . ' ' . FS_PRESUPUESTOS . ' rechazados.');
    }

    public function orden()
    {
        return array(
            'fecha_desc' => array(
                'icono' => '<span class="glyphicon glyphicon-sort-by-attributes-alt" aria-hidden="true"></span>',
                'texto' => 'Fecha',
                'orden' => 'fecha DESC'
            ),
            'fecha_asc' => array(
                'icono' => '<span class="glyphicon glyphicon-sort-by-attributes" aria-hidden="true"></span>',
                'texto' => 'Fecha',
                'orden' => 'fecha ASC'
            ),
            'finoferta_desc' => array(
                'icono' => '<span class="glyphicon glyphicon-sort-by-attributes-alt" aria-hidden="true"></span>',
                'texto' => 'Validez',
                'orden' => 'finoferta DESC'
            ),
            'finoferta_asc' => array(
                'icono' => '<span class="glyphicon glyphicon-sort-by-attributes" aria-hidden="true"></span>',
                'texto' => 'Validez',
                'orden' => 'finoferta ASC'
            ),
            'codigo_desc' => array(
                'icono' => '<span class="glyphicon glyphicon-sort-by-attributes-alt" aria-hidden="true"></span>',
                'texto' => 'Código',
                'orden' => 'codigo DESC'
            ),
            'codigo_asc' => array(
                'icono' => '<span class="glyphicon glyphicon-sort-by-attributes" aria-hidden="true"></span>',
                'texto' => 'Código',
                'orden' => 'codigo ASC'
            ),
            'total_desc' => array(
                'icono' => '<span class="glyphicon glyphicon-sort-by-attributes-alt" aria-hidden="true"></span>',
                'texto' => 'Total',
                'orden' => 'total DESC'
            )
        );
    }
}
